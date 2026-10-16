from odoo import api, fields, models


class EasyCrmCallWizard(models.TransientModel):
    _name = 'easycrm.call.wizard'
    _description = "Log a call"

    prospect_id = fields.Many2one('easycrm.prospect', required=True, readonly=True)
    prospect_name = fields.Char(related='prospect_id.name')
    prospect_phone = fields.Char(related='prospect_id.phone')
    status_id = fields.Many2one('easycrm.status', "Response", required=True,
                                domain="[('code', '!=', 'nouveau')]")
    is_callback = fields.Boolean(related='status_id.is_callback')
    require_note = fields.Boolean(related='status_id.require_note')
    note = fields.Text("Note")
    callback_date = fields.Datetime("Call back on")
    duration = fields.Integer("Duration (min)")
    attachment_ids = fields.Many2many('ir.attachment', string="Recording")

    def _create_call(self):
        self.ensure_one()
        return self.env['easycrm.call'].create({
            'prospect_id': self.prospect_id.id,
            'status_id': self.status_id.id,
            'note': self.note,
            'callback_date': self.callback_date if self.status_id.is_callback else False,
            'duration': self.duration,
            'attachment_ids': [(6, 0, self.attachment_ids.ids)],
        })

    def action_save(self):
        self._create_call()
        return {'type': 'ir.actions.act_window_close'}

    def action_save_next(self):
        self._create_call()
        return self.env['easycrm.prospect'].action_next()
