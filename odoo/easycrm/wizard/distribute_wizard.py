from odoo import _, api, fields, models
from odoo.exceptions import UserError


class EasyCrmDistributeWizard(models.TransientModel):
    _name = 'easycrm.distribute.wizard'
    _description = "Distribute prospects among salespeople"

    prospect_ids = fields.Many2many('easycrm.prospect', string="Selected prospects")
    category_ids = fields.Many2many('easycrm.category', string="Categories")
    user_ids = fields.Many2many('res.users', string="Salespeople", required=True)
    only_unassigned = fields.Boolean("Only unassigned prospects", default=True)
    only_queue = fields.Boolean("Only those still to be called", default=True)
    count = fields.Integer("Prospects concerned", compute='_compute_count')

    @api.onchange('category_ids')
    def _onchange_category_ids(self):
        if self.category_ids and not self.user_ids:
            self.user_ids = self.category_ids.mapped('team_ids.member_ids')

    def _targets(self):
        self.ensure_one()
        if self.prospect_ids:
            dom = [('id', 'in', self.prospect_ids.ids)]
        else:
            dom = []
            if self.category_ids:
                dom.append(('category_id', 'in', self.category_ids.ids))
        if self.only_unassigned:
            dom.append(('user_id', '=', False))
        if self.only_queue:
            dom.append(('status_id.in_queue', '=', True))
        return self.env['easycrm.prospect'].search(dom, order='id')

    @api.depends('prospect_ids', 'category_ids', 'only_unassigned', 'only_queue')
    def _compute_count(self):
        for r in self:
            r.count = len(r._targets()) if (r.prospect_ids or r.category_ids) else 0

    def action_distribute(self):
        self.ensure_one()
        users = self.user_ids
        if not users:
            raise UserError(_("Choose at least one salesperson."))
        targets = self._targets()
        if not targets:
            raise UserError(_("No prospect to distribute with these criteria."))
        buckets = {u.id: self.env['easycrm.prospect'] for u in users}
        ordered = list(users)
        for i, p in enumerate(targets):
            u = ordered[i % len(ordered)]
            buckets[u.id] |= p
        for uid, recs in buckets.items():
            if recs:
                recs.with_context(mail_notrack=True, tracking_disable=True).write({'user_id': uid})
        return {
            'type': 'ir.actions.client', 'tag': 'display_notification', 'params': {
                'title': _("Distribution completed"), 'type': 'success', 'sticky': False,
                'message': _("%d prospect(s) distributed among %d salesperson(s).") % (len(targets), len(users)),
                'next': {'type': 'ir.actions.act_window_close'}}}
