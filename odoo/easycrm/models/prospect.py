# -*- coding: utf-8 -*-
from datetime import timedelta
from urllib.parse import quote

from odoo import _, api, fields, models
from odoo.exceptions import UserError

from . import telephony as tel
from .settings import get_sip, get_wa
from .utils import get_cfg, normalize_phone, normalize_region, wa_number


class EasyCrmProspect(models.Model):
    _name = 'easycrm.prospect'
    _description = "Prospect"
    _inherit = ['mail.thread', 'mail.activity.mixin']
    _order = 'id desc'

    name = fields.Char("Full name", required=True, tracking=True)
    gender = fields.Selection([('m', "Male"), ('f', "Female")], "Gender")
    phone = fields.Char("Phone", index=True, tracking=True)
    phone_valid = fields.Boolean("Valid number", compute='_compute_phone_valid', store=True)
    phone2 = fields.Char("Phone 2")
    email = fields.Char("Email")
    region = fields.Char("Region / Wilaya")
    category_id = fields.Many2one('easycrm.category', "Category", tracking=True, index=True)
    status_id = fields.Many2one('easycrm.status', "Status", required=True, tracking=True, index=True,
                                default=lambda self: self.env.ref('easycrm.status_nouveau',
                                                                   raise_if_not_found=False),
                                group_expand='_group_expand_status', ondelete='restrict')
    user_id = fields.Many2one('res.users', "Assigned to", tracking=True, index=True,
                              domain=lambda self: [('share', '=', False)])
    callback_date = fields.Datetime("Scheduled callback", tracking=True, index=True)
    last_call_date = fields.Datetime("Last call", compute='_compute_calls', store=True)
    call_count = fields.Integer("Number of calls", compute='_compute_calls', store=True)
    call_ids = fields.One2many('easycrm.call', 'prospect_id', "Calls")
    note = fields.Text("Remark")
    import_batch = fields.Char("Import batch", index=True)
    active = fields.Boolean(default=True)
    status_color = fields.Integer(related='status_id.color')
    is_client = fields.Boolean(related='status_id.is_client', store=True)
    is_lost = fields.Boolean(related='status_id.is_lost', store=True)
    duplicate_count = fields.Integer("Duplicates", compute='_compute_duplicate_count')

    # ------------------------------------------------------------------ computes
    @api.depends('phone')
    def _compute_phone_valid(self):
        cfg = get_cfg(self.env)
        for r in self:
            r.phone_valid = normalize_phone(r.phone, cfg)[1] if r.phone else False

    @api.depends('call_ids.date')
    def _compute_calls(self):
        for r in self:
            dates = r.call_ids.mapped('date')
            r.call_count = len(dates)
            r.last_call_date = max(dates) if dates else False

    def _compute_duplicate_count(self):
        for r in self:
            r.duplicate_count = self.sudo().search_count(
                [('phone', '=', r.phone), ('id', '!=', r.id)]) if r.phone else 0

    @api.model
    def _group_expand_status(self, statuses, domain):
        return statuses.search([])

    # ------------------------------------------------------------------ normalisation
    def _clean_vals(self, vals):
        cfg = get_cfg(self.env)
        for k in ('phone', 'phone2'):
            if vals.get(k):
                vals[k] = normalize_phone(vals[k], cfg)[0]
        if vals.get('region'):
            vals['region'] = normalize_region(vals['region'], cfg)
        if vals.get('name'):
            vals['name'] = ' '.join(vals['name'].split())
        return vals

    @api.model_create_multi
    def create(self, vals_list):
        return super().create([self._clean_vals(dict(v)) for v in vals_list])

    def write(self, vals):
        return super().write(self._clean_vals(dict(vals)))

    # ------------------------------------------------------------------ telephony / WhatsApp
    pbx_mode = fields.Selection([('none', "Disabled"), ('uri', "Link"), ('api', "PBX")],
                                compute='_compute_integration_modes')
    wa_mode = fields.Selection([('link', "Link"), ('api', "API")], compute='_compute_integration_modes')

    @api.depends_context('uid')
    def _compute_integration_modes(self):
        icp = self.env['ir.config_parameter'].sudo()
        sip = icp.get_param('easycrm.sip.provider') or 'none'
        wa = icp.get_param('easycrm.wa.provider') or 'link'
        for r in self:
            r.pbx_mode = sip if sip in ('none', 'uri') else 'api'
            r.wa_mode = 'link' if wa == 'link' else 'api'

    def _dial_number(self, cfg):
        cc = get_cfg(self.env)
        return tel.dial_number(self.phone, cc['cc'], cc['trunk'], cfg.get('dial_format') or 'national',
                               cfg.get('dial_prefix') or '')

    def action_pbx_call(self):
        """Click-to-call through the configured PBX (or softphone link)."""
        self.ensure_one()
        if not self.phone:
            raise UserError(_("This prospect has no phone number."))
        prov, cfg = get_sip(self.env)
        if prov == 'none':
            raise UserError(_("No PBX is configured: set one up in EasyCRM settings."))
        number = self._dial_number(cfg)
        if prov == 'uri':
            return {'type': 'ir.actions.act_url', 'target': 'self',
                    'url': "%s:%s" % (cfg.get('uri_scheme') or 'tel', number)}
        ext = (self.env.user.easycrm_extension or '').strip()
        if not ext:
            raise UserError(_("Define your PBX extension in your user preferences first."))
        ok, msg = tel.sip_dial(prov, cfg, ext, number)
        if not ok:
            raise UserError(msg)
        self.message_post(body=_("Call started through the PBX (extension %(ext)s → %(number)s).",
                                 ext=ext, number=number), subtype_xmlid='mail.mt_note')
        return {'type': 'ir.actions.client', 'tag': 'display_notification', 'params': {
            'title': _("Calling…"), 'message': _("Your phone (extension %s) is ringing.") % ext,
            'type': 'success', 'sticky': False}}

    def action_wa_send(self):
        self.ensure_one()
        if not self.phone:
            raise UserError(_("This prospect has no phone number."))
        company = get_cfg(self.env)['company']
        txt = _("Hello %s,") % self.name + " " + (_("%s is contacting you.") % company if company else "")
        return {
            'type': 'ir.actions.act_window', 'name': _("Send a WhatsApp message"),
            'res_model': 'easycrm.wa.wizard', 'view_mode': 'form', 'target': 'new',
            'context': {'default_prospect_id': self.id, 'default_text': txt.strip()},
        }

    # ------------------------------------------------------------------ actions
    def action_log_call(self):
        self.ensure_one()
        return {
            'type': 'ir.actions.act_window', 'name': _("Log the call"),
            'res_model': 'easycrm.call.wizard', 'view_mode': 'form', 'target': 'new',
            'context': {'default_prospect_id': self.id},
        }

    def action_whatsapp(self):
        self.ensure_one()
        if not self.phone:
            raise UserError(_("This prospect has no phone number."))
        num = wa_number(self.phone, get_cfg(self.env))
        company = get_cfg(self.env)['company']
        txt = _("Hello %s,") % self.name + " " + (_("%s is contacting you.") % company if company else "")
        return {'type': 'ir.actions.act_url', 'target': 'new',
                'url': "https://wa.me/%s?text=%s" % (num, quote(txt.strip()))}

    def action_view_calls(self):
        self.ensure_one()
        return {
            'type': 'ir.actions.act_window', 'name': _("Calls"), 'res_model': 'easycrm.call',
            'view_mode': 'list,form', 'domain': [('prospect_id', '=', self.id)],
            'context': {'default_prospect_id': self.id},
        }

    def action_view_duplicates(self):
        self.ensure_one()
        return {
            'type': 'ir.actions.act_window', 'name': _("Duplicates"), 'res_model': 'easycrm.prospect',
            'view_mode': 'list,form', 'domain': [('phone', '=', self.phone)],
        }

    @api.model
    def action_next(self):
        """Open the next prospect to call for the current user."""
        base = [('user_id', '=', self.env.uid), ('status_id.in_queue', '=', True)]
        now = fields.Datetime.now()
        rec = self.search(base + [('callback_date', '!=', False), ('callback_date', '<=', now)],
                          order='callback_date, id', limit=1)
        if not rec:
            rec = self.search(base + [('callback_date', '=', False)], order='id', limit=1)
        if not rec:
            return {'type': 'ir.actions.client', 'tag': 'display_notification', 'params': {
                'title': _("List completed"), 'type': 'success', 'sticky': False,
                'message': _("No prospect to call at the moment.")}}
        return {'type': 'ir.actions.act_window', 'res_model': 'easycrm.prospect', 'res_id': rec.id,
                'view_mode': 'form', 'target': 'current', 'name': rec.name}

    def action_assign_wizard(self):
        return {
            'type': 'ir.actions.act_window', 'name': _("Assign / distribute"), 'target': 'new',
            'res_model': 'easycrm.distribute.wizard', 'view_mode': 'form',
            'context': {'default_prospect_ids': self.env.context.get('active_ids') or self.ids},
        }
