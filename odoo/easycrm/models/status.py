from odoo import _, api, fields, models
from odoo.exceptions import UserError


class EasyCrmStatus(models.Model):
    _name = 'easycrm.status'
    _description = "Call response / status"
    _order = 'sequence, id'

    name = fields.Char("Response", required=True, translate=True)
    code = fields.Char("Internal code", help="Technical identifier (optional).")
    sequence = fields.Integer(default=10)
    color = fields.Integer("Color", default=0)
    active = fields.Boolean(default=True)
    builtin = fields.Boolean("Built-in status", readonly=True)
    in_queue = fields.Boolean("Stays in the call queue", default=False,
                              help="Prospects with this status are offered by \"Call next\".")
    is_callback = fields.Boolean("Requires a callback date", default=False)
    require_note = fields.Boolean("Note required", default=False)
    retry_hours = fields.Float("Call back automatically after (hours)", default=0.0,
                               help="0 = no automatic rescheduling.")
    is_client = fields.Boolean("Counts as customer", default=False)
    is_lost = fields.Boolean("Counts as lost / refusal", default=False)
    prospect_count = fields.Integer("Prospects", compute='_compute_prospect_count')

    def _compute_prospect_count(self):
        data = self.env['easycrm.prospect'].read_group([('status_id', 'in', self.ids)], ['status_id'],
                                                       ['status_id'])
        m = {d['status_id'][0]: d['status_id_count'] for d in data}
        for r in self:
            r.prospect_count = m.get(r.id, 0)

    @api.ondelete(at_uninstall=False)
    def _unlink_except_builtin(self):
        for r in self:
            if r.builtin:
                raise UserError(_("Built-in statuses cannot be deleted: archive them instead."))
