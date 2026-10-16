# -*- coding: utf-8 -*-
from datetime import timedelta

from odoo import _, api, fields, models
from odoo.exceptions import ValidationError


class EasyCrmCall(models.Model):
    _name = 'easycrm.call'
    _description = "Call"
    _order = 'date desc, id desc'

    prospect_id = fields.Many2one('easycrm.prospect', "Prospect", required=True, ondelete='cascade', index=True)
    user_id = fields.Many2one('res.users', "Salesperson", default=lambda self: self.env.user, required=True,
                              index=True)
    date = fields.Datetime("Call date", default=fields.Datetime.now, required=True, index=True)
    status_id = fields.Many2one('easycrm.status', "Response", required=True, ondelete='restrict', index=True)
    note = fields.Text("Note")
    callback_date = fields.Datetime("Scheduled callback")
    duration = fields.Integer("Duration (min)")
    attachment_ids = fields.Many2many('ir.attachment', 'easycrm_call_attachment_rel', 'call_id', 'attachment_id',
                                      string="Recording / attachments")
    category_id = fields.Many2one(related='prospect_id.category_id', store=True, string="Category")
    prospect_phone = fields.Char(related='prospect_id.phone', string="Phone")
    hour = fields.Integer("Hour", compute='_compute_hour', store=True, aggregator=False)
    day = fields.Date("Day", compute='_compute_hour', store=True)
    is_client = fields.Boolean(related='status_id.is_client', store=True)

    @api.depends('date')
    def _compute_hour(self):
        for r in self:
            if r.date:
                local = fields.Datetime.context_timestamp(r, r.date)
                r.hour, r.day = local.hour, local.date()
            else:
                r.hour, r.day = 0, False

    @api.constrains('status_id', 'note', 'callback_date')
    def _check_rules(self):
        for r in self:
            if r.status_id.is_callback and not r.callback_date:
                raise ValidationError(_("The response \"%s\" requires a callback date.") % r.status_id.name)
            if r.status_id.require_note and not (r.note or '').strip():
                raise ValidationError(_("The response \"%s\" requires a note.") % r.status_id.name)

    @api.model_create_multi
    def create(self, vals_list):
        calls = super().create(vals_list)
        for c in calls.sorted('date'):
            c._apply_to_prospect()
        return calls

    def _apply_to_prospect(self):
        self.ensure_one()
        p = self.prospect_id.sudo()
        cb = self.callback_date
        if not cb and self.status_id.retry_hours:
            cb = fields.Datetime.now() + timedelta(hours=self.status_id.retry_hours)
        p.with_context(mail_notrack=True).write({
            'status_id': self.status_id.id,
            'callback_date': cb or False,
        })
        p.activity_unlink(['mail.mail_activity_data_call'])
        if cb and self.status_id.is_callback:
            p.activity_schedule(
                'mail.mail_activity_data_call',
                date_deadline=fields.Datetime.context_timestamp(self, cb).date(),
                user_id=(p.user_id or self.user_id).id,
                summary=_("Call back %s") % p.name,
                note=(self.note or ''))
        p.message_post(body=_("Call: <b>%s</b>%s") % (
            self.status_id.name, ("<br/>%s" % self.note) if self.note else ''),
            subtype_xmlid='mail.mt_note')
