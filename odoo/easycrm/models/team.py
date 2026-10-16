from odoo import fields, models


class EasyCrmTeam(models.Model):
    _name = 'easycrm.team'
    _description = "Sales team"
    _order = 'name'

    name = fields.Char("Team", required=True)
    active = fields.Boolean(default=True)
    category_ids = fields.Many2many('easycrm.category', 'easycrm_team_category_rel', 'team_id', 'category_id',
                                    string="Managed categories")
    member_ids = fields.Many2many('res.users', 'easycrm_team_user_rel', 'team_id', 'user_id',
                                  string="Salespeople")

    _sql_constraints = [('name_uniq', 'unique(name)', "This team already exists.")]
