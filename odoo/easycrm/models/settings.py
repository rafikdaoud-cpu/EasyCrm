import secrets

from odoo import _, api, fields, models
from . import telephony as tel
from .utils import COUNTRIES, PRESETS


class ResConfigSettings(models.TransientModel):
    _inherit = 'res.config.settings'

    easycrm_company = fields.Char("Company name (EasyCRM)", config_parameter='easycrm.company')
    easycrm_country = fields.Selection(COUNTRIES, string="Number country", default='DZ',
                                       config_parameter='easycrm.country')
    easycrm_cc = fields.Char("International dialing code", config_parameter='easycrm.cc',
                             help="Without \"+\", e.g. 213 for Algeria.")
    easycrm_trunk = fields.Char("National prefix", config_parameter='easycrm.trunk',
                                help="Digit preceding national numbers (0 in Algeria, Morocco, France).")

    @api.onchange('easycrm_country')
    def _onchange_easycrm_country(self):
        p = PRESETS.get(self.easycrm_country or 'DZ', PRESETS['OTHER'])
        self.easycrm_cc = p['cc']
        self.easycrm_trunk = p['trunk']

    # ------------------------------------------------------------------ telephony (PBX / SIP)
    easycrm_sip_provider = fields.Selection(tel.SIP_CHOICES, string="PBX / SIP provider", default='none',
                                            config_parameter='easycrm.sip.provider')
    easycrm_sip_host = fields.Char("PBX host", config_parameter='easycrm.sip.host')
    easycrm_sip_port = fields.Char("Port", config_parameter='easycrm.sip.port')
    easycrm_sip_url = fields.Char("API URL", config_parameter='easycrm.sip.url')
    easycrm_sip_user = fields.Char("User / client ID", config_parameter='easycrm.sip.user')
    easycrm_sip_secret = fields.Char("Password / secret", config_parameter='easycrm.sip.secret')
    easycrm_sip_channel = fields.Char("Agent channel", config_parameter='easycrm.sip.channel',
                                      help="Use {ext} for the user's extension, e.g. PJSIP/{ext}.")
    easycrm_sip_context = fields.Char("Dialplan context", config_parameter='easycrm.sip.context')
    easycrm_sip_dial_format = fields.Selection(
        [('national', "National (0555…)"), ('e164', "International with + (+213555…)"),
         ('intl', "International without + (213555…)"), ('intl00', "International with 00 (00213555…)"),
         ('raw', "As stored")], string="Number format sent to the PBX", default='national',
        config_parameter='easycrm.sip.dial_format')
    easycrm_sip_dial_prefix = fields.Char("Outgoing prefix", config_parameter='easycrm.sip.dial_prefix',
                                          help="Digit(s) your PBX requires to get an outside line (e.g. 0 or 9).")
    easycrm_sip_uri_scheme = fields.Selection([('tel', "tel:"), ('sip', "sip:"), ('callto', "callto:")],
                                              string="Link type", default='tel',
                                              config_parameter='easycrm.sip.uri_scheme')
    easycrm_sip_verify_ssl = fields.Boolean("Verify the TLS certificate", default=True,
                                            config_parameter='easycrm.sip.verify_ssl')
    easycrm_sip_http_method = fields.Selection([('POST', "POST"), ('GET', "GET"), ('PUT', "PUT")],
                                               string="HTTP method", default='POST',
                                               config_parameter='easycrm.sip.http_method')
    easycrm_sip_http_headers = fields.Char("HTTP headers (JSON)", config_parameter='easycrm.sip.http_headers')
    easycrm_sip_http_body = fields.Char("HTTP body", config_parameter='easycrm.sip.http_body',
                                        help="Placeholders: {ext} (agent extension), {number} (number to dial).")

    # ------------------------------------------------------------------ WhatsApp
    easycrm_wa_provider = fields.Selection(tel.WA_CHOICES, string="WhatsApp provider", default='link',
                                           config_parameter='easycrm.wa.provider')
    easycrm_wa_phone_id = fields.Char("Phone number ID", config_parameter='easycrm.wa.phone_id')
    easycrm_wa_token = fields.Char("Access token / API key", config_parameter='easycrm.wa.token')
    easycrm_wa_template = fields.Char("Template name (optional)", config_parameter='easycrm.wa.template',
                                      help="Required by WhatsApp to contact someone first. Leave empty to send free "
                                           "text (only allowed within 24 h of the customer's last message).")
    easycrm_wa_language = fields.Char("Template language", config_parameter='easycrm.wa.language')
    easycrm_wa_api_version = fields.Char("API version", config_parameter='easycrm.wa.api_version')
    easycrm_wa_account_sid = fields.Char("Account SID", config_parameter='easycrm.wa.account_sid')
    easycrm_wa_from_number = fields.Char("Sender number", config_parameter='easycrm.wa.from_number')
    easycrm_wa_url = fields.Char("API URL", config_parameter='easycrm.wa.url')
    easycrm_wa_http_method = fields.Selection([('POST', "POST"), ('GET', "GET"), ('PUT', "PUT")],
                                              string="HTTP method", default='POST',
                                              config_parameter='easycrm.wa.http_method')
    easycrm_wa_http_headers = fields.Char("HTTP headers (JSON)", config_parameter='easycrm.wa.http_headers')
    easycrm_wa_http_body = fields.Char("HTTP body", config_parameter='easycrm.wa.http_body',
                                       help="Placeholders: {number} (international digits), {text}, {name}.")

    # ------------------------------------------------------------------ webhooks
    easycrm_webhook_secret = fields.Char("Webhook secret", config_parameter='easycrm.webhook_secret')
    easycrm_pbx_webhook_url = fields.Char("PBX webhook URL", compute='_compute_easycrm_webhook_urls')
    easycrm_wa_webhook_url = fields.Char("WhatsApp webhook URL", compute='_compute_easycrm_webhook_urls')
    easycrm_pbx_status_answered = fields.Many2one('easycrm.status', "Response when the call is answered",
                                                  config_parameter='easycrm.pbx.status_answered')
    easycrm_pbx_status_missed = fields.Many2one('easycrm.status', "Response when the call is not answered",
                                                config_parameter='easycrm.pbx.status_missed')

    @api.depends('easycrm_webhook_secret')
    def _compute_easycrm_webhook_urls(self):
        base = (self.env['ir.config_parameter'].sudo().get_param('web.base.url') or '').rstrip('/')
        for r in self:
            tok = r.easycrm_webhook_secret or '<secret>'
            r.easycrm_pbx_webhook_url = "%s/easycrm/pbx/webhook?token=%s" % (base, tok)
            r.easycrm_wa_webhook_url = "%s/easycrm/wa/webhook?token=%s" % (base, tok)

    @api.onchange('easycrm_sip_provider')
    def _onchange_easycrm_sip_provider(self):
        d = tel.SIP_PRESETS.get(self.easycrm_sip_provider or 'none', {}).get('defaults', {})
        for k, v in d.items():
            f = 'easycrm_sip_' + k
            if f in self._fields:
                self[f] = (v in ('1', 'true')) if self._fields[f].type == 'boolean' else v

    @api.onchange('easycrm_wa_provider')
    def _onchange_easycrm_wa_provider(self):
        d = tel.WA_PRESETS.get(self.easycrm_wa_provider or 'link', {}).get('defaults', {})
        for k, v in d.items():
            f = 'easycrm_wa_' + k
            if f in self._fields:
                self[f] = v

    def action_easycrm_new_secret(self):
        self.easycrm_webhook_secret = secrets.token_urlsafe(24)
        return self.execute() or self._easycrm_reopen()

    def _easycrm_reopen(self):
        return {'type': 'ir.actions.act_window', 'res_model': 'res.config.settings', 'view_mode': 'form',
                'target': 'inline', 'context': {'module': 'easycrm'}}

    def _easycrm_notify(self, ok, msg):
        return {'type': 'ir.actions.client', 'tag': 'display_notification', 'params': {
            'title': _("Connection test"), 'message': msg, 'type': 'success' if ok else 'danger', 'sticky': not ok}}

    def action_easycrm_test_sip(self):
        self.execute()
        ok, msg = tel.sip_test(*get_sip(self.env))
        return self._easycrm_notify(ok, msg)

    def action_easycrm_test_wa(self):
        self.execute()
        ok, msg = tel.wa_test(*get_wa(self.env))
        return self._easycrm_notify(ok, msg)


def _collect(env, prefix, keys):
    icp = env['ir.config_parameter'].sudo()
    out = {k: (icp.get_param('easycrm.%s.%s' % (prefix, k)) or '') for k in keys}
    if 'verify_ssl' in out:
        out['verify_ssl'] = '1' if out['verify_ssl'] in ('True', 'true', '1') else '0'
    return out


SIP_KEYS = ['host', 'port', 'url', 'user', 'secret', 'channel', 'context', 'dial_format', 'dial_prefix', 'uri_scheme', 'verify_ssl', 'http_method', 'http_headers', 'http_body']
WA_KEYS = ['phone_id', 'token', 'template', 'language', 'api_version', 'account_sid', 'from_number', 'url', 'http_method', 'http_headers', 'http_body']


def get_sip(env):
    """(provider, cfg) for the PBX connector."""
    cfg = _collect(env, 'sip', SIP_KEYS)
    prov = env['ir.config_parameter'].sudo().get_param('easycrm.sip.provider') or 'none'
    return prov, cfg


def get_wa(env):
    """(provider, cfg) for the WhatsApp connector."""
    cfg = _collect(env, 'wa', WA_KEYS)
    prov = env['ir.config_parameter'].sudo().get_param('easycrm.wa.provider') or 'link'
    return prov, cfg
