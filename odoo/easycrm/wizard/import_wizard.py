# -*- coding: utf-8 -*-
import base64
import csv
import io
from datetime import date, datetime

from odoo import _, fields, models
from odoo.exceptions import UserError

from ..models.utils import canon, get_cfg, match_option, normalize_phone, stem

ALIASES = {
    'name': {'nom prenom', 'nom et prenom', 'nom complet', 'nom', 'client', 'name', 'full name', 'prospect',
             'nom prenom client'},
    'first': {'prenom', 'first name', 'firstname'},
    'last': {'nom de famille', 'last name', 'lastname'},
    'phone': {'telephone', 'tel', 'phone', 'mobile', 'gsm', 'numero', 'n tel', 'num tel', 'portable',
              'telephone 1', 'tel 1'},
    'phone2': {'telephone 2', 'tel 2', 'phone 2', 'mobile 2'},
    'email': {'mail', 'email', 'e mail', 'courriel', 'adresse mail'},
    'gender': {'sexe', 'genre', 'gender'},
    'region': {'wilaya', 'region', 'ville', 'province'},
    'category': {'categorie', 'categorie produit', 'produit', 'category', 'famille'},
    'note': {'remarque', 'commentaire', 'note', 'observation', 'comment', 'remarques'},
}
# Extra header aliases: Arabic, Spanish, Italian, German
for _k, _v in {
    'name': ['الاسم', 'الاسم الكامل', 'العميل', 'nombre', 'nombre completo', 'cliente', 'nome', 'nome e cognome',
             'vollstandiger name', 'kunde', 'name und vorname'],
    'first': ['الاسم الاول', 'nombre de pila', 'vorname', 'nome proprio'],
    'last': ['اللقب', 'apellido', 'cognome', 'nachname'],
    'phone': ['الهاتف', 'رقم الهاتف', 'الجوال', 'telefono', 'movil', 'celular', 'cellulare', 'telefon', 'handy',
              'mobil', 'telefonnummer'],
    'phone2': ['الهاتف 2', 'telefono 2', 'telefon 2', 'handy 2'],
    'email': ['البريد الالكتروني', 'البريد', 'correo', 'correo electronico', 'e mail', 'posta elettronica'],
    'gender': ['الجنس', 'sexo', 'genero', 'sesso', 'geschlecht'],
    'region': ['الولاية', 'المنطقة', 'region', 'regione', 'provincia', 'ciudad', 'citta', 'stadt', 'bundesland'],
    'category': ['الفئة', 'categoria', 'kategorie'],
    'note': ['ملاحظة', 'ملاحظات', 'nota', 'notas', 'observacion', 'osservazione', 'notiz', 'bemerkung',
             'kommentar', 'commento', 'comentario'],
}.items():
    ALIASES[_k].update(_v)


class EasyCrmImportWizard(models.TransientModel):
    _name = 'easycrm.import.wizard'
    _description = "Prospect import (Excel / CSV)"

    file = fields.Binary("Excel or CSV file", required=True)
    filename = fields.Char()
    category_id = fields.Many2one('easycrm.category', "Default category",
                                  help="Used when the file has no category column.")
    user_id = fields.Many2one('res.users', "Assign to",
                              help="Leave empty to import without assignment, then distribute.")
    skip_duplicates = fields.Boolean("Skip duplicates (same number)", default=True)
    state = fields.Selection([('draft', "Preparation"), ('done', "Done")], default='draft')
    result = fields.Text("Result", readonly=True)
    batch = fields.Char(readonly=True)

    # ------------------------------------------------------------------ lecture fichier
    def _read_rows(self):
        raw = base64.b64decode(self.file)
        name = (self.filename or '').lower()
        rows = []
        if name.endswith('.xlsx') or name.endswith('.xlsm') or raw[:2] == b'PK':
            try:
                import openpyxl
            except ImportError:
                raise UserError(_("The Python library openpyxl is required to read .xlsx files."))
            wb = openpyxl.load_workbook(io.BytesIO(raw), read_only=True, data_only=True)
            ws = wb.worksheets[0]
            for r in ws.iter_rows(values_only=True):
                rows.append(['' if c is None else c for c in r])
        elif name.endswith('.xls'):
            try:
                import xlrd
            except ImportError:
                raise UserError(_("The .xls format is not supported here: save the file as .xlsx or .csv."))
            book = xlrd.open_workbook(file_contents=raw)
            sh = book.sheet_by_index(0)
            for i in range(sh.nrows):
                rows.append(sh.row_values(i))
        else:
            for enc in ('utf-8-sig', 'cp1252', 'latin-1'):
                try:
                    text = raw.decode(enc)
                    break
                except UnicodeDecodeError:
                    continue
            sample = text[:4096]
            try:
                dialect = csv.Sniffer().sniff(sample, delimiters=';,\t|')
            except csv.Error:
                dialect = csv.excel
                dialect.delimiter = ';' if sample.count(';') >= sample.count(',') else ','
            rows = list(csv.reader(io.StringIO(text), dialect))
        rows = [r for r in rows if any(str(c).strip() for c in r)]
        if len(rows) < 2:
            raise UserError(_("The file must contain a header row and at least one data row."))
        return rows

    @staticmethod
    def _txt(v):
        if isinstance(v, float) and v.is_integer():
            v = int(v)
        if isinstance(v, (datetime, date)):
            return v.strftime('%Y-%m-%d')
        return str(v).strip()

    # ------------------------------------------------------------------ conversion champs perso
    def _convert(self, f, value):
        value = self._txt(value)
        if value == '':
            return None
        t = f.ftype
        if t in ('char', 'text'):
            return value
        if t == 'integer':
            try:
                return int(float(value.replace(',', '.').replace(' ', '')))
            except ValueError:
                return None
        if t == 'float':
            try:
                return float(value.replace(',', '.').replace(' ', ''))
            except ValueError:
                return None
        if t == 'boolean':
            return canon(value) in ('oui', 'yes', 'o', 'y', '1', 'true', 'vrai', 'x')
        if t == 'date':
            for fmt in ('%Y-%m-%d', '%d/%m/%Y', '%d-%m-%Y', '%d.%m.%Y', '%Y/%m/%d'):
                try:
                    return datetime.strptime(value[:10], fmt).date()
                except ValueError:
                    pass
            return None
        if t == 'selection':
            return f.add_option(value)
        if t == 'tags':
            names = [p.strip() for p in value.replace(';', ',').replace('|', ',').replace('/', ',').split(',')
                     if p.strip()]
            ids = []
            for n in names:
                canonical = f.add_option(n)
                tag = self.env['easycrm.tag'].sudo().search([('field_id', '=', f.id), ('name', '=', canonical)],
                                                            limit=1)
                if tag:
                    ids.append(tag.id)
            return [(6, 0, ids)] if ids else None
        return value

    # ------------------------------------------------------------------ import
    def action_import(self):
        self.ensure_one()
        cfg = get_cfg(self.env)
        rows = self._read_rows()
        headers = [self._txt(h) for h in rows[0]]
        cf = self.env['easycrm.field'].sudo().search([('active', '=', True)])
        cf_by_label = {canon(f.name): f for f in cf}
        colmap = {}  # index -> ('std', key) | ('custom', field)
        for i, h in enumerate(headers):
            c = canon(h)
            if not c:
                continue
            for key, al in ALIASES.items():
                if c in al and not any(v == ('std', key) for v in colmap.values()):
                    colmap[i] = ('std', key)
                    break
            else:
                if c in cf_by_label:
                    colmap[i] = ('custom', cf_by_label[c])
        keys = {v[1] for v in colmap.values() if v[0] == 'std'}
        if 'phone' not in keys:
            raise UserError(_("Phone column not found. Headers read: %s") % ', '.join(headers))
        if not ({'name', 'first', 'last'} & keys):
            raise UserError(_("Name column not found. Headers read: %s") % ', '.join(headers))

        Prospect = self.env['easycrm.prospect'].with_context(
            tracking_disable=True, mail_create_nolog=True, mail_notrack=True)
        existing = set()
        if self.skip_duplicates:
            existing = {r['phone'] for r in Prospect.sudo().with_context(active_test=False).search_read(
                [('phone', '!=', False)], ['phone'])}
        cats = {stem(c.name): c for c in self.env['easycrm.category'].sudo().search([])}
        users = {}
        batch = "%s — %s" % (fields.Datetime.now().strftime('%Y-%m-%d %H:%M'), self.filename or 'import')
        vals_list, n_dup, n_bad, n_empty = [], 0, 0, 0
        seen = set()
        lines = []
        for ln, row in enumerate(rows[1:], start=2):
            d = {}
            custom = {}
            for i, (kind, key) in colmap.items():
                if i >= len(row):
                    continue
                if kind == 'std':
                    d[key] = self._txt(row[i])
                else:
                    v = self._convert(key, row[i])
                    if v is not None:
                        custom[key.technical_name] = v
            name = d.get('name') or ' '.join(x for x in (d.get('last'), d.get('first')) if x)
            phone, valid = normalize_phone(d.get('phone', ''), cfg)
            if not name and not phone:
                n_empty += 1
                continue
            if not phone:
                n_bad += 1
                lines.append(_("Row %d: missing number (%s)") % (ln, name))
                continue
            if not valid:
                lines.append(_("Row %d: number to check (%s → %s)") % (ln, d.get('phone'), phone))
            if self.skip_duplicates and (phone in existing or phone in seen):
                n_dup += 1
                continue
            seen.add(phone)
            cat = self.category_id
            if d.get('category'):
                cat = cats.get(stem(d['category']))
                if not cat:
                    cat = self.env['easycrm.category'].sudo().create({'name': d['category'].strip()})
                    cats[stem(cat.name)] = cat
            g = canon(d.get('gender', ''))
            vals = {
                'name': name or phone, 'phone': phone, 'phone2': d.get('phone2') or False,
                'email': d.get('email') or False, 'region': d.get('region') or False,
                'gender': 'f' if g in ('f', 'femme', 'feminin', 'female', 'mme') else
                          ('m' if g in ('m', 'h', 'homme', 'masculin', 'male', 'mr') else False),
                'note': d.get('note') or False, 'category_id': cat.id if cat else False,
                'user_id': self.user_id.id or False, 'import_batch': batch,
            }
            vals.update(custom)
            vals_list.append(vals)
        for i in range(0, len(vals_list), 500):
            Prospect.create(vals_list[i:i + 500])
        self.write({
            'state': 'done', 'batch': batch,
            'result': _("%d prospect(s) imported\n%d duplicate(s) skipped\n%d row(s) without number\n%d empty row(s)\n%s") % (len(vals_list), n_dup, n_bad, n_empty,
                                                   '\n'.join(lines[:50])),
        })
        return {
            'type': 'ir.actions.act_window', 'res_model': self._name, 'res_id': self.id,
            'view_mode': 'form', 'target': 'new',
        }

    def action_open_batch(self):
        return {
            'type': 'ir.actions.act_window', 'name': _("Imported prospects"), 'res_model': 'easycrm.prospect',
            'view_mode': 'list,form', 'domain': [('import_batch', '=', self.batch)],
        }
