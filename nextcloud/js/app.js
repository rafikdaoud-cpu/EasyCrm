(() => {
const T = window.EC_T || (s => s);
const TF = (s, ...a) => T(s).replace(/\{(\d)\}/g, (_, i) => a[+i]);
const root = document.getElementById('easycrm');
const API = root.dataset.api;
const tok = () => document.head.dataset.requesttoken || (window.OC && OC.requestToken) || '';
let ST = {}, RES = [], STD = {}, STC = {}, KINDS = {}, TABS = [];
function applyStatuses() {
  const all = me.statuses || [];
  ST = {}; STD = {}; STC = {};
  all.forEach(x => { ST[x.key] = T(x.label); STD[x.key] = x; STC[x.key] = x.color; });
  RES = all.filter(x => x.result && x.active).map(x => x.key);
  const act = all.filter(x => x.result && x.active);
  KINDS = {nouveaux: {t:T('À traiter'), status:'nouveau', asc:1}};
  TABS = [['nouveaux', T('À traiter')]];
  if (act.some(x => x.callback)) { KINDS.rappels = {t:T('À rappeler'), rappels:1}; TABS.push(['rappels', T('À rappeler')]); }
  act.filter(x => !x.callback).forEach(x => { KINDS['st_' + x.key] = {t:T(x.label), status:x.key}; TABS.push(['st_' + x.key, T(x.label)]); });
  KINDS.tous = {t:T('Tous les prospects'), all:1};
}
const inkOn = c => { const n = parseInt(String(c).slice(1), 16) || 0, l = (0.299 * (n >> 16) + 0.587 * ((n >> 8) & 255) + 0.114 * (n & 255)); return l > 160 ? '#000' : '#fff'; };
const badge = k => h('span', {class:'badge', style:'background:' + (STC[k] || '#888') + ';color:' + inkOn(STC[k] || '#888')}, ST[k] || k);
const fmtD = t => Number(t) ? new Date(t * 1000).toLocaleDateString(window.EC_LOCALE || 'fr-FR', {day:'2-digit', month:'2-digit', year:'numeric'}) : '';
const fmtS = t => t ? new Date(t * 1000).toLocaleString(window.EC_LOCALE || 'fr-FR', {day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit', second:'2-digit', hour12:false}) : '';
const fmt = t => t ? new Date(t * 1000).toLocaleString(window.EC_LOCALE || 'fr-FR', {day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit'}) : '';
const waNum = p => { const d = String(p || '').replace(/\D+/g, ''), ph = (me && me.phone) || {cc:'', trunk:''};
  if (!ph.cc) return d;
  return ph.cc + (ph.trunk && d.startsWith(ph.trunk) ? d.slice(ph.trunk.length) : d); };
const lab = k => T(((me && me.fields || []).find(f => f.key === k) || {}).label || k);
const nm = r => ((r.lastname || '') + ' ' + (r.firstname || '')).trim();
const h = (t, a = {}, ...c) => {
  const e = document.createElement(t);
  for (const [k, v] of Object.entries(a)) {
    if (k === 'class') e.className = v;
    else if (k.startsWith('on')) e.addEventListener(k.slice(2), v);
    else if (v !== null && v !== undefined && v !== false) e.setAttribute(k, v);
  }
  for (const x of c.flat()) e.append(x instanceof Node ? x : document.createTextNode(x ?? ''));
  return e;
};
async function api(path, opt = {}) {
  const o = {method: opt.method || 'GET', headers: {requesttoken: tok(), Accept: 'application/json'}};
  if (opt.body instanceof FormData) o.body = opt.body;
  else if (opt.body) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(opt.body); }
  let r;
  try { r = await fetch(API + path, o); } catch (e) { const x = new Error(T('Connexion indisponible')); x.offline = true; throw x; }
  const j = await r.json().catch(() => ({}));
  if (!r.ok) throw new Error(j.error ? T(j.error) : (T('Erreur ') + r.status));
  return j;
}
// ---- file d'attente hors ligne (enregistrement d'appels différé) ----
const QK = 'easycrm_queue';
const qGet = () => { try { return JSON.parse(localStorage.getItem(QK) || '[]'); } catch (e) { return []; } };
const qSet = a => { try { localStorage.setItem(QK, JSON.stringify(a)); } catch (e) {} };
let flushing = false, lastFlushMsg = '';
const banner = h('div', {style:'position:fixed;left:0;right:0;bottom:0;z-index:9999;padding:8px 12px;text-align:center;font-size:14px;display:none;color:#fff'});
function paintBanner() {
  const n = qGet().length, off = !navigator.onLine;
  banner.style.display = (n || off || lastFlushMsg) ? '' : 'none';
  banner.style.background = off ? '#c0392b' : n ? '#e9a100' : '#2e9e55';
  if (!off && !n) banner.style.color = '#fff'; else banner.style.color = off ? '#fff' : '#000';
  banner.textContent = (off ? T('Hors ligne') : '') + (off && n ? ' — ' : '') + (n ? n + T(' appel(s) en attente d’envoi') + (off ? '' : ' …') : '') + (!off && !n ? lastFlushMsg : '');
}
const queueCall = (id, body) => { const q = qGet(); q.push({id, body, ts: Date.now()}); qSet(q); lastFlushMsg = ''; paintBanner(); };
async function flushQueue() {
  if (flushing || !navigator.onLine) return paintBanner();
  let q = qGet(); if (!q.length) return paintBanner();
  flushing = true; let sent = 0, dropped = 0;
  while (q.length) {
    const it = q[0];
    try { await api('/prospects/' + it.id + '/call', {method:'POST', body: it.body}); sent++; }
    catch (e) { if (e.offline) break; dropped++; }
    q.shift(); qSet(q);
  }
  flushing = false;
  lastFlushMsg = (sent ? sent + T(' appel(s) envoyé(s). ') : '') + (dropped ? dropped + T(' refusé(s) par le serveur (fiche supprimée ou droits).') : '');
  paintBanner();
  if (lastFlushMsg) setTimeout(() => { lastFlushMsg = ''; paintBanner(); }, 8000);
}
window.addEventListener('online', flushQueue); window.addEventListener('offline', paintBanner);
setInterval(flushQueue, 30000);
let sortBy = {k:'', d:'desc'};
const sel = new Set();
let pageSize = 10; try { const v = localStorage.getItem('easycrm_ps2'); if (v !== null && [0, 10, 20, 30].includes(Number(v))) pageSize = Number(v); } catch (e) {}
let me, tab = 'nouveaux', filt = {q:'', status:'', assigned:''}, snooze = {}, popup = false;
try { snooze = JSON.parse(localStorage.getItem('easycrm_snooze') || '{}'); } catch (e) {}
const saveSnooze = () => { try { localStorage.setItem('easycrm_snooze', JSON.stringify(snooze)); } catch (e) {} };
const playRec = async (id, holder) => {
  holder.textContent = T('Chargement…');
  try {
    const r = await fetch(API + '/recordings/' + id, {headers:{requesttoken: tok()}});
    if (!r.ok) throw new Error(T('Erreur ') + r.status);
    const a = h('audio', {controls:true, autoplay:true, style:'width:100%'});
    a.src = URL.createObjectURL(await r.blob());
    holder.replaceChildren(a);
  } catch (e) { holder.textContent = e.message; }
};
const recRow = c => { const slot = h('div'); return h('div', {class:'card'}, '🎧 ', fmt(Number(c.recorded_at)), ' — ', c.user_name || c.user_id, c.phone ? ' — ' + c.phone : '',
  c.title && !c.prospect_id ? h('div', {class:'muted'}, c.title) : '', slot, h('button', {style:'margin-top:4px', onclick: () => playRec(c.id, slot)}, T('▶ Écouter'))); };
const fval = (p, f) => f.builtin ? (p[f.key] || '') : ((p.extra || {})[f.key] || '');
const collect = box => { const b = {}; box.querySelectorAll('[data-k]').forEach(e => {
  b[e.dataset.k] = e.dataset.multi ? Array.from(e.querySelectorAll('input:checked')).map(c => c.value).join(' | ') : e.value; }); return b; };
function fieldEl(f, val, disabled) {
  const st = 'width:100%;box-sizing:border-box', a = {'data-k': f.key, disabled, style: st};
  if (f.type === 'textarea') return h('textarea', {...a, rows: 3}, val);
  if (f.type === 'select') return h('select', a, h('option', {value:''}, '—'), (f.options || []).map(x => h('option', {value:x, selected: val === x}, x)));
  if (f.type === 'yesno') return h('select', a, h('option', {value:''}, '—'), [T('Oui'), T('Non')].map(x => h('option', {value:x, selected: val === x}, T(x))));
  if (f.type === 'multiselect') { const cur = String(val).split('|').map(x => x.trim());
    return h('div', {'data-k': f.key, 'data-multi': 1, style:'display:flex;flex-wrap:wrap;gap:4px 14px;padding:4px 0'},
      (f.options || []).map(x => h('label', {style:'display:flex;align-items:center;gap:4px'}, h('input', {type:'checkbox', value:x, checked: cur.includes(x), disabled}), x))); }
  return h('input', {...a, type: f.type === 'tel' ? 'tel' : f.type === 'date' ? 'date' : f.type === 'email' ? 'email' : 'text', inputmode: f.type === 'number' ? 'decimal' : undefined, value: val});
}
function fieldsGrid(p, disabled) {
  const vis = (me.fields || []).filter(f => !f.hidden);
  return h('div', {class:'g2'}, vis.map(f => h('label', {class: ['textarea', 'multiselect'].includes(f.type) || f.key === 'lastname' ? 'full' : '', style:'display:block;margin:5px 0'},
    h('span', {class:'muted'}, f.label + (f.required ? ' *' : '')), fieldEl(f, fval(p, f), disabled))));
}
const show = (...n) => { root.replaceChildren(nav(), ...n.flat()); paintCounters(); paintWa(); };
let CNT = {overdue:0, today:null, goal:0};
/** Badge des rappels en retard + progression de l'objectif quotidien (commerciaux). */
async function paintCounters() {
  try { CNT = await api('/counters'); } catch (e) { return; }
  const btn = root.querySelector('.nav button[data-k="rappels"]');
  if (btn) { btn.querySelector('.ovd')?.remove(); if (CNT.overdue) btn.append(h('span', {class:'ovd', title:T('Rappels en retard')}, String(CNT.overdue))); }
  const g = root.querySelector('.nav .goal');
  if (g && CNT.goal && CNT.today !== null) {
    const pc = Math.min(100, Math.round(100 * CNT.today / CNT.goal));
    g.replaceChildren(T('🎯 Aujourd’hui : ') + CNT.today + ' / ' + CNT.goal + T(' appels'), h('span', {class:'gbar'}, h('span', {style:'width:' + pc + '%'})));
  }
}
function nav() {
  const t = TABS.slice();
  if (me.can.all) t.push(['tous', T('Tous')]);
  if (TEL.wa === 'api') t.push(['wa', T('💬 WhatsApp')]);
  t.push(['stats', me.can.all ? T('Statistiques') : T('Mes statistiques')]);
  if (me.can.import) t.push(['admin', T('Administration')]);
  return h('div', {class:'nav'}, t.map(([k, l]) => h('button', {class: tab === k ? 'on' : '', 'data-k': k, onclick: () => go(k)}, l)),
    me.role === 'commercial' && me.prefs && me.prefs.goal ? h('span', {class:'goal muted'}) : '');
}
function go(k) { tab = k; if (k === 'wa') waList(); else if (k === 'stats') stats(); else if (k === 'admin') admin(); else list(k); }
const modeOpts = none => [
  h('option', {value:'categorie'}, T('Par catégorie (groupe de chaque catégorie)')),
  h('option', {value:'auto'}, T('Équitablement (tous les commerciaux)')),
  me.groups.map(g => h('option', {value:'grp:' + g.id}, T('Tout vers le groupe ') + g.name)),
  none ? h('option', {value:''}, T('Non assignés')) : ''].flat();
async function distribute(mode, out, uids) {
  out.textContent = '…';
  try { const r = await api('/distribute', {method:'POST', body:{mode, uids: uids || []}}); out.textContent = r.assigned + T(' prospect(s) répartis.'); }
  catch (e) { out.textContent = e.message; }
}
function distButtons() {
  const out = h('span', {class:'muted'}), s = h('select', {}, modeOpts(false));
  return h('div', {class:'bar'}, s, h('button', {onclick: () => distribute(s.value, out)}, T('Répartir les non assignés')), out);
}

async function list(k) {
  const K = KINDS[k];
  sel.clear(); let rowsNow = [];
  const box = h('div', {}, T('Chargement…'));
  const q = h('input', {type:'search', placeholder:T('Rechercher…'), value:filt.q});
  const bar = h('div', {class:'bar'}, q);
  let s, a;
  if (K.all) { s = h('select', {}, h('option', {value:''}, T('Tous statuts')), Object.entries(ST).map(([v, l]) => h('option', {value:v, selected: filt.status === v}, l))); bar.append(s); }
  if (me.can.all) { a = h('select', {}, h('option', {value:''}, T('Tous commerciaux')), me.commerciaux.map(u => h('option', {value:u.uid, selected: filt.assigned === u.uid}, u.name))); bar.append(a); }
  let page = 1;
  const SORTS = [['', T('Tri par défaut')], ['nom', T('Nom')], ['date', T('Date d’enregistrement')], ['statut', T('Statut')], ['wilaya', lab('c5')], ['categorie', lab('c2')], ['appel', T('Dernier appel')], ['rappel', T('Date de rappel')]];
  const so = h('select', {title:T('Trier par')}, SORTS.map(([v, l]) => h('option', {value:v, selected: sortBy.k === v}, l)));
  const sd = h('button', {title:T('Sens du tri'), onclick: () => { sortBy.d = sortBy.d === 'asc' ? 'desc' : 'asc'; sd.textContent = sortBy.d === 'asc' ? T('↑ Croissant') : T('↓ Décroissant'); load(); }}, sortBy.d === 'asc' ? T('↑ Croissant') : T('↓ Décroissant'));
  so.addEventListener('change', () => { sortBy.k = so.value; load(); });
  bar.append(so, sd);
  const rf = h('input', {type:'date'}), rt = h('input', {type:'date'}), pf = h('input', {type:'date'}), pt = h('input', {type:'date'});
  const dbar = h('div', {class:'bar'}, h('label', {}, T('Inscrit du '), rf), h('label', {}, T('au '), rt), h('label', {}, T('Traité du '), pf), h('label', {}, T('au '), pt),
    h('button', {onclick: () => { [rf, rt, pf, pt].forEach(x => x.value = ''); load(); }}, T('Effacer les dates')));
  const dts = (v, add) => v ? Math.floor(new Date(v + 'T00:00:00').getTime() / 1000) + add : '';
  const selAll = h('input', {type:'checkbox', title:T('Tout cocher (page)'), onchange: e => { rowsNow.forEach(x => e.target.checked ? sel.add(x.id) : sel.delete(x.id)); box.querySelectorAll('.rsel').forEach(c => { c.checked = e.target.checked; }); refreshSel(); }});
  const selCnt = h('span', {class:'muted'}), selMsg = h('span', {class:'muted'});
  const selTo = h('select', {}, h('option', {value:''}, T('Transférer vers…')), me.commerciaux.map(u => h('option', {value:u.uid}, u.name)));
  const selBtn = h('button', {class:'primary', onclick: async () => {
    if (!selTo.value) { selMsg.textContent = T('Choisissez le commercial destinataire.'); return; }
    const who = (me.commerciaux.find(u => u.uid === selTo.value) || {}).name || selTo.value;
    if (!confirm(T('Transférer ') + sel.size + T(' prospect(s) vers « ') + who + T(' » ?'))) return;
    selMsg.textContent = '…';
    try { const r = await api('/transfer', {method:'POST', body:{ids:[...sel], to:selTo.value}}); sel.clear(); selMsg.textContent = '✔ ' + r.transferred + T(' prospect(s) transféré(s).'); load(true); }
    catch (e) { selMsg.textContent = e.message; }
  }}, T('Transférer la sélection'));
  const stTo = h('select', {}, h('option', {value:''}, T('Changer le statut en…')), Object.entries(ST).map(([v, l]) => h('option', {value:v}, l)));
  const stUn = h('label', {style:'white-space:nowrap'}, h('input', {type:'checkbox'}), T(' puis non assignés'));
  const stBtn = h('button', {onclick: async () => {
    if (!stTo.value) { selMsg.textContent = T('Choisissez le nouveau statut.'); return; }
    if (!confirm(T('Passer ') + sel.size + T(' prospect(s) en « ') + ST[stTo.value] + ' »' + (stUn.firstChild.checked ? T(' et les rendre non assignés') : '') + T(' ? Les rappels prévus et le compteur de tentatives sont remis à zéro.'))) return;
    selMsg.textContent = '…';
    try { const r = await api('/bulkstatus', {method:'POST', body:{ids:[...sel], to_status:stTo.value, assign_mode: stUn.firstChild.checked ? 'none' : 'keep'}}); sel.clear(); selMsg.textContent = '✔ ' + r.changed + T(' prospect(s) mis à jour.'); load(true); }
    catch (e) { selMsg.textContent = e.message; }
  }}, T('Appliquer le statut'));
  const selBar = me.can.assign ? h('div', {class:'bar selbar'}, h('label', {}, selAll, T(' Tout cocher (page)')), selCnt, selTo, selBtn, stTo, stUn, stBtn, selMsg) : '';
  function refreshSel() { selCnt.textContent = sel.size + T(' sélectionné(s)'); selBtn.disabled = !sel.size; stBtn.disabled = !sel.size; selAll.checked = !!rowsNow.length && rowsNow.every(x => sel.has(x.id)); }
  const pst = 'justify-content:space-between;align-items:center;margin:8px 0';
  const pager = h('div', {class:'bar', style:pst}), pagerB = h('div', {class:'bar', style:pst});
  const load = async (keep) => {
    if (keep !== true) page = 1;
    filt = {q:q.value, status: s ? s.value : '', assigned: a ? a.value : ''};
    const p = new URLSearchParams({q:filt.q, status: K.status || filt.status, assigned:filt.assigned, rappels: K.rappels ? 1 : '', asc: K.asc ? 1 : '',
      reg_from: dts(rf.value, 0), reg_to: dts(rt.value, 86400), proc_from: dts(pf.value, 0), proc_to: dts(pt.value, 86400),
      sort:sortBy.k, dir:sortBy.d, paged:1, limit:pageSize, offset:(page - 1) * pageSize});
    try {
      const r = await api('/prospects?' + p);
      const pages = pageSize ? Math.max(1, Math.ceil(r.total / pageSize)) : 1;
      if (page > pages) { page = pages; return load(true); }
      rowsNow = r.rows;
      box.replaceChildren(...(r.rows.length ? r.rows.map(x => row(x, me.can.assign ? refreshSel : null)) : [h('p', {class:'muted'}, T('Aucun prospect.'))]));
      refreshSel();
      const go2 = n => { page = n; load(true); window.scrollTo(0, 0); };
      const mk = () => [
        h('span', {class:'muted'}, r.total + T(' fiche(s) client(s) — page ') + page + ' / ' + pages + (!pageSize && r.total > 5000 ? T(' (5000 premières affichées)') : '')),
        h('span', {}, h('button', {disabled: page <= 1, onclick: () => go2(page - 1)}, T('◀ Précédent')), ' ',
          h('button', {disabled: page >= pages, onclick: () => go2(page + 1)}, T('Suivant ▶')), ' ',
          h('select', {onchange: e => { pageSize = Number(e.target.value); try { localStorage.setItem('easycrm_ps2', String(pageSize)); } catch (x) {} load(); }},
            [10, 20, 30, 0].map(n => h('option', {value:n, selected: n === pageSize}, n ? n + T(' par page') : T('Tout')))))];
      pager.replaceChildren(...mk()); pagerB.replaceChildren(...mk());
    } catch (e) { box.replaceChildren(h('p', {class:'err'}, e.message)); }
  };
  [q, s, a, rf, rt, pf, pt].forEach(e => e && e.addEventListener('change', () => load()));
  show(h('h2', {}, K.t), me.can.create ? h('div', {class:'bar'}, h('button', {class:'primary', onclick: () => addForm()}, T('+ Ajouter un client'))) : '', me.can.assign && K.all ? distButtons() : '', bar, dbar, selBar, pager, box, pagerB); load();
}
async function addForm() {
  const msg = h('div', {class:'err'});
  const asg = me.can.assign ? h('label', {style:'display:block;margin:5px 0'}, h('span', {class:'muted'}, T('Assigner à')),
    h('select', {'data-k':'assigned_to', style:'width:100%;box-sizing:border-box'}, h('option', {value:''}, T('Non assigné')), me.commerciaux.map(u => h('option', {value:u.uid}, u.name)))) : '';
  const box = h('div', {class:'card'}, h('h3', {style:'margin-top:0'}, T('Nouveau client')),
    fieldsGrid({}, false), asg,
    h('button', {class:'primary', style:'margin-top:8px', onclick: async e => {
      msg.textContent = ''; e.target.disabled = true;
      try { const r = await api('/prospects', {method:'POST', body: collect(box)}); detail(r.id); }
      catch (x) { msg.textContent = x.message; e.target.disabled = false; }
    }}, T('Enregistrer')), msg);
  const phIn = box.querySelector('[data-k="phone"]');
  if (phIn) {
    const w = h('div', {class:'err'}); phIn.after(w);
    phIn.addEventListener('change', async () => {
      w.replaceChildren();
      if (!phIn.value.trim()) return;
      try {
        const r = await api('/duplicate?phone=' + encodeURIComponent(phIn.value));
        if (r.exists) w.replaceChildren(T('⚠ Ce numéro existe déjà') + (r.name ? ' : « ' + r.name + ' »' + (r.assigned ? T(' (assigné à ') + r.assigned + ')' : T(' (non assigné)')) : T(' (fiche d’un autre commercial)')) + '. ',
          r.id ? h('a', {href:'#', onclick: e => { e.preventDefault(); detail(r.id); }}, T('Ouvrir la fiche')) : '');
      } catch (e) {}
    });
  }
  show(h('button', {onclick: () => go(tab)}, T('← Retour')), box);
}
function row(r, onSel) {
  const late = r.callback_at && Number(r.callback_at) < Date.now() / 1000 && STD[r.status] && STD[r.status].callback;
  const ck = onSel ? h('input', {type:'checkbox', class:'rsel', checked: sel.has(r.id), onclick: e => { e.stopPropagation(); e.target.checked ? sel.add(r.id) : sel.delete(r.id); onSel(); }}) : '';
  return h('div', {class:'row' + (late ? ' overdue' : ''), onclick: () => detail(r.id)},
    ck ? h('div', {style:'display:flex;align-items:flex-start;padding-top:2px'}, ck) : '',
    h('div', {style:'flex:1'}, h('strong', {}, nm(r)), h('div', {class:'muted'}, [r.phone, r.c5, r.c2].filter(Boolean).join(' · ') || T('Sans numéro')),
      h('div', {class:'muted'}, T('Inscrit le ') + fmtD(r.created_at) + ' · ' + T('Traité le ') + (Number(r.last_call_at) ? fmtD(r.last_call_at) : '—'))),
    h('div', {style:'text-align:right'}, badge(r.status),
      r.callback_at ? h('div', {class: late ? 'late' : 'muted'}, (late ? T('⚠ En retard · ') : '⏰ ') + fmt(Number(r.callback_at))) : ''));
}

/** Note non modifiable : tentatives sans succès (réponses « avec date de rappel »), avec date et heure exactes. */
function attemptsBox(calls) {
  const a = (calls || []).filter(c => STD[c.result] && STD[c.result].callback).sort((x, y) => Number(x.created_at) - Number(y.created_at));
  if (!a.length) return '';
  const max = me.max_callbacks || 0;
  return h('div', {class:'card', style:'background:var(--color-background-dark,#f3f3f3);margin:8px 0', title:T('Note automatique, non modifiable')},
    h('strong', {}, T('🔒 Tentatives de rappel : ') + a.length + (max ? ' / ' + max : '')),
    ...a.map((c, i) => h('div', {}, T('Appel ') + (i + 1) + ' : ' + fmtS(Number(c.created_at)) + ' — ' + ST[c.result] + (c.user_id ? ' (' + c.user_id + ')' : ''))),
    max && a.length >= max ? h('div', {class:'err'}, T('Limite atteinte.')) : '');
}
async function detail(id) {
  let d;
  try { d = await api('/prospects/' + id); } catch (e) { return show(h('p', {class:'err'}, e.message)); }
  const p = d.prospect;
  let sel = null;
  const note = h('textarea', {rows:2, placeholder:T('Note')});
  const cb = h('input', {type:'datetime-local'});
  const P = me.prefs || {};
  const reason = h('select', {}, h('option', {value:''}, T('— Motif (obligatoire) —')), (P.reasons || []).map(x => h('option', {value:x}, x)));
  const msg = h('div', {class:'err'});
  const form = h('div', {class:'card', style:'display:none'});
  const hm = v => { const m = /^(\d{1,2}):(\d{2})$/.exec(v || ''); return m ? (+m[1]) * 60 + (+m[2]) : null; };
  const pad = n => String(n).padStart(2, '0');
  const toLocal = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  const winA = hm(P.cb_from), winB = hm(P.cb_to), gap = P.cb_gap || 0;
  /** ramène une date dans la plage de rappel autorisée */
  const fit = d => {
    if (winA === null || winB === null) return d;
    const m = d.getHours() * 60 + d.getMinutes();
    if (m < winA) d.setHours(Math.floor(winA / 60), winA % 60, 0, 0);
    else if (m > winB) { d.setDate(d.getDate() + 1); d.setHours(Math.floor(winA / 60), winA % 60, 0, 0); }
    return d;
  };
  const setCb = d => { cb.value = toLocal(fit(d)); };
  const inMin = n => setCb(new Date(Date.now() + Math.max(n, gap) * 60000));
  const tomorrow = () => { const d = new Date(); d.setDate(d.getDate() + 1); const a = winA === null ? 540 : winA; d.setHours(Math.floor(a / 60), a % 60, 0, 0); cb.value = toLocal(d); };
  const cbHint = (winA !== null && winB !== null ? T('Plage autorisée : ') + P.cb_from + ' – ' + P.cb_to : '') + (gap ? (winA !== null ? ' · ' : '') + T('espacement minimum : ') + gap + T(' min') : '');
  const checkCb = () => {
    if (!cb.value) return T('Date et heure de rappel obligatoires');
    const t = new Date(cb.value).getTime();
    if (gap && t < Date.now() + gap * 60000 - 120000) return T('Le rappel doit être programmé au moins ') + gap + T(' min après l’appel.');
    if (winA !== null && winB !== null) { const d = new Date(t), m = d.getHours() * 60 + d.getMinutes(); if (m < winA || m > winB) return T('Rappel hors de la plage autorisée (') + P.cb_from + ' – ' + P.cb_to + ').'; }
    return '';
  };
  const pick = r => {
    sel = r;
    document.querySelectorAll('#easycrm .res button').forEach(b => b.classList.toggle('on', b.dataset.r === r));
    const needCb = !!(STD[r] && STD[r].callback);
    note.placeholder = STD[r] && STD[r].note ? T('Note (obligatoire)') : T('Note (facultatif)');
    const needRs = !!(STD[r] && STD[r].reason);
    if (needCb && !cb.value) inMin(60);
    form.replaceChildren(needRs ? h('p', {style:'margin:0 0 6px'}, reason) : '', note,
      needCb ? h('p', {}, T('Date et heure de rappel :')) : '',
      needCb ? h('div', {class:'shortcuts'}, h('button', {onclick: () => inMin(60)}, T('Dans 1 h')), h('button', {onclick: () => inMin(120)}, T('Dans 2 h')), h('button', {onclick: () => inMin(240)}, T('Dans 4 h')), h('button', {onclick: tomorrow}, T('Demain'))) : '',
      needCb ? cb : '', needCb && cbHint ? h('div', {class:'muted'}, cbHint) : '',
      h('button', {class:'primary', style:'margin-top:8px', onclick: save}, T('Enregistrer l’appel et passer au suivant')), msg);
    form.style.display = '';
  };
  const next = async () => {
    tab = 'nouveaux';
    try {
      const rows = await api('/prospects?' + new URLSearchParams({status:'nouveau', asc:1}));
      const n = rows.find(r => String(r.id) !== String(id));
      if (n) return detail(n.id);
    } catch (e) {}
    list('nouveaux');
  };
  const save = async () => {
    msg.textContent = '';
    try {
      const needCb = !!(STD[sel] && STD[sel].callback);
      if (needCb) { const e1 = checkCb(); if (e1) { msg.textContent = e1; return; } }
      if (STD[sel] && STD[sel].reason && !reason.value) { msg.textContent = T('Un motif est obligatoire pour « ') + ST[sel] + ' ».'; return; }
      const body = {result:sel, note:note.value, reason: STD[sel] && STD[sel].reason ? reason.value : '', at: Math.floor(Date.now() / 1000), callback_at: needCb && cb.value ? Math.floor(new Date(cb.value).getTime() / 1000) : null};
      try { const r = await api('/prospects/' + id + '/call', {method:'POST', body}); if (r && r.auto_closed) alert(T('Limite de ') + r.max + T(' tentative(s) atteinte : ce prospect est passé automatiquement en « ') + (ST.faux_numero || T('Injoignable / faux numéro')) + ' ».'); }
      catch (e) {
        if (!e.offline) throw e;
        queueCall(id, body);
        form.replaceChildren(h('p', {}, T('✔ Appel enregistré sur cet appareil (hors ligne). Il sera envoyé automatiquement au retour de la connexion.')),
          h('button', {class:'primary', onclick: () => go('nouveaux')}, T('Revenir à la liste')));
        return;
      }
      await next();
    } catch (e) { msg.textContent = e.offline ? T('Connexion indisponible.') : e.message; }
  };
  const ro = !me.can.call;
  const imsg = h('div', {class:'err'});
  const info = h('div', {class:'card'}, h('h3', {style:'margin-top:0'}, T('Informations client')),
    fieldsGrid(p, ro),
    h('div', {class:'muted'}, T('Heure d’enregistrement : ') + fmt(Number(p.created_at))),
    ro ? '' : h('button', {class:'primary', style:'margin-top:8px', onclick: async () => {
      imsg.className = 'err'; imsg.textContent = '';
      try { await api('/prospects/' + id + '/update', {method:'POST', body: collect(info)}); imsg.className = 'muted'; imsg.textContent = T('Informations enregistrées.'); }
      catch (e) { imsg.textContent = e.message; }
    }}, T('Enregistrer les informations')), imsg);
  const asg = me.can.assign ? h('select', {onchange: async e => { try { await api('/prospects/' + id + '/assign', {method:'POST', body:{uid:e.target.value}}); } catch (x) { alert(x.message); } }},
    h('option', {value:''}, T('Non assigné')), me.commerciaux.map(u => h('option', {value:u.uid, selected: p.assigned_to === u.uid}, u.name))) : '';
  show(
    h('button', {onclick: () => go(tab)}, T('← Retour')),
    h('div', {class:'cols'},
      h('div', {},
        h('h2', {}, nm(p)), badge(p.status),
        h('div', {class:'muted'}, T('Date d’inscription : ') + fmt(Number(p.created_at))),
        h('div', {class:'muted'}, T('Date de traitement : ') + (Number(p.last_call_at) ? fmt(Number(p.last_call_at)) : '—')),
        p.callback_at ? h('div', {}, T('⏰ Rappel : ') + fmt(Number(p.callback_at))) : '',
        attemptsBox(d.calls.filter(c => Number(c.created_at) >= Number(p.reset_at || 0))),
        p.phone && !me.can.call ? h('p', {}, '📞 ' + p.phone) : '',
        p.phone && me.can.call ? (TEL.pbx === 'none' ? h('a', {class:'big', href:'tel:' + p.phone}, T('📞 Appeler ') + p.phone)
          : h('button', {class:'big', onclick: () => pbxCall(id)}, T('📞 Appeler via le standard ') + p.phone)) : (p.phone ? '' : h('p', {class:'muted'}, T('Aucun numéro de téléphone renseigné.'))),
        p.phone && me.can.call ? (TEL.wa === 'link' ? h('a', {class:'big', style:'background:#25D366;color:#fff', target:'_blank', rel:'noopener noreferrer', href:'https://wa.me/' + waNum(p.phone)}, T('💬 WhatsApp ') + p.phone)
          : h('button', {class:'big', style:'background:#25D366;color:#fff', onclick: () => waSend(id, p)}, T('💬 Envoyer un WhatsApp ') + p.phone)) : '',
        p.phone && TEL.wa === 'api' ? waOnProspect(id) : '',
        me.can.call ? h('div', {class:'res'}, RES.map(r => h('button', {'data-r': r, onclick: () => pick(r)}, ST[r]))) : h('p', {class:'muted'}, T('Consultation seule.')),
        form, asg,
        d.recordings && d.recordings.length ? [h('h3', {}, T('Enregistrements')), d.recordings.map(recRow)] : '',
        h('h3', {}, T('Historique')),
        d.calls.length ? d.calls.map(c => h('div', {class:'card'}, h('strong', {}, ST[c.result]), ' — ', fmt(Number(c.created_at)), ' — ', c.user_id,
          c.reason ? h('div', {}, T('Motif : ') + c.reason) : '', c.note ? h('div', {}, c.note) : '',  c.callback_at ? h('div', {class:'muted'}, T('Rappel prévu : ') + fmt(Number(c.callback_at))) : '')) : h('p', {class:'muted'}, T('Aucun appel.'))),
      h('div', {}, info,
        me.can.delete ? h('button', {onclick: async () => { if (confirm(T('Supprimer ce prospect ?'))) { await api('/prospects/' + id + '/delete', {method:'POST'}); go('tous'); } }}, T('Supprimer')) : '')));
}

const TEL = new Proxy({}, {get: (_, k) => ((me && me.tel) || {pbx:'none', wa:'link'})[k]});
async function pbxCall(id) {
  try {
    const r = await api('/prospects/' + id + '/pbxcall', {method:'POST'});
    if (r.url) { location.href = r.url; } else { alert(T(r.message)); }
  } catch (e) { alert(e.message); }
}
async function waSend(id, p) {
  const t = prompt(T('Message WhatsApp à envoyer'), TF(T('Bonjour {0}, '), p.firstname || ''));
  if (!t) return;
  try { const r = await api('/prospects/' + id + '/wasend', {method:'POST', body:{text:t}}); alert(T(r.message)); detail(id); }
  catch (e) { alert(e.message); }
}
function multi(label, opts) {
  const boxes = opts.map(o => h('label', {style:'display:block;padding:3px 10px;white-space:nowrap'}, h('input', {type:'checkbox', value:o.v}), ' ' + o.t));
  const sum = h('summary', {style:'cursor:pointer;padding:6px 10px;border:1px solid var(--color-border);border-radius:6px'}, label);
  const el = h('details', {style:'position:relative;display:inline-block'}, sum,
    h('div', {style:'position:absolute;z-index:50;background:var(--color-main-background);border:1px solid var(--color-border);border-radius:6px;padding:4px 0;max-height:260px;overflow:auto;min-width:210px'}, boxes));
  const values = () => boxes.map(l => l.firstChild).filter(i => i.checked).map(i => i.value);
  boxes.forEach(l => l.firstChild.addEventListener('change', () => { const n = values().length; sum.textContent = n ? label + ' (' + n + ')' : label; }));
  return {el, values};
}

function statusChart(rows) {
  const cnt = {}; rows.forEach(r => { cnt[r.status] = Number(r.n); });
  const keys = Object.keys(ST), total = keys.reduce((a, k) => a + (cnt[k] || 0), 0), max = Math.max(1, ...keys.map(k => cnt[k] || 0));
  if (!total) return h('p', {class:'muted'}, T('Aucun prospect.'));
  return h('div', {class:'card'}, h('div', {class:'muted', style:'margin-bottom:6px'}, T('Total : ') + total + T(' dossier(s)')),
    keys.map(k => { const n = cnt[k] || 0;
      return h('div', {style:'display:flex;align-items:center;gap:8px;margin:5px 0'},
        h('div', {style:'width:150px;flex:none;font-size:13px'}, ST[k]),
        h('div', {style:'flex:1;background:var(--color-background-dark);border-radius:6px;height:20px;overflow:hidden'},
          h('div', {style:'height:100%;border-radius:6px;width:' + (n / max * 100) + '%;background:' + STC[k]})),
        h('div', {style:'width:90px;flex:none;text-align:right;font-size:13px'}, h('strong', {}, String(n)), ' (' + Math.round(n / total * 100) + ' %)')); }));
}
function hoursChart(hrs) {
  const max = Math.max(1, ...hrs), total = hrs.reduce((a, b) => a + b, 0);
  if (!total) return h('div', {class:'card muted'}, T('Aucun appel sur la période.'));
  const best = hrs.indexOf(Math.max(...hrs));
  return h('div', {class:'card'},
    h('div', {style:'display:flex;align-items:flex-end;gap:3px;height:160px;padding-top:18px'}, hrs.map((n, i) =>
      h('div', {title: i + 'h–' + (i + 1) + 'h : ' + n + T(' appel(s)'), style:'flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;height:100%;min-width:0'},
        h('span', {style:'font-size:10px'}, n ? String(n) : ''),
        h('div', {style:'width:100%;border-radius:4px 4px 0 0;background:#3b82f6;opacity:' + (i === best ? 1 : .65) + ';height:' + (n / max * 100 * .85) + '%;min-height:' + (n ? 2 : 0) + 'px'})))),
    h('div', {style:'display:flex;gap:3px;margin-top:4px'}, hrs.map((n, i) => h('span', {style:'flex:1;text-align:center;font-size:10px;color:var(--color-text-maxcontrast)'}, String(i)))),
    h('div', {class:'muted', style:'margin-top:6px'}, total + T(' appel(s) — heure la plus active : ') + best + 'h–' + (best + 1) + 'h (' + hrs[best] + ')'));
}
async function stats() {
  const nameOf = u => (me.commerciaux.find(c => c.uid === u) || {name:u}).name;
  const box = h('div', {}, T('Chargement…')), sum = h('div');
  const from = h('input', {type:'date'}), to = h('input', {type:'date'});
  const usr = multi(T('Commerciaux'), me.commerciaux.map(u => ({v:u.uid, t:u.name})));
  const grp = multi(T('Groupes'), me.groups.map(g => ({v:g.id, t:g.name})));
  const cat = multi(lab('c2'), me.options.c2.map(c => ({v:c, t:c})));
  const res = h('select', {}, h('option', {value:''}, T('Tous résultats')), RES.map(k => h('option', {value:k}, ST[k])));
  const td = t => h('td', {style:'padding:6px 4px;vertical-align:top'}, t);
  const params = () => {
    const day = (v, add) => v ? Math.floor(new Date(v + 'T00:00:00').getTime() / 1000) + add : '';
    return new URLSearchParams({user: me.can.all ? usr.values().join('|') : '', group: me.can.all ? grp.values().join('|') : '', cat: cat.values().join('|'),
      result: res.value, from: day(from.value, 0), to: day(to.value, 86400), tz: -new Date().getTimezoneOffset(), lang: window.EC_LANG || 'fr'});
  };
  let jp = null, jpage = 1, jsize = 10, jsort = {k:'date', d:'desc'};
  try { const v = localStorage.getItem('easycrm_jps2'); if (v !== null && [0, 10, 20, 30].includes(Number(v))) jsize = Number(v); } catch (e) {}
  const jq = h('input', {type:'search', placeholder:T('Rechercher dans le journal…')});
  const jpg = h('div', {class:'bar', style:'justify-content:space-between;align-items:center;margin:8px 0'});
  const jhead = [['date', T('Date et heure')], ['user', T('Commercial')], ['client', T('Client')], ['phone', T('Téléphone')], ['cat', lab('c2')], ['result', T('Résultat')], ['note', T('Note')]];
  const jload = async () => {
    if (!jp) return;
    const p = new URLSearchParams(jp); p.set('q', jq.value); p.set('sort', jsort.k); p.set('dir', jsort.d); p.set('limit', jsize); p.set('offset', (jpage - 1) * jsize);
    try {
      const r = await api('/journal?' + p), pages = jsize ? Math.max(1, Math.ceil(r.total / jsize)) : 1;
      if (jpage > pages) { jpage = pages; return jload(); }
      const go3 = n => { jpage = n; jload(); };
      jpg.replaceChildren(h('span', {class:'muted'}, r.total + T(' appel(s) — page ') + jpage + ' / ' + pages),
        h('span', {}, h('button', {disabled: jpage <= 1, onclick: () => go3(jpage - 1)}, T('◀ Précédent')), ' ', h('button', {disabled: jpage >= pages, onclick: () => go3(jpage + 1)}, T('Suivant ▶')), ' ',
          h('select', {onchange: e => { jsize = Number(e.target.value); try { localStorage.setItem('easycrm_jps2', String(jsize)); } catch (x) {} jpage = 1; jload(); }},
            [10, 20, 30, 0].map(n => h('option', {value:n, selected: n === jsize}, n ? n + T(' par page') : T('Tout'))))));
      box.replaceChildren(r.rows.length ? h('table', {style:'width:100%;border-collapse:collapse'},
        h('thead', {}, h('tr', {}, jhead.map(([k, t]) => h('th', {style:'text-align:left;padding:4px;cursor:pointer;white-space:nowrap;user-select:none', title:T('Trier'), onclick: () => { if (jsort.k === k) jsort.d = jsort.d === 'asc' ? 'desc' : 'asc'; else { jsort.k = k; jsort.d = k === 'date' ? 'desc' : 'asc'; } jpage = 1; jload(); }},
          t + ' ', h('small', {style: jsort.k === k ? '' : 'opacity:.35'}, jsort.k === k ? (jsort.d === 'asc' ? '▲' : '▼') : '⇅'))))),
        h('tbody', {}, r.rows.map(x => h('tr', {style:'border-top:1px solid var(--color-border);cursor:pointer', onclick: () => detail(x.prospect_id)},
          td(fmt(Number(x.created_at))), td(x.user_name), td(nm(x)), td(x.phone), td(x.c2 || ''), td(badge(x.result), x.reason ? ' · ' + x.reason : ''), td(x.note || ''))))) : h('p', {class:'muted'}, T('Aucun appel.')));
    } catch (e) { box.replaceChildren(h('p', {class:'err'}, e.message)); }
  };
  let jt; jq.addEventListener('input', () => { clearTimeout(jt); jt = setTimeout(() => { jpage = 1; jload(); }, 300); });
  const iso = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  const hbox = h('div');
  const setRange = (a, b) => { from.value = a ? iso(a) : ''; to.value = b ? iso(b) : ''; load(); };
  const dAgo = n => { const d = new Date(); d.setDate(d.getDate() - n); return d; };
  const presets = h('span', {style:'display:inline-flex;gap:4px;flex-wrap:wrap'},
    h('button', {onclick: () => setRange(new Date(), new Date())}, T('Aujourd’hui')),
    h('button', {onclick: () => setRange(dAgo(1), dAgo(1))}, T('Hier')),
    h('button', {onclick: () => setRange(dAgo(6), new Date())}, T('7 jours')),
    h('button', {onclick: () => { const n = new Date(); setRange(new Date(n.getFullYear(), n.getMonth(), 1), n); }}, T('Ce mois')),
    h('button', {onclick: () => setRange(null, null)}, T('Toute la période')));
  from.value = to.value = iso(new Date());
  const upd = h('span', {class:'muted'});
  const rbtn = h('button', {class:'primary', onclick: () => load()}, T('↻ Actualiser'));
  const load = async () => {
    const p = params();
    rbtn.disabled = true; rbtn.textContent = T('↻ Actualisation…');
    try {
      jp = p; jpage = 1;
      const [, s] = await Promise.all([jload(), api('/stats?' + p)]);
      hbox.replaceChildren(hoursChart(s.hours || []));
      upd.textContent = T('Mis à jour à ') + new Date().toLocaleTimeString(window.EC_LOCALE || 'fr-FR', {hour:'2-digit', minute:'2-digit', second:'2-digit'});
      const by = {};
      s.calls.forEach(c => { (by[c.user_id] = by[c.user_id] || {})[c.result] = c.n; });
      const cbk = new Set(Object.values(STD).filter(x => x.callback).map(x => x.key)), perf = {};
      s.calls.forEach(c => { const o = perf[c.user_id] = perf[c.user_id] || {n:0, ct:0, it:0}; const n = Number(c.n); o.n += n; if (!cbk.has(c.result) && c.result !== 'faux_numero') o.ct += n; if (c.result === 'interesse') o.it += n; });
      Object.keys(s.today || {}).forEach(u => { perf[u] = perf[u] || {n:0, ct:0, it:0}; });
      const pct = (a, b) => b ? Math.round(100 * a / b) + ' %' : '—';
      const goal = Number(s.goal) || 0;
      const rsum = (s.reasons || []).reduce((a, x) => a + Number(x.n), 0);
      sum.replaceChildren(
        h('h3', {}, T('Performance par commercial')),
        Object.keys(perf).length ? h('div', {style:'overflow-x:auto'}, h('table', {class:'t'},
          h('thead', {}, h('tr', {}, [T('Commercial'), goal ? T('Aujourd’hui / objectif') : T('Aujourd’hui'), T('Appels (période)'), T('Contacts'), T('Taux de contact'), T('Intéressés'), T('Taux d’intérêt')].map(x => h('th', {}, x)))),
          h('tbody', {}, Object.entries(perf).map(([u, o]) => { const t = Number((s.today || {})[u] || 0); return h('tr', {},
            h('td', {}, nameOf(u)), h('td', {}, t + (goal ? ' / ' + goal + ' ' : ''), goal ? h('span', {class:'pbar'}, h('span', {style:'width:' + Math.min(100, Math.round(100 * t / goal)) + '%'})) : ''),
            h('td', {}, o.n), h('td', {}, o.ct), h('td', {}, pct(o.ct, o.n)), h('td', {}, o.it), h('td', {}, pct(o.it, o.ct))); })))) : h('p', {class:'muted'}, T('Aucun appel sur la période.')),
        h('p', {class:'muted'}, T('Contact = appel abouti (hors « sans réponse / à rappeler » et faux numéro). Taux d’intérêt = intéressés / contacts.')),
        ...((s.reasons || []).length ? [h('h3', {}, T('Motifs de refus')), h('div', {class:'card'}, s.reasons.map(x => h('div', {}, x.reason + ' : ', h('strong', {}, x.n), ' (' + Math.round(100 * Number(x.n) / rsum) + ' %)')))] : []),
        h('h3', {}, T('Nombre d’appels par heure')),
        hbox,
        h('h3', {}, T('Nombre de dossiers par statut')),
        statusChart(s.status),
        h('h3', {}, T('Prospects par statut')),
        h('div', {class:'card'}, s.status.length ? s.status.map(r => h('div', {}, (ST[r.status] || r.status) + ' : ', h('strong', {}, r.n))) : T('Aucun prospect.')),
        h('h3', {}, me.can.all ? T('Appels par commercial') : T('Mes appels')),
        ...Object.entries(by).map(([u, r]) => h('div', {class:'card'}, h('strong', {}, nameOf(u)), RES.map(k => h('div', {}, ST[k] + ' : ' + (r[k] || 0))))));
    } catch (e) { box.replaceChildren(h('p', {class:'err'}, e.message)); }
    rbtn.disabled = false; rbtn.textContent = T('↻ Actualiser');
  };
  const xl = async () => {
    try {
      const r = await fetch(API + '/export?' + params(), {headers:{requesttoken: tok()}});
      if (!r.ok) throw new Error(T('Erreur ') + r.status);
      const blob = await r.blob();
      if (window.CRMApp && window.CRMApp.saveFile) {
        const rd = new FileReader();
        rd.onload = () => window.CRMApp.saveFile('easycrm_resultats.xlsx', String(rd.result).split(',')[1]);
        rd.readAsDataURL(blob);
        return;
      }
      const u = URL.createObjectURL(blob);
      const a = h('a', {href:u, download:'easycrm_resultats.xlsx'});
      document.body.append(a); a.click(); a.remove(); URL.revokeObjectURL(u);
    } catch (e) { alert(e.message); }
  };
  show(h('h2', {}, me.can.all ? T('Statistiques') : T('Mes statistiques')),
    h('div', {class:'bar'}, me.can.all ? [usr.el, grp.el] : '', cat.el, res, h('label', {}, T('Du '), from), h('label', {}, T('Au '), to), presets, rbtn, me.can.export ? h('button', {onclick: xl, title: me.can.export_phone ? '' : T('Export sans numéros de téléphone')}, T('⬇ Télécharger Excel') + (me.can.export_phone ? '' : T(' (sans téléphone)'))) : '', upd),
    h('h3', {}, T('Journal des appels')), h('div', {class:'bar'}, jq), jpg,
    h('div', {style:'overflow-x:auto'}, box), sum);
  [usr.el, grp.el, cat.el, res, from, to].forEach(e => e.addEventListener('change', load));
  load();
}

/** Lit un fichier Excel (.xls/.xlsx), nettoie les lignes, supprime les doublons de numéros ; renvoie {csv, total, kept, dupInFile, empty}. */
async function excelToCsv(f) {
  if (!window.XLSX) throw new Error(T('Lecteur Excel indisponible : rechargez la page (Ctrl+F5).'));
  const wb = XLSX.read(await f.arrayBuffer(), {type:'array'});
  const ws = wb.Sheets[wb.SheetNames[0]];
  if (!ws) throw new Error(T('Feuille Excel vide.'));
  const rows = XLSX.utils.sheet_to_json(ws, {header:1, raw:false, defval:''}).map(r => r.map(c => String(c == null ? '' : c).replace(/\s+/g, ' ').trim()));
  const flat = v => String(v).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  const ph = me.phone || {cc:'', trunk:''};
  const digits = v => { let d = String(v).replace(/\D+/g, ''); if (ph.cc) { if (d.startsWith('00' + ph.cc)) d = d.slice(2 + ph.cc.length); else if (d.startsWith(ph.cc) && d.length >= ph.cc.length + 8) d = d.slice(ph.cc.length); }
    if (ph.trunk && d && !d.startsWith(ph.trunk)) d = ph.trunk + d; return d; };
  const looksPhone = v => digits(v).length >= 7;
  // en-tête : si la 1re ligne contient déjà un numéro, il n'y a pas d'en-tête
  let start = 1; const first = rows[0] || [];
  if (first.some(looksPhone) && !first.some(c => /t[eé]l|phone|nom|name/i.test(c))) start = 0;
  // colonnes : par nom d'en-tête si reconnu, sinon ordre standard
  const stdDef = [['lastname', /nom|name|client/], ['phone', /t[eé]l|phone|mobile|gsm|num/], ['c5', /wilaya|r[eé]gion|ville|province|city/], ['c2', /cat[eé]g|produit|product/], ['c3', /emploi|profession|job|poste/], ['c4', /salaire|revenu|income/], ['remarque', /comment|remarque|note|observ/]];
  const std = stdDef.map(([k, re]) => ({test: c => re.test(flat(c)) || flat(c) === flat(lab(k))}));
  const cust = (me.fields || []).filter(f => !f.builtin);
  const cmap = cust.map(f => start === 0 ? -1 : first.findIndex(c => flat(c) === flat(f.label)));
  const idx = std.map((re, i) => { if (start === 0) return i; const j = first.findIndex((c, k) => !cmap.includes(k) && re.test(c)); return j; });
  const map = idx.every(j => j >= 0) || idx[0] >= 0 && idx[1] >= 0 ? idx.map((j, i) => j >= 0 ? j : -1) : std.map((_, i) => i);
  const seen = new Set(); const out = []; let empty = 0, dup = 0;
  for (let i = start; i < rows.length; i++) {
    const r = rows[i]; if (!r.some(c => c !== '')) { empty++; continue; }
    const rec = map.map(j => j >= 0 ? (r[j] || '') : '').concat(cmap.map(j => j >= 0 ? (r[j] || '') : ''));
    const key = digits(rec[1]);
    if (key && seen.has(key)) { dup++; continue; }
    if (key) seen.add(key);
    out.push(rec);
  }
  const q = v => '"' + String(v).replace(/"/g, '""') + '"';
  const head = [lab('lastname'), lab('phone'), lab('c5'), lab('c2'), lab('c3'), lab('c4'), lab('remarque'), ...cust.map(f => f.label)];
  const csv = '\uFEFF' + [head, ...out].map(r => r.map(q).join(';')).join('\r\n');
  return {csv, extras: cust.map(f => f.key), total: rows.length - start - empty, kept: out.length, dupInFile: dup, empty};
}
const TYPE_LABELS = {text:T('Texte libre'), textarea:T('Texte long'), number:T('Nombre'), date:T('Date'), email:T('E-mail'), yesno:T('Oui / Non'), select:T('Liste (un seul choix)'), multiselect:T('Liste (plusieurs choix)')};
function fieldsEditor() {
  let list = JSON.parse(JSON.stringify(me.fields || []));
  const out = h('span', {class:'muted'}), box = h('div');
  const draw = () => {
    box.replaceChildren(...list.map((f, i) => {
      const lab = h('input', {value:f.label, style:'flex:1;min-width:140px', oninput: e => { f.label = e.target.value; }});
      const typ = h('select', {disabled: f.locked, onchange: e => { f.type = e.target.value; if (['select', 'multiselect'].includes(f.type) && !(f.options || []).length) f.options = (f.defaultOptions || []).slice(); draw(); }},
        f.locked ? h('option', {}, f.key === 'phone' ? T('Téléphone') : f.key === 'c2' ? T('Liste (catégories)') : T('Texte')) : Object.entries(TYPE_LABELS).map(([v, l]) => h('option', {value:v, selected: f.type === v}, l)));
      const req = h('label', {style:'white-space:nowrap'}, h('input', {type:'checkbox', checked: f.required, disabled: f.key === 'lastname', onchange: e => { f.required = e.target.checked; }}), T(' Obligatoire'));
      const vis = f.builtin ? (['lastname', 'phone'].includes(f.key) ? '' : h('label', {style:'white-space:nowrap'}, h('input', {type:'checkbox', checked: !f.hidden, onchange: e => { f.hidden = !e.target.checked; }}), T(' Affiché'))) : '';
      const mv = (d) => () => { const j = i + d; if (j < 0 || j >= list.length) return; [list[i], list[j]] = [list[j], list[i]]; draw(); };
      const del = f.builtin ? '' : h('button', {title:T('Supprimer ce champ'), onclick: () => { if (confirm(T('Supprimer le champ « ') + f.label + T(' » ? Les valeurs déjà saisies ne seront plus affichées.'))) { list.splice(i, 1); draw(); } }}, '🗑');
      const opts = ['select', 'multiselect'].includes(f.type) && !(f.locked) ? h('textarea', {rows:3, placeholder:T('Un choix par ligne'), style:'width:100%;box-sizing:border-box;margin-top:4px', oninput: e => { f.options = e.target.value.split('\n'); }}, (f.options || []).join('\n')) : '';
      return h('div', {style:'border-top:1px solid var(--color-border);padding:8px 0'},
        h('div', {style:'display:flex;flex-wrap:wrap;gap:8px;align-items:center'}, lab, typ, req, vis,
          h('button', {title:T('Monter'), onclick: mv(-1)}, '↑'), h('button', {title:T('Descendre'), onclick: mv(1)}, '↓'), del), opts,
        f.key === 'lastname' ? h('div', {class:'muted'}, T('Champ obligatoire en permanence.')) : f.key === 'phone' ? h('div', {class:'muted'}, T('Numéro algérien vérifié et sans doublon ; indispensable pour appeler (WhatsApp, rappels, enregistrements).')) : f.key === 'c2' ? h('div', {class:'muted'}, T('Sert à répartir les prospects entre les équipes : le type reste une liste de catégories.')) : '');
    }));
  };
  draw();
  return h('div', {class:'card'}, h('h3', {}, T('Champs de la fiche client')),
    h('p', {class:'muted'}, T('Renommez, réordonnez, masquez ou supprimez les champs, changez leur type (texte, nombre, date, liste à un ou plusieurs choix…) et choisissez lesquels sont obligatoires. Seul « Nom et prénom » est toujours obligatoire.')),
    box,
    h('div', {style:'margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap'},
      h('button', {onclick: () => { list.push({key:'', label:T('Nouveau champ'), type:'text', required:false, builtin:false, options:[]}); draw(); }}, T('+ Ajouter un champ')),
      h('button', {class:'primary', onclick: async () => {
        out.textContent = '…';
        try { const r = await api('/fields', {method:'POST', body:{fields:list}}); me.fields = r.fields; list = JSON.parse(JSON.stringify(r.fields)); draw(); out.textContent = T('Champs enregistrés.'); }
        catch (e) { out.textContent = e.message; }
      }}, T('Enregistrer les champs')), out));
}
function statusesEditor() {
  let list = JSON.parse(JSON.stringify(me.statuses || []));
  const out = h('span', {class:'muted'}), box = h('div');
  const draw = () => {
    box.replaceChildren(...list.map((f, i) => {
      const nw = f.key === 'nouveau';
      const lab = h('input', {value:f.label, style:'flex:1;min-width:140px', oninput: e => { f.label = e.target.value; }});
      const col = h('input', {type:'color', value:f.color, title:T('Couleur'), oninput: e => { f.color = e.target.value; }});
      const cb = nw ? '' : h('label', {style:'white-space:nowrap'}, h('input', {type:'checkbox', checked: f.callback, onchange: e => { f.callback = e.target.checked; }}), T(' Date de rappel'));
      const nt = nw ? '' : h('label', {style:'white-space:nowrap'}, h('input', {type:'checkbox', checked: f.note, onchange: e => { f.note = e.target.checked; }}), T(' Note obligatoire'));
      const rs = nw ? '' : h('label', {style:'white-space:nowrap'}, h('input', {type:'checkbox', checked: f.reason, onchange: e => { f.reason = e.target.checked; }}), T(' Motif obligatoire'));
      const ac = nw ? '' : h('label', {style:'white-space:nowrap'}, h('input', {type:'checkbox', checked: f.active, onchange: e => { f.active = e.target.checked; }}), T(' Actif'));
      const mv = d => () => { const j = i + d; if (j < 0 || j >= list.length) return; [list[i], list[j]] = [list[j], list[i]]; draw(); };
      const del = f.builtin ? '' : h('button', {title:T('Supprimer'), onclick: () => { if (confirm(T('Supprimer la réponse « ') + f.label + T(' » ?'))) { list.splice(i, 1); draw(); } }}, '🗑');
      return h('div', {style:'border-top:1px solid var(--color-border);padding:8px 0;display:flex;flex-wrap:wrap;gap:8px;align-items:center'}, col, lab, cb, nt, rs, ac,
        h('button', {onclick: mv(-1)}, '↑'), h('button', {onclick: mv(1)}, '↓'), del, nw ? h('span', {class:'muted'}, T('Statut initial (pas une réponse d’appel).')) : '');
    }));
  };
  draw();
  return h('div', {class:'card'}, h('h3', {}, T('Réponses d’appel / statuts')),
    h('p', {class:'muted'}, T('Renommez, recolorez, réordonnez, désactivez ou ajoutez des réponses (intéressé, ne répond pas…). « Date de rappel » : le commercial doit fixer un rappel et le prospect apparaît dans « À rappeler ». « Motif obligatoire » : le commercial doit choisir un motif (liste modifiable dans « Réglages des appels »). Une réponse déjà utilisée ne peut pas être supprimée : désactivez-la.')),
    box,
    h('div', {style:'margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap'},
      h('button', {onclick: () => { list.push({key:'', label:T('Nouvelle réponse'), color:'#607d8b', callback:false, note:false, reason:false, active:true, builtin:false}); draw(); }}, T('+ Ajouter une réponse')),
      h('button', {class:'primary', onclick: async () => {
        out.textContent = '…';
        try { const r = await api('/statuses', {method:'POST', body:{statuses:list}}); me.statuses = r.statuses; applyStatuses(); list = JSON.parse(JSON.stringify(r.statuses)); draw(); out.textContent = T('Enregistré. Rechargez la page pour mettre à jour les onglets.'); }
        catch (e) { out.textContent = e.message; }
      }}, T('Enregistrer les réponses')), out));
}
function generalCard() {
  const A = me.admin || {}, S = me.settings || {};
  const out = h('span', {class:'muted'});
  const company = h('input', {value: S.company || '', style:'width:100%;box-sizing:border-box'});
  const country = h('select', {onchange: () => other()}, Object.entries(A.countries || {}).map(([v, l]) => h('option', {value:v, selected: S.country === v}, l)));
  const cc = h('input', {value: S.cc || '', size:5, placeholder:T('ex. 213')}), trunk = h('input', {value: S.trunk || '', size:3, placeholder:'0'});
  const ob = h('div', {style:'margin:6px 0'}, T('Indicatif pays : '), cc, T(' Préfixe national : '), trunk, h('div', {class:'muted'}, T('Les numéros doivent comporter 6 à 15 chiffres.')));
  const other = () => { ob.style.display = country.value === 'OTHER' ? '' : 'none'; };
  other();
  const roles = A.roles || {}, rin = {};
  const RL = {admin:T('Administrateurs'), superviseur:T('Superviseurs'), commercial:T('Commerciaux'), lecture:T('Lecture seule')};
  const rows = Object.keys(RL).map(r => { rin[r] = h('input', {value: roles[r] || '', style:'width:260px'}); return h('label', {style:'display:block;margin:4px 0'}, h('span', {style:'display:inline-block;width:150px'}, RL[r]), rin[r]); });
  return h('div', {class:'card'}, h('h3', {}, T('Général')),
    h('label', {}, T('Nom de l’entreprise (utilisé dans les e-mails d’invitation)'), company),
    h('div', {style:'margin:8px 0'}, T('Pays (format des téléphones et de WhatsApp) : '), country), ob,
    h('h4', {}, T('Groupes d’accès')),
    h('p', {class:'muted'}, T('Chaque rôle correspond à un groupe Nextcloud (ou un groupe de votre annuaire LDAP / AD). Indiquez un groupe existant, ou un nouvel identifiant : il sera créé. Les administrateurs de Nextcloud ont toujours le rôle administrateur.')),
    rows,
    h('div', {style:'margin-top:8px;display:flex;gap:8px;align-items:center'},
      h('button', {class:'primary', onclick: async () => {
        out.textContent = '…';
        const roles2 = {}; Object.keys(rin).forEach(r => roles2[r] = rin[r].value.trim());
        try { await api('/settings', {method:'POST', body:{settings:{company: company.value, country: country.value, cc: cc.value, trunk: trunk.value}, roles: roles2}}); out.textContent = T('Enregistré, rechargement…'); setTimeout(() => location.reload(), 700); }
        catch (e) { out.textContent = e.message; }
      }}, T('Enregistrer')), out));
}
function telephonyCard() {
  const A = (me.admin || {}).telephony;
  if (!A) return '';
  const FL = {host:T('Serveur (IP ou nom d’hôte)'), port:T('Port'), user:T('Utilisateur / identifiant API'), secret:T('Mot de passe / secret'), url:T('URL du serveur'),
    channel:T('Canal du poste appelant'), context:T('Contexte de numérotation'), dial_format:T('Format du numéro envoyé'), dial_prefix:T('Préfixe de sortie (ex. 0 ou 9)'),
    uri_scheme:T('Type de lien'), verify_ssl:T('Vérifier le certificat SSL'), http_method:T('Méthode HTTP'), http_headers:T('En-têtes HTTP (JSON)'), http_body:T('Corps de la requête'),
    phone_id:T('Phone Number ID'), token:T('Jeton d’accès / clé API'), template:T('Modèle de message (facultatif)'), language:T('Langue du modèle'),
    api_version:T('Version de l’API'), account_sid:T('Account SID'), from_number:T('Numéro expéditeur')};
  const FMT = [['national', T('National (0555…)')], ['e164', T('E.164 (+213555…)')], ['intl', T('International (213555…)')], ['intl00', T('International 00 (00213555…)')], ['raw', T('Tel quel')]];
  const HINT = {asterisk:T('Compte AMI défini dans manager.conf (FreePBX : Paramètres → Utilisateurs Asterisk Manager). Variable : {ext} = poste de l’utilisateur.'),
    yeastar:T('Utilisateur et mot de passe de l’application API créée dans Yeastar (Intégrations → API).'),
    '3cx':T('Utilisateur = Client ID, secret = Client Secret de l’application créée dans la console 3CX (Intégrations → API).'),
    http:T('Variables disponibles : {ext} (poste), {number} (numéro), {text} (message), {name} (prénom).'),
    cloud:T('Identifiants Meta for Developers → WhatsApp → Configuration de l’API. Avec un modèle, le prénom du prospect est injecté dans {{1}}.'),
    '360dialog':T('Clé API de votre canal 360dialog.'), twilio:T('Numéro expéditeur au format international (ex. 14155238886). Modèle = Content SID (HX…).')};
  const out = h('span', {class:'muted'});
  const st = {sip: JSON.parse(JSON.stringify(A.sip)), wa: JSON.parse(JSON.stringify(A.wa)), answered: A.answered || '', missed: A.missed || '', secret: A.secret || ''};
  const exts = Object.assign({}, A.ext || {});
  const box = h('div');
  const field = (cfg, f) => {
    const lab = h('label', {style:'display:block;margin:6px 0'}, h('span', {style:'display:inline-block;min-width:230px'}, FL[f] || f));
    let el;
    if (f === 'dial_format') el = h('select', {onchange: e => { cfg[f] = e.target.value; }}, FMT.map(([v, l]) => h('option', {value:v, selected: cfg[f] === v}, l)));
    else if (f === 'uri_scheme') el = h('select', {onchange: e => { cfg[f] = e.target.value; }}, ['tel', 'sip', 'callto'].map(v => h('option', {value:v, selected: cfg[f] === v}, v + ':')));
    else if (f === 'http_method') el = h('select', {onchange: e => { cfg[f] = e.target.value; }}, ['POST', 'GET', 'PUT'].map(v => h('option', {value:v, selected: cfg[f] === v}, v)));
    else if (f === 'verify_ssl') el = h('input', {type:'checkbox', checked: cfg[f] !== '0', onchange: e => { cfg[f] = e.target.checked ? '1' : '0'; }});
    else if (f === 'http_headers' || f === 'http_body') el = h('textarea', {rows:3, style:'width:100%;box-sizing:border-box;font-family:monospace', oninput: e => { cfg[f] = e.target.value; }}, cfg[f] || '');
    else el = h('input', {type: (f === 'secret' || f === 'token') ? 'password' : 'text', value: cfg[f] || '', autocomplete:'new-password', style:'width:340px;max-width:100%', oninput: e => { cfg[f] = e.target.value; }});
    lab.append(el);
    return lab;
  };
  const section = (title, cfg, presets, kind) => {
    const cur = presets.find(x => x.id === cfg.provider) || presets[0];
    const sel = h('select', {onchange: e => {
      const p = presets.find(x => x.id === e.target.value);
      for (const k of Object.keys(cfg)) delete cfg[k];
      Object.assign(cfg, {provider: p.id}, p.defaults);
      draw();
    }}, presets.map(x => h('option', {value:x.id, selected: x.id === cfg.provider}, T(x.label))));
    const hint = HINT[cfg.provider];
    const test = h('button', {onclick: async () => {
      out.textContent = '…';
      try { await api('/telephony', {method:'POST', body: payload()}); const r = await api('/telephony/test', {method:'POST', body:{kind}}); out.textContent = (r.ok ? '✅ ' : '❌ ') + T(r.message); }
      catch (e) { out.textContent = '❌ ' + e.message; }
    }}, T('Tester la connexion'));
    return h('div', {style:'border-top:1px solid var(--color-border);padding:8px 0'}, h('h4', {}, title), sel,
      hint ? h('p', {class:'muted'}, hint) : '',
      ...(presets.find(x => x.id === cfg.provider) || cur).fields.map(f => field(cfg, f)),
      cfg.provider !== presets[0].id ? test : '');
  };
  const payload = () => ({telephony: {sip: st.sip, wa: st.wa, answered: st.answered, missed: st.missed, secret: st.secret}, ext: exts});
  const results = (me.statuses || []).filter(x => x.result && x.active && !x.callback && !x.note);
  const statSel = key => h('select', {onchange: e => { st[key] = e.target.value; }}, h('option', {value:''}, T('— Ne rien changer —')), results.map(x => h('option', {value:x.key, selected: st[key] === x.key}, T(x.label))));
  const secretIn = h('input', {readonly:true, style:'width:340px;max-width:100%;font-family:monospace', onclick: e => e.target.select()});
  const urlP = h('input', {readonly:true, style:'width:100%;box-sizing:border-box;font-family:monospace', onclick: e => e.target.select()});
  const urlW = h('input', {readonly:true, style:'width:100%;box-sizing:border-box;font-family:monospace', onclick: e => e.target.select()});
  const refreshUrls = () => { secretIn.value = st.secret; urlP.value = A.url_pbx + '?token=' + st.secret; urlW.value = A.url_wa + '?token=' + st.secret; };
  const extBox = h('div', {style:'max-height:220px;overflow:auto;border:1px solid var(--color-border);border-radius:6px;padding:6px;margin:6px 0'},
    (me.commerciaux || []).map(u => h('label', {style:'display:flex;gap:8px;align-items:center;margin:3px 0'}, h('span', {style:'min-width:200px'}, u.name),
      h('input', {value: exts[u.uid] || '', size:8, placeholder:T('ex. 1001'), oninput: e => { exts[u.uid] = e.target.value; }}))));
  const draw = () => {
    box.replaceChildren(section(T('Téléphonie (standard SIP / PBX)'), st.sip, A.sip_presets, 'sip'), section(T('WhatsApp'), st.wa, A.wa_presets, 'wa'));
    refreshUrls();
  };
  draw();
  return h('div', {class:'card'}, h('h3', {}, T('Téléphonie et WhatsApp')),
    h('p', {class:'muted'}, T('Choisissez votre standard (Asterisk, FreePBX, Yeastar, 3CX…) et votre fournisseur WhatsApp : les réglages se pré-remplissent. Sans configuration, EasyCrm utilise le téléphone et le lien WhatsApp du smartphone.')),
    box,
    h('h4', {}, T('Postes des commerciaux')), h('p', {class:'muted'}, T('Numéro de poste (extension) de chaque commercial sur le standard.')), extBox,
    h('h4', {}, T('Notifications entrantes (webhooks)')),
    h('p', {class:'muted'}, T('À renseigner dans le standard (fin d’appel) et chez le fournisseur WhatsApp (messages reçus). Les appels et messages sont rattachés au prospect par les 8 derniers chiffres du numéro.')),
    h('label', {style:'display:block;margin:6px 0'}, h('span', {style:'display:inline-block;min-width:230px'}, T('Jeton secret')), secretIn, ' ',
      h('button', {onclick: async () => { if (!confirm(T('Générer un nouveau jeton ? Les anciennes URLs cesseront de fonctionner.'))) return; try { const r = await api('/telephony/secret', {method:'POST'}); st.secret = r.secret; A.secret = r.secret; refreshUrls(); } catch (e) { out.textContent = e.message; } }}, T('Générer'))),
    h('label', {style:'display:block;margin:6px 0'}, T('URL fin d’appel (standard)'), urlP),
    h('label', {style:'display:block;margin:6px 0'}, T('URL messages WhatsApp reçus'), urlW),
    h('label', {style:'display:block;margin:6px 0'}, h('span', {style:'display:inline-block;min-width:230px'}, T('Statut après un appel abouti')), statSel('answered')),
    h('label', {style:'display:block;margin:6px 0'}, h('span', {style:'display:inline-block;min-width:230px'}, T('Statut après un appel manqué')), statSel('missed')),
    h('div', {style:'margin-top:8px;display:flex;gap:8px;align-items:center'},
      h('button', {class:'primary', onclick: async () => {
        out.textContent = '…';
        try { await api('/telephony', {method:'POST', body: payload()}); out.textContent = T('Enregistré, rechargement…'); setTimeout(() => location.reload(), 700); }
        catch (e) { out.textContent = e.message; }
      }}, T('Enregistrer')), out));
}
function teamsCard() {
  const A = me.admin || {};
  let list = JSON.parse(JSON.stringify(A.teams || []));
  const out = h('span', {class:'muted'}), box = h('div');
  const extra = h('textarea', {rows:3, style:'width:100%;box-sizing:border-box', placeholder:T('Une catégorie par ligne')}, (A.extra || []).join('\n'));
  const draw = () => box.replaceChildren(...list.map((t, i) => {
    const cats = h('textarea', {rows:3, style:'width:100%;box-sizing:border-box', placeholder:T('Catégories de cette équipe, une par ligne'), oninput: e => { t.cats = e.target.value.split('\n'); }}, (t.cats || []).join('\n'));
    return h('div', {style:'border-top:1px solid var(--color-border);padding:8px 0'},
      h('div', {style:'display:flex;flex-wrap:wrap;gap:8px;align-items:center'},
        h('input', {value:t.name, placeholder:T('Nom de l’équipe'), style:'flex:1;min-width:160px', oninput: e => { t.name = e.target.value; }}),
        h('input', {value:t.gid, placeholder:T('Groupe (laisser vide = créer)'), style:'width:220px', oninput: e => { t.gid = e.target.value; }}),
        h('span', {class:'muted'}, t.gid ? ((me.teams[t.gid] || []).length + T(' commercial(aux)')) : ''),
        h('button', {title:T('Supprimer'), onclick: () => { if (confirm(T('Supprimer l’équipe « ') + t.name + T(' » ? Le groupe lui-même n’est pas supprimé.'))) { list.splice(i, 1); draw(); } }}, '🗑')), cats);
  }));
  draw();
  return h('div', {class:'card'}, h('h3', {}, T('Équipes et catégories')),
    h('p', {class:'muted'}, T('Une équipe = un groupe de commerciaux. Les catégories (produits, services…) rattachées à une équipe servent à répartir automatiquement les prospects importés. Les commerciaux doivent être membres du groupe de leur équipe en plus du groupe « Commerciaux ».')),
    box,
    h('button', {onclick: () => { list.push({name:'', gid:'', cats:[]}); draw(); }}, T('+ Ajouter une équipe')),
    h('h4', {}, T('Autres catégories (sans équipe)')), extra,
    h('div', {style:'margin-top:8px;display:flex;gap:8px;align-items:center'},
      h('button', {class:'primary', onclick: async () => {
        out.textContent = '…';
        try { await api('/teams', {method:'POST', body:{teams:list, extra: extra.value.split('\n')}}); out.textContent = T('Enregistré, rechargement…'); setTimeout(() => location.reload(), 700); }
        catch (e) { out.textContent = e.message; }
      }}, T('Enregistrer les équipes')), out));
}
function transferCard() {
  const com = me.commerciaux || [];
  const from = h('select', {}, h('option', {value:'__none'}, T('Non assignés')), com.map(u => h('option', {value:u.uid}, u.name)));
  const to = h('select', {}, h('option', {value:''}, T('— Choisir —')), com.map(u => h('option', {value:u.uid}, u.name)));
  const st = h('select', {}, h('option', {value:'nouveau'}, T('Nouveau (À traiter)')), h('option', {value:''}, T('Tous les statuts')),
    Object.entries(ST).filter(([v]) => v !== 'nouveau').map(([v, l]) => h('option', {value:v}, l)));
  const lim = h('input', {type:'number', min:0, placeholder:T('Tous'), style:'width:90px'});
  const out = h('div', {class:'muted', style:'margin-top:6px'});
  const body = dry => ({from:from.value, to:to.value, status:st.value, limit:lim.value ? Number(lim.value) : 0, dry});
  const prev = async () => {
    if (!to.value) { out.textContent = T('Choisissez le commercial destinataire.'); return; }
    out.textContent = '…';
    try { const r = await api('/transfer', {method:'POST', body:body(true)}); out.textContent = r.count + T(' prospect(s) seront transférés.'); }
    catch (e) { out.textContent = e.message; }
  };
  return h('div', {class:'card'}, h('h3', {}, T('Transférer des prospects entre commerciaux')),
    h('p', {class:'muted'}, T('Déplace en masse les prospects d’un commercial vers un autre (par défaut : seulement les « Nouveau », les plus anciens d’abord). Laissez « Quantité » vide pour tout transférer, ou saisissez un nombre pour n’en transférer qu’une partie.')),
    h('div', {class:'bar'}, h('label', {}, 'De ', from), h('label', {}, T('Vers '), to)),
    h('div', {class:'bar'}, h('label', {}, T('Statut '), st), h('label', {}, T('Quantité max '), lim)),
    h('div', {class:'bar'}, h('button', {onclick: prev}, T('Compter')), h('button', {class:'primary', onclick: async () => {
      if (!to.value) { out.textContent = T('Choisissez le commercial destinataire.'); return; }
      let n; try { n = (await api('/transfer', {method:'POST', body:body(true)})).count; } catch (e) { out.textContent = e.message; return; }
      if (!n) { out.textContent = T('Aucun prospect à transférer.'); return; }
      const nm = u => (com.find(x => x.uid === u) || {name:T('Non assignés')}).name;
      if (!confirm(T('Transférer ') + n + T(' prospect(s) de « ') + nm(from.value === '__none' ? '' : from.value) + T(' » vers « ') + nm(to.value) + T(' » ?'))) return;
      out.textContent = '…';
      try { const r = await api('/transfer', {method:'POST', body:body(false)}); out.textContent = '✔ ' + r.transferred + T(' prospect(s) transféré(s).'); }
      catch (e) { out.textContent = e.message; }
    }}, T('Transférer'))), out);
}
function bulkStatusCard() {
  const com = me.commerciaux || [];
  const who = h('select', {}, h('option', {value:'__all'}, T('Tous les commerciaux')), h('option', {value:''}, T('Non assignés')), com.map(u => h('option', {value:u.uid}, u.name)));
  const chk = Object.entries(ST).map(([v, l]) => ({v, el: h('input', {type:'checkbox', value:v, checked: v === 'injoignable' || v === 'pas_interesse'})}));
  const to = h('select', {}, Object.entries(ST).map(([v, l]) => h('option', {value:v, selected: v === 'nouveau'}, l)));
  const mode = h('select', {}, h('option', {value:'keep'}, T('Garder le commercial actuel')), h('option', {value:'none'}, T('Rendre non assignés (à répartir ensuite)')), h('option', {value:'user'}, T('Affecter à un commercial…')));
  const dest = h('select', {style:'display:none'}, h('option', {value:''}, T('— Choisir —')), com.map(u => h('option', {value:u.uid}, u.name)));
  mode.addEventListener('change', () => { dest.style.display = mode.value === 'user' ? '' : 'none'; });
  const lim = h('input', {type:'number', min:0, placeholder:T('Tous'), style:'width:90px'});
  const out = h('div', {class:'muted', style:'margin-top:6px'});
  const body = dry => ({from_status: chk.filter(c => c.el.checked).map(c => c.v), assigned:who.value, to_status:to.value, assign_mode:mode.value, assign_to:dest.value, limit: lim.value ? Number(lim.value) : 0, dry});
  const prev = async () => { out.textContent = '…'; try { const r = await api('/bulkstatus', {method:'POST', body:body(true)}); out.textContent = r.count + T(' prospect(s) concernés.'); } catch (e) { out.textContent = e.message; } };
  return h('div', {class:'card'}, h('h3', {}, T('Changer le statut des prospects en masse')),
    h('p', {class:'muted'}, T('Sélectionnez les statuts d’origine (aucun coché = tous), éventuellement un commercial, puis le nouveau statut. Exemple : « Ne répond pas » et « Pas intéressé » → « Nouveau », puis répartissez-les. Les rappels prévus et le compteur de tentatives sont remis à zéro ; l’historique des appels est conservé. Pour une sélection précise, cochez les fiches dans les listes.')),
    h('div', {style:'display:flex;flex-wrap:wrap;gap:4px 14px;padding:4px 0'}, chk.map((c, i) => h('label', {style:'white-space:nowrap'}, c.el, ' ' + ST[c.v]))),
    h('div', {class:'bar'}, h('label', {}, T('Commercial '), who), h('label', {}, T('Quantité max '), lim)),
    h('div', {class:'bar'}, h('label', {}, T('Nouveau statut '), to), mode, dest),
    h('div', {class:'bar'}, h('button', {onclick: prev}, T('Compter')), h('button', {class:'primary', onclick: async () => {
      if (mode.value === 'user' && !dest.value) { out.textContent = T('Choisissez le commercial destinataire.'); return; }
      let n; try { n = (await api('/bulkstatus', {method:'POST', body:body(true)})).count; } catch (e) { out.textContent = e.message; return; }
      if (!n) { out.textContent = T('Aucun prospect concerné.'); return; }
      if (!confirm(T('Passer ') + n + T(' prospect(s) en « ') + ST[to.value] + T(' » ?'))) return;
      out.textContent = '…';
      try { const r = await api('/bulkstatus', {method:'POST', body:body(false)}); out.textContent = '✔ ' + r.changed + T(' prospect(s) mis à jour.'); }
      catch (e) { out.textContent = e.message; }
    }}, T('Appliquer'))), out);
}
function settingsCard() {
  const P = me.prefs || {};
  const num = (v, w = 70) => h('input', {type:'number', min:0, value:v || 0, style:'width:' + w + 'px'});
  const max = num(P.max_callbacks), gap = num(P.cb_gap, 80), goal = num(P.goal, 80);
  const from = h('input', {type:'time', value:P.cb_from || ''}), to = h('input', {type:'time', value:P.cb_to || ''});
  const tz = h('input', {value:P.tz || 'Africa/Algiers', style:'width:160px'});
  const rs = h('textarea', {rows:6, style:'width:100%;box-sizing:border-box'}, (P.reasons || []).join('\n'));
  const out = h('span', {class:'muted'});
  return h('div', {class:'card'}, h('h3', {}, T('Réglages des appels')),
    h('p', {class:'muted'}, T('Limite de tentatives : après ce nombre de tentatives sans succès (réponses avec date de rappel : « Ne répond pas », « À rappeler »…), le prospect passe automatiquement en « Injoignable / faux numéro ». Avec 3, la 3e tentative sans succès clôture le prospect. 0 = illimité.')),
    h('div', {class:'bar'}, h('label', {}, T('Tentatives maximum '), max)),
    h('p', {class:'muted'}, T('Plage de rappel : heures entre lesquelles un rappel peut être programmé (vider les deux champs = aucune limite). Espacement : délai minimum entre l’appel et le rappel programmé (0 = aucun).')),
    h('div', {class:'bar'}, h('label', {}, T('Plage de rappel de '), from), h('label', {}, 'à ', to), h('label', {}, T('Espacement minimum (min) '), gap), h('label', {}, T('Fuseau horaire '), tz)),
    h('p', {class:'muted'}, T('Objectif quotidien : nombre d’appels attendus par commercial et par jour (0 = pas d’objectif).')),
    h('div', {class:'bar'}, h('label', {}, T('Appels par jour '), goal)),
    h('p', {class:'muted'}, T('Motifs de refus proposés pour les réponses « Motif obligatoire » (un par ligne).')), rs,
    h('div', {class:'bar'}, h('button', {class:'primary', onclick: async () => {
      out.textContent = '…';
      try {
        const r = await api('/callprefs', {method:'POST', body:{max_callbacks:Number(max.value) || 0, cb_gap:Number(gap.value) || 0, goal:Number(goal.value) || 0, cb_from:from.value, cb_to:to.value, tz:tz.value, reasons:rs.value}});
        me.prefs = r.prefs; me.max_callbacks = r.max_callbacks; out.textContent = T('✔ Réglages enregistrés.');
      } catch (e) { out.textContent = e.message; }
    }}, T('Enregistrer')), out));
}
/** Liste de commerciaux à cocher (affectation à l'import / à la répartition). */
function commPicker(title) {
  const all = h('input', {type:'checkbox', onchange: () => boxes.forEach(x => x.cb.checked = all.checked)});
  const boxes = (me.commerciaux || []).map(u => ({u, cb: h('input', {type:'checkbox'})}));
  return {el: h('div', {style:'margin:6px 0'}, h('strong', {}, title),
      h('div', {style:'max-height:180px;overflow:auto;border:1px solid var(--color-border);border-radius:6px;padding:6px;margin-top:4px'},
        h('label', {}, all, T(' Tout cocher / décocher')), ...boxes.map(x => h('label', {style:'display:block'}, x.cb, ' ' + x.u.name)))),
    values: () => boxes.filter(x => x.cb.checked).map(x => x.u.uid)};
}
const AUDIT = {transfer:T('Transfert'), status:T('Changement de statut'), auto_close:T('Clôture automatique'), assign:T('Affectation'), import:T('Import'), distribute:T('Répartition'), release:T('Réattribution'), delete:T('Suppression'), export:T('Export'), settings:T('Réglages')};
function auditCard() {
  const box = h('div'), out = h('div', {class:'muted'});
  const act = h('select', {}, h('option', {value:''}, T('Toutes les actions')), Object.entries(AUDIT).map(([k, l]) => h('option', {value:k}, l)));
  let page = 1; const size = 20;
  const load = async () => {
    box.textContent = '…';
    try {
      const r = await api('/audit?' + new URLSearchParams({action:act.value, limit:size, offset:(page - 1) * size}));
      const pages = Math.max(1, Math.ceil(r.total / size));
      out.replaceChildren(r.total + T(' événement(s) — page ') + page + ' / ' + pages + ' ', h('button', {disabled: page <= 1, onclick: () => { page--; load(); }}, '◀'), ' ', h('button', {disabled: page >= pages, onclick: () => { page++; load(); }}, '▶'));
      box.replaceChildren(r.rows.length ? h('div', {style:'overflow-x:auto'}, h('table', {class:'t'}, h('thead', {}, h('tr', {}, [T('Date et heure'), T('Utilisateur'), T('Action'), T('Fiche'), T('Détail')].map(x => h('th', {}, x)))),
        h('tbody', {}, r.rows.map(x => h('tr', {}, h('td', {style:'white-space:nowrap'}, fmtS(Number(x.created_at))), h('td', {}, x.user_name || '—'), h('td', {}, AUDIT[x.action] || x.action),
          h('td', {}, x.prospect_id ? h('a', {href:'#', onclick: e => { e.preventDefault(); detail(Number(x.prospect_id)); }}, '#' + x.prospect_id) : ''), h('td', {}, x.detail || '')))))) : h('p', {class:'muted'}, T('Aucun événement.')));
    } catch (e) { box.textContent = e.message; }
  };
  act.addEventListener('change', () => { page = 1; load(); });
  return h('div', {class:'card'}, h('h3', {}, T('Journal d’activité')),
    h('p', {class:'muted'}, T('Transferts, clôtures automatiques, imports, exports, suppressions : qui, quand, quoi.')),
    h('div', {class:'bar'}, act, h('button', {onclick: () => { page = 1; load(); }}, T('Afficher / actualiser'))), out, box);
}
function admin() {
  const m2 = h('div', {class:'muted'}), m3 = h('span', {class:'muted'});
  const file = h('input', {type:'file', accept:'.xlsx,.xls,.csv,.txt'});
  const asg = h('select', {}, h('option', {value:'auto'}, T('Équitablement entre les commerciaux cochés')), h('option', {value:'categorie'}, T('Par catégorie (groupe de la catégorie, parmi les cochés)')));
  const impPick = commPicker(T('Affecter les prospects importés à (obligatoire) :')), dstPick = commPicker(T('Répartir entre (obligatoire) :'));
  const dsel = h('select', {}, modeOpts(false));
  const allCb = h('input', {type:'checkbox'});
  const orph = h('div');
  const invSub = h('input', {value:T('Accès à EasyCrm')});
  const invBody = h('textarea', {rows:9}, T('Bonjour {nom},\n\nVotre accès à EasyCrm (') + ((me.settings || {}).company || '') + T(') est activé.\n\nAdresse : {url}\nIdentifiant : {identifiant}\nMot de passe : celui de votre compte habituel.\n\nSur smartphone, ouvrez la même adresse dans le navigateur ou utilisez l’application fournie par votre administrateur.\n\nCordialement,\n') + ((me.settings || {}).company || ''));
  const invOut = h('span', {class:'muted'});
  const invChecks = (me.commerciaux || []).map(u => ({u, cb: h('input', {type:'checkbox', checked:true})}));
  const invAll = h('input', {type:'checkbox', checked:true, onchange: () => invChecks.forEach(x => x.cb.checked = invAll.checked)});
  const invList = h('div', {style:'max-height:200px;overflow:auto;border:1px solid var(--color-border);border-radius:6px;padding:6px;margin:6px 0'},
    h('label', {}, invAll, T(' Tout cocher / décocher')), ...invChecks.map(x => h('label', {style:'display:block'}, x.cb, ' ' + x.u.name)));
  const invite = async test => {
    const uids = invChecks.filter(x => x.cb.checked).map(x => x.u.uid);
    if (!test && !uids.length) { invOut.textContent = T('Cochez au moins un commercial.'); return; }
    if (!test && !confirm(T('Envoyer l’invitation à ') + uids.length + T(' commercial(aux) ?'))) return;
    invOut.textContent = T('Envoi en cours…');
    try {
      const r = await api('/invite', {method:'POST', body:{uids, subject:invSub.value, body:invBody.value, test}});
      invOut.textContent = r.sent + T(' envoyé(s)') + (r.no_mail.length ? T(' — sans e-mail : ') + r.no_mail.join(', ') : '') + (r.failed.length ? T(' — échec : ') + r.failed.join(', ') : '');
    } catch (e) { invOut.textContent = e.message; }
  };
  show(h('h2', {}, T('Administration')),
    me.can.fields ? generalCard() : '',
    me.can.fields ? telephonyCard() : '',
    me.can.fields ? teamsCard() : h('div', {class:'card'}, h('h3', {}, T('Équipes')),
      me.groups.length ? me.groups.map(g => h('div', {style:'margin:6px 0'}, h('strong', {}, g.name), ' : ' + ((me.teams[g.id] || []).length) + T(' commercial(aux)'), h('div', {class:'muted'}, T('Catégories : ') + g.cats.join(', ')))) : h('p', {class:'muted'}, T('Aucune équipe configurée.'))),
    me.can.fields ? fieldsEditor() : '',
    me.can.fields ? statusesEditor() : '',
    me.can.fields ? settingsCard() : '',
    me.can.assign ? transferCard() : '',
    me.can.assign ? bulkStatusCard() : '',
    me.can.audit ? auditCard() : '',
    !me.can.invite ? '' : h('div', {class:'card'}, h('h3', {}, T('Inviter les commerciaux par e-mail')),
      h('p', {class:'muted'}, T('Envoie à chaque commercial coché son adresse d’accès et son identifiant de connexion. Variables : {nom}, {identifiant}, {url}. Prérequis : serveur e-mail configuré dans Nextcloud (Paramètres d’administration → Paramètres de base).')),
      invSub, invBody, invList,
      h('div', {class:'bar'}, h('button', {onclick: () => invite(true)}, T('M’envoyer un test')), h('button', {class:'primary', onclick: () => invite(false)}, T('Envoyer aux commerciaux cochés')), invOut)),
    h('div', {class:'card'}, h('h3', {}, T('Importer des prospects (Excel ou CSV)')),
      h('p', {class:'muted'}, T('Colonnes, dans cet ordre : ') + [lab('lastname'), lab('phone'), lab('c5'), lab('c2'), lab('c3'), lab('c4'), lab('remarque')].join(' ; ') + T('. La 1re ligne (en-tête) est ignorée. Fichiers Excel (.xlsx, .xls) acceptés directement : 1re feuille, colonnes reconnues par leur titre (y compris vos champs personnalisés), doublons de numéros supprimés. Les numéros et les valeurs de listes sont corrigés automatiquement ; ce qui n’est pas reconnu est recopié dans le commentaire.')),
      file, impPick.el, asg,
      h('button', {class:'primary', onclick: async () => {
        const f0 = file.files[0];
        if (!f0) { m2.textContent = T('Choisissez un fichier Excel ou CSV.'); return; }
        const who = impPick.values();
        if (!who.length) { m2.textContent = T('Sélectionnez au moins un commercial à qui affecter les prospects.'); return; }
        const fd = new FormData(); fd.append('assign', asg.value); fd.append('uids', JSON.stringify(who));
        m2.textContent = T('Import en cours…');
        try {
          let pre = '';
          if (/\.xlsx?$/i.test(f0.name)) {
            const c = await excelToCsv(f0);
            fd.append('file', new Blob([c.csv], {type:'text/csv'}), 'import.csv'); fd.append('extras', JSON.stringify(c.extras));
            pre = TF(T('{0} ligne(s) lue(s), {1} doublon(s) dans le fichier supprimé(s)'), c.total, c.dupInFile) + (c.empty ? TF(T(', {0} ligne(s) vide(s) ignorée(s)'), c.empty) : '') + ' — ';
          } else { fd.append('file', f0); }
          const r = await api('/import', {method:'POST', body:fd});
          m2.replaceChildren(pre + TF(T('{0} importés, {1} déjà dans la base (doublons), {2} sans nom, {3} numéros invalides'), r.imported, r.duplicates, r.skipped, r.invalid)
            + (r.unrecognized ? TF(T(', {0} avec valeur non reconnue (voir commentaire)'), r.unrecognized) : '') + (r.unassigned ? TF(T(', {0} sans commercial (groupe vide ou catégorie vide).'), r.unassigned) : '.'),
            ...r.samples.map(x => h('div', {class:'err'}, x)));
        } catch (e) { m2.textContent = e.message; }
      }}, T('Importer')), m2),
    h('div', {class:'card'}, h('h3', {}, T('Répartir les prospects non assignés')), dstPick.el, dsel,
      h('button', {onclick: () => { const w = dstPick.values(); if (!w.length) { m3.textContent = T('Sélectionnez au moins un commercial.'); return; } distribute(dsel.value, m3, w); }}, T('Répartir')), m3,
      h('hr'),
      h('p', {class:'muted'}, T('Prospects encore assignés à des personnes qui ne sont plus dans le groupe des commerciaux (superviseurs, comptes supprimés…) : les réattribuer aux commerciaux actuels, avec le mode de répartition choisi ci-dessus.')),
      h('label', {style:'display:block;margin:6px 0'}, allCb, T(' Y compris les prospects déjà appelés (sinon seulement « À traiter »)')),
      h('button', {onclick: async () => {
        if (!confirm(T('Réattribuer ces prospects aux commerciaux actuels ?'))) return;
        m3.textContent = '…';
        try { const r = await api('/release', {method:'POST', body:{mode:dsel.value, all:allCb.checked}}); m3.textContent = r.reassigned + T(' prospect(s) réattribué(s)') + (r.not_assigned ? ' — ' + r.not_assigned + T(' sans commercial disponible') : '') + '.'; }
        catch (e) { m3.textContent = e.message; }
      }}, T('Réattribuer aux commerciaux actuels'))),
    orph,
    h('p', {class:'muted'}, T('Profils : définis par les groupes (AD ou Nextcloud) associés aux rôles administrateur, superviseur, commercial et lecture seule.')));
  api('/recordings').then(rows => {
    orph.replaceChildren(...(rows.length ? [h('h3', {}, T('Enregistrements non rattachés à une fiche')),
      h('p', {class:'muted'}, T('Numéro introuvable dans la base, ou appelé depuis un contact enregistré.')), ...rows.map(recRow)] : []));
  }).catch(() => {});
}

// ---- WhatsApp (boîte partagée) ----
let waBoxSel = null, waView = 0, waLast = -1;
const waTicks = m => m.dir !== 'out' ? '' : ({sent:' ✓', delivered:' ✓✓', read:' ✓✓', failed:' ⚠'}[m.status] || '');
const waUrl = id => API + '/wa/file/' + id;
/** Numéro, ou mention « nom d'utilisateur WhatsApp » quand Meta ne transmet pas le numéro */
const waWho = c => c.phone || (c.username ? T('👤 nom d’utilisateur WhatsApp (numéro masqué)') : '+' + c.wa_id);
const PREV = ['📷 Photo', '📄 Document', '🎤 Vocal', '🎬 Vidéo', '📍 Position', 'Autocollant', '[pièce jointe non enregistrée]', '[modèle ', '⚠ Non délivré : '];
const trPrev = s => { let t = String(s || ''); PREV.forEach(k => { if (t.includes(k)) t = t.split(k).join(T(k)); }); return t; };
const hhmm = t => t ? new Date(t * 1000).toLocaleTimeString('fr-FR', {hour:'2-digit', minute:'2-digit'}) : '';
function waWin(c) {
  if (!c.last_in_at) return h('span', {class:'muted'}, T('Le client n’a pas encore écrit'));
  return c.window_open ? h('span', {class:'wa-ok'}, TF('🟢 Réponse possible jusqu’à {0}', fmt(c.window_ends)))
    : h('span', {class:'wa-ko'}, T('⏳ Fenêtre de 24 h expirée'));
}
async function paintWa() {
  if (TEL.wa !== 'api') return;
  try {
    const c = await api('/wa/counters');
    const btn = root.querySelector('.nav button[data-k="wa"]');
    if (btn) { btn.querySelector('.ovd')?.remove(); const n = Number(c.unread || 0) || Number(c.queue || 0); if (n) btn.append(h('span', {class:'ovd', title: me.can.all ? T('Messages en file d’attente') : T('Messages non lus')}, String(n))); }
    const n = Number(c.unread || 0);
    if (waLast >= 0 && n > waLast && window.Notification && Notification.permission === 'granted') new Notification('WhatsApp', {body: TF('{0} nouveau(x) message(s) client', n - waLast)});
    waLast = n;
  } catch (e) {}
}
async function waList(off = 0, keep) {
  const boxes = me.can.all ? [['queue', T('File d’attente')], ['open', T('Ouvertes')], ['all', T('Toutes')], ['resolved', T('Résolues')]] : [['mine', T('Mes conversations')], ['resolved', T('Résolues')]];
  if (!waBoxSel || !boxes.some(b => b[0] === waBoxSel)) waBoxSel = boxes[0][0];
  const st = keep || {q:'', user:''};
  const q = h('input', {type:'search', placeholder:T('Rechercher (nom, numéro, message)…'), value: st.q});
  const u = me.can.all ? h('select', {}, h('option', {value:''}, T('Tous commerciaux')), me.commerciaux.map(x => h('option', {value:x.uid, selected: st.user === x.uid}, x.name))) : null;
  const box = h('div', {}, T('Chargement…'));
  const tabs = h('div', {class:'bar'}, boxes.map(([k, l]) => h('button', {class: waBoxSel === k ? 'primary' : '', onclick: () => { waBoxSel = k; waList(0, {q:q.value, user: u ? u.value : ''}); }}, l)));
  const load = async () => {
    try {
      const p = new URLSearchParams({box: waBoxSel, q: q.value.trim(), user: u ? u.value : '', offset: off});
      const r = await api('/wa?' + p);
      if (!r.rows.length) { box.replaceChildren(h('p', {class:'muted'}, waBoxSel === 'queue' ? T('Aucune conversation en attente d’attribution.') : T('Aucune conversation.'))); return; }
      box.replaceChildren(...r.rows.map(c => h('div', {class:'row' + (c.unread ? ' wa-unread' : ''), onclick: () => waConv(c.id, () => waList(off, {q:q.value, user: u ? u.value : ''}))},
        h('div', {style:'min-width:0;flex:1'},
          h('strong', {}, c.prospect_name || c.name || c.phone || c.wa_id), ' ', h('span', {class:'muted'}, waWho(c)),
          c.prospect_id ? '' : h('span', {class:'badge', style:'margin-left:6px;background:#e9a100;color:#000'}, T('Inconnu')),
          h('div', {class:'muted wa-prev'}, trPrev(c.preview)),
          h('div', {class:'muted'}, c.owner_name ? '👤 ' + c.owner_name : T('👥 File d’attente'), ' · ', waWin(c))),
        h('div', {style:'text-align:right;flex:none'}, h('div', {class:'muted'}, fmt(c.last_msg_at)), c.unread ? h('span', {class:'ovd'}, String(c.unread)) : '',
          c.status === 'resolu' ? h('div', {class:'muted'}, T('✔ Résolue')) : ''))),
        r.total > off + r.limit ? h('button', {onclick: () => waList(off + r.limit, {q:q.value, user: u ? u.value : ''})}, T('Suivantes →')) : '',
        off > 0 ? h('button', {onclick: () => waList(Math.max(0, off - r.limit), {q:q.value, user: u ? u.value : ''})}, T('← Précédentes')) : '',
        h('div', {class:'muted'}, TF('{0} conversation(s)', r.total)));
    } catch (e) { box.replaceChildren(h('p', {class:'err'}, e.message)); }
  };
  let t; q.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => { off = 0; load(); }, 350); });
  if (u) u.addEventListener('change', () => { off = 0; load(); });
  show(h('h2', {}, T('💬 WhatsApp')), tabs, h('div', {class:'bar'}, q, u || ''), box);
  load(); paintWa();
  const myView = ++waView;
  const iv = setInterval(() => { if (waView !== myView || !document.body.contains(box)) return clearInterval(iv); load(); }, 20000);
}
function waMedia(m) {
  if (!m.file) return '';
  const url = waUrl(m.id), mime = m.mime || '';
  if (m.type === 'image' || m.type === 'sticker' || mime.startsWith('image/')) return h('a', {href:url, target:'_blank', rel:'noopener'}, h('img', {src:url, class:'wa-img', loading:'lazy', alt: m.file_name || T('photo')}));
  if (m.type === 'audio' || mime.startsWith('audio/')) return h('audio', {controls:true, preload:'none', src:url, style:'width:100%'});
  if (m.type === 'video' || mime.startsWith('video/')) return h('video', {controls:true, preload:'none', src:url, style:'max-width:100%'});
  return h('a', {href:url, target:'_blank', rel:'noopener', class:'wa-doc'}, '📄 ' + (m.file_name || T('Document')));
}
async function waConv(id, back) {
  let d;
  try { d = await api('/wa/' + id); } catch (e) { return show(h('p', {class:'err'}, e.message)); }
  const myView = ++waView;
  const thread = h('div', {class:'wa-thread'});
  const head = h('div');
  const paint = dd => {
    const c = dd.conv; d = dd;
    head.replaceChildren(
      h('h2', {style:'margin:6px 0'}, c.prospect_name || c.name || c.phone || c.wa_id, ' ', h('span', {class:'muted'}, waWho(c))),
      c.name && c.prospect_name && c.name !== c.prospect_name ? h('div', {class:'muted'}, T('Nom WhatsApp : ') + c.name) : '',
      h('div', {class:'muted'}, c.owner_name ? '👤 ' + c.owner_name : T('👥 File d’attente'), ' · ', waWin(c), c.status === 'resolu' ? ' · ' + T('✔ Résolue') : ''),
      c.prospect_id ? h('button', {onclick: () => detail(c.prospect_id)}, T('📇 Ouvrir la fiche') + (c.prospect_status ? ' (' + (ST[c.prospect_status] || c.prospect_status) + ')' : '')) : '');
    const atBottom = thread.scrollHeight - thread.scrollTop - thread.clientHeight < 60;
    thread.replaceChildren(...dd.messages.map(m => h('div', {class:'wa-b ' + (m.dir === 'out' ? 'out' : 'in')},
      waMedia(m), m.body ? h('div', {class:'wa-t'}, trPrev(m.body)) : '',
      h('div', {class:'wa-meta'}, (m.dir === 'out' && m.user_name ? T(m.user_name) + ' · ' : '') + fmt(m.at), h('span', {class: m.status === 'read' ? 'wa-read' : m.status === 'failed' ? 'wa-ko' : ''}, waTicks(m))))));
    if (atBottom || !thread.dataset.done) { thread.scrollTop = thread.scrollHeight; thread.dataset.done = 1; }
  };
  paint(d);
  const c0 = d.conv;
  // ---- réponse ----
  const txt = h('textarea', {rows:3, placeholder:T('Votre réponse…')});
  const file = h('input', {type:'file', accept:'image/jpeg,image/png,application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt'});
  const msg = h('div', {class:'err'});
  const sendBtn = h('button', {class:'primary', onclick: async () => {
    msg.className = 'err'; msg.textContent = '';
    if (!txt.value.trim() && !file.files.length) { msg.textContent = T('Écrivez un message ou joignez un fichier.'); return; }
    const fd = new FormData(); fd.append('text', txt.value.trim()); if (file.files.length) fd.append('file', file.files[0]);
    sendBtn.disabled = true; sendBtn.textContent = T('Envoi…');
    try { await api('/wa/' + id + '/send', {method:'POST', body: fd}); txt.value = ''; file.value = ''; paint(await api('/wa/' + id)); }
    catch (e) { msg.textContent = T(e.message); }
    sendBtn.disabled = false; sendBtn.textContent = T('Envoyer');
  }}, T('Envoyer'));
  txt.addEventListener('keydown', e => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) sendBtn.click(); });
  const reply = !d.can.reply ? h('p', {class:'muted'}, T('Consultation seule.'))
    : (me.role === 'commercial' && c0.owner !== me.uid) ? h('p', {class:'muted'}, T('Conversation d’un autre commercial.'))
    : c0.window_open ? h('div', {class:'card'}, txt, h('div', {class:'bar', style:'margin-top:6px;align-items:center'}, file, sendBtn), h('div', {class:'muted'}, T('Ctrl+Entrée pour envoyer · photos JPG/PNG et documents (PDF, Word, Excel) · 50 Mo max.')), msg)
    : h('div', {class:'card'}, h('p', {}, T('Le client n’a pas écrit depuis plus de 24 h : WhatsApp n’autorise plus de réponse libre depuis le CRM.')),
        c0.phone ? h('a', {class:'big', href:'tel:' + c0.phone}, T('📞 Appeler ') + c0.phone) : '',
        c0.username ? '' : h('a', {class:'big', style:'background:#25D366;color:#fff', target:'_blank', rel:'noopener noreferrer', href:'https://wa.me/' + c0.wa_id}, T('💬 Écrire depuis mon WhatsApp')));
  // ---- actions superviseur ----
  const amsg = h('span', {class:'muted'});
  const actions = h('div', {class:'bar', style:'align-items:center'});
  if (d.can.assign) {
    const asg = h('select', {}, h('option', {value:''}, T('— File d’attente —')), me.commerciaux.map(u => h('option', {value:u.uid, selected: c0.owner === u.uid}, u.name)));
    asg.addEventListener('change', async () => { amsg.textContent = '…'; try { await api('/wa/' + id + '/assign', {method:'POST', body:{uid: asg.value}}); amsg.textContent = T('Attribuée.'); paint(await api('/wa/' + id)); paintWa(); } catch (e) { amsg.textContent = T(e.message); } });
    actions.append(h('label', {}, T('Attribuer à '), asg));
    if (!c0.prospect_id) {
      const nmIn = h('input', {type:'text', value: c0.name || '', placeholder:T('Nom du client')});
      const cat = h('select', {}, h('option', {value:''}, T('Catégorie —')), (me.options.c2 || []).map(x => h('option', {value:x}, x)));
      actions.append(h('details', {class:'card', style:'width:100%'}, h('summary', {style:'cursor:pointer'}, T('➕ Créer la fiche prospect de ce numéro')),
        h('div', {class:'bar', style:'margin-top:6px'}, nmIn, cat,
          h('button', {class:'primary', onclick: async () => {
            amsg.textContent = '…';
            try { const r = await api('/wa/' + id + '/prospect', {method:'POST', body:{name: nmIn.value.trim(), c2: cat.value, uid: asg.value}}); amsg.textContent = r.existing ? T('Fiche existante rattachée.') : T('Fiche créée.'); waConv(id, back); }
            catch (e) { amsg.textContent = T(e.message); }
          }}, T('Créer la fiche'))), h('div', {class:'muted'}, T('La fiche est attribuée au commercial choisi ci-dessus (ou reste non assignée).'))));
    }
  }
  if (d.can.reply) actions.append(h('button', {onclick: async () => { try { await api('/wa/' + id + '/resolve', {method:'POST'}); waConv(id, back); } catch (e) { amsg.textContent = T(e.message); } }}, c0.status === 'resolu' ? T('↺ Rouvrir') : T('✔ Marquer résolue')));
  actions.append(amsg);
  show(h('button', {onclick: () => (back || (() => { tab = 'wa'; waList(); }))()}, T('← Retour')), head, actions, thread, reply);
  paintWa();
  const iv = setInterval(async () => {
    if (waView !== myView || !document.body.contains(thread)) return clearInterval(iv);
    try { paint(await api('/wa/' + id)); } catch (e) {}
  }, 10000);
}
/** Bloc WhatsApp de la fiche prospect */
function waOnProspect(pid) {
  const el = h('div');
  api('/wa/prospect/' + pid).then(r => {
    const c = r.conv;
    if (!c) return;
    el.replaceChildren(h('div', {class:'card'}, h('strong', {}, T('💬 Conversation WhatsApp')), c.unread ? h('span', {class:'ovd'}, String(c.unread)) : '',
      h('div', {class:'muted wa-prev'}, fmt(c.last_msg_at) + ' — ' + trPrev(c.preview)), h('div', {class:'muted'}, waWin(c)),
      h('button', {style:'margin-top:6px', onclick: () => waConv(c.id, () => detail(pid))}, T('Ouvrir la conversation'))));
  }).catch(() => {});
  return el;
}


async function checkReminders() {
  if (!me.can.call || popup) return;
  try {
    const rows = await api('/prospects?rappels=1');
    const now = Date.now() / 1000;
    const due = rows.find(r => r.assigned_to === me.uid && Number(r.callback_at) <= now && (snooze[r.id] || 0) < now);
    if (!due) return;
    popup = true;
    const close = mins => { snooze[due.id] = Date.now() / 1000 + mins * 60; saveSnooze(); box.remove(); popup = false; };
    const box = h('div', {class:'ccp-bg'}, h('div', {class:'ccp'},
      h('h3', {}, T('⏰ Rappel client')),
      h('p', {}, h('strong', {}, nm(due)), ' — ' + due.phone),
      h('p', {class:'muted'}, T('Prévu : ') + fmt(Number(due.callback_at)) + (due.last_note ? ' — ' + due.last_note : '')),
      h('a', {href:'https://wa.me/' + waNum(due.phone), target:'_blank', rel:'noopener noreferrer', onclick: () => close(30), style:'display:block;text-align:center;padding:14px;font-size:18px;border-radius:10px;margin:10px 0;background:#25D366;color:#fff;text-decoration:none'}, T('💬 WhatsApp')),
      h('a', {href:'tel:' + due.phone, onclick: () => close(30), style:'display:block;text-align:center;padding:14px;font-size:18px;border-radius:10px;margin:10px 0;background:var(--color-primary-element);color:var(--color-primary-element-text);text-decoration:none'}, T('📞 Appeler')),
      h('button', {onclick: () => { close(30); detail(due.id); }}, T('Ouvrir la fiche')),
      h('button', {onclick: () => close(10)}, T('Plus tard (10 min)'))));
    document.body.append(box);
    if (window.Notification && Notification.permission === 'granted') new Notification(T('Rappel client'), {body: nm(due) + ' — ' + due.phone});
  } catch (e) {}
}

(async () => {
  try { me = await api('/me'); }
  catch (e) { root.textContent = e.offline ? T('Connexion indisponible : rechargez la page quand le réseau revient.') : T('Accès refusé : demandez à l’administrateur de vous ajouter à un groupe easycrm_*.'); return; }
  applyStatuses();
  document.body.append(banner); paintBanner(); flushQueue();
  tab = me.role === 'commercial' ? 'nouveaux' : 'tous';
  go(tab);
  if (window.Notification && Notification.permission === 'default') Notification.requestPermission();
  checkReminders(); setInterval(checkReminders, 30000);
  setInterval(paintWa, 20000);
})();
})();
