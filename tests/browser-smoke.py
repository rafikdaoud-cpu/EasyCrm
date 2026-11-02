#!/usr/bin/env python3
"""Optional real Chromium layout/workflow checks with synthetic data, no VPS requests."""
import json
import os
from pathlib import Path
import sys
from playwright.sync_api import sync_playwright

root = Path(__file__).resolve().parents[1]
out = Path(sys.argv[1] if len(sys.argv) > 1 else '/tmp/crm-browser-smoke')
out.mkdir(parents=True, exist_ok=True)
status_labels = [('rappel', 'À rappeler (occupé)'), ('faux_numero', 'Faux numéro'), ('interesse', 'Intéressé'), ('injoignable', 'Ne répond pas'), ('non_eligible', 'Non éligible'), ('pas_interesse', 'Pas intéressé'), ('plateforme', 'Transmis sur Plateforme')]
with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=os.environ.get('CHROMIUM_BIN') or None, headless=True, args=['--no-sandbox'])
    for folder, app_id in [('callcrm', 'callcrm'), ('nextcloud', 'easycrm')]:
        statuses = [dict(key=k, label=v, result=True, active=True, callback=(k == 'rappel'), color='#4263a6') for k, v in status_labels]
        counts = dict(zip((k for k, _ in status_labels), [4, 0, 3, 2, 1, 1, 1]))
        total = dict(selected=dict(online=12250, pause=1080, lunch=3600))
        report = dict(server_now=1793620800, timezone='Africa/Algiers', range=dict(from_='2026-11-02', to='2026-11-02'), totals=total, processed=12, by_result=counts)
        report['range']['from'] = report['range'].pop('from_')
        people = [dict(uid='alice', name='Commercial A', status='online', paused=False, pause_kind='pause', totals=total, processed=12, by_result=counts), dict(uid='bob', name='Commercial B', status='pause', paused=True, pause_kind='lunch', pause_since=1793616400, pause_alert='overdue', totals=total, processed=12, by_result=counts)]
        people.append(dict(uid='claire',name='Commercial C',status='pause',paused=True,pause_kind='pause',pause_since=1793620199,pause_alert='overdue',totals=total,processed=12,by_result=counts))
        for role in ['admin', 'commercial']:
            page = browser.new_page(viewport=dict(width=1440, height=1050))
            errors = []
            page.on('pageerror', lambda e: errors.append(str(e)))
            me = dict(uid='alice' if role == 'commercial' else 'admin', role=role, can=dict(all=role == 'admin', **{'import': role == 'admin'}), statuses=statuses, groupes=[], groups=[], commerciaux=[dict(uid=u['uid'], name=u['name']) for u in people], fields=[], prefs={}, options=dict(c2=[]), tel=dict(wa='link', pbx='none'))
            fixture = dict(me=me, report=report, people=people)
            page.set_content('<!doctype html><html lang="fr"><head><style>body{margin:0;background:#f6f8fc;--color-main-background:#fff;--color-main-text:#19223d;--color-border:#e3e7ef;--color-primary-element:#283b7e;--color-primary-element-text:#fff;--color-text-maxcontrast:#66738a;--color-background-hover:#f5f7fc}</style></head><body><div id="' + app_id + '" data-api="/api"></div></body></html>')
            page.add_style_tag(content=(root / folder / 'css/style.css').read_text())
            page.evaluate('''fixture => {
              window.fixture = fixture; window.Notification = undefined;
              let paused = false, kind = 'pause', usedLunch = false;
              window.fetch = async (path, options={}) => {
                const url = new URL(path,'https://example.test'), key = url.pathname;
                let data = {};
                if (key === '/api/me') data = fixture.me;
                if (key === '/api/presence/mine') data = fixture.report;
                if (key === '/api/presence') data = {...fixture.report, rows:fixture.people};
                if (key === '/api/presence/pause') { const b = JSON.parse(options.body); paused = b.paused; kind = b.kind || 'pause'; if (paused && kind === 'lunch') usedLunch = true; }
                if (key === '/api/presence/pause' || key === '/api/presence/heartbeat') data = {status:paused?'pause':'online',paused,pause_kind:kind,lunch_used_today:usedLunch,pause_alert:'normal',last_seen:fixture.report.server_now,server_now:fixture.report.server_now,pause_since:paused?fixture.report.server_now:null};
                if (key === '/api/counters') data = {overdue:4,today:15,goal:20};
                if (key === '/api/prospects' || key === '/api/journal') data = {rows:[],total:0};
                if (key === '/api/announcements') data = [];
                if (key === '/api/stats') data = {calls:[],status:[],hours:[]};
                return {ok:true,status:200,json:async()=>data};
              };
            }''', fixture)
            page.add_script_tag(content=(root / folder / 'js/app.js').read_text())
            if role == 'admin':
                page.locator('.crm-sidebar [data-k=commerciaux]').click()
                page.locator('.team-kpis').wait_for()
                assert page.locator('.commercial-outcome-detail').count() == 3
                assert page.locator('.presence-overview .pause-overdue').count() == 2
                assert 'Transmis sur Plateforme' in page.locator('.commercial-outcome-detail').first.inner_text()
            else:
                page.locator('.day-work-time').wait_for()
                assert page.locator('.crm-sidebar [data-k=journee]').get_attribute('aria-current') == 'page'
                page.locator('.day-start').click()
                assert page.locator('.dossier-states [data-k=nouveaux]').get_attribute('aria-pressed') == 'true'
                page.locator('.crm-sidebar [data-k=journee]').click()
                page.locator('.day-work-time').wait_for()
            assert page.locator('.crm-sidebar').bounding_box()['x'] < page.locator('.crm-main').bounding_box()['x']
            page.screenshot(path=str(out / f'{app_id}-{role}-desktop.png'), full_page=True)
            page.set_viewport_size(dict(width=390, height=844))
            assert page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 2'), 'Page overflow outside its table scroll container'
            page.screenshot(path=str(out / f'{app_id}-{role}-mobile.png'), full_page=True)
            if role == 'commercial':
                page.locator('.lunch-break-button').click()
                page.locator('.pause-screen[open]').wait_for()
                assert page.locator('#' + app_id).get_attribute('inert') is not None
                assert 'Pause déjeuner' in page.locator('#pause-title').inner_text()
                page.locator('.pause-screen button').click()
                page.locator('.pause-screen').wait_for(state='detached')
                assert page.locator('.lunch-break-button').is_disabled()
            assert not errors, errors
            print(f'PASS {app_id} {role}: desktop/mobile layout, role navigation and workflows')
            page.close()
    browser.close()
