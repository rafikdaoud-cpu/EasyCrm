# -*- coding: utf-8 -*-
"""Telephony (PBX / SIP) and WhatsApp connectors — pure Python, no Odoo dependency.

Every function returns ``(ok: bool, message: str)`` so it can be unit-tested against a
simulated server. Nothing here talks to the database.
"""
import json
import re
import socket
import time

try:
    import requests
except ImportError:  # pragma: no cover
    requests = None

TIMEOUT = 10

# ---------------------------------------------------------------------------- presets
# 'fields' lists the settings shown for the provider; 'defaults' pre-fills them.
SIP_PRESETS = {
    'none': {
        'label': "Disabled (call from the smartphone)",
        'fields': [], 'defaults': {},
    },
    'uri': {
        'label': "Softphone link (tel: / sip: / callto:)",
        'fields': ['dial_format', 'dial_prefix', 'uri_scheme'],
        'defaults': {'uri_scheme': 'tel', 'dial_format': 'national', 'dial_prefix': ''},
    },
    'asterisk': {
        'label': "Asterisk / FreePBX / Issabel (AMI)",
        'fields': ['host', 'port', 'user', 'secret', 'channel', 'context', 'dial_format', 'dial_prefix'],
        'defaults': {'port': '5038', 'channel': 'PJSIP/{ext}', 'context': 'from-internal',
                     'dial_format': 'national', 'dial_prefix': ''},
    },
    'yeastar': {
        'label': "Yeastar P-Series (OpenAPI)",
        'fields': ['url', 'user', 'secret', 'dial_format', 'dial_prefix', 'verify_ssl'],
        'defaults': {'url': 'https://pbx.example.com:8088', 'dial_format': 'national', 'dial_prefix': '',
                     'verify_ssl': '1'},
    },
    '3cx': {
        'label': "3CX (Call Control API)",
        'fields': ['url', 'user', 'secret', 'dial_format', 'dial_prefix', 'verify_ssl'],
        'defaults': {'url': 'https://pbx.example.com', 'dial_format': 'e164', 'dial_prefix': '',
                     'verify_ssl': '1'},
    },
    'http': {
        'label': "Generic HTTP API (any PBX)",
        'fields': ['url', 'http_method', 'http_headers', 'http_body', 'dial_format', 'dial_prefix', 'verify_ssl'],
        'defaults': {'http_method': 'POST', 'http_headers': '{"Content-Type": "application/json"}',
                     'http_body': '{"extension": "{ext}", "number": "{number}"}',
                     'dial_format': 'national', 'dial_prefix': '', 'verify_ssl': '1'},
    },
}

WA_PRESETS = {
    'link': {
        'label': "WhatsApp link (wa.me — message sent manually)",
        'fields': [], 'defaults': {},
    },
    'cloud': {
        'label': "Meta WhatsApp Cloud API (official)",
        'fields': ['phone_id', 'token', 'template', 'language', 'api_version'],
        'defaults': {'api_version': 'v21.0', 'language': 'fr'},
    },
    '360dialog': {
        'label': "360dialog (WhatsApp Business API)",
        'fields': ['token', 'template', 'language'],
        'defaults': {'language': 'fr'},
    },
    'twilio': {
        'label': "Twilio WhatsApp",
        'fields': ['account_sid', 'token', 'from_number', 'template'],
        'defaults': {},
    },
    'http': {
        'label': "Generic HTTP API (any gateway)",
        'fields': ['url', 'http_method', 'http_headers', 'http_body'],
        'defaults': {'http_method': 'POST', 'http_headers': '{"Content-Type": "application/json"}',
                     'http_body': '{"to": "{number}", "text": "{text}"}'},
    },
}

SIP_CHOICES = [(k, v['label']) for k, v in SIP_PRESETS.items()]
WA_CHOICES = [(k, v['label']) for k, v in WA_PRESETS.items()]


# ---------------------------------------------------------------------------- numbers
def dial_number(national, cc, trunk, fmt='national', prefix=''):
    """Format a normalized national number for the PBX.

    fmt: national (0555…), e164 (+213555…), intl (213555…), intl00 (00213555…), raw.
    """
    n = re.sub(r'[^\d+]', '', national or '')
    if fmt == 'raw' or not n:
        return (prefix or '') + n
    digits = n.lstrip('+')
    if trunk and digits.startswith(trunk):
        core = digits[len(trunk):]
    elif cc and digits.startswith(cc):
        core = digits[len(cc):]
    else:
        core = digits
    if fmt == 'national':
        out = (trunk or '') + core
    elif fmt == 'e164':
        out = '+' + (cc or '') + core
    elif fmt == 'intl':
        out = (cc or '') + core
    elif fmt == 'intl00':
        out = '00' + (cc or '') + core
    else:
        out = digits
    return (prefix or '') + out


def _json(text, default=None):
    try:
        return json.loads(text) if text else (default if default is not None else {})
    except ValueError:
        return default if default is not None else {}


def _fill(template, **values):
    """Replace {name} placeholders without choking on other braces (JSON)."""
    out = template or ''
    for k, v in values.items():
        out = out.replace('{' + k + '}', json_escape(str(v)))
    return out


def json_escape(s):
    return json.dumps(s)[1:-1]


def _http(method, url, **kw):
    if requests is None:
        return None, "The Python library 'requests' is missing."
    try:
        r = requests.request(method, url, timeout=TIMEOUT, **kw)
        return r, ''
    except requests.exceptions.SSLError as e:
        return None, "SSL error: %s" % e
    except requests.exceptions.RequestException as e:
        return None, "Connection error: %s" % e


def _truthy(v):
    return str(v).strip().lower() in ('1', 'true', 'yes', 'on')


# ---------------------------------------------------------------------------- Asterisk AMI
def _ami_read(sock, until=None, timeout=TIMEOUT):
    """Read AMI data until a blank-line terminated block containing `until`."""
    sock.settimeout(timeout)
    buf = b''
    end = time.time() + timeout
    while time.time() < end:
        try:
            chunk = sock.recv(4096)
        except socket.timeout:
            break
        if not chunk:
            break
        buf += chunk
        text = buf.decode('utf-8', 'replace')
        if until is None and '\r\n' in text:
            return text
        if until and until in text and text.endswith('\r\n\r\n'):
            return text
    return buf.decode('utf-8', 'replace')


def _ami_login(sock, user, secret):
    banner = _ami_read(sock)
    if 'Asterisk Call Manager' not in banner:
        return False, "Not an Asterisk Manager Interface (banner: %r)" % banner[:60]
    sock.sendall(("Action: Login\r\nUsername: %s\r\nSecret: %s\r\nEvents: off\r\n\r\n" % (user, secret)).encode())
    resp = _ami_read(sock, until='Response:')
    if 'Response: Success' not in resp:
        m = re.search(r'Message: (.*)', resp)
        return False, "AMI login refused: %s" % (m.group(1).strip() if m else resp.strip()[:80])
    return True, ''


def ami_test(host, port, user, secret):
    try:
        with socket.create_connection((host, int(port or 5038)), timeout=TIMEOUT) as sock:
            ok, msg = _ami_login(sock, user, secret)
            if ok:
                sock.sendall(b"Action: Logoff\r\n\r\n")
                return True, "AMI connection OK."
            return False, msg
    except (OSError, ValueError) as e:
        return False, "Connection error: %s" % e


def ami_originate(host, port, user, secret, channel, context, exten, callerid=''):
    """Ring the agent's channel, then dial `exten` in `context` (click-to-call)."""
    try:
        with socket.create_connection((host, int(port or 5038)), timeout=TIMEOUT) as sock:
            ok, msg = _ami_login(sock, user, secret)
            if not ok:
                return False, msg
            lines = ["Action: Originate", "Channel: %s" % channel, "Context: %s" % context,
                     "Exten: %s" % exten, "Priority: 1", "Async: true", "Timeout: 30000"]
            if callerid:
                lines.append("CallerID: %s" % callerid)
            sock.sendall(("\r\n".join(lines) + "\r\n\r\n").encode())
            resp = _ami_read(sock, until='Response:')
            sock.sendall(b"Action: Logoff\r\n\r\n")
            if 'Response: Success' in resp:
                return True, "Call started."
            m = re.search(r'Message: (.*)', resp)
            return False, "Originate refused: %s" % (m.group(1).strip() if m else resp.strip()[:80])
    except (OSError, ValueError) as e:
        return False, "Connection error: %s" % e


# ---------------------------------------------------------------------------- Yeastar / 3CX
def _yeastar_token(url, user, secret, verify):
    r, err = _http('POST', url.rstrip('/') + '/openapi/v1.0/get_token', json={'username': user, 'password': secret},
                   verify=verify)
    if r is None:
        return None, err
    try:
        j = r.json()
    except ValueError:
        return None, "Unexpected response (HTTP %s)." % r.status_code
    if j.get('errcode') not in (0, '0', None) or not j.get('access_token'):
        return None, "Yeastar refused the login: %s" % (j.get('errmsg') or j)
    return j['access_token'], ''


def yeastar_test(url, user, secret, verify=True):
    tok, err = _yeastar_token(url, user, secret, verify)
    return (True, "Yeastar API connection OK.") if tok else (False, err)


def yeastar_dial(url, user, secret, ext, number, verify=True):
    tok, err = _yeastar_token(url, user, secret, verify)
    if not tok:
        return False, err
    r, err = _http('POST', url.rstrip('/') + '/openapi/v1.0/call/dial', params={'access_token': tok},
                   json={'caller': ext, 'callee': number, 'auto_answer': 'yes'}, verify=verify)
    if r is None:
        return False, err
    try:
        j = r.json()
    except ValueError:
        return False, "Unexpected response (HTTP %s)." % r.status_code
    if j.get('errcode') in (0, '0'):
        return True, "Call started."
    return False, "Yeastar refused the call: %s" % (j.get('errmsg') or j)


def _threecx_token(url, client_id, secret, verify):
    r, err = _http('POST', url.rstrip('/') + '/connect/token', data={
        'grant_type': 'client_credentials', 'client_id': client_id, 'client_secret': secret}, verify=verify)
    if r is None:
        return None, err
    try:
        j = r.json()
    except ValueError:
        return None, "Unexpected response (HTTP %s)." % r.status_code
    if not j.get('access_token'):
        return None, "3CX refused the login: %s" % (j.get('error_description') or j.get('error') or j)
    return j['access_token'], ''


def threecx_test(url, client_id, secret, verify=True):
    tok, err = _threecx_token(url, client_id, secret, verify)
    return (True, "3CX API connection OK.") if tok else (False, err)


def threecx_dial(url, client_id, secret, ext, number, verify=True):
    tok, err = _threecx_token(url, client_id, secret, verify)
    if not tok:
        return False, err
    r, err = _http('POST', url.rstrip('/') + '/callcontrol/%s/makecall' % ext,
                   headers={'Authorization': 'Bearer ' + tok}, json={'destination': number}, verify=verify)
    if r is None:
        return False, err
    if r.status_code in (200, 201, 202, 204):
        return True, "Call started."
    return False, "3CX refused the call (HTTP %s): %s" % (r.status_code, r.text[:120])


# ---------------------------------------------------------------------------- generic HTTP
def http_generic(url, method, headers_json, body_template, verify=True, **values):
    url = _fill(url, **{k: _urlsafe(v) for k, v in values.items()}) if '{' in (url or '') else url
    headers = _json(headers_json, {})
    body = _fill(body_template, **values) if body_template else None
    kw = {'headers': headers, 'verify': verify}
    if body:
        ct = {k.lower(): v for k, v in headers.items()}.get('content-type', '')
        kw['data'] = body.encode('utf-8') if 'json' in ct or not ct else body.encode('utf-8')
    r, err = _http((method or 'POST').upper(), url, **kw)
    if r is None:
        return False, err
    if 200 <= r.status_code < 300:
        return True, "OK (HTTP %s)." % r.status_code
    return False, "HTTP %s: %s" % (r.status_code, r.text[:160])


def _urlsafe(v):
    from urllib.parse import quote
    return quote(str(v), safe='')


# ---------------------------------------------------------------------------- SIP front-ends
def sip_test(provider, cfg):
    p = provider
    verify = _truthy(cfg.get('verify_ssl', '1'))
    if p in ('none', ''):
        return True, "Nothing to test (disabled)."
    if p == 'uri':
        return True, "Nothing to test: the browser opens the softphone link."
    if p == 'asterisk':
        return ami_test(cfg.get('host'), cfg.get('port'), cfg.get('user'), cfg.get('secret'))
    if p == 'yeastar':
        return yeastar_test(cfg.get('url'), cfg.get('user'), cfg.get('secret'), verify)
    if p == '3cx':
        return threecx_test(cfg.get('url'), cfg.get('user'), cfg.get('secret'), verify)
    if p == 'http':
        r, err = _http('GET', _fill(cfg.get('url'), ext='', number=''), headers=_json(cfg.get('http_headers'), {}),
                       verify=verify)
        if r is None:
            return False, err
        return True, "Server reachable (HTTP %s)." % r.status_code
    return False, "Unknown provider."


def sip_dial(provider, cfg, ext, number, callerid=''):
    p = provider
    verify = _truthy(cfg.get('verify_ssl', '1'))
    if not ext and p not in ('uri', 'http'):
        return False, "No extension defined for this user."
    if p == 'asterisk':
        channel = (cfg.get('channel') or 'PJSIP/{ext}').replace('{ext}', ext)
        return ami_originate(cfg.get('host'), cfg.get('port'), cfg.get('user'), cfg.get('secret'), channel,
                             cfg.get('context') or 'from-internal', number, callerid or ext)
    if p == 'yeastar':
        return yeastar_dial(cfg.get('url'), cfg.get('user'), cfg.get('secret'), ext, number, verify)
    if p == '3cx':
        return threecx_dial(cfg.get('url'), cfg.get('user'), cfg.get('secret'), ext, number, verify)
    if p == 'http':
        return http_generic(cfg.get('url'), cfg.get('http_method'), cfg.get('http_headers'),
                            cfg.get('http_body'), verify, ext=ext or '', number=number)
    return False, "This provider does not place calls from the server."


# ---------------------------------------------------------------------------- WhatsApp
def _wa_payload(cfg, number, text, name=''):
    tpl = (cfg.get('template') or '').strip()
    if tpl:
        return {'messaging_product': 'whatsapp', 'to': number, 'type': 'template',
                'template': {'name': tpl, 'language': {'code': cfg.get('language') or 'fr'},
                             'components': [{'type': 'body', 'parameters': [{'type': 'text', 'text': name or ' '}]}]}}
    return {'messaging_product': 'whatsapp', 'to': number, 'type': 'text', 'text': {'body': text}}


def wa_send(provider, cfg, number, text, name=''):
    """number: international digits without '+'."""
    p = provider
    verify = True
    if p == 'cloud':
        url = 'https://graph.facebook.com/%s/%s/messages' % (cfg.get('api_version') or 'v21.0', cfg.get('phone_id'))
        r, err = _http('POST', url, headers={'Authorization': 'Bearer %s' % cfg.get('token')},
                       json=_wa_payload(cfg, number, text, name), verify=verify)
    elif p == '360dialog':
        r, err = _http('POST', 'https://waba-v2.360dialog.io/messages', headers={'D360-API-KEY': cfg.get('token') or ''},
                       json=_wa_payload(cfg, number, text, name), verify=verify)
    elif p == 'twilio':
        sid = cfg.get('account_sid')
        data = {'From': 'whatsapp:+%s' % (cfg.get('from_number') or '').lstrip('+'), 'To': 'whatsapp:+%s' % number}
        if (cfg.get('template') or '').strip():
            data['ContentSid'] = cfg['template'].strip()
            data['ContentVariables'] = json.dumps({'1': name or ' '})
        else:
            data['Body'] = text
        r, err = _http('POST', 'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json' % sid,
                       data=data, auth=(sid, cfg.get('token') or ''), verify=verify)
    elif p == 'http':
        return http_generic(cfg.get('url'), cfg.get('http_method'), cfg.get('http_headers'), cfg.get('http_body'),
                            True, number=number, text=text, name=name)
    else:
        return False, "This provider does not send messages from the server."
    if r is None:
        return False, err
    if 200 <= r.status_code < 300:
        return True, "Message sent."
    try:
        j = r.json()
        msg = (j.get('error') or {}).get('message') if isinstance(j.get('error'), dict) else (j.get('message') or j)
    except ValueError:
        msg = r.text[:160]
    return False, "WhatsApp provider refused the message (HTTP %s): %s" % (r.status_code, msg)


def wa_test(provider, cfg):
    p = provider
    if p in ('link', 'none', ''):
        return True, "Nothing to test (manual WhatsApp link)."
    if p == 'cloud':
        url = 'https://graph.facebook.com/%s/%s' % (cfg.get('api_version') or 'v21.0', cfg.get('phone_id'))
        r, err = _http('GET', url, params={'fields': 'display_phone_number,verified_name'},
                       headers={'Authorization': 'Bearer %s' % cfg.get('token')})
        if r is None:
            return False, err
        if r.status_code == 200:
            j = r.json()
            return True, "Connected: %s (%s)." % (j.get('display_phone_number', '?'), j.get('verified_name', '?'))
        return False, "Meta refused the credentials (HTTP %s)." % r.status_code
    if p == 'twilio':
        sid = cfg.get('account_sid')
        r, err = _http('GET', 'https://api.twilio.com/2010-04-01/Accounts/%s.json' % sid, auth=(sid, cfg.get('token') or ''))
        if r is None:
            return False, err
        return (True, "Twilio credentials OK.") if r.status_code == 200 else (False, "Twilio refused the credentials (HTTP %s)." % r.status_code)
    if p == '360dialog':
        return (True, "No live test available: send a message to check.") if cfg.get('token') else (False, "API key missing.")
    if p == 'http':
        return (True, "No live test available: send a message to check.") if cfg.get('url') else (False, "URL missing.")
    return False, "Unknown provider."


# ---------------------------------------------------------------------------- webhooks
def digits(s):
    return re.sub(r'\D', '', str(s or ''))


def parse_pbx_event(data):
    """Normalise a call-end notification coming from any PBX / generic HTTP post.

    Accepted keys (first found wins): number|callee|dst|destination|called, ext|extension|caller|src,
    duration|billsec, status|disposition|result, recording|recording_url|recordingfile, uniqueid|id|call_id,
    direction.
    """
    def pick(*keys):
        low = {str(k).lower(): v for k, v in data.items()}
        for k in keys:
            if low.get(k) not in (None, ''):
                return low[k]
        return ''
    direction = str(pick('direction', 'type')).lower() or 'outbound'
    inbound = direction.startswith('in')
    number = pick('number', 'prospect', 'phone') or (pick('caller', 'src', 'from') if inbound else pick('callee', 'dst', 'destination', 'called', 'to'))
    ext = pick('ext', 'extension') or (pick('callee', 'dst', 'to') if inbound else pick('caller', 'src', 'from'))
    status = str(pick('status', 'disposition', 'result')).lower()
    ok = status in ('answered', 'answer', 'completed', 'success', 'connected', 'ok', '')
    try:
        duration = int(float(pick('duration', 'billsec', 'talk_time') or 0))
    except ValueError:
        duration = 0
    return {
        'number': digits(number), 'ext': str(ext), 'status': status or ('answered' if duration else 'unknown'),
        'answered': ok and duration > 0, 'duration': duration,
        'recording': str(pick('recording', 'recording_url', 'recordingfile', 'recordurl')),
        'uid': str(pick('uniqueid', 'id', 'call_id', 'callid')), 'inbound': inbound,
    }


def parse_wa_inbound(data):
    """Return a list of {'from': digits, 'text': str, 'id': str} from Meta / 360dialog / Twilio payloads."""
    out = []
    if isinstance(data, dict) and 'entry' in data:  # Meta Cloud API and 360dialog v2
        for e in data.get('entry') or []:
            for ch in e.get('changes') or []:
                val = ch.get('value') or {}
                for m in val.get('messages') or []:
                    txt = (m.get('text') or {}).get('body') or ''
                    if not txt:
                        t = m.get('type', '')
                        txt = '[%s]' % t if t else ''
                    out.append({'from': digits(m.get('from')), 'text': txt, 'id': m.get('id', '')})
    elif isinstance(data, dict) and ('From' in data or 'WaId' in data):  # Twilio
        out.append({'from': digits(data.get('WaId') or str(data.get('From', '')).replace('whatsapp:', '')),
                    'text': data.get('Body', ''), 'id': data.get('MessageSid', '')})
    elif isinstance(data, dict) and ('from' in data or 'number' in data):  # generic
        out.append({'from': digits(data.get('from') or data.get('number')), 'text': data.get('text') or data.get('message') or '',
                    'id': str(data.get('id', ''))})
    return out
