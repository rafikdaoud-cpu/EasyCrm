# -*- coding: utf-8 -*-
"""Webhooks: PBX call-end notifications and incoming WhatsApp messages."""
import hmac
import json
import logging

from markupsafe import Markup, escape

from odoo import fields, http
from odoo.http import request

from ..models import telephony as tel
from ..models.utils import get_cfg, wa_number

_logger = logging.getLogger(__name__)


def _authorized():
    secret = request.env['ir.config_parameter'].sudo().get_param('easycrm.webhook_secret') or ''
    given = request.params.get('token') or request.httprequest.headers.get('X-Webhook-Token') or ''
    return bool(secret) and hmac.compare_digest(secret, given)


def _payload():
    """JSON body, form fields or query string, whichever the sender used."""
    raw = request.httprequest.get_data(as_text=True) or ''
    data = {}
    if raw.strip().startswith(('{', '[')):
        try:
            data = json.loads(raw)
        except ValueError:
            data = {}
    if not data:
        data = dict(request.httprequest.form) or dict(request.httprequest.args)
    return data


def find_prospect(env, number_digits):
    """Match a prospect by phone (national format stored; compare on the last 8 digits)."""
    if len(number_digits) < 6:
        return env['easycrm.prospect']
    tail = number_digits[-8:]
    return env['easycrm.prospect'].sudo().with_context(active_test=False).search(
        ['|', ('phone', 'like', tail), ('phone2', 'like', tail)], limit=1, order='id desc')


class EasyCrmHooks(http.Controller):

    @http.route('/easycrm/pbx/webhook', type='http', auth='public', methods=['GET', 'POST'], csrf=False)
    def pbx_webhook(self, **kw):
        if not _authorized():
            return request.make_json_response({'ok': False, 'error': 'forbidden'}, status=403)
        env = request.env
        ev = tel.parse_pbx_event(_payload() or kw)
        prospect = find_prospect(env, ev['number'])
        if not prospect:
            return request.make_json_response({'ok': True, 'matched': False})
        icp = env['ir.config_parameter'].sudo()
        st_id = icp.get_param('easycrm.pbx.status_answered' if ev['answered'] else 'easycrm.pbx.status_missed')
        user = env['res.users'].sudo().search([('easycrm_extension', '=', ev['ext'])], limit=1) if ev['ext'] else env['res.users']
        lines = [escape("%s: %s" % ("Incoming" if ev['inbound'] else "Outgoing", ev['status']))]
        if ev['duration']:
            lines.append(escape("%d s" % ev['duration']))
        if ev['recording'].startswith(('http://', 'https://')):
            lines.append(Markup('<a href="%s">recording</a>') % ev['recording'])
        body = Markup("PBX call — ") + Markup(" · ").join(lines)
        prospect.sudo().message_post(body=body, subtype_xmlid='mail.mt_note')
        if st_id and str(st_id).isdigit() and user and not ev['inbound']:
            status = env['easycrm.status'].sudo().browse(int(st_id)).exists()
            if status and not status.is_callback and not status.require_note:
                env['easycrm.call'].sudo().create({
                    'prospect_id': prospect.id, 'user_id': user.id, 'status_id': status.id,
                    'date': fields.Datetime.now(), 'duration': max(1, round(ev['duration'] / 60)) if ev['duration'] else 0,
                    'note': "PBX: %s (%s s)" % (ev['status'], ev['duration'])})
        return request.make_json_response({'ok': True, 'matched': True, 'prospect': prospect.id})

    @http.route('/easycrm/wa/webhook', type='http', auth='public', methods=['GET', 'POST'], csrf=False)
    def wa_webhook(self, **kw):
        # Meta verification handshake: GET ?hub.mode=subscribe&hub.verify_token=…&hub.challenge=…
        if request.httprequest.method == 'GET' and kw.get('hub.mode') == 'subscribe':
            secret = request.env['ir.config_parameter'].sudo().get_param('easycrm.webhook_secret') or ''
            if secret and hmac.compare_digest(secret, kw.get('hub.verify_token') or ''):
                return request.make_response(kw.get('hub.challenge') or '', headers=[('Content-Type', 'text/plain')])
            return request.make_response('forbidden', status=403)
        if not _authorized():
            return request.make_json_response({'ok': False, 'error': 'forbidden'}, status=403)
        n = 0
        for m in tel.parse_wa_inbound(_payload() or kw):
            prospect = find_prospect(request.env, m['from'])
            if prospect:
                prospect.sudo().message_post(
                    body=Markup("WhatsApp received: %s") % (m['text'] or ''), subtype_xmlid='mail.mt_note')
                n += 1
        return request.make_json_response({'ok': True, 'logged': n})
