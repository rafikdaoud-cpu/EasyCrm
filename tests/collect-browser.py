#!/usr/bin/env python3
"""Page publique de dépôt (Call CRM) dans Chromium, API simulée, fausse caméra :
scanner guidé (OpenCV), galerie, envoi final, à 360 et 390 px.
Usage : CHROMIUM_BIN=/chemin/chromium python3 tests/collect-browser.py /tmp/collect-previews"""
from pathlib import Path
import os, sys, json, base64, re
from playwright.sync_api import sync_playwright
root = Path(__file__).resolve().parents[1] / 'callcrm'
out = Path(sys.argv[1] if len(sys.argv) > 1 else '/tmp/collect-previews'); out.mkdir(parents=True, exist_ok=True)

def camera(path, w=640, h=480):
    """Feuille blanche inclinée avec lignes de texte, sur une table claire (cas où seul OpenCV détecte)."""
    q = [(170, 70), (470, 95), (455, 420), (150, 400)]
    def inside(x, y):
        s = 0
        for i in range(4):
            (ax, ay), (bx, by) = q[i], q[(i + 1) % 4]; c = (bx - ax) * (y - ay) - (by - ay) * (x - ax)
            if c:
                if s and (c > 0) != (s > 0): return False
                s = c
        return True
    Y = bytearray(w * h)
    for y in range(h):
        for x in range(w): Y[y * w + x] = (60 if (y % 14 < 3 and 40 < (x * 7 + y) % 200 < 180) else 240) if inside(x, y) else 180 + (x + y) // 80
    with open(path, 'wb') as f:
        f.write(b'YUV4MPEG2 W%d H%d F10:1 Ip A1:1 C420jpeg\n' % (w, h))
        for _ in range(3): f.write(b'FRAME\n' + bytes(Y) + bytes([128]) * (w * h // 2))
    return path

def static(name, kind):
    """Gestionnaire à un seul paramètre : Playwright passe aussi la requête aux fonctions à deux paramètres."""
    return lambda route: route.fulfill(path=str(root / name), content_type=kind)

JPEG = base64.b64decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAAQABABAREA/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAA/AL+AD//Z')
video = camera(str(out / 'fake-camera.y4m'))
BASE = 'https://cloud.test/apps/callcrm/'
TOKEN = 'T' * 32
HTML = ('<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        '<link rel="stylesheet" href="/apps/callcrm/css/nextcloud-like.css"><link rel="stylesheet" href="/apps/callcrm/css/collect.css"><body>'
        f'<main id="crm-collect" class="crm-collect" data-api="/apps/callcrm/api/public/collecte/{TOKEN}"><p>Chargement…</p></main>'
        '<script src="/apps/callcrm/js/scanpdf.js"></script><script src="/apps/callcrm/js/docscan.js"></script><script src="/apps/callcrm/js/collect.js"></script></body></html>')
# 'unsafe-eval' uniquement pour les attentes de Playwright ; OpenCV passe par le worker (fichier statique, sans CSP).
CSP = "default-src 'none'; script-src 'self' 'unsafe-eval'; worker-src 'self'; style-src 'self'; img-src 'self' blob: data:; connect-src 'self'; media-src 'self' blob:"

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=os.environ.get('CHROMIUM_BIN') or None, headless=True,
                                args=['--no-sandbox', '--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', '--use-file-for-fake-video-capture=' + video])
    for width, height in [(360, 800), (390, 844)]:
        page = browser.new_page(viewport={'width': width, 'height': height}); errors = []
        page.on('pageerror', lambda e: errors.append(str(e)))
        page.on('dialog', lambda d: d.accept())
        state = {'docs': [{'index': 0, 'label': 'Carte d’identité (recto)', 'required': True}, {'index': 1, 'label': 'Carte d’identité (verso)', 'required': True}, {'index': 2, 'label': 'Fiche de paie', 'required': False}],
                 'uploaded': {}, 'submitted': False}
        def view():
            return {'state': 'open', 'expires_at': 1793900000, 'max_file': 20971520,
                    'documents': [dict(d, uploaded=d['index'] in state['uploaded'], size=state['uploaded'].get(d['index'], 0)) for d in state['docs']]}
        def api(route):
            req = route.request; url = req.url
            if url.endswith('/submit'):
                state['submitted'] = True; return route.fulfill(json={'ok': True, 'count': len(state['uploaded'])})
            m = re.search(r'/doc/(\d+)$', url)
            if m and req.method == 'POST':
                body = req.post_data_buffer or b''
                assert b'%PDF-' in body, 'upload is not a PDF'
                state['uploaded'][int(m.group(1))] = len(body); return route.fulfill(json={'ok': True, 'view': view()})
            return route.fulfill(json=view())
        page.route(BASE + 'api/public/collecte/**', api)
        page.route(BASE + 'collecte/' + TOKEN, lambda r: r.fulfill(content_type='text/html', headers={'Content-Security-Policy': CSP}, body=HTML))
        for name, kind in [('css/collect.css', 'text/css'), ('js/scanpdf.js', 'application/javascript'), ('js/docscan.js', 'application/javascript'), ('js/collect.js', 'application/javascript'), ('js/docscan-worker.js', 'application/javascript'), ('js/vendor/opencv.js', 'application/javascript')]:
            page.route(BASE + name, static(name, kind))
        # Comme Nextcloud : <body> ne défile pas, et les boutons ont leur propre style.
        page.route(BASE + 'css/nextcloud-like.css', lambda r: r.fulfill(content_type='text/css', body='html,body{height:100%;overflow:hidden;margin:0}button,label{background:#1e293b;color:#93c5fd;border:0}'))
        page.goto(BASE + 'collecte/' + TOKEN)
        page.locator('.crm-collect-doc').first.wait_for()
        assert page.locator('.crm-collect-doc').count() == 3
        assert page.locator('.crm-collect-footer button').is_disabled(), 'submit allowed before required documents'
        state['docs'] += [{'index': i, 'label': 'Pièce supplémentaire ' + str(i), 'required': False} for i in range(3, 11)]
        page.evaluate("location.reload()"); page.locator('#doc-10').wait_for()
        assert page.locator('#crm-collect').evaluate('e=>e.scrollHeight>e.clientHeight+200'), 'page content not scrollable'
        page.locator('#crm-collect').evaluate('e=>e.scrollTop=e.scrollHeight')
        assert page.locator('#doc-10').evaluate('e=>{const r=e.getBoundingClientRect();return r.bottom>0&&r.top<innerHeight}'), 'cannot scroll to the last document'
        page.mouse.move(width / 2, 300); page.locator('#crm-collect').evaluate('e=>e.scrollTop=0'); page.mouse.wheel(0, 3000); page.wait_for_timeout(300)
        assert page.locator('#crm-collect').evaluate('e=>e.scrollTop>500'), 'wheel/touch scrolling does not move the page'
        assert page.locator('#doc-0 .crm-btn-primary').evaluate('e=>getComputedStyle(e).backgroundColor')=='rgb(27, 79, 156)', 'Nextcloud button style overrides ours'
        page.screenshot(path=str(out / f'collect-{width}-scrolled.png'))
        state['docs'] = state['docs'][:3]; page.evaluate("location.reload()"); page.locator('#doc-2').wait_for()
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), 'horizontal overflow'
        assert page.locator('.crm-collect-bar span').evaluate('e=>e.getBoundingClientRect().width')==0, 'progress bar not empty at 0/3'
        page.screenshot(path=str(out / f'collect-{width}-start.png'))
        # Scanner guidé : document 1 puis 2 sans quitter la caméra.
        page.get_by_role('button', name='📷 Scanner mes documents').click(); page.locator('.co-docscan').wait_for()
        page.locator('.co-docscan-bottom .co-docscan-chip').click()  # Auto coupé le temps qu'OpenCV se charge
        page.wait_for_function("document.querySelector('.co-docscan-engine')?.textContent==='Détection OpenCV'", timeout=60000)
        page.locator('.co-docscan-bottom .co-docscan-chip').click()
        for doc in (1, 2):
            page.wait_for_function(f"document.querySelector('.co-docscan-title-text')?.textContent.startsWith('Document {doc}/3')")
            page.locator('.co-docscan-panel .co-guided-thumbs img').wait_for(timeout=30000)
            if doc == 1: page.screenshot(path=str(out / f'collect-{width}-review.png'))
            page.get_by_role('button', name='✅ Envoyer et suivant').click()
            page.wait_for_function(f"Object.keys({{}}).length>=0 && document.querySelector('.co-docscan-title-text')?.textContent.startsWith('Document {doc + 1}/3')")
        assert sorted(state['uploaded']) == [0, 1]
        page.get_by_role('button', name='Passer ce document').click()
        page.wait_for_function("document.querySelector('.co-docscan-panel')?.textContent.includes('Tous les documents obligatoires')")
        page.get_by_role('button', name='Continuer').click()
        page.wait_for_function("!document.querySelector('.co-docscan') && document.querySelectorAll('.crm-collect-doc.is-done').length===2")
        # Galerie : une photo devient un PDF.
        page.locator('#doc-2 input[type=file]').set_input_files([{'name': 'paie.jpg', 'mimeType': 'image/jpeg', 'buffer': JPEG}])
        page.wait_for_function("document.querySelectorAll('.crm-collect-doc.is-done').length===3")
        btn = page.locator('.crm-collect-footer button')
        assert not btn.is_disabled() and btn.evaluate('e=>{const r=e.getBoundingClientRect();return r.height>=56&&r.bottom<=innerHeight}'), 'submit button not reachable'
        page.screenshot(path=str(out / f'collect-{width}-ready.png'))
        btn.click(); page.get_by_text('Merci !').wait_for()
        assert state['submitted']
        page.screenshot(path=str(out / f'collect-{width}-done.png'))
        assert not errors, errors
        print(f'PASS client document page {width}px: guided OpenCV scan, gallery, submit'); page.close()
    browser.close()
