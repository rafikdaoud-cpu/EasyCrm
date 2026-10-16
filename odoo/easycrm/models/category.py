from odoo import fields, models


class EasyCrmCategory(models.Model):
    _name = 'easycrm.category'
    _description = "Prospect category"
    _order = 'sequence, name'

    name = fields.Char("Category", required=True, translate=False)
    sequence = fields.Integer(default=10)
    color = fields.Integer("Color")
    active = fields.Boolean(default=True)
    team_ids = fields.Many2many('easycrm.team', 'easycrm_team_category_rel', 'category_id', 'team_id',
                                string="Teams")
    prospect_count = fields.Integer("Prospects", compute='_compute_prospect_count')

    _sql_constraints = [('name_uniq', 'unique(name)', "This category already exists.")]

    def _compute_prospect_count(self):
        data = self.env['easycrm.prospect'].read_group([('category_id', 'in', self.ids)], ['category_id'],
                                                       ['category_id'])
        m = {d['category_id'][0]: d['category_id_count'] for d in data}
        for r in self:
            r.prospect_count = m.get(r.id, 0)
