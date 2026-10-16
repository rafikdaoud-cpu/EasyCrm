# -*- coding: utf-8 -*-
import json
import socket
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer
from unittest.mock import patch

from odoo.exceptions import UserError
from odoo.tests import HttpCase, TransactionCase, tagged

from ..models import telephony as tel


class FakeAMI:
    """Minimal Asterisk Manager Interface."""

    def __init__(self, user='crm', secret='pw'):
        self.user, self.secret, self.received = user, secret, []
        self.sock = socket.socket()
        self.sock.bind(('127.0.0.1', 0))
        self.sock.listen(5)
        self.port = self.sock.getsockname()[1]
        threading.Thread(target=self._serve, daemon=True).start()

    def _serve(self):
        while True:
            try:
                c, _ = self.sock.accept()
            except OSError:
                return
            threading.Thread(target=self._handle, args=(c,), daemon=True).start()

    def _handle(self, c):
        c.sendall(b"Asterisk Call Manager/5.0.1\r\n")
        buf = b''
        while True:
            try:
                d = c.recv(4096)
            except OSError:
                return
            if not d:
                return
            buf += d
            while b'\r\n\r\n' in buf:
                block, buf = buf.split(b'\r\n\r\n', 1)
                f = dict(l.split(': ', 1) for l in block.decode().split('\r\n') if ': ' in l)
                self.received.append(f)
                act = f.get('Action')
                if act == 'Login':
                    ok = f.get('Username') == self.user and f.get('Secret') == self.secret
                    c.sendall(("Response: Success\r\nMessage: Authentication accepted\r\n\r\n" if ok else
                               "Response: Error\r\nMessage: Authentication failed\r\n\r\n").encode())
                elif act == 'Originate':
                    c.sendall(b"Response: Success\r\nMessage: Originate successfully queued\r\n\r\n")
                elif act == 'Logoff':
                    c.close()
                    return

    def close(self):
        self.sock.close()


class FakeHTTP:
    def __init__(self):
        outer = self
        self.calls = []

        class H(BaseHTTPRequestHandler):
            def log_message(self, *a):
                pass

            def _do(self):
                n = int(self.headers.get('Content-Length') or 0)
                body = self.rfile.read(n).decode() if n else ''
                outer.calls.append((self.command, self.path, dict(self.headers), body))
                path = self.path.split('?')[0]
                if path == '/openapi/v1.0/get_token':
                    j = json.loads(body)
                    out = {'errcode': 0, 'access_token': 'TOK'} if j.get('password') == 'pw' else \
                        {'errcode': 10004, 'errmsg': 'bad password'}
                elif path == '/openapi/v1.0/call/dial':
                    out = {'errcode': 0}
                elif path == '/connect/token':
                    out = {'access_token': 'T3CX'} if 'client_secret=pw' in body else {'error': 'invalid_client'}
                elif path.startswith('/callcontrol/'):
                    out = {}
                else:
                    out = {'ok': True}
                data = json.dumps(out).encode()
                self.send_response(200)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Content-Length', str(len(data)))
                self.end_headers()
                self.wfile.write(data)

            do_GET = do_POST = do_PUT = _do

        self.srv = HTTPServer(('127.0.0.1', 0), H)
        self.url = 'http://127.0.0.1:%d' % self.srv.server_port
        threading.Thread(target=self.srv.serve_forever, daemon=True).start()

    def close(self):
        self.srv.shutdown()


@tagged('post_install', '-at_install')
class TestTelephonyPure(TransactionCase):

    def test_dial_number(self):
        f = tel.dial_number
        self.assertEqual(f('0555123456', '213', '0', 'national'), '0555123456')
        self.assertEqual(f('0555123456', '213', '0', 'e164'), '+213555123456')
        self.assertEqual(f('0555123456', '213', '0', 'intl'), '213555123456')
        self.assertEqual(f('0555123456', '213', '0', 'intl00'), '00213555123456')
        self.assertEqual(f('0555123456', '213', '0', 'national', '9'), '90555123456')

    def test_asterisk_ami(self):
        ami = FakeAMI()
        self.addCleanup(ami.close)
        ok, msg = tel.ami_test('127.0.0.1', ami.port, 'crm', 'pw')
        self.assertTrue(ok, msg)
        ok, msg = tel.ami_test('127.0.0.1', ami.port, 'crm', 'bad')
        self.assertFalse(ok)
        self.assertIn('refused', msg)
        cfg = {'host': '127.0.0.1', 'port': str(ami.port), 'user': 'crm', 'secret': 'pw',
               'channel': 'PJSIP/{ext}', 'context': 'from-internal'}
        ok, msg = tel.sip_dial('asterisk', cfg, '1001', '0555123456')
        self.assertTrue(ok, msg)
        orig = [r for r in ami.received if r.get('Action') == 'Originate'][0]
        self.assertEqual(orig['Channel'], 'PJSIP/1001')
        self.assertEqual(orig['Exten'], '0555123456')
        self.assertEqual(orig['Context'], 'from-internal')

    def test_ami_connection_refused(self):
        s = socket.socket()
        s.bind(('127.0.0.1', 0))
        port = s.getsockname()[1]
        s.close()
        ok, msg = tel.ami_test('127.0.0.1', port, 'a', 'b')
        self.assertFalse(ok)

    def test_yeastar_and_3cx_and_generic(self):
        h = FakeHTTP()
        self.addCleanup(h.close)
        cfg = {'url': h.url, 'user': 'api', 'secret': 'pw', 'verify_ssl': '1'}
        self.assertTrue(tel.sip_test('yeastar', cfg)[0])
        self.assertFalse(tel.sip_test('yeastar', dict(cfg, secret='no'))[0])
        ok, msg = tel.sip_dial('yeastar', cfg, '1001', '0555')
        self.assertTrue(ok, msg)
        dial = [c for c in h.calls if c[1].startswith('/openapi/v1.0/call/dial')][0]
        self.assertIn('access_token=TOK', dial[1])
        self.assertEqual(json.loads(dial[3]), {'caller': '1001', 'callee': '0555', 'auto_answer': 'yes'})
        # 3CX
        self.assertTrue(tel.sip_test('3cx', cfg)[0])
        self.assertFalse(tel.sip_test('3cx', dict(cfg, secret='no'))[0])
        ok, msg = tel.sip_dial('3cx', cfg, '1001', '+213555')
        self.assertTrue(ok, msg)
        mk = [c for c in h.calls if c[1] == '/callcontrol/1001/makecall'][0]
        self.assertEqual(mk[2].get('Authorization'), 'Bearer T3CX')
        self.assertEqual(json.loads(mk[3]), {'destination': '+213555'})
        # generic
        g = {'url': h.url + '/dial?n={number}', 'http_method': 'POST',
             'http_headers': '{"Content-Type": "application/json", "X-Key": "k"}',
             'http_body': '{"ext": "{ext}", "number": "{number}"}', 'verify_ssl': '1'}
        ok, msg = tel.sip_dial('http', g, '1001', '0555')
        self.assertTrue(ok, msg)
        last = h.calls[-1]
        self.assertEqual(last[1], '/dial?n=0555')
        self.assertEqual(json.loads(last[3]), {'ext': '1001', 'number': '0555'})
        self.assertEqual(last[2].get('X-Key'), 'k')

    def test_whatsapp_payloads(self):
        calls = []

        class R:
            status_code = 200

            def json(self):
                return {}

        def fake(method, url, **kw):
            calls.append((method, url, kw))
            return R(), ''

        with patch.object(tel, '_http', fake):
            ok, _m = tel.wa_send('cloud', {'phone_id': '123', 'token': 'T', 'api_version': 'v21.0'}, '213555', 'Hi')
            self.assertTrue(ok)
            m, url, kw = calls[-1]
            self.assertEqual(url, 'https://graph.facebook.com/v21.0/123/messages')
            self.assertEqual(kw['json']['text']['body'], 'Hi')
            self.assertEqual(kw['headers']['Authorization'], 'Bearer T')
            tel.wa_send('cloud', {'phone_id': '123', 'token': 'T', 'template': 'hello', 'language': 'fr'}, '213555',
                        'x', 'Ali')
            self.assertEqual(calls[-1][2]['json']['template']['name'], 'hello')
            self.assertEqual(calls[-1][2]['json']['template']['components'][0]['parameters'][0]['text'], 'Ali')
            tel.wa_send('360dialog', {'token': 'K'}, '213555', 'Hi')
            self.assertEqual(calls[-1][2]['headers']['D360-API-KEY'], 'K')
            tel.wa_send('twilio', {'account_sid': 'AC1', 'token': 'S', 'from_number': '14155238886'}, '213555', 'Hi')
            self.assertEqual(calls[-1][2]['data']['To'], 'whatsapp:+213555')
            self.assertEqual(calls[-1][2]['auth'], ('AC1', 'S'))
            tel.wa_send('http', {'url': 'https://gw/x', 'http_method': 'POST', 'http_headers': '{}',
                                 'http_body': '{"to": "{number}", "text": "{text}"}'}, '213555', 'He said "hi"')
            self.assertEqual(json.loads(calls[-1][2]['data']), {'to': '213555', 'text': 'He said "hi"'})

    def test_parse_events(self):
        e = tel.parse_pbx_event({'caller': '1001', 'callee': '0555-12 34 56', 'duration': '42',
                                 'disposition': 'ANSWERED', 'recording': 'https://x/r.mp3', 'uniqueid': 'u1'})
        self.assertEqual((e['number'], e['ext'], e['duration'], e['answered']), ('0555123456', '1001', 42, True))
        e = tel.parse_pbx_event({'src': '1001', 'dst': '0555', 'billsec': 0, 'disposition': 'NO ANSWER'})
        self.assertFalse(e['answered'])
        e = tel.parse_pbx_event({'direction': 'inbound', 'from': '0555123456', 'to': '1001', 'duration': 10})
        self.assertTrue(e['inbound'])
        self.assertEqual((e['number'], e['ext']), ('0555123456', '1001'))
        meta = {'entry': [{'changes': [{'value': {'messages': [{'from': '213555123456', 'id': 'm1',
                                                              'text': {'body': 'Bonjour'}}]}}]}]}
        self.assertEqual(tel.parse_wa_inbound(meta)[0]['text'], 'Bonjour')
        tw = {'From': 'whatsapp:+213555123456', 'Body': 'Salut', 'MessageSid': 'SM1'}
        self.assertEqual(tel.parse_wa_inbound(tw)[0]['from'], '213555123456')


@tagged('post_install', '-at_install')
class TestTelephonyOdoo(TransactionCase):

    def setUp(self):
        super().setUp()
        self.icp = self.env['ir.config_parameter'].sudo()
        self.prospect = self.env['easycrm.prospect'].create({'name': 'Karim', 'phone': '0555123456'})
        self.env.user.easycrm_extension = '1001'

    def test_modes_and_uri(self):
        self.assertEqual(self.prospect.pbx_mode, 'none')
        self.icp.set_param('easycrm.sip.provider', 'uri')
        self.icp.set_param('easycrm.sip.uri_scheme', 'sip')
        self.icp.set_param('easycrm.sip.dial_format', 'e164')
        self.prospect.invalidate_recordset()
        self.assertEqual(self.prospect.pbx_mode, 'uri')
        act = self.prospect.action_pbx_call()
        self.assertEqual(act['url'], 'sip:+213555123456')

    def test_pbx_call_asterisk(self):
        ami = FakeAMI()
        self.addCleanup(ami.close)
        for k, v in {'provider': 'asterisk', 'host': '127.0.0.1', 'port': str(ami.port), 'user': 'crm',
                     'secret': 'pw', 'channel': 'PJSIP/{ext}', 'context': 'from-internal',
                     'dial_format': 'national', 'dial_prefix': '0'}.items():
            self.icp.set_param('easycrm.sip.' + k, v)
        self.prospect.invalidate_recordset()
        self.assertEqual(self.prospect.pbx_mode, 'api')
        act = self.prospect.action_pbx_call()
        self.assertEqual(act['tag'], 'display_notification')
        orig = [r for r in ami.received if r.get('Action') == 'Originate'][0]
        self.assertEqual(orig['Exten'], '00555123456')
        self.env.user.easycrm_extension = False
        with self.assertRaises(UserError):
            self.prospect.action_pbx_call()

    def test_settings_presets_and_test_button(self):
        ami = FakeAMI()
        self.addCleanup(ami.close)
        s = self.env['res.config.settings'].create({'easycrm_sip_provider': 'asterisk'})
        s._onchange_easycrm_sip_provider()
        self.assertEqual(s.easycrm_sip_port, '5038')
        self.assertEqual(s.easycrm_sip_context, 'from-internal')
        s.write({'easycrm_sip_host': '127.0.0.1', 'easycrm_sip_port': str(ami.port),
                 'easycrm_sip_user': 'crm', 'easycrm_sip_secret': 'pw'})
        res = s.action_easycrm_test_sip()
        self.assertEqual(res['params']['type'], 'success', res)
        s.easycrm_sip_secret = 'wrong'
        res = s.action_easycrm_test_sip()
        self.assertEqual(res['params']['type'], 'danger')
        w = self.env['res.config.settings'].create({'easycrm_wa_provider': 'cloud'})
        w._onchange_easycrm_wa_provider()
        self.assertEqual(w.easycrm_wa_api_version, 'v21.0')
        w.action_easycrm_new_secret()
        self.assertTrue(w.easycrm_webhook_secret)

    def test_wa_wizard(self):
        self.icp.set_param('easycrm.wa.provider', 'cloud')
        self.icp.set_param('easycrm.wa.phone_id', '123')
        self.icp.set_param('easycrm.wa.token', 'T')
        sent = []

        def fake(provider, cfg, number, text, name=''):
            sent.append((provider, number, text, name))
            return True, 'ok'

        with patch.object(tel, 'wa_send', fake):
            w = self.env['easycrm.wa.wizard'].create({'prospect_id': self.prospect.id, 'text': 'Bonjour'})
            w.action_send()
        self.assertEqual(sent[0][:3], ('cloud', '213555123456', 'Bonjour'))
        self.assertIn('Bonjour', ''.join(self.prospect.message_ids.mapped('body')))


@tagged('post_install', '-at_install')
class TestWebhooks(HttpCase):

    def setUp(self):
        super().setUp()
        icp = self.env['ir.config_parameter'].sudo()
        icp.set_param('easycrm.webhook_secret', 'sekret')
        self.prospect = self.env['easycrm.prospect'].create({'name': 'Karim', 'phone': '0555123456'})
        self.user = self.env.ref('base.user_admin')
        self.user.easycrm_extension = '1001'
        st = self.env['easycrm.status'].search([('is_callback', '=', False), ('require_note', '=', False),
                                                ('in_queue', '=', False)], limit=1)
        self.st = st
        icp.set_param('easycrm.pbx.status_answered', str(st.id))

    def test_pbx_webhook(self):
        r = self.url_open('/easycrm/pbx/webhook?token=bad', data=json.dumps({}), headers={'Content-Type': 'application/json'})
        self.assertEqual(r.status_code, 403)
        body = {'caller': '1001', 'callee': '213555123456', 'duration': 75, 'disposition': 'ANSWERED',
                'recording': 'https://pbx/rec/1.mp3'}
        r = self.url_open('/easycrm/pbx/webhook?token=sekret', data=json.dumps(body),
                          headers={'Content-Type': 'application/json'})
        self.assertEqual(r.status_code, 200)
        self.assertTrue(r.json()['matched'])
        self.prospect.invalidate_recordset()
        self.assertIn('recording', ''.join(self.prospect.message_ids.mapped('body')))
        call = self.env['easycrm.call'].search([('prospect_id', '=', self.prospect.id)])
        self.assertEqual(len(call), 1)
        self.assertEqual(call.status_id, self.st)
        self.assertEqual(call.user_id, self.user)

    def test_wa_webhook(self):
        r = self.url_open('/easycrm/wa/webhook?hub.mode=subscribe&hub.verify_token=sekret&hub.challenge=777')
        self.assertEqual(r.text, '777')
        r = self.url_open('/easycrm/wa/webhook?hub.mode=subscribe&hub.verify_token=nope&hub.challenge=777')
        self.assertEqual(r.status_code, 403)
        meta = {'entry': [{'changes': [{'value': {'messages': [
            {'from': '213555123456', 'id': 'm1', 'text': {'body': 'Je suis intéressé'}}]}}]}]}
        r = self.url_open('/easycrm/wa/webhook?token=sekret', data=json.dumps(meta),
                          headers={'Content-Type': 'application/json'})
        self.assertEqual(r.json()['logged'], 1)
        self.prospect.invalidate_recordset()
        self.assertIn('intéressé', ''.join(self.prospect.message_ids.mapped('body')))
