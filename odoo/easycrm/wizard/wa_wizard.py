# -*- coding: utf-8 -*-
from odoo import _, fields, models
from odoo.exceptions import UserError

from ..models import telephony as tel
from ..models.settings import get_wa
from ..models.utils import get_cfg, wa_number


class EasyCrmWaWizard(models.TransientModel):
    _name = 'easycrm.wa.wizard'
    _description = "WhatsApp message"

    prospect_id = fields.Many2one('easycrm.prospect', required=True, ondelete='cascade')
    prospect_name = fields.Char(related='prospect_id.name')
    prospect_phone = fields.Char(related='prospect_id.phone')
    text = fields.Text("Message", required=True)

    def action_send(self):
        self.ensure_one()
        prov, cfg = get_wa(self.env)
        if prov == 'link':
            raise UserError(_("No WhatsApp API is configured: set one up in EasyCRM settings."))
        number = wa_number(self.prospect_id.phone, get_cfg(self.env))
        ok, msg = tel.wa_send(prov, cfg, number, self.text, self.prospect_id.name)
        if not ok:
            raise UserError(msg)
        self.prospect_id.message_post(body=_("WhatsApp sent: %s") % self.text, subtype_xmlid='mail.mt_note')
        return {'type': 'ir.actions.client', 'tag': 'display_notification', 'params': {
            'title': _("WhatsApp"), 'message': _("Message sent."), 'type': 'success', 'sticky': False,
            'next': {'type': 'ir.actions.act_window_close'}}}
