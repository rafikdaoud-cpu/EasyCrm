const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const {JSDOM} = require('jsdom');
const source = fs.readFileSync(require('node:path').join(__dirname, '../js/app.js'), 'utf8');
function setup(role = 'admin') {
  const dom = new JSDOM('<!doctype html><body><button id="previous">Fiche client</button></body>', {pretendToBeVisual:true});
  const calls = [];
  const context = vm.createContext({document:dom.window.document, window:dom.window, Node:dom.window.Node, root:dom.window.document.body,
    setTimeout:() => 0, setInterval:() => 0, clearInterval:() => {},
    api:async (path, options) => { calls.push({path, options}); return context.respond(path.split('?')[0], options, path); },
    respond:async () => ({sent:1}), fmt:String});
  vm.runInContext(`let me = ${JSON.stringify({role, can:{}, commerciaux:[{uid:'alice', name:'Alice'},{uid:'bob', name:'Bob'}]})};
    const T = x => x; const TEL = {wa:'api'};
    let popup = false, tab = 'annonce'; let TABS = [], KINDS = {}, ST = {}, STD = {}, STC = {}, RES = [];
    const badge = k => h('span', {}, ST[k] || k);
    const list = k => { globalThis.lastList = k; };
    const waList = () => {}, stats = () => {}, admin = () => {}, openSim = () => {}, show = (...nodes) => document.body.replaceChildren(...nodes);
    ${source.slice(source.indexOf('const h ='), source.indexOf('async function api('))}
    ${source.slice(source.indexOf('function applyStatuses()'), source.indexOf('const inkOn'))}
    ${source.slice(source.indexOf('function nav()'), source.indexOf('const modeOpts'))}
    ${source.slice(source.indexOf('const PRESENCE_LABELS'), source.indexOf('function announcementCard()'))}
    ${source.slice(source.indexOf('function announcementCard()'), source.indexOf('function admin()'))}
    globalThis.ui = {announcementCard, checkAnnouncements, nav, go, presenceControl, updateMyPresence, presenceBoard, refreshPresenceBoard, personalTimesCard, refreshPersonalTimes, setWhatsapp:value => { TEL.wa = value; }, callResponsesTable, openCommercialMessage, sortedCommercials, presencePeriodFilter, configure:(statuses, can) => { me.statuses = statuses; me.can = can; applyStatuses(); }, getPopup:() => popup};`, context);
  return {dom, document:dom.window.document, calls, context, ui:context.ui};
}
const tick = () => new Promise(resolve => setImmediate(resolve));
test('Annonce tab visible to admin and supervisor, hidden from commercial and reader', () => {
  for (const role of ['admin','superviseur','commercial','lecture']) {
    const {ui} = setup(role);
    assert.equal(ui.nav().textContent.includes('Annonce'), ['admin','superviseur'].includes(role));
  }
});
test('individual and everyone sends use selected recipients and prevent double click', async () => {
  const {document,ui,calls,context,dom} = setup();
  document.body.append(ui.announcementCard());
  const checks = document.querySelectorAll('input[type=checkbox]');
  const button = document.querySelector('.card button'), text = document.querySelector('textarea');
  button.click(); assert.equal(calls.length,0);
  checks[1].checked = true; text.value = 'Bonjour Alice';
  button.click(); button.click(); await tick();
  assert.equal(calls.length,1); assert.deepEqual(Array.from(calls[0].options.body.uids),['alice']);
  assert.equal(text.value,''); assert.equal(button.disabled,false);
  context.respond = async () => ({sent:2});
  checks[0].checked = true; checks[0].dispatchEvent(new dom.window.Event('change'));
  text.value = 'Réunion'; button.click(); await tick();
  assert.deepEqual(Array.from(calls[1].options.body.uids),['alice','bob']);
  assert.match(document.body.textContent,/envoyé à 2/);
});
test('failed send retains draft and allows retry', async () => {
  const {document,ui,context} = setup();
  context.respond = async () => { throw Error('Réseau indisponible'); };
  document.body.append(ui.announcementCard());
  document.querySelectorAll('input')[1].checked = true;
  const text = document.querySelector('textarea'); text.value = 'À conserver';
  const button = document.querySelector('.card button'); button.click(); await tick();
  assert.equal(text.value,'À conserver'); assert.equal(button.disabled,false);
  assert.match(document.body.textContent,/Réseau indisponible/);
});
test('popup escapes message and sender, persists on failed dismissal and drains queue', async () => {
  const {document,ui,context,calls} = setup('commercial');
  const messages = [{id:1,sender_name:'<img src=x onerror=alert(1)>',body:'<script>bad()</script>\nBonjour',created_at:1},
    {id:2,sender_name:'Superviseur',body:'Deuxième message',created_at:2}];
  let fail = true;
  context.respond = async (path,options) => {
    if (!options) return messages;
    if (fail) throw Error('hors ligne');
    messages.shift(); return {ok:true};
  };
  document.querySelector('#previous').focus();
  await ui.checkAnnouncements(); await ui.checkAnnouncements();
  assert.equal(document.querySelectorAll('[role=dialog]').length,1);
  assert.equal(document.querySelectorAll('script,img').length,0);
  assert.match(document.querySelector('.announcement-body').textContent,/<script>/);
  document.querySelector('.announcement-popup button').click(); await tick();
  assert.equal(ui.getPopup(),true); assert.match(document.body.textContent,/Fermeture non enregistrée/);
  fail = false; document.querySelector('.announcement-popup button').click(); await tick();
  assert.match(document.querySelector('[role=dialog]').textContent,/Deuxième message/);
  document.querySelector('.announcement-popup button').click(); await tick();
  assert.equal(document.querySelector('[role=dialog]'),null); assert.equal(ui.getPopup(),false);
  assert.equal(document.activeElement.id,'previous');
  assert.ok(calls.some(c => c.path === '/announcements/1/dismiss' && c.options.method === 'POST'));
});
test('poll failure retries and concurrent polls do not create duplicate popups', async () => {
  const {context,ui,document} = setup('commercial');
  context.respond = async () => { throw Error('offline'); }; await ui.checkAnnouncements();
  assert.equal(ui.getPopup(),false);
  let finish; context.respond = () => new Promise(resolve => { finish = resolve; });
  const first = ui.checkAnnouncements(); await ui.checkAnnouncements();
  finish([{id:1,sender_name:'Admin',body:'Bonjour',created_at:1}]); await first;
  assert.equal(document.querySelectorAll('[role=dialog]').length,1);
});

test('client states grouped under Dossiers Clients and reopen on À traiter', () => {
  const {ui, context} = setup('admin');
  ui.configure([{key:'rappel', label:'Rappel', result:true, active:true, callback:true},
    {key:'interesse', label:'Intéressé', result:true, active:true},
    {key:'ancien', label:'Ancien', result:true, active:false}], {all:true});
  ui.go('dossiers');
  let navigation = ui.nav();
  assert.equal(context.lastList,'nouveaux');
  assert.equal(navigation.querySelector('.nav button.on').textContent,'Dossiers Clients');
  assert.equal(navigation.querySelector('.dossier-states button.on').textContent,'À traiter');
  assert.deepEqual(Array.from(navigation.querySelectorAll('.dossier-states button'), b => b.textContent),
    ['À traiter','À rappeler','Intéressé','Tous']);
  assert.equal(navigation.querySelector('.nav button[data-k="rappels"]'),null);
  navigation.querySelector('.dossier-states button[data-k="st_interesse"]').click();
  assert.equal(context.lastList,'st_interesse');
  assert.equal(ui.nav().querySelector('.dossier-states button.on').textContent,'Intéressé');
  ui.go('stats'); navigation = ui.nav();
  assert.equal(navigation.querySelector('.dossier-states'),null);
  navigation.querySelector('.nav button[data-k="dossiers"]').click();
  assert.equal(context.lastList,'nouveaux');
});
test('commercial keeps permitted states without the all-clients shortcut', () => {
  const {ui} = setup('commercial');
  ui.configure([], {all:false}); ui.go('dossiers');
  assert.deepEqual(Array.from(ui.nav().querySelectorAll('.dossier-states button'), b => b.textContent), ['À traiter']);
});

test('commercial heartbeat and pause button use server state; failed pause does not claim success', async () => {
  const {ui,context,document,calls} = setup('commercial');
  document.body.append(ui.presenceControl());
  assert.equal(document.querySelector('.presence-control button').disabled,true);
  context.respond = async () => ({status:'online', paused:false, last_seen:1});
  await ui.updateMyPresence();
  assert.match(document.querySelector('.presence-badge').textContent,/En poste/);
  context.respond = async () => ({status:'pause', paused:true, last_seen:2});
  document.querySelector('.presence-control button').click(); await tick();
  assert.equal(calls.at(-1).path,'/presence/pause');
  assert.equal(calls.at(-1).options.body.paused,true);
  assert.match(document.querySelector('.presence-control button').textContent,/Reprendre/);
  await ui.updateMyPresence();
  assert.equal(calls.at(-1).path,'/presence/heartbeat');
  assert.equal(calls.at(-1).options.body,undefined);
  context.respond = async () => { throw Error('offline'); };
  document.querySelector('.presence-control button').click(); await tick();
  assert.match(document.querySelector('.presence-badge').textContent,/non confirmé/);
  assert.match(document.querySelector('.presence-control button').textContent,/Reprendre/);
  context.respond = async () => ({status:'online', paused:false, last_seen:3});
  document.querySelector('.presence-control button').click(); await tick();
  assert.equal(calls.at(-1).options.body.paused,false);
  assert.match(document.querySelector('.presence-badge').textContent,/En poste/);
});
test('only admin and supervisor see Commerciaux; their pages do not send heartbeats', async () => {
  for (const role of ['admin','superviseur','commercial','lecture']) {
    const {ui,calls} = setup(role);
    assert.equal(!!ui.nav().querySelector('[data-k=commerciaux]'), ['admin','superviseur'].includes(role));
    if (role !== 'commercial') { await ui.updateMyPresence(); assert.equal(calls.length,0); }
  }
});

test('roster shows all three states and removes stale information after a failed refresh', async () => {
  const {ui,context,document} = setup('superviseur');
  context.respond = async () => ({range:{from:'2026-11-02',to:'2026-11-02'},server_now:200,timezone:'Africa/Algiers',rows:[
    {uid:'alice',name:'Alice',status:'online',last_seen:123},
    {uid:'bob',name:'Bob',status:'pause',last_seen:120},
    {uid:'carol',name:'Carol',status:'absent',last_seen:0}].map(u => ({...u,processed:2,totals:{selected:{online:3600,pause:600},day:{online:3600,pause:600},week:{online:7200,pause:1200},month:{online:10800,pause:1800}}}))});
  ui.go('commerciaux'); await tick();
  assert.equal(document.querySelectorAll('tbody tr').length,3);
  assert.deepEqual(Array.from(document.querySelectorAll('.presence-badge'), b => b.textContent), ['En poste','En pause','Absent']);
  assert.match(document.body.textContent,/01:00:00/);
  assert.match(document.body.textContent,/Dossiers traités/);
  assert.match(document.body.textContent,/1 en poste · 1 en pause · 1 absent/);
  context.respond = async () => { throw Error('offline'); };
  await ui.refreshPresenceBoard();
  assert.equal(document.querySelector('tbody'),null);
  assert.match(document.body.textContent,/Impossible de vérifier la présence/);
});

test('pause screen blocks CRM, shows server stopwatch, and survives failed resume', async () => {
  const {ui,context,document,dom,calls} = setup('commercial');
  context.respond = async () => ({status:'pause',paused:true,pause_since:40,server_now:100,last_seen:100});
  await ui.updateMyPresence();
  const dialog = document.querySelector('.pause-screen');
  assert.ok(dialog); assert.ok(document.body.hasAttribute('inert'));
  assert.equal(dialog.querySelector('[role=timer]').textContent,'00:01:00');
  const cancel = new dom.window.Event('cancel',{cancelable:true}); dialog.dispatchEvent(cancel);
  assert.equal(cancel.defaultPrevented,true);
  await ui.updateMyPresence(); assert.equal(document.querySelectorAll('.pause-screen').length,1);
  const count = calls.length; await ui.checkAnnouncements(); assert.equal(calls.length,count);
  context.respond = async () => { throw Error('offline'); };
  dialog.querySelector('button').click(); await tick();
  assert.ok(document.querySelector('.pause-screen')); assert.ok(document.body.hasAttribute('inert'));
  assert.match(dialog.querySelector('.pause-error').textContent,/non confirmé/);
  context.respond = async () => ({status:'online',paused:false,pause_since:null,server_now:101,last_seen:101});
  dialog.querySelector('button').click(); await tick();
  assert.equal(document.querySelector('.pause-screen'),null); assert.equal(document.body.hasAttribute('inert'),false);
});
test('full application reload restores blocking pause; unavailable presence blocks entry', async () => {
  for (const offline of [false,true]) {
    const dom = new JSDOM('<!doctype html><head></head><body><div id="easycrm" data-api="/api"></div></body>',
      {url:'https://example.test',pretendToBeVisual:true,runScripts:'outside-only'});
    const w = dom.window;
    w.setTimeout = () => 0; w.setInterval = () => 0;
    w.fetch = async rawPath => {
      const path = rawPath.split('?')[0];
      let data = {};
      if (path === '/api/me') data = {uid:'alice',role:'commercial',can:{},statuses:[],groups:[],commerciaux:[],fields:[],prefs:{},options:{}};
      if (path === '/api/presence/heartbeat') {
        if (offline) return {ok:false,status:503,json:async () => ({error:'offline'})};
        data = {status:'pause',paused:true,pause_since:40,server_now:100,last_seen:100};
      }
      if (path === '/api/prospects') data = {rows:[],total:0};
      return {ok:true,json:async () => data};
    };
    w.eval(source); await tick(); await tick();
    const root = w.document.querySelector('#easycrm');
    if (offline) {
      assert.match(root.textContent,/Impossible de vérifier votre état/);
      assert.equal(root.querySelector('.nav'),null);
    } else {
      assert.ok(w.document.querySelector('.pause-screen'));
      assert.equal(root.hasAttribute('inert'),true);
    }
    w.close();
  }
});

test('commercial personal statistics show day, week and month work/pause and handle failed refresh', async () => {
  const {ui,context,document,calls} = setup('commercial');
  ui.go('stats'); document.body.append(ui.personalTimesCard());
  context.respond = async () => ({range:{from:'2026-11-02',to:'2026-11-02'},processed:2,timezone:'Africa/Algiers',totals:{selected:{online:3600,pause:600},day:{online:3600,pause:600},week:{online:36000,pause:3600},month:{online:180000,pause:7200}}});
  await ui.refreshPersonalTimes();
  assert.equal(calls.at(-1).path,'/presence/mine?period=day');
  assert.equal(calls.at(-1).options,undefined);
  const section = document.querySelector('[aria-label="Mes temps de présence"]');
  assert.equal(section.querySelectorAll('.presence-totals .card').length,3);
  for (const expected of ['Temps travaillé','Temps de pause','Dossiers traités','01:00:00','00:10:00']) assert.ok(section.textContent.includes(expected));
  context.respond = async () => { throw Error('offline'); };
  await ui.refreshPersonalTimes();
  assert.match(section.textContent,/Impossible de charger vos durées/);
  assert.equal(section.querySelector('.presence-totals'),null);
  ui.go('wa'); const count = calls.length; await ui.refreshPersonalTimes(); assert.equal(calls.length,count);
});
test('other roles never request personal commercial counters', async () => {
  for (const role of ['admin','superviseur','lecture']) {
    const {ui,document,calls} = setup(role);
    ui.go('stats'); document.body.append(ui.personalTimesCard()); await ui.refreshPersonalTimes();
    assert.equal(calls.length,0);
  }
});

test('clicking a commercial opens targeted message; failed send preserves draft and success prevents duplicate send', async () => {
  const {ui,context,document,calls} = setup('superviseur');
  let fail = true;
  context.respond = async path => {
    if (path === '/presence') return {range:{from:'2026-11-02',to:'2026-11-02'},server_now:200,timezone:'Africa/Algiers',rows:[{uid:'alice',name:'Alice',status:'online',processed:2,totals:{selected:{online:1,pause:0},day:{online:1,pause:0},week:{online:1,pause:0},month:{online:1,pause:0}}}]};
    if (fail) throw Error('offline'); return {sent:1};
  };
  ui.go('commerciaux'); await tick();
  document.querySelector('[aria-label="Envoyer un message à Alice"]').click();
  const dialog = document.querySelector('.commercial-message');
  assert.ok(dialog); assert.match(dialog.textContent,/Message à Alice/);
  const text = dialog.querySelector('textarea'); text.value = 'Merci de me rappeler';
  const send = dialog.querySelector('button'); send.click(); await tick();
  assert.equal(text.value,'Merci de me rappeler'); assert.match(dialog.textContent,/Envoi impossible/);
  assert.deepEqual(Array.from(calls.at(-1).options.body.uids),['alice']);
  fail = false; send.click(); send.click(); await tick();
  assert.match(dialog.textContent,/Message envoyé à Alice/); assert.equal(send.disabled,true);
  assert.equal(calls.filter(x => x.path === '/announcements').length,2);
  dialog.querySelectorAll('button')[1].click();
  assert.equal(document.querySelector('.commercial-message'),null); assert.equal(document.body.hasAttribute('inert'),false);
});
test('commercial and read-only roles cannot open direct message composer', () => {
  for (const role of ['commercial','lecture']) {
    const {ui,document} = setup(role); ui.openCommercialMessage({uid:'alice',name:'Alice'});
    assert.equal(document.querySelector('.commercial-message'),null);
  }
});
test('full Mes statistiques page includes personal work and pause counters', async () => {
  const dom = new JSDOM('<!doctype html><head></head><body><div id="easycrm" data-api="/api"></div></body>',
    {url:'https://example.test',pretendToBeVisual:true,runScripts:'outside-only'});
  const w = dom.window; w.setTimeout = () => 0; w.setInterval = () => 0;
  w.fetch = async rawPath => {
      const path = rawPath.split('?')[0];
    let data = {};
    if (path === '/api/me') data = {uid:'alice',role:'commercial',can:{},statuses:[],groups:[],commerciaux:[],fields:[],prefs:{},options:{c2:[]}};
    if (path === '/api/presence/heartbeat') data = {status:'online',paused:false,server_now:100,last_seen:100};
    if (path === '/api/prospects' || path === '/api/journal') data = {rows:[],total:0};
    if (path === '/api/stats') data = {calls:[],status:[],hours:[]};
    if (path === '/api/announcements') data = [];
    if (path === '/api/presence/mine') data = {range:{from:'2026-11-02',to:'2026-11-02'},processed:2,timezone:'Africa/Algiers',totals:{selected:{online:3600,pause:600},day:{online:3600,pause:600},week:{online:36000,pause:3600},month:{online:180000,pause:7200}}};
    return {ok:true,json:async () => data};
  };
  w.eval(source); await tick(); await tick();
  w.document.querySelector('[data-k=stats]').click(); await tick(); await tick();
  assert.match(w.document.querySelector('[aria-label="Mes temps de présence"]').textContent,/01:00:00/);
  assert.match(w.document.body.textContent,/Journal des appels/);
  w.close();
});
test('personal period selector validates manual dates, preserves them on refresh and sorts outcome counts', async () => {
  const {ui,context,document,calls,dom} = setup('commercial');
  ui.configure([{key:'rappel',label:'À rappeler',result:true,active:true,callback:true},{key:'interesse',label:'Intéressé',result:true,active:true}],{});
  context.respond = async (base,options,path) => {
    const p = new URL('https://example.test'+path).searchParams;
    return {timezone:'Africa/Algiers',range:{from:p.get('from') || '2026-11-02',to:p.get('to') || '2026-11-02'},
      totals:{selected:{online:3600,pause:600}},processed:7,by_result:{rappel:2,interesse:5}};
  };
  ui.go('stats'); document.body.append(ui.personalTimesCard()); await ui.refreshPersonalTimes();
  const section = document.querySelector('[aria-label="Mes temps de présence"]');
  const period = section.querySelector('[aria-label=Période]');
  period.value = 'custom'; period.dispatchEvent(new dom.window.Event('change'));
  const from = section.querySelector('[aria-label=Du]'), to = section.querySelector('[aria-label=Au]');
  from.value = '2026-11-03'; to.value = '2026-11-02'; const count = calls.length;
  section.querySelector('.presence-dates button').click(); await tick(); assert.equal(calls.length,count);
  assert.match(section.textContent,/égale ou postérieure/);
  from.value = '2026-10-01'; section.querySelector('.presence-dates button').click(); await tick();
  assert.match(calls.at(-1).path,/period=custom&from=2026-10-01&to=2026-11-02/);
  assert.match(section.textContent,/2026-10-01/); assert.match(section.textContent,/Dossiers traités/);
  await ui.refreshPersonalTimes(); assert.match(calls.at(-1).path,/period=custom/);
  section.querySelector('[data-sort-key=number]').click();
  section.querySelector('[data-sort-key=number]').click();
  assert.match(section.querySelector('.personal-results tbody tr').textContent,/Intéressé.*5/);
  assert.equal(section.querySelector('[data-sort-key=number]').closest('th').getAttribute('aria-sort'),'descending');
  period.value = 'week'; period.dispatchEvent(new dom.window.Event('change')); await tick();
  assert.equal(calls.at(-1).path,'/presence/mine?period=week');
});
test('roster supports numeric, name and outcome sorting and shares its selected range with all counters', async () => {
  const {ui,context,document,calls,dom} = setup('superviseur');
  ui.configure([{key:'rappel',label:'À rappeler',result:true,active:true,callback:true}],{});
  context.respond = async () => ({server_now:200,timezone:'Africa/Algiers',range:{from:'2026-11-02',to:'2026-11-02'},rows:[
    {uid:'bob',name:'Bob',status:'online',processed:2,by_result:{rappel:2},totals:{selected:{online:20,pause:0}}},
    {uid:'alice',name:'Alice',status:'pause',processed:10,by_result:{rappel:1,interesse:9},totals:{selected:{online:4,pause:10}}}]});
  ui.go('commerciaux'); await tick();
  const names = () => Array.from(document.querySelectorAll('tbody .commercial-message-link'),x => x.textContent);
  assert.deepEqual(names(),['Alice','Bob']);
  assert.equal(document.querySelector('[aria-label="Trier les commerciaux"]'),null);
  document.querySelector('[data-sort-key=processed]').click(); assert.deepEqual(names(),['Bob','Alice']);
  assert.equal(document.querySelector('[data-sort-key=processed]').closest('th').getAttribute('aria-sort'),'ascending');
  document.querySelector('[data-sort-key=processed]').click(); assert.deepEqual(names(),['Alice','Bob']);
  assert.equal(document.querySelector('[data-sort-key=processed]').closest('th').getAttribute('aria-sort'),'descending');
  document.querySelector('[data-sort-key="result:rappel"]').click();
  document.querySelector('[data-sort-key="result:rappel"]').click(); assert.deepEqual(names(),['Bob','Alice']);
  assert.equal(document.activeElement.dataset.sortKey,'result:rappel');
  assert.match(document.querySelector('thead').textContent,/À rappeler/);
  const period = document.querySelector('[aria-label=Période]'); period.value = 'month'; period.dispatchEvent(new dom.window.Event('change')); await tick();
  assert.equal(calls.at(-1).path,'/presence?period=month'); assert.deepEqual(names(),['Bob','Alice']);
  period.value = 'custom'; period.dispatchEvent(new dom.window.Event('change'));
  document.querySelector('[aria-label=Du]').value = '2026-10-01'; document.querySelector('[aria-label=Au]').value = '2026-10-31';
  document.querySelector('.presence-dates button').click(); await tick();
  assert.match(calls.at(-1).path,/period=custom&from=2026-10-01&to=2026-10-31/);
});

test('EasyCrm keeps WhatsApp navigation conditional on its configured integration', () => {
  const {ui} = setup('admin');
  ui.setWhatsapp('link'); assert.equal(ui.nav().querySelector('[data-k=wa]'),null);
  ui.setWhatsapp('api'); assert.ok(ui.nav().querySelector('[data-k=wa]'));
});

test('statistics include every configured or historical response type, zero counts and clickable sorting', () => {
  const {ui,document} = setup('admin');
  const types = [['rappel','À rappeler (occupé)',18],['interesse','Intéressé',8],['injoignable','Ne répond pas',9],['non_eligible','Non éligible',7],['pas_interesse','Pas intéressé',4],['plateforme','Transmis sur Plateforme',1],['verifier','À vérifier',0]];
  ui.configure(types.map(([key,label]) => ({key,label,result:true,active:true})),{});
  document.body.append(ui.callResponsesTable(types.filter(([, ,n]) => n).map(([result,,n]) => ({user_id:'alice',result,n})).concat([{user_id:'bob',result:'interesse',n:2}])));
  for (const [,label] of types) assert.ok(document.querySelector('thead').textContent.includes(label));
  const cells = () => Array.from(document.querySelectorAll('tbody tr')).find(x => x.firstChild.textContent === 'Alice');
  assert.deepEqual(Array.from(cells().querySelectorAll('td')).slice(1).map(x => x.textContent),['18','8','9','7','4','1','0']);
  document.querySelector('[data-sort-key=rappel]').click(); assert.match(document.querySelector('tbody tr').textContent,/^Bob/);
  document.querySelector('[data-sort-key=rappel]').click(); assert.match(document.querySelector('tbody tr').textContent,/^Alice/);
});
