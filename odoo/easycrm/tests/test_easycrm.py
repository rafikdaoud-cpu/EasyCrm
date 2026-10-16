import base64
from datetime import timedelta

from odoo import fields
from odoo.exceptions import AccessError, ValidationError
from odoo.tests.common import TransactionCase, tagged

from odoo.addons.easycrm.models.utils import get_cfg, normalize_phone, normalize_region, stem, match_option


@tagged('post_install', '-at_install')
class TestEasyCrm(TransactionCase):

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.cat_a = cls.env['easycrm.category'].create({'name': 'Motos'})
        cls.cat_b = cls.env['easycrm.category'].create({'name': 'Electro'})
        mk = lambda login, grp: cls.env['res.users'].create({
            'name': login, 'login': login, 'email': login + '@x.dz',
            'groups_id': [(6, 0, [cls.env.ref(grp).id])]})
        cls.com1 = mk('com1', 'easycrm.group_commercial')
        cls.com2 = mk('com2', 'easycrm.group_commercial')
        cls.sup = mk('sup', 'easycrm.group_superviseur')
        cls.ro = mk('ro', 'easycrm.group_lecture')
        cls.team = cls.env['easycrm.team'].create({
            'name': 'T1', 'category_ids': [(6, 0, cls.cat_a.ids)], 'member_ids': [(6, 0, cls.com1.ids)]})

    def test_phone(self):
        cfg = {'country': 'DZ', 'cc': '213', 'trunk': '0', 'company': ''}
        self.assertEqual(normalize_phone('+213 555 12 34 56', cfg), ('0555123456', True))
        self.assertEqual(normalize_phone('00213555123456', cfg), ('0555123456', True))
        self.assertEqual(normalize_phone('555123456', cfg), ('0555123456', True))
        self.assertEqual(normalize_phone('0555123456', cfg), ('0555123456', True))
        self.assertEqual(normalize_phone('021 12 34 56', cfg)[0], '021123456')
        self.assertFalse(normalize_phone('12345', cfg)[1])
        tn = {'country': 'TN', 'cc': '216', 'trunk': '', 'company': ''}
        self.assertEqual(normalize_phone('+216 22 123 456', tn), ('22123456', True))
        self.assertEqual(normalize_region('16', cfg), 'Alger')
        self.assertEqual(normalize_region('وهران', cfg), 'Oran')
        self.assertEqual(normalize_region('bejaia', cfg), 'Béjaïa')
        self.assertEqual(match_option('moto', ['Motos', 'Electro']), 'Motos')
        self.assertEqual(stem('Aménagement'), stem('amenagements'))

    def test_prospect_create_normalizes(self):
        p = self.env['easycrm.prospect'].create({'name': '  Ali   Ben ', 'phone': '+213 661 00 00 01',
                                                 'region': 'alger'})
        self.assertEqual(p.phone, '0661000001')
        self.assertTrue(p.phone_valid)
        self.assertEqual(p.name, 'Ali Ben')
        self.assertEqual(p.region, 'Alger')
        self.assertEqual(p.status_id, self.env.ref('easycrm.status_nouveau'))

    def test_call_flow(self):
        p = self.env['easycrm.prospect'].create({'name': 'A', 'phone': '0661000002', 'user_id': self.com1.id,
                                                 'category_id': self.cat_a.id})
        rappel = self.env.ref('easycrm.status_rappel')
        with self.assertRaises(ValidationError):
            self.env['easycrm.call'].create({'prospect_id': p.id, 'status_id': rappel.id})
        cb = fields.Datetime.now() + timedelta(days=1)
        self.env['easycrm.call'].with_user(self.com1).create({
            'prospect_id': p.id, 'status_id': rappel.id, 'callback_date': cb, 'note': 'rappeler demain'})
        self.assertEqual(p.status_id, rappel)
        self.assertEqual(p.callback_date, cb)
        self.assertEqual(p.call_count, 1)
        self.assertTrue(p.activity_ids)
        inj = self.env.ref('easycrm.status_injoignable')
        self.env['easycrm.call'].create({'prospect_id': p.id, 'status_id': inj.id})
        self.assertTrue(p.callback_date)
        self.assertFalse(p.activity_ids)
        self.assertEqual(p.call_count, 2)

    def test_wizard_and_next(self):
        p1 = self.env['easycrm.prospect'].create({'name': 'N1', 'phone': '0661000003', 'user_id': self.com1.id})
        p2 = self.env['easycrm.prospect'].create({'name': 'N2', 'phone': '0661000004', 'user_id': self.com1.id})
        P = self.env['easycrm.prospect'].with_user(self.com1)
        nxt = P.action_next()
        self.assertEqual(nxt['res_id'], p1.id)
        w = self.env['easycrm.call.wizard'].with_user(self.com1).create({
            'prospect_id': p1.id, 'status_id': self.env.ref('easycrm.status_interesse').id})
        res = w.action_save_next()
        self.assertEqual(res['res_id'], p2.id)
        self.assertTrue(p1.is_client)

    def test_security(self):
        p_own = self.env['easycrm.prospect'].create({'name': 'O', 'phone': '0661000005', 'user_id': self.com1.id})
        p_other = self.env['easycrm.prospect'].create({'name': 'X', 'phone': '0661000006', 'user_id': self.com2.id})
        p_pool = self.env['easycrm.prospect'].create({'name': 'Pool', 'phone': '0661000007',
                                                      'category_id': self.cat_a.id})
        p_pool_b = self.env['easycrm.prospect'].create({'name': 'PoolB', 'phone': '0661000008',
                                                        'category_id': self.cat_b.id})
        P = self.env['easycrm.prospect']
        seen = P.with_user(self.com1).search([('id', 'in', (p_own + p_other + p_pool + p_pool_b).ids)])
        self.assertEqual(seen, p_own + p_pool)
        self.assertEqual(len(P.with_user(self.sup).search([('id', 'in', (p_own + p_other).ids)])), 2)
        self.assertEqual(len(P.with_user(self.ro).search([('id', 'in', (p_own + p_other).ids)])), 2)
        with self.assertRaises(AccessError):
            p_own.with_user(self.ro).write({'name': 'hack'})
        with self.assertRaises(AccessError):
            self.env['easycrm.status'].with_user(self.com1).create({'name': 'x'})
        # calls: commercial ne voit que les siens
        c1 = self.env['easycrm.call'].with_user(self.com1).create(
            {'prospect_id': p_own.id, 'status_id': self.env.ref('easycrm.status_interesse').id})
        c2 = self.env['easycrm.call'].create(
            {'prospect_id': p_other.id, 'user_id': self.com2.id,
             'status_id': self.env.ref('easycrm.status_interesse').id})
        self.assertEqual(self.env['easycrm.call'].with_user(self.com1).search([('id', 'in', (c1 + c2).ids)]), c1)

    def test_custom_fields(self):
        F = self.env['easycrm.field']
        f_txt = F.create({'name': 'Employeur', 'ftype': 'char', 'show_in_list': True})
        f_sel = F.create({'name': 'Type emploi', 'ftype': 'selection', 'options': 'CDI\nCDD\nRetraité'})
        f_tag = F.create({'name': 'Produits vus', 'ftype': 'tags', 'options': 'Moto\nFrigo'})
        f_dt = F.create({'name': 'Date naissance', 'ftype': 'date'})
        f_n = F.create({'name': 'Salaire', 'ftype': 'float'})
        self.assertTrue(f_txt.technical_name.startswith('x_ec_'))
        p = self.env['easycrm.prospect'].create({
            'name': 'Z', 'phone': '0661000009', f_txt.technical_name: 'CSM',
            f_sel.technical_name: 'CDD', f_n.technical_name: 55000.0})
        self.assertEqual(p[f_txt.technical_name], 'CSM')
        self.assertEqual(p[f_sel.technical_name], 'CDD')
        tag = self.env['easycrm.tag'].search([('field_id', '=', f_tag.id), ('name', '=', 'Frigo')])
        p[f_tag.technical_name] = tag
        self.assertEqual(len(p[f_tag.technical_name]), 1)
        # les vues contiennent les champs
        form = self.env['easycrm.prospect'].get_view(view_type='form')
        self.assertIn(f_txt.technical_name, form['arch'])
        lst = self.env['easycrm.prospect'].get_view(view_type='list')
        self.assertIn(f_dt.technical_name, lst['arch'])
        # suppression propre
        dt_name = f_dt.technical_name
        f_dt.unlink()
        form = self.env['easycrm.prospect'].get_view(view_type='form')
        self.assertNotIn(dt_name, form['arch'])
        self.assertFalse(self.env['ir.model.fields'].search([('name', '=', dt_name)]))

    def test_import_csv(self):
        f = self.env['easycrm.field'].create({'name': 'Type emploi', 'ftype': 'selection', 'options': 'CDI\nCDD'})
        P = self.env['easycrm.prospect']
        P.create({'name': 'Existant', 'phone': '0555000001'})
        csv_txt = ("Nom & prénom;Téléphone;Wilaya;Catégorie produit;Sexe;Type emploi;Commentaire\n"
                   "Karim Amrani;+213 555 00 00 02;16;motos;Homme;cdi;ok\n"
                   "Existant;0555000001;Oran;Motos;F;CDD;doublon\n"
                   "Nadia B;0661 00 00 03;Oran;Nouvelle cat;Femme;Freelance;\n"
                   "Sans numero;;Alger;Motos;;;\n"
                   "Karim bis;0555000002;Alger;Motos;;;doublon dans le fichier\n").encode()
        w = self.env['easycrm.import.wizard'].create({
            'file': base64.b64encode(csv_txt), 'filename': 'test.csv', 'user_id': self.com1.id})
        w.action_import()
        res = P.search([('import_batch', '=', w.batch)])
        self.assertEqual(len(res), 2, w.result)
        k = res.filtered(lambda r: r.name == 'Karim Amrani')
        self.assertEqual(k.phone, '0555000002')
        self.assertEqual(k.region, 'Alger')
        self.assertEqual(k.category_id.name, 'Motos')
        self.assertEqual(k.gender, 'm')
        self.assertEqual(k[f.technical_name], 'CDI')
        n = res.filtered(lambda r: r.name == 'Nadia B')
        self.assertEqual(n.category_id.name, 'Nouvelle cat')
        self.assertEqual(n[f.technical_name], 'Freelance')
        self.assertIn('Freelance', f.option_list())

    def test_import_xlsx(self):
        import io
        import openpyxl
        wb = openpyxl.Workbook()
        ws = wb.active
        ws.append(['Nom', 'Prénom', 'Mobile'])
        ws.append(['Amrani', 'Karim', 555000010])
        ws.append(['Benali', 'Sara', '0661000011'])
        buf = io.BytesIO()
        wb.save(buf)
        w = self.env['easycrm.import.wizard'].create({
            'file': base64.b64encode(buf.getvalue()), 'filename': 'x.xlsx'})
        w.action_import()
        res = self.env['easycrm.prospect'].search([('import_batch', '=', w.batch)])
        self.assertEqual(sorted(res.mapped('phone')), ['0555000010', '0661000011'])

    def test_distribute(self):
        ps = self.env['easycrm.prospect']
        for i in range(6):
            ps |= self.env['easycrm.prospect'].create({'name': 'D%d' % i, 'phone': '066100002%d' % i,
                                                       'category_id': self.cat_a.id})
        w = self.env['easycrm.distribute.wizard'].create({
            'category_ids': [(6, 0, self.cat_a.ids)], 'user_ids': [(6, 0, (self.com1 + self.com2).ids)]})
        self.assertEqual(w.count, 6)
        w.action_distribute()
        self.assertEqual(len(ps.filtered(lambda p: p.user_id == self.com1)), 3)
        self.assertEqual(len(ps.filtered(lambda p: p.user_id == self.com2)), 3)

    def test_whatsapp(self):
        p = self.env['easycrm.prospect'].create({'name': 'W', 'phone': '0661000030'})
        self.assertIn('wa.me/213661000030', p.action_whatsapp()['url'])
