# -*- coding: utf-8 -*-
import uuid
from xml.sax.saxutils import quoteattr

from odoo import _, api, fields, models
from odoo.exceptions import UserError

from .utils import match_option

FTYPES = [
    ('char', "Text"),
    ('text', "Long text"),
    ('integer', "Integer"),
    ('float', "Decimal number"),
    ('date', "Date"),
    ('boolean', "Yes / No"),
    ('selection', "List (single choice)"),
    ('tags', "List (multiple choices)"),
]


class EasyCrmTag(models.Model):
    _name = 'easycrm.tag'
    _description = "Multiple-choice field option"
    _order = 'name'

    name = fields.Char("Option", required=True)
    field_id = fields.Many2one('easycrm.field', "Field", ondelete='cascade', required=True)


class EasyCrmField(models.Model):
    """Champ personnalisé : crée un vrai champ Odoo (x_ec_xxxx) sur easycrm.prospect
    et l'ajoute aux vues formulaire / liste / recherche. Fonctionne donc avec
    l'import/export natif, les filtres, les regroupements et les tableaux croisés."""
    _name = 'easycrm.field'
    _description = "Custom field"
    _order = 'sequence, id'

    name = fields.Char("Label", required=True)
    ftype = fields.Selection(FTYPES, "Type", required=True, default='char')
    options = fields.Text("Choices (one per line)")
    sequence = fields.Integer(default=10)
    active = fields.Boolean(default=True)
    technical_name = fields.Char("Technical name", readonly=True, copy=False)
    ir_field_id = fields.Many2one('ir.model.fields', readonly=True, copy=False, ondelete='set null')
    show_in_list = fields.Boolean("Show in list", default=False)

    # ------------------------------------------------------------------ helpers
    def option_list(self):
        self.ensure_one()
        return [o.strip() for o in (self.options or '').splitlines() if o.strip()]

    def add_option(self, value):
        """Ajoute une option à un champ liste (retourne la valeur canonique)."""
        self.ensure_one()
        opts = self.option_list()
        found = match_option(value, opts)
        if found:
            return found
        value = (value or '').strip()
        if value:
            self.sudo().write({'options': '\n'.join(opts + [value])})
        return value

    def _sel_key(self, label):
        return label.strip()

    def _sync_ir_options(self):
        for rec in self.sudo():
            if not rec.ir_field_id:
                continue
            if rec.ftype == 'selection':
                have = {s.value for s in rec.ir_field_id.selection_ids}
                for i, o in enumerate(rec.option_list()):
                    key = rec._sel_key(o)
                    if key not in have:
                        self.env['ir.model.fields.selection'].create({
                            'field_id': rec.ir_field_id.id, 'value': key, 'name': o, 'sequence': i + 1})
                        have.add(key)
            elif rec.ftype == 'tags':
                have = {t.name for t in self.env['easycrm.tag'].search([('field_id', '=', rec.id)])}
                for o in rec.option_list():
                    if o not in have:
                        self.env['easycrm.tag'].create({'name': o, 'field_id': rec.id})
                        have.add(o)

    # ------------------------------------------------------------------ vues
    @api.model
    def _rebuild_views(self):
        fields_ = self.sudo().search([('active', '=', True), ('technical_name', '!=', False)])
        form, lst, srch = [], [], []
        for f in fields_:
            n = f.technical_name
            if f.ftype == 'tags':
                form.append('<field name="%s" widget="many2many_tags" options="{\'no_create\': True}"/>' % n)
            elif f.ftype == 'text':
                form.append('<field name="%s" colspan="2"/>' % n)
            else:
                form.append('<field name="%s"/>' % n)
            lst.append('<field name="%s" optional="%s"%s/>' % (
                n, 'show' if f.show_in_list else 'hide',
                ' widget="many2many_tags"' if f.ftype == 'tags' else ''))
            if f.ftype != 'text':
                srch.append('<field name="%s"/>' % n)
        defs = [
            ('easycrm.view_prospect_form_custom', "//group[@name='custom_fields']", form),
            ('easycrm.view_prospect_list_custom', "//list", lst),
            ('easycrm.view_prospect_search_custom', "//search", srch),
        ]
        for xmlid, xpath, items in defs:
            view = self.env.ref(xmlid, raise_if_not_found=False)
            if not view:
                continue
            arch = '<data><xpath expr=%s position="inside">%s</xpath></data>' % (quoteattr(xpath), ''.join(items))
            view.sudo().write({'arch_base': arch})

    # ------------------------------------------------------------------ CRUD
    @api.model_create_multi
    def create(self, vals_list):
        recs = super().create(vals_list)
        model = self.env['ir.model']._get('easycrm.prospect')
        for rec in recs.sudo():
            tech = 'x_ec_' + uuid.uuid4().hex[:6]
            vals = {
                'name': tech, 'model_id': model.id, 'field_description': rec.name,
                'state': 'manual', 'copied': False,
            }
            if rec.ftype == 'tags':
                vals.update(ttype='many2many', relation='easycrm.tag',
                            relation_table='x_ec_rel_' + tech[5:], column1='prospect_id', column2='tag_id',
                            domain="[('field_id', '=', %d)]" % rec.id)
            elif rec.ftype == 'selection':
                vals.update(ttype='selection')
            else:
                vals.update(ttype=rec.ftype)
            irf = self.env['ir.model.fields'].sudo().create(vals)
            rec.write({'technical_name': tech, 'ir_field_id': irf.id})
            rec._sync_ir_options()
        self._rebuild_views()
        return recs

    def write(self, vals):
        if 'ftype' in vals:
            for r in self:
                if r.technical_name and vals['ftype'] != r.ftype:
                    raise UserError(_("The type of an existing field cannot be changed: create a new field."))
        res = super().write(vals)
        for rec in self.sudo():
            if rec.ir_field_id and 'name' in vals:
                rec.ir_field_id.field_description = rec.name
        if 'options' in vals:
            self._sync_ir_options()
        if {'active', 'show_in_list', 'sequence', 'name'} & set(vals):
            self._rebuild_views()
        return res

    def unlink(self):
        irfs = self.mapped('ir_field_id')
        for r in self:
            r.active = False  # retire le champ des vues avant suppression
        self._rebuild_views()
        res = super().unlink()
        irfs.sudo().unlink()
        return res
