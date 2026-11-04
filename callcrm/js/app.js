(() => {
const root = document.getElementById('callcrm');
const API = root.dataset.api;
const tok = () => document.head.dataset.requesttoken || (window.OC && OC.requestToken) || '';
let ST = {}, RES = [], STD = {}, STC = {}, KINDS = {}, TABS = [];
function applyStatuses() {
  const all = me.statuses || [];
  ST = {}; STD = {}; STC = {};
  all.forEach(x => { ST[x.key] = x.label; STD[x.key] = x; STC[x.key] = x.color; });
  RES = all.filter(x => x.result && x.active).map(x => x.key);
  const act = all.filter(x => x.result && x.active);
  KINDS = {nouveaux: {t:'À traiter', status:'nouveau', asc:1}};
  TABS = [['nouveaux', 'À traiter']];
  if (act.some(x => x.callback)) { KINDS.rappels = {t:'À rappeler', rappels:1}; TABS.push(['rappels', 'À rappeler']); }
  act.filter(x => !x.callback).forEach(x => { KINDS['st_' + x.key] = {t:x.label, status:x.key}; TABS.push(['st_' + x.key, x.label]); });
  KINDS.tous = {t:'Tous les prospects', all:1};
}
const inkOn = c => { const n = parseInt(String(c).slice(1), 16) || 0, l = (0.299 * (n >> 16) + 0.587 * ((n >> 8) & 255) + 0.114 * (n & 255)); return l > 160 ? '#000' : '#fff'; };
const badge = k => h('span', {class:'badge', style:'background:' + (STC[k] || '#888') + ';color:' + inkOn(STC[k] || '#888')}, ST[k] || k);
const fmtD = t => Number(t) ? new Date(t * 1000).toLocaleDateString('fr-FR', {day:'2-digit', month:'2-digit', year:'numeric'}) : '';
const fmtS = t => t ? new Date(t * 1000).toLocaleString('fr-FR', {day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit', second:'2-digit', hour12:false}) : '';
const fmt = t => t ? new Date(t * 1000).toLocaleString('fr-FR', {day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit'}) : '';
const waNum = p => { const d = String(p || '').replace(/\D+/g, ''); return d.startsWith('0') ? '213' + d.slice(1) : d; };
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
  try { r = await fetch(API + path, o); } catch (e) { const x = new Error('Connexion indisponible'); x.offline = true; throw x; }
  const j = await r.json().catch(() => ({}));
  if (!r.ok) throw new Error(j.error || ('Erreur ' + r.status));
  return j;
}
// ---- file d'attente hors ligne (enregistrement d'appels différé) ----
const QK = 'callcrm_queue';
const qGet = () => { try { return JSON.parse(localStorage.getItem(QK) || '[]'); } catch (e) { return []; } };
const qSet = a => { try { localStorage.setItem(QK, JSON.stringify(a)); } catch (e) {} };
let flushing = false, lastFlushMsg = '';
const banner = h('div', {style:'position:fixed;left:0;right:0;bottom:0;z-index:9999;padding:8px 12px;text-align:center;font-size:14px;display:none;color:#fff'});
function paintBanner() {
  const n = qGet().length, off = !navigator.onLine;
  banner.style.display = (n || off || lastFlushMsg) ? '' : 'none';
  banner.style.background = off ? '#c0392b' : n ? '#e9a100' : '#2e9e55';
  if (!off && !n) banner.style.color = '#fff'; else banner.style.color = off ? '#fff' : '#000';
  banner.textContent = (off ? 'Hors ligne' : '') + (off && n ? ' — ' : '') + (n ? n + ' appel(s) en attente d’envoi' + (off ? '' : ' …') : '') + (!off && !n ? lastFlushMsg : '');
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
  lastFlushMsg = (sent ? sent + ' appel(s) envoyé(s). ' : '') + (dropped ? dropped + ' refusé(s) par le serveur (fiche supprimée ou droits).' : '');
  paintBanner();
  if (lastFlushMsg) setTimeout(() => { lastFlushMsg = ''; paintBanner(); }, 8000);
}
window.addEventListener('online', flushQueue); window.addEventListener('offline', paintBanner);
setInterval(flushQueue, 30000);
let sortBy = {k:'', d:'desc'};
const sel = new Set();
let pageSize = 10; try { const v = localStorage.getItem('callcrm_ps2'); if (v !== null && [0, 10, 20, 30].includes(Number(v))) pageSize = Number(v); } catch (e) {}
let me, tab = 'nouveaux', filt = {q:'', status:'', assigned:''}, snooze = {}, popup = false;
try { snooze = JSON.parse(localStorage.getItem('callcrm_snooze') || '{}'); } catch (e) {}
const saveSnooze = () => { try { localStorage.setItem('callcrm_snooze', JSON.stringify(snooze)); } catch (e) {} };
const playRec = async (id, holder) => {
  holder.textContent = 'Chargement…';
  try {
    const r = await fetch(API + '/recordings/' + id, {headers:{requesttoken: tok()}});
    if (!r.ok) throw new Error('Erreur ' + r.status);
    const a = h('audio', {controls:true, autoplay:true, style:'width:100%'});
    a.src = URL.createObjectURL(await r.blob());
    holder.replaceChildren(a);
  } catch (e) { holder.textContent = e.message; }
};
const recRow = c => { const slot = h('div'); return h('div', {class:'card'}, '🎧 ', fmt(Number(c.recorded_at)), ' — ', c.user_name || c.user_id, c.phone ? ' — ' + c.phone : '',
  c.title && !c.prospect_id ? h('div', {class:'muted'}, c.title) : '', slot, h('button', {style:'margin-top:4px', onclick: () => playRec(c.id, slot)}, '▶ Écouter')); };
const fval = (p, f) => f.builtin ? (p[f.key] || '') : ((p.extra || {})[f.key] || '');
const collect = box => { const b = {}; box.querySelectorAll('[data-k]').forEach(e => {
  b[e.dataset.k] = e.dataset.multi ? Array.from(e.querySelectorAll('input:checked')).map(c => c.value).join(' | ') : e.value; }); return b; };
function fieldEl(f, val, disabled) {
  const st = 'width:100%;box-sizing:border-box', a = {'data-k': f.key, disabled, style: st};
  if (f.type === 'textarea') return h('textarea', {...a, rows: 3}, val);
  if (f.type === 'select') return h('select', a, h('option', {value:''}, '—'), (f.options || []).map(x => h('option', {value:x, selected: val === x}, x)));
  if (f.type === 'yesno') return h('select', a, h('option', {value:''}, '—'), ['Oui', 'Non'].map(x => h('option', {value:x, selected: val === x}, x)));
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
// Agenda, client chronicle and unsaved-change protection.
const dirtyChecks = new Map();
function watchEdits(key,label,check){dirtyChecks.set(key,{label,check});}
function canLeave(action,only=null){
 const pending=[...dirtyChecks.entries()].filter(([key,v])=>(!only||only.includes(key))&&v.check());
 if(!pending.length)return true;
 if(root.querySelector('.crm-leave-warning'))return false;
 const close=()=>{overlay.remove();};
 const overlay=h('div',{class:'crm-dialog-bg crm-leave-warning'},h('section',{class:'crm-dialog',role:'dialog','aria-modal':'true','aria-label':'Modifications non enregistrées'},
 h('h2',{},'Modifications non enregistrées'),h('p',{},'Ces informations seront perdues si vous quittez cette fiche.'),h('ul',{},pending.map(([,v])=>h('li',{},v.label))),
 h('button',{class:'primary',onclick:close},'Rester sur la fiche'),h('button',{onclick:()=>{pending.forEach(([key])=>dirtyChecks.delete(key));close();action();}},'Quitter sans enregistrer')));
 root.append(overlay);overlay.querySelector('button').focus();return false;
}
window.addEventListener('beforeunload',e=>{if([...dirtyChecks.values()].some(v=>v.check())){e.preventDefault();e.returnValue='';}});
function chronology(pid){
 const box=h('div',{class:'card crm-chronology'}),items=h('div'),message=h('p',{class:'muted'});let kind='all',cursor='',sequence=0;
 const more=h('button',{onclick:()=>load(true)},'Afficher les événements précédents');
 const title={call:'Appel',note:'Commentaire modifié',sim:'Simulation enregistrée',assign:'Affectation modifiée',appointment:'Rendez-vous',documents:'Documents client'};
 const render=e=>{const b=e.body||{};let body;
  if(e.kind==='call')body=[h('strong',{},ST[b.result]||b.result),b.reason?h('p',{},'Motif : '+b.reason):'',b.note?h('p',{},b.note):'',b.callback_at?h('small',{},'Rappel : '+fmt(b.callback_at)):''];
  else if(e.kind==='assign')body=[b.from+' → '+b.to];
  else if(e.kind==='sim')body=[h('strong',{},Math.round(b.total||0).toLocaleString('fr-FR')+' DA'),h('p',{},[b.in?.model,b.in?.w&&b.in?.l?b.in.w+' × '+b.in.l+' m':''].filter(Boolean).join(' · '))];
  else if(e.kind==='appointment')body=[h('strong',{},b.title),h('p',{},fmt(b.starts_at)+' · '+({open:'Prévu',done:'Terminé',cancelled:'Annulé'}[b.state]||'')),b.notes||''];
  else if(e.kind==='documents')body=[b.action==='received'?'Documents reçus du client : '+b.count+(b.folder?' · '+b.folder:''):'Lien envoyé au client : '+b.count+' document(s) demandé(s)'+(b.expires_at?', valable jusqu’au '+fmt(b.expires_at):'')];
  else body=[b.note||'Commentaire effacé'];
  return h('article',{class:'crm-event','data-kind':e.kind},h('div',{class:'bar'},h('strong',{},title[e.kind]||'Événement'),h('small',{},fmt(e.created_at))),h('div',{class:'crm-event-body'},body),h('small',{},e.author));
 };
 async function load(append=false){const request=++sequence;more.disabled=true;message.textContent='Chargement…';
  try{const r=await api('/prospects/'+pid+'/timeline?'+new URLSearchParams({kind,cursor:append?cursor:''}));if(request!==sequence)return;if(!box.isConnected)return;
   if(!append)items.replaceChildren();items.append(...r.rows.map(render));cursor=r.cursor;more.hidden=!r.has_more;message.textContent=items.children.length?'':'Aucun événement pour ce filtre.';
  }catch(e){if(request===sequence)message.textContent=e.message;}finally{if(request===sequence)more.disabled=false;}}
 const filters=h('div',{class:'bar crm-timeline-filters'},[['all','Tout'],['call','Appels'],['note','Notes'],['sim','Simulations'],['assign','Affectations'],['appointment','Rendez-vous']].map(([key,label])=>h('button',{class:key==='all'?'on':'',onclick:e=>{kind=key;filters.querySelectorAll('button').forEach(b=>b.classList.toggle('on',b===e.currentTarget));load();}},label)));
 box.append(h('h3',{},'Chronologie du client'),filters,message,items,more);setTimeout(()=>load(),0);return box;
}
function appointmentEditor(pid,appointment=null,after=()=>{}){
 const zone=me.prefs?.tz||'Africa/Algiers';const local=t=>{const parts=new Intl.DateTimeFormat('sv-SE',{timeZone:zone,year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hour12:false}).format(new Date(t*1000));return parts.replace(' ','T');};
 const date=h('input',{type:'datetime-local',value:appointment?local(appointment.starts_at):'',required:true}),title=h('input',{value:appointment?.title||'Rendez-vous client',maxlength:120}),notes=h('textarea',{rows:3,maxlength:2000},appointment?.notes||''),msg=h('p',{class:'muted',role:'status'});
 const initial=()=>JSON.stringify([date.value,title.value,notes.value]);const baseline=initial(),key='appointment';watchEdits(key,'Rendez-vous non enregistré',()=>overlay.isConnected&&initial()!==baseline);
 const close=()=>{if(canLeave(()=>{dirtyChecks.delete(key);overlay.remove();},[key])){dirtyChecks.delete(key);overlay.remove();}};
 const submit=async state=>{buttons.querySelectorAll('button').forEach(b=>b.disabled=true);msg.textContent='Enregistrement…';try{await api('/prospects/'+pid+'/appointments',{method:'POST',body:{id:appointment?.id||0,revision:appointment?.revision||0,date:date.value,title:title.value,notes:notes.value,state}});dirtyChecks.delete(key);overlay.remove();after();}catch(e){msg.textContent=e.message;}finally{buttons.querySelectorAll('button').forEach(b=>b.disabled=false);}};
 const buttons=h('div',{class:'bar'},h('button',{class:'primary',onclick:()=>submit('open')},'Enregistrer le rendez-vous'),appointment?h('button',{onclick:()=>submit('done')},'Terminé'):'',appointment?h('button',{onclick:()=>submit('cancelled')},'Annuler le rendez-vous'):'');
 const overlay=h('div',{class:'crm-dialog-bg'},h('section',{class:'crm-dialog',role:'dialog','aria-modal':'true','aria-label':'Rendez-vous client'},h('div',{class:'bar'},h('h2',{},appointment?'Modifier le rendez-vous':'Nouveau rendez-vous'),h('button',{'aria-label':'Fermer',onclick:close},'×')),h('label',{},'Date et heure · '+zone,date),h('label',{},'Objet',title),h('label',{},'Consignes',notes),msg,buttons));root.append(overlay);date.focus();
}
function clientWorkspace(p,info,form,note,reason,cb,getResult,saveCall){
 let baseline=JSON.stringify(collect(info));const saved=e=>{baseline=JSON.stringify(e.detail||collect(info));};
 watchEdits('client','Informations du client modifiées',()=>info.isConnected&&JSON.stringify(collect(info))!==baseline);
 watchEdits('call','Résultat d’appel non enregistré',()=>form.isConnected&&!!getResult());
 info.addEventListener('crm-saved',saved);
 const wrap=h('details',{class:'card crm-client-info'},h('summary',{},'Informations du client'));info.classList.remove('card');info.replaceWith(wrap);wrap.append(info);if(window.innerWidth>760)wrap.open=true;
 const columns=root.querySelector('.cols');if(columns){columns.classList.add('crm-client-columns');columns.children[1].append(collectCard(p),documentsCard(p.id));}
 const footer=h('div',{class:'crm-client-footer'},p.phone&&me.can.call?h('a',{class:'footer-call',href:'tel:'+p.phone},'☎ Appeler'):'',p.phone&&me.can.call?h('a',{class:'footer-wa',href:'https://wa.me/'+waNum(p.phone),target:'_blank',rel:'noopener noreferrer'},'WhatsApp'):'',me.can.call?h('button',{class:'primary',onclick:()=>{if(getResult())saveCall();else{wrap.open=true;info.querySelector('button')?.click();}}},'Enregistrer'):'');root.querySelector('.crm-page')?.append(footer);
}
function agenda(){
 let period='today',offset=0,sequence=0;const box=h('div'),rows=h('div'),counts=h('div',{class:'team-kpis'}),msg=h('p',{class:'muted'}),search=h('input',{type:'search',placeholder:'Rechercher un client…','aria-label':'Rechercher un client'}),type=h('select',{'aria-label':'Type'},h('option',{value:'all'},'Tous les types'),h('option',{value:'callback'},'Rappels'),h('option',{value:'appointment'},'Rendez-vous'));
 const assigned=h('select',{'aria-label':'Commercial'},h('option',{value:''},'Toute l’équipe'),(me.commerciaux||[]).map(u=>h('option',{value:u.uid},u.name)));
 const tabs=h('div',{class:'bar crm-agenda-tabs'},[['today','Aujourd’hui'],['tomorrow','Demain'],['late','En retard']].map(([k,l])=>h('button',{class:k===period?'primary':'',onclick:e=>{period=k;tabs.querySelectorAll('button').forEach(b=>b.classList.toggle('primary',b===e.currentTarget));load();}},l)));
 const more=h('button',{onclick:()=>load(true)},'Afficher les suivants');
 async function load(append=false){const request=++sequence;more.disabled=true;msg.textContent='Chargement…';if(!append)offset=0;
  try{const r=await api('/agenda?'+new URLSearchParams({period,type:type.value,assigned:me.can.all?assigned.value:'',q:search.value,offset,limit:20}));if(request!==sequence||!box.isConnected)return;counts.replaceChildren(...[['today','À faire aujourd’hui'],['late','En retard'],['tomorrow','Prévus demain']].map(([k,l])=>h('div',{class:'team-kpi'},h('strong',{},String(r.counts[k])),h('span',{},l))));
   if(!append)rows.replaceChildren();rows.append(...r.rows.map(a=>h('article',{class:'card crm-agenda-entry'+(a.late?' is-late':'')},h('div',{class:'bar'},h('strong',{},fmt(a.starts_at)),h('span',{class:'badge'},a.type==='callback'?'Rappel':'Rendez-vous'),a.late?h('span',{class:'err'},'En retard'):''),h('h3',{},a.client_name),h('p',{},a.title),h('p',{class:'muted'},a.notes),h('small',{},a.owner),h('div',{class:'bar'},h('button',{onclick:()=>detail(a.prospect_id)},'Ouvrir la fiche ↗'),a.phone&&me.can.call?h('a',{href:'tel:'+a.phone,class:'crm-agenda-call'},'☎ Appeler'):'',a.type==='appointment'&&(me.can.call||me.can.assign)?h('button',{onclick:()=>appointmentEditor(a.prospect_id,{id:a.appointment_id,starts_at:a.starts_at,title:a.title,notes:a.notes,revision:a.revision},()=>load())},'Modifier / terminer'):''))));
   offset=r.next_offset;more.hidden=!r.has_more;msg.textContent=r.rows.length?'Fuseau : '+r.timezone:'Aucun élément pour cette période.';
  }catch(e){if(request===sequence)msg.textContent=e.message;}finally{if(request===sequence)more.disabled=false;}}
 let timer;search.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>load(),300);});type.addEventListener('change',()=>load());assigned.addEventListener('change',()=>load());
 box.append(counts,tabs,h('div',{class:'bar'},search,type,me.can.all?assigned:''),msg,rows,more);show(h('h2',{},'Mon agenda'),h('p',{class:'muted'},'Vos rappels et rendez-vous, au même endroit. Créez un rendez-vous depuis la fiche du client.'),box);load();
}

function discovery(mode='search',initial=''){
 const q=h('input',{type:'search',placeholder:'Nom, téléphone, commentaire ou commercial…',value:initial,'aria-label':'Recherche globale'}),days=h('input',{type:'number',min:1,max:365,value:7,'aria-label':'Jours sans suivi'}),type=h('select',{'aria-label':'Type de relance'},h('option',{value:'idle'},'Sans appel récent'),h('option',{value:'callback'},'Rappels dépassés'),h('option',{value:'estimate'},'Estimation sans suivi'));
 const dayControl=h('label',{},'Depuis plus de ',days,' jours');
 const rows=h('div'),out=h('p',{class:'muted'}),box=h('div'),more=h('button',{onclick:()=>load(true)},'Afficher les suivants');let offset=0,seq=0;
 async function load(append=false){const n=++seq;if(!append)offset=0;more.disabled=true;out.textContent='Recherche…';try{const r=await api((mode==='search'?'/search?':'/neglected?')+new URLSearchParams({q:q.value,type:type.value,days:days.value,offset}));if(n!==seq||!box.isConnected)return;if(!append)rows.replaceChildren();rows.append(...r.rows.map(p=>h('div',{class:'card'},h('h3',{},nm(p)),badge(p.status),h('p',{class:'muted'},p.owner),p.last_call_at?h('p',{},'Dernier appel : '+fmt(p.last_call_at)):'',h('div',{class:'bar'},h('button',{onclick:()=>detail(p.id)},'Ouvrir la fiche'),p.phone&&me.can.call?h('a',{href:'tel:'+p.phone,class:'crm-agenda-call'},'☎ Appeler '+p.phone):''))));offset=r.next_offset;more.hidden=!r.has_more;out.textContent=r.rows.length?'':mode==='search'&&q.value.trim().length<2?'Saisissez au moins 2 caractères.':'Aucun prospect correspondant.';}catch(e){out.textContent=e.message;}finally{more.disabled=false;}}
 let timer;q.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>load(),300);});type.addEventListener('change',()=>{dayControl.hidden=type.value==='callback';load();});days.addEventListener('change',()=>load());box.append(h('div',{class:'bar'},mode==='search'?q:type,mode==='search'?'':dayControl),out,rows,more);
 show(h('h2',{},mode==='search'?'Recherche globale':'Prospects à relancer'),h('p',{class:'muted'},mode==='search'?'Recherche dans les dossiers auxquels vous avez accès.':'Repérez les dossiers sans suivi, puis appelez ou ouvrez la fiche pour les réattribuer.'),box);load();
}

function documentsCard(pid=null){
 const box=h('div',{class:'card crm-documents'}),rows=h('div'),msg=h('p',{class:'muted',role:'status'});let offset=0,sequence=0;
 const more=h('button',{onclick:()=>load(true)},'Afficher les suivantes');
 const render=d=>{const actions=h('div',{class:'bar'});if(d.has_original)actions.append(h('a',{class:'crm-agenda-call',href:API+'/documents/'+d.id+'/download',target:'_blank',rel:'noopener noreferrer'},'Télécharger l’original'));if(d.has_pdf)actions.append(h('a',{class:'crm-agenda-call',href:API+'/documents/'+d.id+'/download?format=pdf',target:'_blank',rel:'noopener noreferrer'},'PDF'));
  if(d.state==='failed'&&(me.can.call||me.can.assign))actions.append(h('button',{onclick:async()=>{try{await api('/documents/'+d.id+'/retry',{method:'POST'});load();}catch(e){msg.textContent=e.message;}}},'Réessayer la conversion'));
  const article=h('article',{class:'crm-event-body'},h('strong',{},d.name),h('p',{class:'muted'},({email:'E-mail',whatsapp:'WhatsApp',manual:'Ajout manuel'}[d.source]||d.source)+' · '+fmt(d.created_at)+' · '+Math.ceil(Number(d.size)/1024)+' Ko'),h('p',{},({queued:'Conversion PDF en attente',waiting_original:'Récupération du fichier en attente',processing:'Conversion en cours',ready:'PDF disponible',original:'Original conservé · format sans conversion PDF',failed:'Traitement à vérifier'}[d.state]||d.state)),d.error?h('p',{class:'err'},d.error):'',d.sender?h('small',{},'Expéditeur : '+(d.source==='manual'?((me.commerciaux||[]).find(u=>u.uid===d.sender)?.name||'Utilisateur CRM'):d.sender)):'',d.subject?h('p',{class:'muted'},d.subject):'',actions);
  if(pid===null){const search=h('input',{type:'search',placeholder:'Rechercher le client…','aria-label':'Rechercher le client destinataire'}),select=h('select',{'aria-label':'Client destinataire'},h('option',{value:''},'— Choisir un client —'));let seq=0,timer;
   search.addEventListener('input',()=>{clearTimeout(timer);const request=++seq;select.replaceChildren(h('option',{value:''},'— Choisir un client —'));timer=setTimeout(async()=>{try{const r=await api('/search?'+new URLSearchParams({q:search.value}));if(request!==seq)return;select.append(...r.rows.map(p=>h('option',{value:p.id},nm(p)+(p.phone?' · '+p.phone:''))));}catch(e){msg.textContent=e.message;}},300);});
   article.append(h('div',{class:'bar'},search,select,h('button',{onclick:async()=>{if(!select.value){msg.textContent='Choisissez le client destinataire.';return;}try{await api('/documents/'+d.id+'/attach',{method:'POST',body:{prospect_id:Number(select.value)}});load();}catch(e){msg.textContent=e.message;}}},'Rattacher à ce client')));
  }return article;
 };
 async function load(append=false){const request=++sequence;if(!append)offset=0;more.disabled=true;try{const r=await api(pid===null?'/documents/inbox?offset='+offset:'/prospects/'+pid+'/documents?offset='+offset);if(request!==sequence||!box.isConnected)return;if(!append)rows.replaceChildren();rows.append(...r.rows.map(render));offset=r.next_offset;more.hidden=!r.has_more;msg.textContent=rows.children.length?'Les originaux sont conservés. Conversion traitée par les tâches de fond Nextcloud.':'Aucune pièce pour le moment.';}catch(e){if(request===sequence)msg.textContent=e.message;}finally{more.disabled=false;}}
 box.append(h('h3',{},pid===null?'Pièces reçues · À rattacher':'Pièces jointes du client'),h('button',{onclick:()=>load()},'Actualiser'),msg,rows,more);
 if(pid!==null&&(me.can.call||me.can.assign)){const file=h('input',{type:'file','aria-label':'Ajouter une pièce au dossier'}),upload=h('button',{onclick:async()=>{if(!file.files.length){msg.textContent='Choisissez un fichier.';return;}if(file.files[0].size>25*1024*1024){msg.textContent='Fichier limité à 25 Mo.';return;}const fd=new FormData();fd.append('file',file.files[0]);upload.disabled=true;try{await api('/prospects/'+pid+'/documents',{method:'POST',body:fd});file.value='';load();}catch(e){msg.textContent=e.message;}finally{upload.disabled=false;}}},'Ajouter au dossier');box.append(h('div',{class:'bar'},file,upload));watchEdits('document-upload','Pièce jointe sélectionnée mais non envoyée',()=>box.isConnected&&file.files.length>0);}
 setTimeout(()=>load(),0);return box;
}
/** Lien de dépôt de documents envoyé au client : création (liste et durée réglables), suivi, annulation. */
function collectCard(p){
 const box=h('details',{class:'card crm-collect-card',open:window.innerWidth>760}),rows=h('div'),msg=h('p',{class:'muted',role:'status'}),result=h('div');
 const canSend=me.can.call||me.can.assign;
 const STATE={open:'En attente du client',expired:'Expiré',revoked:'Annulé / remplacé',submitted:'Documents reçus',submitting:'Envoi en cours'};
 async function load(){try{const r=await api('/prospects/'+p.id+'/collect');if(!box.isConnected)return;rows.replaceChildren(...r.map(l=>h('div',{class:'crm-collect-link','data-state':l.state},
  h('div',{class:'bar'},h('strong',{},STATE[l.state]||l.state),h('small',{},l.uploaded+' / '+l.requested+' document(s)')),
  h('small',{},'Créé le '+fmt(l.created_at)+(l.state==='open'?' · expire le '+fmt(l.expires_at):'')+(l.submitted_at?' · reçu le '+fmt(l.submitted_at):'')),
  l.state==='submitted'&&l.folder_path?h('p',{},'Dossier Nextcloud de '+(l.owner||'')+' : ',h('a',{href:OC.generateUrl('/apps/files/?dir='+encodeURIComponent(l.folder_path)),target:'_blank',rel:'noopener noreferrer'},l.folder_path)):'',
  l.state==='open'&&canSend?h('button',{onclick:async()=>{if(!confirm('Annuler ce lien ? Le client ne pourra plus l’utiliser.'))return;try{await api('/collect/'+l.id+'/revoke',{method:'POST'});load();}catch(e){msg.textContent=e.message;}}},'Annuler le lien'):'')));
  msg.textContent=r.length?'':'Aucune demande envoyée.';}catch(e){msg.textContent=e.message;}}
 /** Liste et validité définies par l'administrateur : le commercial envoie simplement le lien. */
 async function send(btn){
  if(!confirm('Créer un lien de dépôt de documents pour ce client ? Un lien encore ouvert sera remplacé.'))return;
  btn.disabled=true;try{const r=await api('/prospects/'+p.id+'/collect',{method:'POST'});showLink(r.url,r.link);load();}catch(e){msg.textContent=e.message;}finally{btn.disabled=false;}
 }
 function showLink(url,link){
  const text='Bonjour, merci d’envoyer vos documents avec votre téléphone en ouvrant ce lien sécurisé (valable jusqu’au '+fmt(link.expires_at)+') : '+url;
  const field=h('input',{value:url,readonly:true,'aria-label':'Lien à envoyer au client',onfocus:e=>e.target.select()});
  result.replaceChildren(h('div',{class:'crm-collect-result'},h('strong',{},'Lien créé ('+link.requested+' document(s), valable jusqu’au '+fmt(link.expires_at)+') — à envoyer au client :'),field,
   h('div',{class:'bar'},
    h('button',{class:'primary',onclick:async()=>{try{await navigator.clipboard.writeText(url);msg.textContent='Lien copié.';}catch(e){field.select();msg.textContent='Copiez le lien sélectionné.';}}},'Copier le lien'),
    p.phone?h('a',{class:'crm-agenda-call',href:'https://wa.me/'+waNum(p.phone)+'?text='+encodeURIComponent(text),target:'_blank',rel:'noopener noreferrer'},'Envoyer par WhatsApp'):'',
    p.phone?h('a',{class:'crm-agenda-call',href:'sms:'+p.phone+'?body='+encodeURIComponent(text)},'Envoyer par SMS'):'',
    p.email?h('a',{class:'crm-agenda-call',href:'mailto:'+p.email+'?subject='+encodeURIComponent('Vos documents')+'&body='+encodeURIComponent(text)},'Envoyer par e-mail'):''),
   h('p',{class:'muted'},'Le lien ne sera plus affiché ensuite : en cas de perte, créez-en un nouveau (l’ancien est annulé). Les documents arriveront dans le dossier Nextcloud du commercial de la fiche.')));
 }
 box.append(h('summary',{},'Documents à demander au client'),rows,msg,result,canSend?h('button',{class:'primary',onclick:e=>send(e.currentTarget)},'📨 Envoyer le lien de documents au client'):'');
 setTimeout(load,0);return box;
}
/** Administration : liste de documents proposée par défaut et durée de validité des liens. */
function collectSettingsCard(){
 const box=h('div',{class:'card'}),body=h('div'),out=h('p',{class:'muted',role:'status'});box.append(h('h3',{},'Demande de documents au client · liste et validité'),body,out);
 api('/collect/settings').then(s=>{
  const list=h('div',{class:'crm-collect-docs'});
  const line=(label,required)=>{const row=h('div',{class:'bar crm-collect-doc-line'},h('input',{value:label,maxlength:120,'aria-label':'Libellé du document'}),h('label',{},h('input',{type:'checkbox',checked:required}),' Obligatoire'),h('button',{onclick:()=>row.remove(),'aria-label':'Retirer ce document'},'✕'));return row;};
  s.documents.forEach(d=>list.append(line(d.label,d.required)));
  const days=h('input',{type:'number',min:1,max:s.max_days,value:s.days,style:'width:5em'});
  body.replaceChildren(h('p',{class:'muted'},'Documents demandés dans chaque lien envoyé par les commerciaux (ils ne voient pas cette liste). '+s.max_documents+' documents au maximum.'),list,
   h('button',{onclick:()=>{list.append(line('',true));list.lastChild.querySelector('input').focus();}},'+ Ajouter un document'),
   h('label',{style:'display:block;margin:8px 0'},'Validité des liens : ',days,' jour(s)'),
   h('button',{class:'primary',onclick:async()=>{const documents=[...list.children].map(r=>({label:r.querySelector('input').value.trim(),required:r.querySelector('input[type=checkbox]').checked})).filter(d=>d.label);
    try{await api('/collect/settings',{method:'POST',body:{documents,days:Number(days.value)}});out.textContent='Liste enregistrée.';}catch(e){out.textContent=e.message;}}},'Enregistrer'));
 }).catch(e=>{out.textContent=e.message;});
 return box;
}
function documentSettingsCard(){
 const box=h('div',{class:'card'}),body=h('div'),message=h('p',{class:'muted',role:'status'});box.append(h('h3',{},'Réception des pièces · WhatsApp et e-mail'),body,message);
 async function load(){try{const s=await api('/documents/settings');if(!box.isConnected)return;
  const enabled=h('input',{type:'checkbox',checked:s.mail_enabled}),host=h('input',{value:s.mail_host,placeholder:'mail.exemple.dz',autocomplete:'off'}),user=h('input',{value:s.mail_user,placeholder:'documents@exemple.dz',autocomplete:'off'}),password=h('input',{type:'password',autocomplete:'new-password',placeholder:s.has_password?'Mot de passe enregistré · laisser vide pour conserver':'Mot de passe de la boîte'}),folder=h('input',{value:s.mail_folder||'INBOX'}),port=h('select',{},['993','143'].map(v=>h('option',{value:v,selected:String(s.mail_port)===v},v))),security=h('select',{},['ssl','tls'].map(v=>h('option',{value:v,selected:s.mail_security===v},v==='ssl'?'SSL / TLS':'STARTTLS')));
  const payload=()=>({mail_enabled:enabled.checked,mail_host:host.value,mail_user:user.value,password:password.value,mail_folder:folder.value,mail_port:port.value,mail_security:security.value});let baseline=JSON.stringify(payload());watchEdits('document-settings','Réglages de la boîte mail modifiés',()=>box.isConnected&&JSON.stringify(payload())!==baseline);
  const save=h('button',{class:'primary',onclick:async()=>{save.disabled=true;message.textContent='Enregistrement…';try{await api('/documents/settings',{method:'POST',body:payload()});password.value='';baseline=JSON.stringify(payload());message.textContent='Réglages enregistrés. La collecte s’effectue via le cron Nextcloud.';}catch(e){message.textContent=e.message;}finally{save.disabled=false;}}},'Enregistrer les réglages');
  body.replaceChildren(h('p',{class:'muted'},'Vous pouvez créer la boîte plus tard. Activation facultative ; le mot de passe est conservé chiffré et n’est jamais affiché.'),h('label',{},enabled,' Activer la collecte e-mail'),h('div',{class:'g2'},...[[host,'Serveur IMAP'],[user,'Adresse / utilisateur'],[password,'Mot de passe'],[folder,'Dossier à surveiller'],[port,'Port'],[security,'Chiffrement']].map(([field,label])=>h('label',{},label,field))),h('p',{class:'muted'},'Le début de collecte parcourt les messages déjà présents dans ce dossier. Les messages restent sur le serveur, sans suppression ni marquage comme lus.'),h('p',{},'Capacités serveur : IMAP '+(s.imap_available?'✓':'absent')+' · Images (GD) '+(s.gd_available?'✓':'absent')+' · Documents Office (LibreOffice) '+(s.office_available?'✓':'absent')),h('p',{class:'muted'},'Dernière relève : '+(s.last_poll?fmt(Number(s.last_poll)):'Jamais')),s.mail_error?h('p',{class:'err'},s.mail_error):'',s.wa_error?h('p',{class:'err'},s.wa_error):'',h('div',{class:'bar'},save,h('button',{onclick:async e=>{const button=e.currentTarget;button.disabled=true;try{await api('/documents/test',{method:'POST'});message.textContent='Connexion IMAP réussie avec les réglages enregistrés.';}catch(error){message.textContent=error.message;}finally{button.disabled=false;}}},'Tester la connexion enregistrée')));
 }catch(e){message.textContent=e.message;}}setTimeout(load,0);return box;
}

const show = (...n) => { dirtyChecks.clear(); root.classList.add('crm-workspace'); root.replaceChildren(nav(), h('div', {class:'crm-main'}, workspaceHeader(), h('div', {class:'crm-page'}, ...n.flat()))); paintCounters(); paintWa(); };
let CNT = {overdue:0, today:null, goal:0};
/** Badge des rappels en retard + progression de l'objectif quotidien (commerciaux). */
async function paintCounters() {
  try { CNT = await api('/counters'); } catch (e) { return; }
  const btn = root.querySelector('.dossier-states button[data-k="rappels"]');
  if (btn) { btn.querySelector('.ovd')?.remove(); if (CNT.overdue) btn.append(h('span', {class:'ovd', title:'Rappels en retard'}, String(CNT.overdue))); }
  const g = root.querySelector('.goal');
  if (g && CNT.goal && CNT.today !== null) {
    const pc = Math.min(100, Math.round(100 * CNT.today / CNT.goal));
    g.replaceChildren('🎯 Aujourd’hui : ' + CNT.today + ' / ' + CNT.goal + ' appels', h('span', {class:'gbar'}, h('span', {style:'width:' + pc + '%'})));
  }
}
function workspaceHeader() {
  const name = (me.commerciaux || []).find(u => u.uid === me.uid)?.name || me.uid || '';
  return h('header', {class:'crm-session-header'}, h('button',{class:'crm-global-search',onclick:()=>go('search'),'aria-label':'Recherche globale'},'⌕ Rechercher un client'), h('div', {}, h('span', {class:'crm-app-title'}, 'Call CRM'),
    h('div', {class:'muted'}, name)),
    me.role === 'commercial' ? h('div', {class:'crm-session-actions'},
      me.prefs?.goal ? h('span', {class:'goal muted'}) : '', presenceControl()) :
      h('span', {class:'crm-role'}, me.role === 'admin' ? 'Administration' : me.role === 'superviseur' ? 'Supervision' : 'Consultation'));
}
function nav() {
  const icon = key => {
    const paths = {
      search:['M16 10a6 6 0 1 1-12 0 6 6 0 0 1 12 0','m14.5 14.5 6 6'],
      neglected:['M12 3a9 9 0 1 1-9 9 9 9 0 0 1 9-9','M12 7v5l3 2'],
      documents:['m8 12 7-7a4 4 0 0 1 6 6l-9 9a6 6 0 0 1-8-8l9-9','m7 13 7-7a2 2 0 0 1 3 3l-8 8'],
      journee:['M12 3v2m0 14v2M3 12h2m14 0h2M5.6 5.6 7 7m10 10 1.4 1.4M5.6 18.4 7 17M17 7l1.4-1.4','M16 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0'],
      agenda:['M7 3v4m10-4v4M4 10h16','M5 5h14a1 1 0 0 1 1 1v14H4V6a1 1 0 0 1 1-1Z','m8 15 2 2 5-5'],
      dossiers:['M3 7V5h6l2 2h10v13H3V7Z'],
      wa:['M20 11.5a8 8 0 0 1-11.8 7L3 20l1.5-5.2A8 8 0 1 1 20 11.5Z','M8 8c0 4 4 8 8 8l1-2-3-1-1 1-3-3 1-1-1-3-2 1Z'],
      sim:['M5 3h14v18H5Z','M8 6h8v4H8Z','M8 14h1m6 0h1m-8 3h1m6 0h1'],
      stats:['M4 3v18h17','M8 16v-4m5 4V8m5 8V5'],
      admin:['M12 8a4 4 0 1 1 0 8 4 4 0 0 1 0-8Z','M9 3h6l.5 3 2.5 1 2.5-1 2 5-2.5 1v2l2.5 1-2 5-2.5-1-2.5 1-.5 3H9l-.5-3L6 19l-2.5 1-2-5L4 14v-2L1.5 11l2-5L6 7l2.5-1L9 3Z'],
      annonce:['M3 10v5h5l11 5V5L8 10H3Z','m8 15 2 6h3l-2-5M22 9v7'],
      commerciaux:['M9 3a4 4 0 1 1 0 8 4 4 0 0 1 0-8Z','M2 21v-3a5 5 0 0 1 5-5h4a5 5 0 0 1 5 5v3','M17 4a4 4 0 0 1 0 7m2 3a5 5 0 0 1 3 4v3']
    };
    const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');
    for(const [name,value] of Object.entries({viewBox:'0 0 24 24',width:'19',height:'19',fill:'none',stroke:'currentColor','stroke-width':'1.7','stroke-linecap':'round','stroke-linejoin':'round','aria-hidden':'true',focusable:'false',class:'crm-nav-icon'}))svg.setAttribute(name,value);
    (paths[key]||paths.dossiers).forEach(d=>{const p=document.createElementNS(svg.namespaceURI,'path');p.setAttribute('d',d);svg.append(p);});return svg;
  };

  const inDossiers = !!KINDS[tab], t = [];
  if (me.role === 'commercial') t.push(['journee','Ma journée']);
  t.push(['agenda','Agenda'],['neglected','À relancer'],['search','Recherche']);
  t.push(['dossiers','Dossiers Clients']);
  t.push(['wa','WhatsApp']);
  t.push(['sim','Simulateur']);
  t.push(['stats',me.can.all ? 'Statistiques' : 'Mes statistiques']);
  if (me.can.import) t.push(['admin','Administration']);
  if (['admin','superviseur'].includes(me.role)) t.push(['documents','Pièces reçues'],['annonce','Annonce'],['commerciaux','Commerciaux']);
  const states = TABS.slice(); if (me.can.all) states.push(['tous','Tous']);
  return h('aside', {class:'client-navigation crm-sidebar', 'aria-label':'Navigation CRM'},
    h('div', {class:'crm-brand'}, h('span', {class:'crm-brand-mark', 'aria-hidden':'true'}, 'C'), h('strong', {}, 'Call CRM')),
    h('div', {class:'nav', role:'navigation'}, t.map(([key,label]) => h('button', {
      class:(key === 'dossiers' ? inDossiers : tab === key) ? 'on' : '', 'data-k':key,
      'aria-current':(key === 'dossiers' ? inDossiers : tab === key) ? 'page' : null, onclick:() => go(key)}, icon(key), h('span',{class:'crm-nav-label'},label)))),
    inDossiers ? h('div', {class:'dossier-states', role:'group', 'aria-label':'États des dossiers clients'},
      states.map(([key,label]) => h('button', {class:tab === key ? 'on' : '', 'data-k':key, 'aria-pressed':String(tab === key), onclick:() => go(key)}, label))) : '',
    h('div', {class:'crm-sidebar-note muted'}, me.role === 'commercial' ? 'Votre espace de travail' : 'Pilotage de l’activité'));
}
function go(k) { if(typeof canLeave==='function'&&!canLeave(()=>go(k)))return; if (k === 'dossiers') k = 'nouveaux'; if (k === 'sim') { openSim(null); return; } tab = k; if(k==='documents'){show(h('h2',{},'Pièces reçues'),documentsCard());return;} if(k==='search'||k==='neglected'){discovery(k);return;} if(k==='agenda'){agenda();return;} if (k === 'journee' && me.role === 'commercial') myDay(); else if (k === 'wa') waList(); else if (k === 'stats') stats(); else if (k === 'admin') admin(); else if (k === 'annonce') show(h('h2', {}, 'Annonce'), announcementCard()); else if (k === 'commerciaux') presenceBoard(); else list(k); }
const modeOpts = none => [
  h('option', {value:'categorie'}, 'Par catégorie (groupe de chaque catégorie)'),
  h('option', {value:'auto'}, 'Équitablement (tous les commerciaux)'),
  me.groups.map(g => h('option', {value:'grp:' + g.id}, 'Tout vers le groupe ' + g.name)),
  none ? h('option', {value:''}, 'Non assignés') : ''].flat();
async function distribute(mode, out, uids) {
  out.textContent = '…';
  try { const r = await api('/distribute', {method:'POST', body:{mode, uids: uids || []}}); out.textContent = r.assigned + ' prospect(s) répartis.'; }
  catch (e) { out.textContent = e.message; }
}
function distButtons() {
  const out = h('span', {class:'muted'}), s = h('select', {}, modeOpts(false));
  return h('div', {class:'bar'}, s, h('button', {onclick: () => distribute(s.value, out)}, 'Répartir les non assignés'), out);
}

async function list(k) {
  const K = KINDS[k];
  sel.clear(); let rowsNow = [];
  const box = h('div', {}, skel());
  const q = h('input', {type:'search', placeholder:'Rechercher…', value:filt.q});
  const bar = h('div', {class:'bar'}, q);
  let s, a;
  if (K.all) { s = h('select', {}, h('option', {value:''}, 'Tous statuts'), Object.entries(ST).map(([v, l]) => h('option', {value:v, selected: filt.status === v}, l))); bar.append(s); }
  if (me.can.all) { a = h('select', {}, h('option', {value:''}, 'Tous commerciaux'), me.commerciaux.map(u => h('option', {value:u.uid, selected: filt.assigned === u.uid}, u.name))); bar.append(a); }
  let page = 1;
  const SORTS = [['', 'Tri par défaut'], ['nom', 'Nom'], ['date', 'Date d’enregistrement'], ['statut', 'Statut'], ['wilaya', 'Wilaya'], ['categorie', 'Catégorie'], ['appel', 'Dernier appel'], ['rappel', 'Date de rappel']];
  const so = h('select', {title:'Trier par'}, SORTS.map(([v, l]) => h('option', {value:v, selected: sortBy.k === v}, l)));
  const sd = h('button', {title:'Sens du tri', onclick: () => { sortBy.d = sortBy.d === 'asc' ? 'desc' : 'asc'; sd.textContent = sortBy.d === 'asc' ? '↑ Croissant' : '↓ Décroissant'; load(); }}, sortBy.d === 'asc' ? '↑ Croissant' : '↓ Décroissant');
  so.addEventListener('change', () => { sortBy.k = so.value; load(); });
  bar.append(so, sd);
  const rf = h('input', {type:'date'}), rt = h('input', {type:'date'}), pf = h('input', {type:'date'}), pt = h('input', {type:'date'});
  const dbar = h('div', {class:'bar'}, h('label', {}, 'Inscrit du ', rf), h('label', {}, 'au ', rt), h('label', {}, 'Traité du ', pf), h('label', {}, 'au ', pt),
    h('button', {onclick: () => { [rf, rt, pf, pt].forEach(x => x.value = ''); load(); }}, 'Effacer les dates'));
  const dts = (v, add) => v ? Math.floor(new Date(v + 'T00:00:00').getTime() / 1000) + add : '';
  const selAll = h('input', {type:'checkbox', title:'Tout cocher (page)', onchange: e => { rowsNow.forEach(x => e.target.checked ? sel.add(x.id) : sel.delete(x.id)); box.querySelectorAll('.rsel').forEach(c => { c.checked = e.target.checked; }); refreshSel(); }});
  const selCnt = h('span', {class:'muted'}), selMsg = h('span', {class:'muted'});
  const selTo = h('select', {}, h('option', {value:''}, 'Transférer vers…'), h('option', {value:'__none'}, 'Non assigné'), me.commerciaux.map(u => h('option', {value:u.uid}, u.name)));
  const selBtn = h('button', {class:'primary', onclick: async () => {
    if (!selTo.value) { selMsg.textContent = 'Choisissez le commercial destinataire.'; return; }
    const who = (me.commerciaux.find(u => u.uid === selTo.value) || {}).name || (selTo.value === '__none' ? 'Non assigné' : selTo.value);
    if (!confirm('Transférer ' + sel.size + ' prospect(s) vers « ' + who + ' » ?')) return;
    selMsg.textContent = '…';
    try { const r = await api('/transfer', {method:'POST', body:{ids:[...sel], to:selTo.value}}); sel.clear(); selMsg.textContent = '✔ ' + r.transferred + ' prospect(s) transféré(s).'; load(true); }
    catch (e) { selMsg.textContent = e.message; }
  }}, 'Transférer la sélection');
  const stTo = h('select', {}, h('option', {value:''}, 'Changer le statut en…'), Object.entries(ST).map(([v, l]) => h('option', {value:v}, l)));
  const stUn = h('label', {style:'white-space:nowrap'}, h('input', {type:'checkbox'}), ' puis non assignés');
  const stBtn = h('button', {onclick: async () => {
    if (!stTo.value) { selMsg.textContent = 'Choisissez le nouveau statut.'; return; }
    if (!confirm('Passer ' + sel.size + ' prospect(s) en « ' + ST[stTo.value] + ' »' + (stUn.firstChild.checked ? ' et les rendre non assignés' : '') + ' ? Les rappels prévus et le compteur de tentatives sont remis à zéro.')) return;
    selMsg.textContent = '…';
    try { const r = await api('/bulkstatus', {method:'POST', body:{ids:[...sel], to_status:stTo.value, assign_mode: stUn.firstChild.checked ? 'none' : 'keep'}}); sel.clear(); selMsg.textContent = '✔ ' + r.changed + ' prospect(s) mis à jour.'; load(true); }
    catch (e) { selMsg.textContent = e.message; }
  }}, 'Appliquer le statut');
  const selBar = me.can.assign ? h('div', {class:'bar selbar'}, h('label', {}, selAll, ' Tout cocher (page)'), selCnt, selTo, selBtn, stTo, stUn, stBtn, selMsg) : '';
  function refreshSel() { selCnt.textContent = sel.size + ' sélectionné(s)'; selBtn.disabled = !sel.size; stBtn.disabled = !sel.size; selAll.checked = !!rowsNow.length && rowsNow.every(x => sel.has(x.id)); }
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
      box.replaceChildren(...(r.rows.length ? r.rows.map(x => row(x, me.can.assign ? refreshSel : null)) : [h('div', {class:'empty'}, h('strong', {}, 'Rien à traiter ici'), h('div', {class:'muted'}, 'Aucun prospect ne correspond à ce filtre.'))]));
      refreshSel();
      const go2 = n => { page = n; load(true); window.scrollTo(0, 0); };
      const mk = () => [
        h('span', {class:'muted'}, r.total + ' fiche(s) client(s) — page ' + page + ' / ' + pages + (!pageSize && r.total > 5000 ? ' (5000 premières affichées)' : '')),
        h('span', {}, h('button', {disabled: page <= 1, onclick: () => go2(page - 1)}, '◀ Précédent'), ' ',
          h('button', {disabled: page >= pages, onclick: () => go2(page + 1)}, 'Suivant ▶'), ' ',
          h('select', {onchange: e => { pageSize = Number(e.target.value); try { localStorage.setItem('callcrm_ps2', String(pageSize)); } catch (x) {} load(); }},
            [10, 20, 30, 0].map(n => h('option', {value:n, selected: n === pageSize}, n ? n + ' par page' : 'Tout'))))];
      pager.replaceChildren(...mk()); pagerB.replaceChildren(...mk());
    } catch (e) { box.replaceChildren(h('p', {class:'err'}, e.message)); }
  };
  [q, s, a, rf, rt, pf, pt].forEach(e => e && e.addEventListener('change', () => load()));
  show(h('h2', {}, K.t), me.can.create ? h('div', {class:'bar'}, h('button', {class:'primary', onclick: () => addForm()}, '+ Ajouter un client')) : '', me.can.assign && K.all ? distButtons() : '', bar, dbar, selBar, pager, box, pagerB); load();
}
async function addForm() {
  const msg = h('div', {class:'err'});
  const asg = me.can.assign ? h('label', {style:'display:block;margin:5px 0'}, h('span', {class:'muted'}, 'Assigner à'),
    h('select', {'data-k':'assigned_to', style:'width:100%;box-sizing:border-box'}, h('option', {value:''}, 'Non assigné'), me.commerciaux.map(u => h('option', {value:u.uid}, u.name)))) : '';
  const box = h('div', {class:'card'}, h('h3', {style:'margin-top:0'}, 'Nouveau client'),
    fieldsGrid({}, false), asg,
    h('button', {class:'primary', style:'margin-top:8px', onclick: async e => {
      msg.textContent = ''; e.target.disabled = true;
      try { const r = await api('/prospects', {method:'POST', body: collect(box)}); detail(r.id); }
      catch (x) { msg.textContent = x.message; e.target.disabled = false; }
    }}, 'Enregistrer'), msg);
  const phIn = box.querySelector('[data-k="phone"]');
  if (phIn) {
    const w = h('div', {class:'err'}); phIn.after(w);
    phIn.addEventListener('change', async () => {
      w.replaceChildren();
      if (!phIn.value.trim()) return;
      try {
        const r = await api('/duplicate?phone=' + encodeURIComponent(phIn.value));
        if (r.exists) w.replaceChildren('⚠ Ce numéro existe déjà' + (r.name ? ' : « ' + r.name + ' »' + (r.assigned ? ' (assigné à ' + r.assigned + ')' : ' (non assigné)') : ' (fiche d’un autre commercial)') + '. ',
          r.id ? h('a', {href:'#', onclick: e => { e.preventDefault(); detail(r.id); }}, 'Ouvrir la fiche') : '');
      } catch (e) {}
    });
  }
  show(h('button', {onclick: () => go(tab)}, '← Retour'), box);
}
const skel = () => [1, 2, 3, 4].map(() => h('div', {class:'skel'}));
function row(r, onSel) {
  const late = r.callback_at && Number(r.callback_at) < Date.now() / 1000 && STD[r.status] && STD[r.status].callback;
  const ck = onSel ? h('input', {type:'checkbox', class:'rsel', checked: sel.has(r.id), onclick: e => { e.stopPropagation(); e.target.checked ? sel.add(r.id) : sel.delete(r.id); onSel(); }}) : '';
  return h('div', {class:'row' + (late ? ' overdue' : ''), onclick: () => detail(r.id)},
    ck ? h('div', {style:'display:flex;align-items:center'}, ck) : '',
    h('div', {class:'av'}, (nm(r).match(/[\p{L}\p{N}]/gu) || ['?']).slice(0, 2).join('').toUpperCase()),
    h('div', {class:'main'}, h('strong', {}, nm(r)), h('div', {class:'muted'}, [r.phone, r.c5, r.c2].filter(Boolean).join(' · ') || 'Sans numéro'),
      h('div', {class:'muted'}, 'Inscrit le ' + fmtD(r.created_at) + ' · ' + 'Traité le ' + (Number(r.last_call_at) ? fmtD(r.last_call_at) : '—'))),
    h('div', {class:'side'}, badge(r.status),
      r.callback_at ? h('div', {class: late ? 'late' : 'muted'}, (late ? '⚠ En retard · ' : '⏰ ') + fmt(Number(r.callback_at))) : ''));
}

/** Note non modifiable : tentatives sans succès (réponses « avec date de rappel »), avec date et heure exactes. */
function attemptsBox(calls) {
  const a = (calls || []).filter(c => STD[c.result] && STD[c.result].callback).sort((x, y) => Number(x.created_at) - Number(y.created_at));
  if (!a.length) return '';
  const max = me.max_callbacks || 0;
  return h('div', {class:'card', style:'background:var(--color-background-dark,#f3f3f3);margin:8px 0', title:'Note automatique, non modifiable'},
    h('strong', {}, '🔒 Tentatives de rappel : ' + a.length + (max ? ' / ' + max : '')),
    ...a.map((c, i) => h('div', {}, 'Appel ' + (i + 1) + ' : ' + fmtS(Number(c.created_at)) + ' — ' + ST[c.result] + (c.reason ? ' — ' + c.reason : ''))),
    max && a.length >= max ? h('div', {class:'err'}, 'Limite atteinte.') : '');
}
function callHistoryEntry(c) {
  return h('div', {class:'card call-history-entry'}, h('strong', {}, ST[c.result] || c.result), ' — ', fmt(Number(c.created_at)),
    c.reason ? h('div', {}, 'Motif : ' + c.reason) : '', c.note ? h('div', {class:'call-history-note'}, c.note) : '',
    c.callback_at ? h('div', {class:'muted'}, 'Rappel prévu : ' + fmt(Number(c.callback_at))) : '');
}

async function detail(id) {
  if(!canLeave(()=>detail(id)))return;
  let d;
  try { d = await api('/prospects/' + id); } catch (e) { return show(h('p', {class:'err'}, e.message)); }
  const p = d.prospect;
  let sel = null;
  const note = h('textarea', {rows:2, placeholder:'Note'});
  const cb = h('input', {type:'datetime-local'});
  const P = me.prefs || {};
  const reason = h('select', {}, h('option', {value:''}, '— Motif (obligatoire) —'), (P.reasons || []).map(x => h('option', {value:x}, x)));
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
  const cbHint = (winA !== null && winB !== null ? 'Plage autorisée : ' + P.cb_from + ' – ' + P.cb_to : '') + (gap ? (winA !== null ? ' · ' : '') + 'espacement minimum : ' + gap + ' min' : '');
  const checkCb = () => {
    if (!cb.value) return 'Date et heure de rappel obligatoires';
    const t = new Date(cb.value).getTime();
    if (gap && t < Date.now() + gap * 60000 - 120000) return 'Le rappel doit être programmé au moins ' + gap + ' min après l’appel.';
    if (winA !== null && winB !== null) { const d = new Date(t), m = d.getHours() * 60 + d.getMinutes(); if (m < winA || m > winB) return 'Rappel hors de la plage autorisée (' + P.cb_from + ' – ' + P.cb_to + ').'; }
    return '';
  };
  const pick = r => {
    sel = r;
    document.querySelectorAll('#callcrm .res button').forEach(b => b.classList.toggle('on', b.dataset.r === r));
    const needCb = !!(STD[r] && STD[r].callback);
    note.placeholder = STD[r] && STD[r].note ? 'Note (obligatoire)' : 'Note (facultatif)';
    const needRs = !!(STD[r] && STD[r].reason) || !!STD[r]?.reason_options?.length;
    reason.replaceChildren(h('option',{value:''},STD[r]?.reason?'— Motif obligatoire —':'— Motif facultatif —'),...(STD[r]?.reason_options||[]).map(x=>h('option',{value:x},x)));
    reason.hidden=true; const reasonButtons=h('div',{class:'crm-reason-options'},(STD[r]?.reason_options||[]).map(value=>h('button',{type:'button',onclick:e=>{reason.value=value;reasonButtons.querySelectorAll('button').forEach(b=>b.classList.toggle('on',b===e.currentTarget));}},value)));
    if (needCb && !cb.value) inMin(60);
    form.replaceChildren(needRs ? h('div', {style:'margin:0 0 6px'}, reason, reasonButtons) : '', note,
      needCb ? h('p', {}, 'Date et heure de rappel :') : '',
      needCb ? h('div', {class:'shortcuts'}, h('button', {onclick: () => inMin(60)}, 'Dans 1 h'), h('button', {onclick: () => inMin(120)}, 'Dans 2 h'), h('button', {onclick: () => inMin(240)}, 'Dans 4 h'), h('button', {onclick: tomorrow}, 'Demain')) : '',
      needCb ? cb : '', needCb && cbHint ? h('div', {class:'muted'}, cbHint) : '',
      h('button', {class:'primary', style:'margin-top:8px', onclick: save}, 'Enregistrer l’appel et passer au suivant'), msg);
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
      if (STD[sel] && STD[sel].reason && !reason.value) { msg.textContent = 'Un motif est obligatoire pour « ' + ST[sel] + ' ».'; return; }
      const body = {result:sel, note:note.value, reason: reason.value, at: Math.floor(Date.now() / 1000), callback_at: needCb && cb.value ? Math.floor(new Date(cb.value).getTime() / 1000) : null};
      try { const r = await api('/prospects/' + id + '/call', {method:'POST', body}); if (r && r.auto_closed) alert('Limite de ' + r.max + ' tentative(s) atteinte : ce prospect est passé automatiquement en « ' + (ST.faux_numero || 'Injoignable / faux numéro') + ' ».'); }
      catch (e) {
        if (!e.offline) throw e;
        queueCall(id, body);sel=null;dirtyChecks.delete('call');
        form.replaceChildren(h('p', {}, '✔ Appel enregistré sur cet appareil (hors ligne). Il sera envoyé automatiquement au retour de la connexion.'),
          h('button', {class:'primary', onclick: () => go('nouveaux')}, 'Revenir à la liste'));
        return;
      }
      sel=null;dirtyChecks.delete('call');
      await next();
    } catch (e) { msg.textContent = e.offline ? 'Connexion indisponible.' : e.message; }
  };
  const ro = !me.can.call;
  const imsg = h('div', {class:'err'});
  const info = h('div', {class:'card'}, h('h3', {style:'margin-top:0'}, 'Informations client'),
    fieldsGrid(p, ro),
    h('div', {class:'muted'}, 'Heure d’enregistrement : ' + fmt(Number(p.created_at))),
    ro ? '' : h('button', {class:'primary', style:'margin-top:8px', onclick: async () => {
      imsg.className = 'err'; imsg.textContent = '';
      try { const snapshot=collect(info); await api('/prospects/' + id + '/update', {method:'POST', body: {...snapshot,_base_note:String(p.remarque||'')}}); if('remarque' in snapshot)p.remarque=snapshot.remarque; info.dispatchEvent(new CustomEvent('crm-saved',{detail:snapshot})); imsg.className = 'muted'; imsg.textContent = 'Informations enregistrées.'; }
      catch (e) { imsg.textContent = e.message; }
    }}, 'Enregistrer les informations'), imsg);
  const asg = me.can.assign ? h('select', {onchange: async e => { try { await api('/prospects/' + id + '/assign', {method:'POST', body:{uid:e.target.value}}); } catch (x) { alert(x.message); } }},
    h('option', {value:''}, 'Non assigné'), me.commerciaux.map(u => h('option', {value:u.uid, selected: p.assigned_to === u.uid}, u.name))) : '';
  show(
    h('button', {onclick: () => go(tab)}, '← Retour'),
    h('div', {class:'cols'},
      h('div', {},
        h('h2', {}, nm(p)), badge(p.status),
        h('div', {class:'muted'}, 'Date d’inscription : ' + fmt(Number(p.created_at))),
        h('div', {class:'muted'}, 'Date de traitement : ' + (Number(p.last_call_at) ? fmt(Number(p.last_call_at)) : '—')),
        p.callback_at ? h('div', {}, '⏰ Rappel : ' + fmt(Number(p.callback_at))) : '',
        attemptsBox(d.calls.filter(c => Number(c.created_at) >= Number(p.reset_at || 0))),
        p.phone && !me.can.call ? h('p', {}, '📞 ' + p.phone) : '',
        p.phone && me.can.call ? h('a', {class:'big call', href:'tel:' + p.phone}, '📞 Appeler ' + p.phone) : (p.phone ? '' : h('p', {class:'muted'}, 'Aucun numéro de téléphone renseigné.')),
        p.phone && me.can.call ? h('a', {class:'big', style:'background:#25D366;color:#fff', target:'_blank', rel:'noopener noreferrer', href:'https://wa.me/' + waNum(p.phone)}, '💬 WhatsApp ' + p.phone) : '',
        p.phone ? waOnProspect(id) : '',
        h('button', {type:'button', class:'sim-open', onclick: () => openSim(p)}, '🧮 Simulateur de cuisine'),
        me.can.call ? h('div', {class:'res'}, RES.map(r => h('button', {'data-r': r, style:'--sc:' + (STC[r] || '#888') + ';--si:' + inkOn(STC[r] || '#888'), onclick: () => pick(r)}, ST[r]))) : h('p', {class:'muted'}, 'Consultation seule.'),
        form, asg,
        d.recordings && d.recordings.length ? [h('h3', {}, 'Enregistrements'), d.recordings.map(recRow)] : '',
        chronology(id)),
      h('div', {}, info,
        me.can.delete ? h('button', {onclick: async () => { if (confirm('Supprimer ce prospect ? Ses pièces jointes seront conservées dans Pièces reçues.')) { await api('/prospects/' + id + '/delete', {method:'POST'}); go('tous'); } }}, 'Supprimer') : '')));
  clientWorkspace(p,info,form,note,reason,cb,()=>sel,save);
}

/* ───────── Simulateur de devis « cuisine équipée » ───────── */
let SIM = null;
const DA = n => Math.round(n).toLocaleString('fr-FR').replace(/[  ]/g, ' ') + ' DA';
const mFmt = n => String(n).replace('.', ',') + ' m';
async function simCfg(force) { if (!SIM || force) SIM = (await api('/sim')).cfg; return SIM; }
/** Même calcul que lib/Service/Sim.php (le serveur recalcule à l'enregistrement). */
function simCalc(c, i) {
  const price = (c.models.find(m => m.name === i.model) || {price: 0}).price;
  let ded = 0, eqp = 0;
  c.equip.forEach(e => { const on = !!i.eq[e.name]; if ((e.when === 'yes') === on) ded += e.deduct; if (on) eqp += e.price; });
  const net = Math.max(0, i.w + i.l - ded), furn = net * (i.hl ? c.hl_mult : 1) * price;
  const ex = c.extras.reduce((s, e) => s + (i.extras[e.name] || 0) * e.price, 0);
  const len = Math.max(0, i.w + i.l - c.wt_deduct);
  const wt = (len * c.depth * c.m2 + c.labour + (c.plinth_ml ? c.plinth * len : c.plinth)) * c.coef;
  const total = furn + ex + eqp + wt, rd = c.round || 1000, r = x => Math.round(x / rd) * rd;
  return {net, furn, ex, eqp, wt, total, approx: r(total), lo: r(total * (1 - c.range / 100)), hi: r(total * (1 + c.range / 100))};
}
const SVGNS = 'http://www.w3.org/2000/svg';
const sv = (t, a = {}, ...c) => { const e = document.createElementNS(SVGNS, t); for (const [k, v] of Object.entries(a)) e.setAttribute(k, v); c.flat().forEach(x => e.append(x)); return e; };
/** Schéma en L : le mur « largeur » en haut, le mur « longueur » à gauche. */
function simPlan(i) {
  const W = 300, H = 210, pad = 34, s = Math.min((W - 2 * pad) / i.w, (H - 2 * pad) / i.l), d = Math.max(0.6 * s, 14);
  const x = pad, y = pad, bw = i.w * s, bh = i.l * s, fill = 'var(--brand)', t = {fill: 'var(--color-text-maxcontrast,#666)', 'font-size': 12, 'font-family': 'Arial'};
  const hi = i.hl ? sv('g', {opacity: .35}, sv('rect', {x: x, y: y, width: bw, height: d / 2, fill}), sv('rect', {x: x, y: y + d / 2, width: d / 2, height: bh - d / 2, fill})) : '';
  return sv('svg', {viewBox: '0 0 ' + W + ' ' + H, class: 'sim-plan', role: 'img', 'aria-label': 'Schéma de la cuisine'},
    sv('rect', {x: x, y: y, width: bw, height: d, rx: 3, fill}), sv('rect', {x: x, y: y + d, width: d, height: bh - d, rx: 3, fill}),
    hi,
    sv('text', {...t, x: x + bw / 2, y: y - 12, 'text-anchor': 'middle'}, mFmt(i.w)),
    sv('text', {...t, x: x - 12, y: y + bh / 2, 'text-anchor': 'middle', transform: 'rotate(-90 ' + (x - 12) + ' ' + (y + bh / 2) + ')'}, mFmt(i.l)),
    sv('text', {...t, x: x + d + 12, y: y + d + 28}, i.hl ? 'Meubles hauts et bas' : 'Meubles bas'));
}
function seg(opts, val, on) {
  const el = h('div', {class: 'seg', role: 'group'});
  const paint = v => el.replaceChildren(...opts.map(([k, l]) => h('button', {type: 'button', class: k === v ? 'on' : '', 'aria-pressed': k === v ? 'true' : 'false', onclick: () => { paint(k); on(k); }}, l)));
  paint(val); return el;
}
async function openSim(p) {
  let c;
  try { c = await simCfg(true); } catch (e) { alert(e.message); return; }
  const i = {w: 4, l: 3, hl: true, eq: {}, model: c.models[0].name, extras: {}};
  const initialSimulation=JSON.stringify(i);
  const num = (k, lab) => h('label', {}, lab, h('input', {type: 'number', inputmode: 'decimal', step: '0.05', min: '0.5', max: '20', value: i[k], oninput: e => { const v = parseFloat(String(e.target.value).replace(',', '.')); i[k] = isFinite(v) ? v : 0; upd(); }}));
  const yn = (k, lab, hint) => h('div', {class: 'sim-row'}, h('span', {}, lab, hint ? h('small', {class: 'muted'}, ' ' + hint) : ''), seg([[true, 'Oui'], [false, 'Non']], i[k], v => { i[k] = v; upd(); }));
  const models = h('div', {class: 'sim-models'});
  const paintModels = () => models.replaceChildren(...c.models.map(m => h('button', {type: 'button', class: m.name === i.model ? 'on' : '', 'aria-pressed': m.name === i.model ? 'true' : 'false', onclick: () => { i.model = m.name; paintModels(); upd(); }}, h('strong', {}, m.name), h('small', {class: 'muted'}, DA(m.price) + ' / ml'))));
  paintModels();
  const extras = c.extras.map(e => { const n = h('output', {}, '0');
    const set = v => { i.extras[e.name] = Math.max(0, Math.min(20, v)); n.textContent = i.extras[e.name]; upd(); };
    return h('div', {class: 'sim-row'}, h('span', {}, e.name, h('small', {class: 'muted'}, ' +' + DA(e.price))), h('div', {class: 'step'}, h('button', {type: 'button', 'aria-label': 'Moins', onclick: () => set((i.extras[e.name] || 0) - 1)}, '−'), n, h('button', {type: 'button', 'aria-label': 'Plus', onclick: () => set((i.extras[e.name] || 0) + 1)}, '+'))); });
  const plan = h('div', {class: 'sim-planbox'}), res = h('div', {class: 'sim-res'}), bar = h('div', {class: 'sim-bar', 'aria-live': 'polite'}), msg = h('div', {class: 'muted'});
  const summary = r => ['Cuisine équipée — modèle ' + i.model + (i.hl ? ', meubles hauts et bas' : ', meubles bas') + ' — ' + mFmt(i.w) + ' × ' + mFmt(i.l),
    c.equip.filter(e => i.eq[e.name]).length ? 'Équipements : ' + c.equip.filter(e => i.eq[e.name]).map(e => e.name).join(', ') : '',
    Object.entries(i.extras).filter(([, n]) => n).map(([k, n]) => n + ' × ' + k).join(', '),
    'Estimation : environ ' + DA(r.approx) + ' (entre ' + DA(r.lo) + ' et ' + DA(r.hi) + ')'].filter(Boolean).join('\n');
  const clientNoteField = p ? root.querySelector('[data-k="remarque"]') : null;
  let saving = false, savedInput = '', target = p || null, searchId = 0;
  const targetPick = h('select', {'aria-label':'Client destinataire'}, h('option', {value:''}, '— Choisir le client —'));
  const clientSearch = h('input', {type:'search', placeholder:'Rechercher un client (nom ou téléphone)', 'aria-label':'Rechercher un client'});
  const pickerMsg = h('div', {class:'muted', role:'status'});
  let choices = [];
  clientSearch.addEventListener('input', async () => {
    const request = ++searchId, q = clientSearch.value.trim();
    target = null; targetPick.replaceChildren(h('option', {value:''}, '— Choisir le client —')); upd();
    if (q.length < 2) { pickerMsg.textContent = 'Saisissez au moins 2 caractères.'; return; }
    pickerMsg.textContent = 'Recherche…';
    try {
      const rows = await api('/prospects?' + new URLSearchParams({q, limit:20}));
      if (request !== searchId) return;
      choices = rows;
      targetPick.append(...rows.map(x => h('option', {value:x.id}, nm(x) + (x.phone ? ' — ' + x.phone : ''))));
      pickerMsg.textContent = rows.length ? 'Choisissez le client auquel ajouter cette estimation.' : 'Aucun client trouvé.';
    } catch (e) { if (request === searchId) pickerMsg.textContent = e.message; }
  });
  targetPick.addEventListener('change', () => { target = choices.find(x => String(x.id) === targetPick.value) || null; upd(); });
  const saveButton = h('button', {type:'button', class:'primary', onclick: async () => {
    if (!target || !last || saving || savedInput === JSON.stringify([target.id, i])) return;
    const client = target, snapshot = JSON.parse(JSON.stringify(i));
    saving = true; saveButton.disabled = true; msg.textContent = 'Enregistrement…';
    try {
      const r = await api('/prospects/' + client.id + '/sim', {method:'POST', body:{in:snapshot}});
      savedInput = JSON.stringify([client.id, snapshot]);
      if (p && p.id === client.id) {
        const field = clientNoteField;
        if (field) field.value = field.value === String(p.remarque || '') ? r.remarque : field.value + (field.value ? '\n\n' : '') + r.note;
        p.remarque = r.remarque;
      }
      msg.textContent = '✔ Estimation ajoutée à la note du client « ' + nm(client) + ' ».';
    } catch (e) { msg.textContent = e.message; }
    finally { saving = false; upd(); }
  }}, 'Enregistrer dans la note du client');
  let last;
  function upd() {
    const ok = i.w >= 0.5 && i.l >= 0.5 && i.w <= 20 && i.l <= 20;
    saveButton.disabled = !ok || !target || saving || savedInput === JSON.stringify([target?.id, i]);
    if (!ok) { last = null; bar.replaceChildren(h('span', {class: 'err'}, 'Largeur et longueur : entre 0,5 et 20 m.')); return; }
    last = simCalc(c, i);
    plan.replaceChildren(simPlan(i));
    bar.replaceChildren(h('span', {}, 'Estimation'), h('strong', {}, '≈ ' + DA(last.approx)), h('small', {}, 'entre ' + DA(last.lo) + ' et ' + DA(last.hi)));
    res.replaceChildren(h('table', {class: 't'}, h('tbody', {},
        [['Meubles (' + last.net.toFixed(2).replace('.', ',') + ' ml utiles)', last.furn], ['Suppléments et équipements', last.ex + last.eqp], ['Plan de travail, pose et plinthe', last.wt]].map(([a, b]) => h('tr', {}, h('td', {}, a), h('td', {style: 'text-align:right;white-space:nowrap'}, DA(b)))))));
  }
  watchEdits('simulation','Simulation de cuisine non enregistrée',()=>bg.isConnected&&JSON.stringify(i)!==initialSimulation&&savedInput!==JSON.stringify([target?.id,i]));
  const close=()=>{if(canLeave(()=>{dirtyChecks.delete('simulation');bg.remove();},['simulation'])){dirtyChecks.delete('simulation');bg.remove();}};
  const bg = h('div', {class: 'sim-bg', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Simulateur de cuisine'},
    h('div', {class: 'sim'},
      h('div', {class: 'sim-head'}, h('h2', {}, 'Simulateur de cuisine' + (p ? ' — ' + nm(p) : '')), h('button', {type: 'button', 'aria-label': 'Fermer', onclick: close}, '✕')),
      h('div', {class: 'sim-body'},
        h('div', {class: 'sim-form'},
          h('h3', {}, '1. Dimensions'), h('div', {class: 'g2'}, num('w', 'Largeur (m)'), num('l', 'Longueur (m)')),
          yn('hl', 'Meubles hauts et bas'),
          h('h3', {}, '2. Équipements'), ...c.equip.map(e => h('div', {class: 'sim-row'}, h('span', {}, e.name, e.price ? h('small', {class: 'muted'}, ' +' + DA(e.price)) : ''), seg([[true, 'Oui'], [false, 'Non']], false, v => { i.eq[e.name] = v; upd(); }))),
          h('h3', {}, '3. Modèle'), models,
          h('h3', {}, '4. Suppléments'), ...extras),
        h('div', {class: 'sim-side'}, plan, res,
          !p && me.can.call ? h('div', {class:'sim-client-picker'}, clientSearch, targetPick, pickerMsg) : '',
          h('div', {class: 'sim-actions'},
            me.can.call ? saveButton : '',
            h('button', {type: 'button', onclick: async () => { if (!last) { msg.textContent = 'Corrigez les dimensions avant de copier.'; return; } try { await navigator.clipboard.writeText(summary(last)); msg.textContent = '✔ Résumé copié : collez-le dans une note ou un message.'; } catch (e) { msg.textContent = summary(last); } }}, 'Copier le résumé')),
          msg,
          h('p', {class: 'muted'}, 'Estimation indicative, à confirmer après prise de mesures.'))),
      bar));
  root.append(bg); upd();
  bg.querySelector('input').focus();
}
/** Simulations déjà enregistrées sur la fiche. */
function simHistory(sims) {
  if (!sims || !sims.length) return '';
  return h('div', {}, h('h3', {}, 'Simulations de cuisine'), sims.map(s => {
    let d = {}; try { d = JSON.parse(s.detail) || {}; } catch (e) {}
    const i = d.in || {};
    return h('div', {class: 'card'}, h('strong', {}, '≈ ' + DA(d.total || 0)), ' — ', fmt(Number(s.created_at)), ' — ', s.user_id,
      h('div', {class: 'muted'}, [i.model, i.w && i.l ? mFmt(i.w) + ' × ' + mFmt(i.l) : '', i.hl ? 'haut et bas' : 'bas'].filter(Boolean).join(' · ')));
  }));
}
/** Administration : prix et coefficients du simulateur. */
function simCard() {
  const box = h('div', {class: 'card'}, h('h3', {}, 'Simulateur de cuisine : prix et coefficients'), h('div', {class: 'skel'}));
  simCfg(true).then(c => {
    const out = h('span', {class: 'muted'});
    const lines = (arr, lab, extra) => { const wrap = h('div', {});
      const paint = () => wrap.replaceChildren(...arr.map((x, n) => h('div', {class: 'bar'},
        h('input', {value: x.name, placeholder: lab, 'aria-label': lab, oninput: e => x.name = e.target.value}),
        extra ? extra(x) : '',
        h('input', {type: 'number', min: 0, value: x.price, style: 'max-width:130px', 'aria-label': 'Prix (DA)', title: 'Prix (DA)', oninput: e => x.price = parseFloat(e.target.value) || 0}),
        h('button', {type: 'button', 'aria-label': 'Supprimer', onclick: () => { arr.splice(n, 1); paint(); }}, '✕'))),
        h('button', {type: 'button', onclick: () => { arr.push(extra ? {name: '', price: 0, deduct: 0, when: 'yes'} : {name: '', price: 0}); paint(); }}, '＋ Ajouter'));
      paint(); return wrap; };
    const models = c.models.map(x => ({...x})), extras = c.extras.map(x => ({...x})), equip = c.equip.map(x => ({...x}));
    const eqExtra = x => [
      h('input', {type: 'number', step: '0.05', min: 0, value: x.deduct, style: 'max-width:90px', 'aria-label': 'Déduction (m)', title: 'Mètres retirés', oninput: e => x.deduct = parseFloat(e.target.value) || 0}),
      h('select', {'aria-label': 'Déduire quand', title: 'Déduire quand', onchange: e => x.when = e.target.value}, h('option', {value: 'yes', selected: x.when === 'yes' ? 'selected' : null}, 'si présent'), h('option', {value: 'no', selected: x.when === 'no' ? 'selected' : null}, 'si absent'))];
    const f = (k, lab, step) => { const el = h('input', {type: 'number', step: step || '0.01', min: 0, value: c[k], style: 'max-width:140px'}); return {k, el, label: h('label', {}, lab, el)}; };
    const fs = [f('hl_mult', 'Coefficient meubles hauts et bas (×)'), f('depth', 'Profondeur plan de travail (m)'), f('m2', 'Prix du m² du plan (DA)', '1'), f('labour', 'Main-d’œuvre (DA)', '1'), f('plinth', 'Plinthe (DA)', '1'), f('wt_deduct', 'Déduction plan de travail (m)'), f('coef', 'Coefficient plan de travail (×)'), f('range', 'Fourchette affichée (± %)', '1'), f('round', 'Arrondi du prix (DA)', '100')];
    const pml = h('input', {type: 'checkbox', checked: c.plinth_ml ? 'checked' : null});
    box.replaceChildren(h('h3', {}, 'Simulateur de cuisine : prix et coefficients'),
      h('p', {class: 'muted'}, 'Prix des modèles (par mètre linéaire) :'), lines(models, 'Modèle'),
      h('p', {class: 'muted'}, 'Équipements — colonnes : nom · mètres retirés des meubles · condition de la déduction (équipement présent ou absent) · prix ajouté s’il est présent (0 = aucun) :'), lines(equip, 'Équipement', eqExtra),
      h('p', {class: 'muted'}, 'Suppléments (prix unitaire) :'), lines(extras, 'Supplément'),
      h('div', {class: 'g2'}, ...fs.map(x => x.label)),
      h('label', {}, pml, ' La plinthe est facturée au mètre linéaire (sinon : forfait)'),
      h('div', {class: 'bar'}, h('button', {class: 'primary', onclick: async () => {
        out.textContent = '…';
        const cfg = {models, extras, equip, plinth_ml: pml.checked ? 1 : 0}; fs.forEach(x => cfg[x.k] = parseFloat(x.el.value) || 0);
        try { SIM = (await api('/sim', {method: 'POST', body: {cfg}})).cfg; out.textContent = '✔ Prix enregistrés.'; } catch (e) { out.textContent = e.message; }
      }}, 'Enregistrer'), out));
  }).catch(e => box.replaceChildren(h('p', {class: 'err'}, e.message)));
  return box;
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
  if (!total) return h('p', {class:'muted'}, 'Aucun prospect.');
  return h('div', {class:'card'}, h('div', {class:'muted', style:'margin-bottom:6px'}, 'Total : ' + total + ' dossier(s)'),
    keys.map(k => { const n = cnt[k] || 0;
      return h('div', {style:'display:flex;align-items:center;gap:8px;margin:5px 0'},
        h('div', {style:'width:150px;flex:none;font-size:13px'}, ST[k]),
        h('div', {style:'flex:1;background:var(--color-background-dark);border-radius:6px;height:20px;overflow:hidden'},
          h('div', {style:'height:100%;border-radius:6px;width:' + (n / max * 100) + '%;background:' + STC[k]})),
        h('div', {style:'width:90px;flex:none;text-align:right;font-size:13px'}, h('strong', {}, String(n)), ' (' + Math.round(n / total * 100) + ' %)')); }));
}
function hoursChart(hrs) {
  const max = Math.max(1, ...hrs), total = hrs.reduce((a, b) => a + b, 0);
  if (!total) return h('div', {class:'card muted'}, 'Aucun appel sur la période.');
  const best = hrs.indexOf(Math.max(...hrs));
  return h('div', {class:'card'},
    h('div', {style:'display:flex;align-items:flex-end;gap:3px;height:160px;padding-top:18px'}, hrs.map((n, i) =>
      h('div', {title: i + 'h–' + (i + 1) + 'h : ' + n + ' appel(s)', style:'flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;height:100%;min-width:0'},
        h('span', {style:'font-size:10px'}, n ? String(n) : ''),
        h('div', {style:'width:100%;border-radius:4px 4px 0 0;background:#3b82f6;opacity:' + (i === best ? 1 : .65) + ';height:' + (n / max * 100 * .85) + '%;min-height:' + (n ? 2 : 0) + 'px'})))),
    h('div', {style:'display:flex;gap:3px;margin-top:4px'}, hrs.map((n, i) => h('span', {style:'flex:1;text-align:center;font-size:10px;color:var(--color-text-maxcontrast)'}, String(i)))),
    h('div', {class:'muted', style:'margin-top:6px'}, total + ' appel(s) — heure la plus active : ' + best + 'h–' + (best + 1) + 'h (' + hrs[best] + ')'));
}
async function stats() {
  const nameOf = u => (me.commerciaux.find(c => c.uid === u) || {name:u}).name;
  const box = h('div', {}, skel()), sum = h('div');
  const from = h('input', {type:'date'}), to = h('input', {type:'date'});
  const usr = multi('Commerciaux', me.commerciaux.map(u => ({v:u.uid, t:u.name})));
  const grp = multi('Groupes', me.groups.map(g => ({v:g.id, t:g.name})));
  const cat = multi('Catégories', me.options.c2.map(c => ({v:c, t:c})));
  const res = h('select', {}, h('option', {value:''}, 'Tous résultats'), RES.map(k => h('option', {value:k}, ST[k])));
  const td = t => h('td', {style:'padding:6px 4px;vertical-align:top'}, t);
  const params = () => {
    const day = (v, add) => v ? Math.floor(new Date(v + 'T00:00:00').getTime() / 1000) + add : '';
    return new URLSearchParams({user: me.can.all ? usr.values().join('|') : '', group: me.can.all ? grp.values().join('|') : '', cat: cat.values().join('|'),
      result: res.value, from: day(from.value, 0), to: day(to.value, 86400), tz: -new Date().getTimezoneOffset()});
  };
  let jp = null, jpage = 1, jsize = 10, jsort = {k:'date', d:'desc'};
  try { const v = localStorage.getItem('callcrm_jps2'); if (v !== null && [0, 10, 20, 30].includes(Number(v))) jsize = Number(v); } catch (e) {}
  const jq = h('input', {type:'search', placeholder:'Rechercher dans le journal…'});
  const jpg = h('div', {class:'bar', style:'justify-content:space-between;align-items:center;margin:8px 0'});
  const jhead = [['date', 'Date et heure'], ['user', 'Commercial'], ['client', 'Client'], ['phone', 'Téléphone'], ['cat', 'Catégorie'], ['result', 'Résultat'], ['note', 'Note']];
  const jload = async () => {
    if (!jp) return;
    const p = new URLSearchParams(jp); p.set('q', jq.value); p.set('sort', jsort.k); p.set('dir', jsort.d); p.set('limit', jsize); p.set('offset', (jpage - 1) * jsize);
    try {
      const r = await api('/journal?' + p), pages = jsize ? Math.max(1, Math.ceil(r.total / jsize)) : 1;
      if (jpage > pages) { jpage = pages; return jload(); }
      const go3 = n => { jpage = n; jload(); };
      jpg.replaceChildren(h('span', {class:'muted'}, r.total + ' appel(s) — page ' + jpage + ' / ' + pages),
        h('span', {}, h('button', {disabled: jpage <= 1, onclick: () => go3(jpage - 1)}, '◀ Précédent'), ' ', h('button', {disabled: jpage >= pages, onclick: () => go3(jpage + 1)}, 'Suivant ▶'), ' ',
          h('select', {onchange: e => { jsize = Number(e.target.value); try { localStorage.setItem('callcrm_jps2', String(jsize)); } catch (x) {} jpage = 1; jload(); }},
            [10, 20, 30, 0].map(n => h('option', {value:n, selected: n === jsize}, n ? n + ' par page' : 'Tout')))));
      box.replaceChildren(r.rows.length ? h('table', {style:'width:100%;border-collapse:collapse'},
        h('thead', {}, h('tr', {}, jhead.map(([k, t]) => h('th', {style:'text-align:left;padding:4px;cursor:pointer;white-space:nowrap;user-select:none', title:'Trier', onclick: () => { if (jsort.k === k) jsort.d = jsort.d === 'asc' ? 'desc' : 'asc'; else { jsort.k = k; jsort.d = k === 'date' ? 'desc' : 'asc'; } jpage = 1; jload(); }},
          t + ' ', h('small', {style: jsort.k === k ? '' : 'opacity:.35'}, jsort.k === k ? (jsort.d === 'asc' ? '▲' : '▼') : '⇅'))))),
        h('tbody', {}, r.rows.map(x => h('tr', {style:'border-top:1px solid var(--color-border);cursor:pointer', onclick: () => detail(x.prospect_id)},
          td(fmt(Number(x.created_at))), td(x.user_name), td(nm(x)), td(x.phone), td(x.c2 || ''), td(badge(x.result), x.reason ? ' · ' + x.reason : ''), td(x.note || ''))))) : h('p', {class:'muted'}, 'Aucun appel.'));
    } catch (e) { box.replaceChildren(h('p', {class:'err'}, e.message)); }
  };
  let jt; jq.addEventListener('input', () => { clearTimeout(jt); jt = setTimeout(() => { jpage = 1; jload(); }, 300); });
  const iso = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  const hbox = h('div');
  const setRange = (a, b) => { from.value = a ? iso(a) : ''; to.value = b ? iso(b) : ''; load(); };
  const dAgo = n => { const d = new Date(); d.setDate(d.getDate() - n); return d; };
  const presets = h('span', {style:'display:inline-flex;gap:4px;flex-wrap:wrap'},
    h('button', {onclick: () => setRange(new Date(), new Date())}, 'Aujourd’hui'),
    h('button', {onclick: () => setRange(dAgo(1), dAgo(1))}, 'Hier'),
    h('button', {onclick: () => setRange(dAgo(6), new Date())}, '7 jours'),
    h('button', {onclick: () => { const n = new Date(); setRange(new Date(n.getFullYear(), n.getMonth(), 1), n); }}, 'Ce mois'),
    h('button', {onclick: () => setRange(null, null)}, 'Toute la période'));
  from.value = to.value = iso(new Date());
  const upd = h('span', {class:'muted'});
  const rbtn = h('button', {class:'primary', onclick: () => load()}, '↻ Actualiser');
  const load = async () => {
    const p = params();
    refreshPersonalTimes();
    rbtn.disabled = true; rbtn.textContent = '↻ Actualisation…';
    try {
      jp = p; jpage = 1;
      const [, s] = await Promise.all([jload(), api('/stats?' + p)]);
      hbox.replaceChildren(hoursChart(s.hours || []));
      upd.textContent = 'Mis à jour à ' + new Date().toLocaleTimeString('fr-FR', {hour:'2-digit', minute:'2-digit', second:'2-digit'});
      const by = {};
      s.calls.forEach(c => { (by[c.user_id] = by[c.user_id] || {})[c.result] = c.n; });
      const cbk = new Set(Object.values(STD).filter(x => x.callback).map(x => x.key)), perf = {};
      s.calls.forEach(c => { const o = perf[c.user_id] = perf[c.user_id] || {n:0, ct:0, it:0}; const n = Number(c.n); o.n += n; if (!cbk.has(c.result) && c.result !== 'faux_numero') o.ct += n; if (c.result === 'interesse') o.it += n; });
      Object.keys(s.today || {}).forEach(u => { perf[u] = perf[u] || {n:0, ct:0, it:0}; });
      const pct = (a, b) => b ? Math.round(100 * a / b) + ' %' : '—';
      const goal = Number(s.goal) || 0;
      const rsum = (s.reasons || []).reduce((a, x) => a + Number(x.n), 0);
      sum.replaceChildren(
        h('h3', {}, 'Résultats d’appels par type de réponse'),
        h('p', {class:'muted'}, 'Nombre de résultats d’appel sur la période des statistiques, y compris les rappels et les réponses personnalisées. Plusieurs appels sur un dossier sont comptés ici séparément.'),
        callResponsesTable(s.calls),
        h('h3', {}, 'Performance par commercial'),
        Object.keys(perf).length ? h('div', {style:'overflow-x:auto'}, h('table', {class:'t'},
          h('thead', {}, h('tr', {}, ['Commercial', goal ? 'Aujourd’hui / objectif' : 'Aujourd’hui', 'Appels (période)', 'Contacts', 'Taux de contact', 'Intéressés', 'Taux d’intérêt'].map(x => h('th', {}, x)))),
          h('tbody', {}, Object.entries(perf).map(([u, o]) => { const t = Number((s.today || {})[u] || 0); return h('tr', {},
            h('td', {}, nameOf(u)), h('td', {}, t + (goal ? ' / ' + goal + ' ' : ''), goal ? h('span', {class:'pbar'}, h('span', {style:'width:' + Math.min(100, Math.round(100 * t / goal)) + '%'})) : ''),
            h('td', {}, o.n), h('td', {}, o.ct), h('td', {}, pct(o.ct, o.n)), h('td', {}, o.it), h('td', {}, pct(o.it, o.ct))); })))) : h('p', {class:'muted'}, 'Aucun appel sur la période.'),
        h('p', {class:'muted'}, 'Contact = appel abouti (hors « sans réponse / à rappeler » et faux numéro). Taux d’intérêt = intéressés / contacts.'),
        ...((s.reasons || []).length ? [h('h3', {}, 'Motifs de refus'), h('div', {class:'card'}, s.reasons.map(x => h('div', {}, x.reason + ' : ', h('strong', {}, x.n), ' (' + Math.round(100 * Number(x.n) / rsum) + ' %)')))] : []),
        h('h3', {}, 'Nombre d’appels par heure'),
        hbox,
        h('h3', {}, 'Nombre de dossiers par statut'),
        statusChart(s.status),
        h('h3', {}, 'Prospects par statut'),
        h('div', {class:'card'}, s.status.length ? s.status.map(r => h('div', {}, (ST[r.status] || r.status) + ' : ', h('strong', {}, r.n))) : 'Aucun prospect.'),
        h('h3', {}, me.can.all ? 'Appels par commercial' : 'Mes appels'),
        ...Object.entries(by).map(([u, r]) => h('div', {class:'card'}, h('strong', {}, nameOf(u)), RES.map(k => h('div', {}, ST[k] + ' : ' + (r[k] || 0))))));
    } catch (e) { box.replaceChildren(h('p', {class:'err'}, e.message)); }
    rbtn.disabled = false; rbtn.textContent = '↻ Actualiser';
  };
  const xl = async () => {
    try {
      const r = await fetch(API + '/export?' + params(), {headers:{requesttoken: tok()}});
      if (!r.ok) throw new Error('Erreur ' + r.status);
      const blob = await r.blob();
      if (window.CRMApp && window.CRMApp.saveFile) {
        const rd = new FileReader();
        rd.onload = () => window.CRMApp.saveFile('callcrm_resultats.xlsx', String(rd.result).split(',')[1]);
        rd.readAsDataURL(blob);
        return;
      }
      const u = URL.createObjectURL(blob);
      const a = h('a', {href:u, download:'callcrm_resultats.xlsx'});
      document.body.append(a); a.click(); a.remove(); URL.revokeObjectURL(u);
    } catch (e) { alert(e.message); }
  };
  show(h('h2', {}, me.can.all ? 'Statistiques' : 'Mes statistiques'),
    me.role === 'commercial' ? personalTimesCard() : '',
    h('div', {class:'bar'}, me.can.all ? [usr.el, grp.el] : '', cat.el, res, h('label', {}, 'Du ', from), h('label', {}, 'Au ', to), presets, rbtn, me.can.export ? h('button', {onclick: xl, title: me.can.export_phone ? '' : 'Export sans numéros de téléphone'}, '⬇ Télécharger Excel' + (me.can.export_phone ? '' : ' (sans téléphone)')) : '', upd),
    h('h3', {}, 'Journal des appels'), h('div', {class:'bar'}, jq), jpg,
    h('div', {style:'overflow-x:auto'}, box), sum);
  [usr.el, grp.el, cat.el, res, from, to].forEach(e => e.addEventListener('change', load));
  load();
}

/** Lit un fichier Excel (.xls/.xlsx), nettoie les lignes, supprime les doublons de numéros ; renvoie {csv, total, kept, dupInFile, empty}. */
async function excelToCsv(f) {
  if (!window.XLSX) throw new Error('Lecteur Excel indisponible : rechargez la page (Ctrl+F5).');
  const wb = XLSX.read(await f.arrayBuffer(), {type:'array'});
  const ws = wb.Sheets[wb.SheetNames[0]];
  if (!ws) throw new Error('Feuille Excel vide.');
  const rows = XLSX.utils.sheet_to_json(ws, {header:1, raw:false, defval:''}).map(r => r.map(c => String(c == null ? '' : c).replace(/\s+/g, ' ').trim()));
  const flat = v => String(v).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  const digits = v => { let d = String(v).replace(/\D+/g, ''); if (d.startsWith('00213')) d = d.slice(5); else if (d.startsWith('213') && d.length >= 11) d = d.slice(3); if (d && d[0] !== '0') d = '0' + d; return d; };
  const looksPhone = v => digits(v).length >= 9;
  // en-tête : si la 1re ligne contient déjà un numéro, il n'y a pas d'en-tête
  let start = 1; const first = rows[0] || [];
  if (first.some(looksPhone) && !first.some(c => /t[eé]l|phone|nom|name/i.test(c))) start = 0;
  // colonnes : par nom d'en-tête si reconnu, sinon ordre standard
  const std = [/nom|name|client/, /t[eé]l|phone|mobile|gsm|num/, /wilaya/, /cat[eé]g|produit/, /emploi|profession|job/, /salaire|revenu/, /comment|remarque|note|observ/];
  const cust = (me.fields || []).filter(f => !f.builtin);
  const cmap = cust.map(f => start === 0 ? -1 : first.findIndex(c => flat(c) === flat(f.label)));
  const idx = std.map((re, i) => { if (start === 0) return i; const j = first.findIndex((c, k) => !cmap.includes(k) && re.test(flat(c))); return j; });
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
  const head = ['Nom', 'Téléphone', 'Wilaya', 'Catégorie', 'Emploi', 'Salaire', 'Commentaire', ...cust.map(f => f.label)];
  const csv = '\uFEFF' + [head, ...out].map(r => r.map(q).join(';')).join('\r\n');
  return {csv, extras: cust.map(f => f.key), total: rows.length - start - empty, kept: out.length, dupInFile: dup, empty};
}
const TYPE_LABELS = {text:'Texte libre', textarea:'Texte long', number:'Nombre', date:'Date', email:'E-mail', yesno:'Oui / Non', select:'Liste (un seul choix)', multiselect:'Liste (plusieurs choix)'};
function fieldsEditor() {
  let list = JSON.parse(JSON.stringify(me.fields || []));
  const out = h('span', {class:'muted'}), box = h('div');
  const draw = () => {
    box.replaceChildren(...list.map((f, i) => {
      const lab = h('input', {value:f.label, style:'flex:1;min-width:140px', oninput: e => { f.label = e.target.value; }});
      const typ = h('select', {disabled: f.locked, onchange: e => { f.type = e.target.value; if (['select', 'multiselect'].includes(f.type) && !(f.options || []).length) f.options = (f.defaultOptions || []).slice(); draw(); }},
        f.locked ? h('option', {}, f.key === 'phone' ? 'Téléphone' : f.key === 'c2' ? 'Liste (catégories)' : 'Texte') : Object.entries(TYPE_LABELS).map(([v, l]) => h('option', {value:v, selected: f.type === v}, l)));
      const req = h('label', {style:'white-space:nowrap'}, h('input', {type:'checkbox', checked: f.required, disabled: f.key === 'lastname', onchange: e => { f.required = e.target.checked; }}), ' Obligatoire');
      const vis = f.builtin ? (['lastname', 'phone'].includes(f.key) ? '' : h('label', {style:'white-space:nowrap'}, h('input', {type:'checkbox', checked: !f.hidden, onchange: e => { f.hidden = !e.target.checked; }}), ' Affiché')) : '';
      const mv = (d) => () => { const j = i + d; if (j < 0 || j >= list.length) return; [list[i], list[j]] = [list[j], list[i]]; draw(); };
      const del = f.builtin ? '' : h('button', {title:'Supprimer ce champ', onclick: () => { if (confirm('Supprimer le champ « ' + f.label + ' » ? Les valeurs déjà saisies ne seront plus affichées.')) { list.splice(i, 1); draw(); } }}, '🗑');
      const opts = ['select', 'multiselect'].includes(f.type) && !(f.locked) ? h('textarea', {rows:3, placeholder:'Un choix par ligne', style:'width:100%;box-sizing:border-box;margin-top:4px', oninput: e => { f.options = e.target.value.split('\n'); }}, (f.options || []).join('\n')) : '';
      return h('div', {style:'border-top:1px solid var(--color-border);padding:8px 0'},
        h('div', {style:'display:flex;flex-wrap:wrap;gap:8px;align-items:center'}, lab, typ, req, vis,
          h('button', {title:'Monter', onclick: mv(-1)}, '↑'), h('button', {title:'Descendre', onclick: mv(1)}, '↓'), del), opts,
        f.key === 'lastname' ? h('div', {class:'muted'}, 'Champ obligatoire en permanence.') : f.key === 'phone' ? h('div', {class:'muted'}, 'Numéro algérien vérifié et sans doublon ; indispensable pour appeler (WhatsApp, rappels, enregistrements).') : f.key === 'c2' ? h('div', {class:'muted'}, 'Sert à répartir les prospects entre les équipes : le type reste une liste de catégories.') : '');
    }));
  };
  draw();
  return h('div', {class:'card'}, h('h3', {}, 'Champs de la fiche client'),
    h('p', {class:'muted'}, 'Renommez, réordonnez, masquez ou supprimez les champs, changez leur type (texte, nombre, date, liste à un ou plusieurs choix…) et choisissez lesquels sont obligatoires. Seul « Nom et prénom » est toujours obligatoire.'),
    box,
    h('div', {style:'margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap'},
      h('button', {onclick: () => { list.push({key:'', label:'Nouveau champ', type:'text', required:false, builtin:false, options:[]}); draw(); }}, '+ Ajouter un champ'),
      h('button', {class:'primary', onclick: async () => {
        out.textContent = '…';
        try { const r = await api('/fields', {method:'POST', body:{fields:list}}); me.fields = r.fields; list = JSON.parse(JSON.stringify(r.fields)); draw(); out.textContent = 'Champs enregistrés.'; }
        catch (e) { out.textContent = e.message; }
      }}, 'Enregistrer les champs'), out));
}
function statusesEditor() {
  let list = JSON.parse(JSON.stringify(me.statuses || []));
  const out = h('span', {class:'muted'}), box = h('div');
  const draw = () => {
    box.replaceChildren(...list.map((f, i) => {
      const nw = f.key === 'nouveau';
      const lab = h('input', {value:f.label, style:'flex:1;min-width:140px', oninput: e => { f.label = e.target.value; }});
      const col = h('input', {type:'color', value:f.color, title:'Couleur', oninput: e => { f.color = e.target.value; }});
      const cb = nw ? '' : h('label', {style:'white-space:nowrap'}, h('input', {type:'checkbox', checked: f.callback, onchange: e => { f.callback = e.target.checked; }}), ' Date de rappel');
      const nt = nw ? '' : h('label', {style:'white-space:nowrap'}, h('input', {type:'checkbox', checked: f.note, onchange: e => { f.note = e.target.checked; }}), ' Note obligatoire');
      const rs = nw ? '' : h('label', {style:'white-space:nowrap'}, h('input', {type:'checkbox', checked: f.reason, onchange: e => { f.reason = e.target.checked; }}), ' Motif obligatoire');
      const ac = nw ? '' : h('label', {style:'white-space:nowrap'}, h('input', {type:'checkbox', checked: f.active, onchange: e => { f.active = e.target.checked; }}), ' Actif');
      const mv = d => () => { const j = i + d; if (j < 0 || j >= list.length) return; [list[i], list[j]] = [list[j], list[i]]; draw(); };
      const del = f.builtin ? '' : h('button', {title:'Supprimer', onclick: () => { if (confirm('Supprimer la réponse « ' + f.label + ' » ?')) { list.splice(i, 1); draw(); } }}, '🗑');
      return h('div', {style:'border-top:1px solid var(--color-border);padding:8px 0;display:flex;flex-wrap:wrap;gap:8px;align-items:center'}, col, lab, cb, nt, rs, ac,
        h('button', {onclick: mv(-1)}, '↑'), h('button', {onclick: mv(1)}, '↓'), del, !nw ? h('label',{style:'width:100%'},'Motifs de cette réponse (un par ligne)',h('textarea',{rows:3,style:'width:100%',oninput:e=>{f.reason_options=e.target.value.split(/\r?\n/).map(x=>x.trim()).filter(Boolean);}},(f.reason_options||[]).join('\n'))) : '', nw ? h('span', {class:'muted'}, 'Statut initial (pas une réponse d’appel).') : '');
    }));
  };
  draw();
  return h('div', {class:'card'}, h('h3', {}, 'Réponses d’appel / statuts'),
    h('p', {class:'muted'}, 'Renommez, recolorez, réordonnez, désactivez ou ajoutez des réponses (intéressé, ne répond pas…). « Date de rappel » : le commercial doit fixer un rappel et le prospect apparaît dans « À rappeler ». « Motif obligatoire » : le commercial doit choisir un motif (liste modifiable dans « Réglages des appels »). Une réponse déjà utilisée ne peut pas être supprimée : désactivez-la.'),
    box,
    h('div', {style:'margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap'},
      h('button', {onclick: () => { list.push({key:'', label:'Nouvelle réponse', color:'#607d8b', callback:false, note:false, reason:false, active:true, builtin:false}); draw(); }}, '+ Ajouter une réponse'),
      h('button', {class:'primary', onclick: async () => {
        out.textContent = '…';
        try { const r = await api('/statuses', {method:'POST', body:{statuses:list}}); me.statuses = r.statuses; applyStatuses(); list = JSON.parse(JSON.stringify(r.statuses)); draw(); out.textContent = 'Enregistré. Rechargez la page pour mettre à jour les onglets.'; }
        catch (e) { out.textContent = e.message; }
      }}, 'Enregistrer les réponses'), out));
}
function transferCard() {
  const com = me.commerciaux || [];
  const from = h('select', {}, h('option', {value:'__none'}, 'Non assignés'), com.map(u => h('option', {value:u.uid}, u.name)));
  const to = h('select', {}, h('option', {value:''}, '— Choisir —'), h('option', {value:'__none'}, 'Non assigné'), com.map(u => h('option', {value:u.uid}, u.name)));
  const st = h('select', {}, h('option', {value:'nouveau'}, 'Nouveau (À traiter)'), h('option', {value:''}, 'Tous les statuts'),
    Object.entries(ST).filter(([v]) => v !== 'nouveau').map(([v, l]) => h('option', {value:v}, l)));
  const lim = h('input', {type:'number', min:0, placeholder:'Tous', style:'width:90px'});
  const out = h('div', {class:'muted', style:'margin-top:6px'});
  const body = dry => ({from:from.value, to:to.value, status:st.value, limit:lim.value ? Number(lim.value) : 0, dry});
  const prev = async () => {
    if (!to.value) { out.textContent = 'Choisissez le commercial destinataire.'; return; }
    out.textContent = '…';
    try { const r = await api('/transfer', {method:'POST', body:body(true)}); out.textContent = r.count + ' prospect(s) seront transférés.'; }
    catch (e) { out.textContent = e.message; }
  };
  return h('div', {class:'card'}, h('h3', {}, 'Transférer des prospects entre commerciaux'),
    h('p', {class:'muted'}, 'Déplace en masse les prospects d’un commercial vers un autre ou vers Non assigné (par défaut : seulement les « Nouveau », les plus anciens d’abord). Laissez « Quantité » vide pour tout transférer, ou saisissez un nombre pour n’en transférer qu’une partie.'),
    h('div', {class:'bar'}, h('label', {}, 'De ', from), h('label', {}, 'Vers ', to)),
    h('div', {class:'bar'}, h('label', {}, 'Statut ', st), h('label', {}, 'Quantité max ', lim)),
    h('div', {class:'bar'}, h('button', {onclick: prev}, 'Compter'), h('button', {class:'primary', onclick: async () => {
      if (!to.value) { out.textContent = 'Choisissez le commercial destinataire.'; return; }
      let n; try { n = (await api('/transfer', {method:'POST', body:body(true)})).count; } catch (e) { out.textContent = e.message; return; }
      if (!n) { out.textContent = 'Aucun prospect à transférer.'; return; }
      const nm = u => (com.find(x => x.uid === u) || {name:'Non assignés'}).name;
      if (!confirm('Transférer ' + n + ' prospect(s) de « ' + nm(from.value === '__none' ? '' : from.value) + ' » vers « ' + nm(to.value) + ' » ?')) return;
      out.textContent = '…';
      try { const r = await api('/transfer', {method:'POST', body:body(false)}); out.textContent = '✔ ' + r.transferred + ' prospect(s) transféré(s).'; }
      catch (e) { out.textContent = e.message; }
    }}, 'Transférer')), out);
}
function bulkStatusCard() {
  const com = me.commerciaux || [];
  const who = h('select', {}, h('option', {value:'__all'}, 'Tous les commerciaux'), h('option', {value:''}, 'Non assignés'), com.map(u => h('option', {value:u.uid}, u.name)));
  const chk = Object.entries(ST).map(([v, l]) => ({v, el: h('input', {type:'checkbox', value:v, checked: v === 'injoignable' || v === 'pas_interesse'})}));
  const to = h('select', {}, Object.entries(ST).map(([v, l]) => h('option', {value:v, selected: v === 'nouveau'}, l)));
  const mode = h('select', {}, h('option', {value:'keep'}, 'Garder le commercial actuel'), h('option', {value:'none'}, 'Rendre non assignés (à répartir ensuite)'), h('option', {value:'user'}, 'Affecter à un commercial…'));
  const dest = h('select', {style:'display:none'}, h('option', {value:''}, '— Choisir —'), com.map(u => h('option', {value:u.uid}, u.name)));
  mode.addEventListener('change', () => { dest.style.display = mode.value === 'user' ? '' : 'none'; });
  const lim = h('input', {type:'number', min:0, placeholder:'Tous', style:'width:90px'});
  const out = h('div', {class:'muted', style:'margin-top:6px'});
  const body = dry => ({from_status: chk.filter(c => c.el.checked).map(c => c.v), assigned:who.value, to_status:to.value, assign_mode:mode.value, assign_to:dest.value, limit: lim.value ? Number(lim.value) : 0, dry});
  const prev = async () => { out.textContent = '…'; try { const r = await api('/bulkstatus', {method:'POST', body:body(true)}); out.textContent = r.count + ' prospect(s) concernés.'; } catch (e) { out.textContent = e.message; } };
  return h('div', {class:'card'}, h('h3', {}, 'Changer le statut des prospects en masse'),
    h('p', {class:'muted'}, 'Sélectionnez les statuts d’origine (aucun coché = tous), éventuellement un commercial, puis le nouveau statut. Exemple : « Ne répond pas » et « Pas intéressé » → « Nouveau », puis répartissez-les. Les rappels prévus et le compteur de tentatives sont remis à zéro ; l’historique des appels est conservé. Pour une sélection précise, cochez les fiches dans les listes.'),
    h('div', {style:'display:flex;flex-wrap:wrap;gap:4px 14px;padding:4px 0'}, chk.map((c, i) => h('label', {style:'white-space:nowrap'}, c.el, ' ' + ST[c.v]))),
    h('div', {class:'bar'}, h('label', {}, 'Commercial ', who), h('label', {}, 'Quantité max ', lim)),
    h('div', {class:'bar'}, h('label', {}, 'Nouveau statut ', to), mode, dest),
    h('div', {class:'bar'}, h('button', {onclick: prev}, 'Compter'), h('button', {class:'primary', onclick: async () => {
      if (mode.value === 'user' && !dest.value) { out.textContent = 'Choisissez le commercial destinataire.'; return; }
      let n; try { n = (await api('/bulkstatus', {method:'POST', body:body(true)})).count; } catch (e) { out.textContent = e.message; return; }
      if (!n) { out.textContent = 'Aucun prospect concerné.'; return; }
      if (!confirm('Passer ' + n + ' prospect(s) en « ' + ST[to.value] + ' » ?')) return;
      out.textContent = '…';
      try { const r = await api('/bulkstatus', {method:'POST', body:body(false)}); out.textContent = '✔ ' + r.changed + ' prospect(s) mis à jour.'; }
      catch (e) { out.textContent = e.message; }
    }}, 'Appliquer')), out);
}
function settingsCard() {
  const P = me.prefs || {};
  const num = (v, w = 70) => h('input', {type:'number', min:0, value:v || 0, style:'width:' + w + 'px'});
  const max = num(P.max_callbacks), gap = num(P.cb_gap, 80), goal = num(P.goal, 80);
  const from = h('input', {type:'time', value:P.cb_from || ''}), to = h('input', {type:'time', value:P.cb_to || ''});
  const tz = h('input', {value:P.tz || 'Africa/Algiers', style:'width:160px'});
  const rs = h('textarea', {rows:6, style:'width:100%;box-sizing:border-box'}, (P.reasons || []).join('\n'));
  const out = h('span', {class:'muted'});
  return h('div', {class:'card'}, h('h3', {}, 'Réglages des appels'),
    h('p', {class:'muted'}, 'Limite de tentatives : après ce nombre de tentatives sans succès (réponses avec date de rappel : « Ne répond pas », « À rappeler »…), le prospect passe automatiquement en « Injoignable / faux numéro ». Avec 3, la 3e tentative sans succès clôture le prospect. 0 = illimité.'),
    h('div', {class:'bar'}, h('label', {}, 'Tentatives maximum ', max)),
    h('p', {class:'muted'}, 'Plage de rappel : heures entre lesquelles un rappel peut être programmé (vider les deux champs = aucune limite). Espacement : délai minimum entre l’appel et le rappel programmé (0 = aucun).'),
    h('div', {class:'bar'}, h('label', {}, 'Plage de rappel de ', from), h('label', {}, 'à ', to), h('label', {}, 'Espacement minimum (min) ', gap), h('label', {}, 'Fuseau horaire ', tz)),
    h('p', {class:'muted'}, 'Objectif quotidien : nombre d’appels attendus par commercial et par jour (0 = pas d’objectif).'),
    h('div', {class:'bar'}, h('label', {}, 'Appels par jour ', goal)),
    h('p', {class:'muted'}, 'Motifs de refus proposés pour les réponses « Motif obligatoire » (un par ligne).'), rs,
    h('div', {class:'bar'}, h('button', {class:'primary', onclick: async () => {
      out.textContent = '…';
      try {
        const r = await api('/callprefs', {method:'POST', body:{max_callbacks:Number(max.value) || 0, cb_gap:Number(gap.value) || 0, goal:Number(goal.value) || 0, cb_from:from.value, cb_to:to.value, tz:tz.value, reasons:rs.value}});
        me.prefs = r.prefs; me.max_callbacks = r.max_callbacks; out.textContent = '✔ Réglages enregistrés.';
      } catch (e) { out.textContent = e.message; }
    }}, 'Enregistrer'), out));
}
/** Liste de commerciaux à cocher (affectation à l'import / à la répartition). */
function commPicker(title) {
  const all = h('input', {type:'checkbox', onchange: () => boxes.forEach(x => x.cb.checked = all.checked)});
  const boxes = (me.commerciaux || []).map(u => ({u, cb: h('input', {type:'checkbox'})}));
  return {el: h('div', {style:'margin:6px 0'}, h('strong', {}, title),
      h('div', {style:'max-height:180px;overflow:auto;border:1px solid var(--color-border);border-radius:6px;padding:6px;margin-top:4px'},
        h('label', {}, all, ' Tout cocher / décocher'), ...boxes.map(x => h('label', {style:'display:block'}, x.cb, ' ' + x.u.name)))),
    values: () => boxes.filter(x => x.cb.checked).map(x => x.u.uid)};
}
const AUDIT = {transfer:'Transfert', status:'Changement de statut', auto_close:'Clôture automatique', assign:'Affectation', import:'Import', distribute:'Répartition', release:'Réattribution', delete:'Suppression', export:'Export', settings:'Réglages', sim:'Simulation cuisine'};
function auditCard() {
  const box = h('div'), out = h('div', {class:'muted'});
  const act = h('select', {}, h('option', {value:''}, 'Toutes les actions'), Object.entries(AUDIT).map(([k, l]) => h('option', {value:k}, l)));
  let page = 1; const size = 20;
  const load = async () => {
    box.textContent = '…';
    try {
      const r = await api('/audit?' + new URLSearchParams({action:act.value, limit:size, offset:(page - 1) * size}));
      const pages = Math.max(1, Math.ceil(r.total / size));
      out.replaceChildren(r.total + ' événement(s) — page ' + page + ' / ' + pages + ' ', h('button', {disabled: page <= 1, onclick: () => { page--; load(); }}, '◀'), ' ', h('button', {disabled: page >= pages, onclick: () => { page++; load(); }}, '▶'));
      box.replaceChildren(r.rows.length ? h('div', {style:'overflow-x:auto'}, h('table', {class:'t'}, h('thead', {}, h('tr', {}, ['Date et heure', 'Utilisateur', 'Action', 'Fiche', 'Détail'].map(x => h('th', {}, x)))),
        h('tbody', {}, r.rows.map(x => h('tr', {}, h('td', {style:'white-space:nowrap'}, fmtS(Number(x.created_at))), h('td', {}, x.user_name || '—'), h('td', {}, AUDIT[x.action] || x.action),
          h('td', {}, x.prospect_id ? h('a', {href:'#', onclick: e => { e.preventDefault(); detail(Number(x.prospect_id)); }}, '#' + x.prospect_id) : ''), h('td', {}, x.detail || '')))))) : h('p', {class:'muted'}, 'Aucun événement.'));
    } catch (e) { box.textContent = e.message; }
  };
  act.addEventListener('change', () => { page = 1; load(); });
  return h('div', {class:'card'}, h('h3', {}, 'Journal d’activité'),
    h('p', {class:'muted'}, 'Transferts, clôtures automatiques, imports, exports, suppressions : qui, quand, quoi.'),
    h('div', {class:'bar'}, act, h('button', {onclick: () => { page = 1; load(); }}, 'Afficher / actualiser')), out, box);
}
// ---- Présence des commerciaux ----
const PRESENCE_LABELS = {online:'En poste', pause:'En pause', absent:'Absent'};
const presenceLabel = p => {
  const label = p.status === 'pause' && p.pause_kind === 'lunch' ? 'Pause déjeuner' : (PRESENCE_LABELS[p.status] || 'Absent');
  return label + (p.pause_alert === 'overdue' ? ' · délai dépassé' : p.pause_alert === 'tolerance' ? ' · tolérance' : '');
};
const presenceTone = p => p.status === 'pause' && p.pause_alert === 'overdue' ? ' pause-overdue' : p.status === 'pause' && p.pause_alert === 'tolerance' ? ' pause-tolerance' : '';

let dayBox = null, dayBusy = false;
function myDay() {
  if (me.role !== 'commercial') return;
  dayBox = h('div', {class:'day-content'}, 'Chargement de votre journée…');
  show(h('div', {class:'crm-page-heading'}, h('div', {}, h('p', {class:'crm-eyebrow'}, 'Mon espace commercial'),
    h('h2', {}, 'Ma journée'), h('p', {class:'muted'}, 'Vos priorités et vos résultats, au même endroit.'))), dayBox);
  refreshMyDay();
}
async function refreshMyDay() {
  const box = dayBox;
  if (me.role !== 'commercial' || tab !== 'journee' || !box?.isConnected || dayBusy || document.hidden) return;
  dayBusy = true;
  try {
    const [report, counters] = await Promise.all([api('/presence/mine?period=day'),api('/counters')]);
    if (box !== dayBox || !box.isConnected || tab !== 'journee') return;
    const totals = report.totals.selected;
    box.replaceChildren(h('div', {class:'day-grid'},
      h('section', {class:'card day-session'}, h('p', {class:'crm-eyebrow'}, 'Aujourd’hui'),
        h('h3', {}, 'Votre journée de travail'), h('div', {class:'day-work-time'}, duration(totals.online)),
        h('p', {class:'muted'}, 'Temps en poste · ' + duration(totals.pause) + ' de pause classique · ' + duration(totals.lunch) + ' de déjeuner'),
        h('button', {class:'primary day-start', onclick:() => go('dossiers')}, 'Ouvrir mes dossiers à traiter'),
        h('div', {class:'day-priority'}, h('span', {}, 'Rappels en retard'), h('strong', {}, String(counters.overdue || 0)),
          h('button', {onclick:() => go(KINDS.rappels ? 'rappels' : 'dossiers')}, 'Consulter')),
        h('div', {class:'day-priority'}, h('span', {}, 'Dossiers traités aujourd’hui'), h('strong', {}, String(report.processed))),
        h('div', {class:'day-priority'}, h('span', {}, 'Appels saisis aujourd’hui'), h('strong', {}, String(counters.today || 0)))),
      h('section', {class:'card day-results'}, h('h3', {}, 'Mes résultats du jour'), dossierResults(report.by_result,true),
        h('button', {onclick:() => go('stats')}, 'Voir mes statistiques'))),
      h('p', {class:'muted'}, 'Actualisé : ' + new Date(report.server_now * 1000).toLocaleTimeString('fr-FR') + ' · ' + report.timezone));
  } catch (e) {
    if (box === dayBox && box.isConnected) box.replaceChildren(h('div', {class:'card'}, h('p', {class:'err', role:'status'}, 'Impossible de charger votre journée.'), h('button', {onclick:refreshMyDay}, 'Réessayer')));
  } finally { dayBusy = false; }
}
let personalTimesBox = null, personalTimesBusy = false;
let personalPeriod = {period:'day'}, rosterPeriod = {period:'day'};
const presencePath = (base, filter) => base + '?' + new window.URLSearchParams(filter);
function presencePeriodFilter(current, apply) {
  const period = h('select', {'aria-label':'Période'},
    [['day','Jour'],['week','Semaine'],['month','Mois'],['custom','Du / Au']].map(([value,label]) => h('option', {value, selected:current.period === value}, label)));
  const from = h('input', {type:'date', value:current.from || '', 'aria-label':'Du'});
  const to = h('input', {type:'date', value:current.to || '', 'aria-label':'Au'});
  const error = h('span', {class:'err', role:'status'});
  const submit = () => {
    if (period.value === 'custom' && (!from.value || !to.value || from.value > to.value)) {
      error.textContent = 'Choisissez une date Du et une date Au égale ou postérieure.'; return;
    }
    error.textContent = '';
    apply(period.value === 'custom' ? {period:'custom',from:from.value,to:to.value} : {period:period.value});
  };
  const dates = h('span', {class:'presence-dates', hidden:current.period !== 'custom'},
    h('label', {}, 'Du ', from), h('label', {}, 'Au ', to), h('button', {onclick:submit}, 'Appliquer'));
  period.addEventListener('change', () => { dates.hidden = period.value !== 'custom'; error.textContent = ''; if (period.value !== 'custom') submit(); });
  return h('div', {class:'bar presence-period'}, h('label', {}, 'Période ', period), dates, error);
}
function sortableHeading(label, key, active, direction, change) {
  return h('th', {scope:'col', 'aria-sort':active === key ? (direction === 'asc' ? 'ascending' : 'descending') : 'none'},
    h('button', {class:'sort-heading', 'data-sort-key':key, onclick:() => change(key)},
      label + ' ' + (active === key ? (direction === 'asc' ? '▲' : '▼') : '⇅')));
}
let personalResultSort = 'label', personalResultDirection = 'asc';
function responseKeys(observed = []) {
  const keys = (me.statuses || []).filter(x => x.result).map(x => x.key);
  observed.forEach(key => { if (!keys.includes(key)) keys.push(key); });
  return keys;
}
let responseSortKey = 'name', responseSortDirection = 'asc';
function callResponsesTable(calls) {
  const keys = responseKeys(calls.map(x => x.result)), byUser = {};
  calls.forEach(x => { const row = byUser[x.user_id] ||= {}; row[x.result] = (row[x.result] || 0) + Number(x.n); });
  if (me.role === 'commercial' && me.uid) byUser[me.uid] ||= {};
  const box = h('div', {class:'presence-table call-response-stats'});
  const name = uid => (me.commerciaux.find(u => u.uid === uid) || {name:uid}).name;
  const change = key => {
    responseSortDirection = responseSortKey === key && responseSortDirection === 'asc' ? 'desc' : 'asc'; responseSortKey = key; draw();
    Array.from(box.querySelectorAll('th button')).find(b => b.dataset.sortKey === key)?.focus();
  };
  const draw = () => {
    const users = Object.keys(byUser).sort((a,b) => {
      const order = responseSortKey === 'name' ? name(a).localeCompare(name(b),'fr') : Number(byUser[a][responseSortKey] || 0)-Number(byUser[b][responseSortKey] || 0);
      return (responseSortDirection === 'desc' ? -order : order) || name(a).localeCompare(name(b),'fr');
    });
    box.replaceChildren(users.length ? h('table', {class:'t'}, h('thead', {}, h('tr', {},
      sortableHeading('Commercial','name',responseSortKey,responseSortDirection,change),
      keys.map(key => sortableHeading(ST[key] || key,key,responseSortKey,responseSortDirection,change)))),
      h('tbody', {}, users.map(uid => h('tr', {}, h('td', {}, name(uid)), keys.map(key => h('td', {}, String(byUser[uid][key] || 0))))))) : h('p', {class:'muted'}, 'Aucun appel sur la période.'));
  };
  draw(); return box;
}
function dossierResults(counts, sortable = false) {
  const entries = responseKeys(Object.keys(counts || {})).map(key => [key,Number((counts || {})[key] || 0)]);
  if (!sortable) return h('div', {class:'dossier-result-counts'}, entries.length ? entries.sort(([a],[b]) => (ST[a] || a).localeCompare(ST[b] || b,'fr')).map(([key,n]) => h('span', {class:'dossier-result-count'}, badge(key), ' ', h('strong', {}, String(n)))) : h('span', {class:'muted'}, 'Aucun dossier traité sur cette période.'));
  const box = h('div', {class:'presence-table personal-results'});
  const change = key => {
    personalResultDirection = personalResultSort === key && personalResultDirection === 'asc' ? 'desc' : 'asc';
    personalResultSort = key; draw();
    Array.from(box.querySelectorAll('th button')).find(b => b.dataset.sortKey === key)?.focus();
  };
  const draw = () => {
    const sorted = entries.slice().sort(([a,n],[b,m]) => {
      const label = (ST[a] || a).localeCompare(ST[b] || b,'fr'), order = personalResultSort === 'number' ? Number(n)-Number(m) : label;
      return (personalResultDirection === 'desc' ? -order : order) || label;
    });
    box.replaceChildren(h('table', {class:'t'}, h('thead', {}, h('tr', {},
      sortableHeading('État','label',personalResultSort,personalResultDirection,change),
      sortableHeading('Nombre','number',personalResultSort,personalResultDirection,change))),
      h('tbody', {}, sorted.map(([key,n]) => h('tr', {}, h('td', {}, badge(key)), h('td', {}, String(n)))))),
      sorted.length ? '' : h('p', {class:'muted'}, 'Aucun dossier traité sur cette période.'));
  };
  draw(); return box;
}
let rosterSort = 'name', rosterDirection = 'asc', lastRosterReport = null;
function changeRosterSort(key) {
  rosterDirection = rosterSort === key && rosterDirection === 'asc' ? 'desc' : 'asc';
  rosterSort = key;
  if (lastRosterReport) renderPresenceBoard(lastRosterReport);
  Array.from(rosterBox?.querySelectorAll('th button') || []).find(b => b.dataset.sortKey === key)?.focus();
}
function sortedCommercials(rows) {
  const value = u => rosterSort === 'name' ? u.name : rosterSort === 'status' ? presenceLabel(u) : rosterSort === 'currentPause' ? (u.status === 'pause' && u.pause_since ? Math.max(0,lastRosterReport.server_now - u.pause_since) : 0) : rosterSort === 'processed' ? Number(u.processed || 0) : rosterSort.startsWith('result:') ? Number((u.by_result || {})[rosterSort.slice(7)] || 0) : Number(u.totals.selected[rosterSort] || 0);
  return rows.slice().sort((a,b) => {
    const av = value(a), bv = value(b), order = typeof av === 'string' ? av.localeCompare(bv,'fr',{numeric:true,sensitivity:'base'}) : av - bv;
    return (rosterDirection === 'desc' ? -order : order) || a.name.localeCompare(b.name,'fr') || a.uid.localeCompare(b.uid);
  });
}
function personalTimesCard() {
  personalTimesBox = h('div', {}, 'Chargement des durées…');
  return h('section', {class:'card', 'aria-label':'Mes temps de présence'}, h('h3', {}, 'Mon temps travaillé, mes pauses et mes dossiers traités'),
    h('p', {class:'muted'}, 'Période commune aux temps et aux dossiers traités. Un dossier est compté une seule fois, selon son dernier résultat d’appel sur cette période. Les filtres du journal des appels restent séparés.'),
    presencePeriodFilter(personalPeriod, filter => { personalPeriod = filter; personalTimesBox.replaceChildren('Chargement…'); refreshPersonalTimes(); }), personalTimesBox);
}
async function refreshPersonalTimes() {
  const box = personalTimesBox, filterKey = JSON.stringify(personalPeriod);
  if (me.role !== 'commercial' || tab !== 'stats' || !box?.isConnected || personalTimesBusy || document.hidden) return;
  personalTimesBusy = true;
  try {
    const report = await api(presencePath('/presence/mine',personalPeriod));
    if (box !== personalTimesBox || !box.isConnected || tab !== 'stats' || filterKey !== JSON.stringify(personalPeriod)) return;
    const selected = report.totals.selected;
    box.replaceChildren(h('p', {class:'muted'}, 'Du ' + report.range.from + ' au ' + report.range.to + ' inclus · ' + report.timezone),
      h('div', {class:'presence-totals'},
        h('div', {class:'card'}, h('strong', {}, 'Temps travaillé (en poste)'), h('p', {}, duration(selected.online))),
        h('div', {class:'card'}, h('strong', {}, 'Temps de pause'), h('p', {}, duration(selected.pause))),
        h('div', {class:'card'}, h('strong', {}, 'Pause déjeuner'), h('p', {}, duration(selected.lunch))),
        h('div', {class:'card'}, h('strong', {}, 'Dossiers traités'), h('p', {}, String(report.processed)))),
      h('h4', {}, 'État des dossiers traités'), dossierResults(report.by_result,true),
      h('p', {class:'muted'}, 'Actualisé : ' + new Date().toLocaleTimeString('fr-FR')));
  } catch (e) {
    if (box === personalTimesBox && box.isConnected && filterKey === JSON.stringify(personalPeriod)) box.replaceChildren(h('p', {class:'err', role:'status'}, 'Impossible de charger vos durées. Réessai automatique dans quelques secondes.'));
  } finally {
    personalTimesBusy = false;
    if (filterKey !== JSON.stringify(personalPeriod)) refreshPersonalTimes();
  }
}
let pauseDialog = null, pauseClock = null, presenceReceived = 0;
let myPresence = null, presenceBusy = false, presenceError = '', rosterBox = null, rosterBusy = false;
const duration = seconds => {
  const n = Math.max(0, Math.floor(Number(seconds) || 0));
  return String(Math.floor(n / 3600)).padStart(2,'0') + ':' + String(Math.floor(n / 60) % 60).padStart(2,'0') + ':' + String(n % 60).padStart(2,'0');
};
function syncPauseScreen() {
  if (!myPresence || !myPresence.paused) {
    if (pauseDialog) {
      if (typeof pauseDialog.close === 'function') pauseDialog.close();
      pauseDialog.remove(); pauseDialog = null;
      clearInterval(pauseClock); pauseClock = null;
      root.removeAttribute('inert');
    }
    return;
  }
  if (!pauseDialog) {
    const resume = h('button', {class:'primary', onclick:() => updateMyPresence(false)}, 'Reprendre le travail');
    pauseDialog = h('dialog', {class:'pause-screen', 'aria-labelledby':'pause-title', 'aria-modal':'true',
      oncancel:e => e.preventDefault(),
      onkeydown:e => { if (e.key === 'Tab') { e.preventDefault(); resume.focus(); } }},
      h('div', {class:'pause-panel'}, h('h2', {id:'pause-title'}, 'Vous êtes en pause'),
        h('p', {}, 'Reprenez le travail pour accéder à Call CRM.'),
        h('div', {class:'pause-timer', role:'timer', 'aria-label':'Durée de la pause'}, '00:00:00'),
        h('p', {class:'pause-error', role:'status'}), resume));
    document.body.append(pauseDialog); root.setAttribute('inert','');
    if (typeof pauseDialog.showModal === 'function') pauseDialog.showModal(); else pauseDialog.setAttribute('open','');
    resume.focus();
    pauseClock = setInterval(paintPauseClock, 1000);
  }
  pauseDialog.querySelector('#pause-title').textContent = myPresence.pause_kind === 'lunch' ? 'Pause déjeuner — bon appétit !' : 'Vous êtes en pause';
  pauseDialog.querySelector('button').disabled = presenceBusy;
  pauseDialog.querySelector('.pause-error').textContent = presenceError;
  paintPauseClock();
}
function paintPauseClock() {
  if (!pauseDialog || !myPresence) return;
  const now = Math.min(Number(myPresence.last_seen) + 120, Number(myPresence.server_now) + (Date.now() - presenceReceived) / 1000);
  pauseDialog.querySelector('.pause-timer').textContent = duration(myPresence.pause_since ? now - Number(myPresence.pause_since) : 0);
}
let commercialMessageDialog = null;
function openCommercialMessage(user) {
  if (!['admin','superviseur'].includes(me.role) || commercialMessageDialog) return;
  const previousFocus = document.activeElement;
  let sending = false, sent = false;
  const text = h('textarea', {rows:6, maxlength:2000, 'aria-label':'Message pour ' + user.name, placeholder:'Votre message…'});
  const out = h('p', {role:'status'});
  const close = () => {
    if (sending) return;
    if (typeof dialog.close === 'function') dialog.close();
    dialog.remove(); commercialMessageDialog = null; root.removeAttribute('inert');
    if (previousFocus?.isConnected) previousFocus.focus();
  };
  const cancel = h('button', {onclick:close}, 'Fermer');
  const send = h('button', {class:'primary', onclick:async () => {
    if (sending || sent) return;
    if (!text.value.trim()) { out.textContent = 'Saisissez votre message.'; text.focus(); return; }
    sending = true; send.disabled = true; cancel.disabled = true; out.textContent = 'Envoi en cours…';
    try {
      await api('/announcements', {method:'POST', body:{uids:[user.uid], body:text.value}});
      sent = true; text.disabled = true; out.textContent = 'Message envoyé à ' + user.name + '.';
    } catch (e) { out.textContent = 'Envoi impossible : ' + e.message; }
    finally { sending = false; send.disabled = sent; cancel.disabled = false; if (sent) cancel.focus(); }
  }}, 'Envoyer');
  const dialog = h('dialog', {class:'commercial-message', 'aria-labelledby':'commercial-message-title', 'aria-modal':'true',
    oncancel:e => { e.preventDefault(); close(); },
    onkeydown:e => {
      if (e.key !== 'Tab' || typeof dialog.showModal === 'function') return;
      const targets = [text,send,cancel].filter(x => !x.disabled), i = targets.indexOf(document.activeElement);
      e.preventDefault(); targets[(i + (e.shiftKey ? -1 : 1) + targets.length) % targets.length]?.focus();
    }}, h('h2', {id:'commercial-message-title'}, 'Message à ' + user.name),
    h('p', {class:'muted'}, 'Ce commercial recevra un popup dans Call CRM, sans possibilité de réponse.'),
    text, out, h('div', {class:'commercial-message-actions'}, send, cancel));
  commercialMessageDialog = dialog; document.body.append(dialog); root.setAttribute('inert','');
  if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open','');
  text.focus();
}
function presenceControl() {
  const label = presenceError ? 'État non confirmé' : (myPresence ? presenceLabel(myPresence) : 'Vérification…');
  return h('div', {class:'presence-control'},
    h('span', {class:'presence-badge ' + (!presenceError && myPresence ? myPresence.status : 'unknown') + (!presenceError && myPresence ? presenceTone(myPresence) : ''), role:'status'}, label),
    h('button', {disabled:presenceBusy || !myPresence, onclick:() => updateMyPresence(!myPresence.paused)},
      myPresence && myPresence.paused ? 'Reprendre le travail' : 'Me mettre en pause'),
    h('button', {class:'lunch-break-button', disabled:presenceBusy || !myPresence || myPresence.paused || myPresence.lunch_used_today, title:myPresence?.lunch_used_today ? 'Déjeuner déjà utilisé aujourd’hui' : 'Une fois par jour · 1 h et 10 min de tolérance', onclick:() => updateMyPresence(true,'lunch')}, 'Pause déjeuner'),
    presenceError ? h('span', {class:'err', role:'status'}, presenceError) : '');
}
function paintMyPresence() {
  root.querySelector('.presence-control')?.replaceWith(presenceControl());
  syncPauseScreen();
}
async function updateMyPresence(paused, kind) {
  if (me.role !== 'commercial' || presenceBusy) return;
  presenceBusy = true; paintMyPresence();
  try {
    myPresence = await api(paused === undefined ? '/presence/heartbeat' : '/presence/pause',
      {method:'POST', ...(paused === undefined ? {} : {body:{paused, ...(kind ? {kind} : {})}})});
    presenceReceived = Date.now();
    presenceError = '';
  } catch (e) { presenceError = 'Connexion indisponible : état non confirmé.'; }
  finally { presenceBusy = false; paintMyPresence(); }
}
async function refreshPresenceBoard() {
  const box = rosterBox, filterKey = JSON.stringify(rosterPeriod);
  if (tab !== 'commerciaux' || !box || !box.isConnected || rosterBusy || document.hidden) return;
  rosterBusy = true;
  try {
    const report = await api(presencePath('/presence',rosterPeriod)), rows = report.rows;
    if (tab !== 'commerciaux' || box !== rosterBox || !box.isConnected || filterKey !== JSON.stringify(rosterPeriod)) return;
    lastRosterReport = report; renderPresenceBoard(report);
  } catch (e) {
    if (box === rosterBox && box.isConnected && filterKey === JSON.stringify(rosterPeriod)) { lastRosterReport = null; box.replaceChildren(h('p', {class:'err', role:'status'}, 'Impossible de vérifier la présence. Réessai automatique dans quelques secondes.')); }
  } finally { rosterBusy = false; if (filterKey !== JSON.stringify(rosterPeriod)) refreshPresenceBoard(); }
}
function renderPresenceBoard(report) {
  const box = rosterBox;
  if (!box?.isConnected || tab !== 'commerciaux') return;
  const rows = sortedCommercials(report.rows);
    const states = responseKeys();
    rows.forEach(u => Object.keys(u.by_result || {}).forEach(key => { if (!states.includes(key)) states.push(key); }));
    const columns = [['name','Commercial'],['status','État actuel'],['currentPause','Pause en cours'],['online','Temps en poste'],['pause','Pause classique'],['lunch','Déjeuner'],['processed','Dossiers traités']];
    const detailHeading = h('th', {scope:'col', class:'outcome-detail-heading', 'aria-sort':rosterSort.startsWith('result:') ? (rosterDirection === 'asc' ? 'ascending' : 'descending') : 'none'},
      'Détail des dossiers traités', h('div', {class:'outcome-sort-headings'}, states.map(key =>
        h('button', {class:'sort-heading', 'data-sort-key':'result:' + key, onclick:() => changeRosterSort('result:' + key)},
          (ST[key] || key) + ' ' + (rosterSort === 'result:' + key ? (rosterDirection === 'asc' ? '▲' : '▼') : '⇅')))));
    const header = h('tr', {}, columns.map(([key,label]) => sortableHeading(label,key,rosterSort,rosterDirection,changeRosterSort)), detailHeading);
    const body = rows.map(u => {
      const counts = Object.fromEntries(states.map(key => [key,Number((u.by_result || {})[key] || 0)]));
      return h('tr', {},
        h('td', {}, h('button', {class:'commercial-message-link', 'aria-label':'Envoyer un message à ' + u.name, onclick:() => openCommercialMessage(u)}, u.name)),
        h('td', {}, h('span', {class:'presence-badge ' + u.status + presenceTone(u)}, presenceLabel(u))),
        h('td', {}, u.status === 'pause' && u.pause_since ? duration(report.server_now - u.pause_since) : '—'),
        ['online','pause','lunch'].map(mode => h('td', {}, duration(u.totals.selected[mode]))),
        h('td', {}, String(u.processed)), h('td', {class:'commercial-outcome-detail'}, dossierResults(counts)));
    });
    const table = h('table', {class:'t'}, h('thead', {}, header), h('tbody', {}, body));
    const count = status => rows.filter(u => status === 'lunch' ? u.status === 'pause' && u.pause_kind === 'lunch' : status === 'pause' ? u.status === 'pause' && u.pause_kind !== 'lunch' : u.status === status).length;
    const totals = [['online','Temps en poste'],['pause','Pause classique'],['lunch','Déjeuner']].map(([key,label]) => {
      const n = rows.reduce((sum,u) => sum + Number(key === 'processed' ? u.processed : u.totals.selected[key]),0);
      return h('div', {class:'card'}, h('strong', {}, label), h('p', {}, key === 'processed' ? String(n) : duration(n)));
    });
    const byResult = {};
    rows.forEach(u => Object.entries(u.by_result || {}).forEach(([key,n]) => { byResult[key] = (byResult[key] || 0) + Number(n); }));
    box.replaceChildren(h('p', {class:'muted'}, 'Du ' + report.range.from + ' au ' + report.range.to + ' inclus · ' + report.timezone + ' · Actualisé : ' + new Date(report.server_now * 1000).toLocaleTimeString('fr-FR')),
      h('div', {class:'team-kpis'},
        [['online','En poste maintenant'],['pause','En pause maintenant'],['lunch','Au déjeuner'],['absent','Absents'],['processed','Dossiers traités · période']].map(([key,label]) =>
          h('div', {class:'team-kpi', 'data-kpi':key}, h('strong', {}, String(key === 'processed' ? rows.reduce((n,u) => n + Number(u.processed),0) : count(key))), h('span', {}, label)))) ,
      h('div', {class:'presence-totals'}, totals), dossierResults(byResult),
      rows.length ? h('div', {class:'presence-table presence-overview'}, table) : h('p', {}, 'Aucun commercial dans le groupe configuré.'));

}
function presenceBoard() {
  if (!['admin', 'superviseur'].includes(me.role)) return;
  lastRosterReport = null;
  rosterBox = h('div', {}, 'Chargement…');
  show(h('h2', {}, 'Commerciaux'),
    h('p', {class:'muted'}, 'État actuel actualisé toutes les 15 secondes. Temps et dossiers traités sur la période sélectionnée, hors absences. Un dossier est compté une fois par commercial, selon son dernier résultat saisi sur cette période. La présence ne mesure pas l’activité devant le PC.'),
    presencePeriodFilter(rosterPeriod, filter => { rosterPeriod = filter; lastRosterReport = null; rosterBox.replaceChildren('Chargement…'); refreshPresenceBoard(); }), rosterBox);
  refreshPresenceBoard();
}

function announcementCard() {
  const text = h('textarea', {rows:5, maxlength:2000, 'aria-label':'Message aux commerciaux', placeholder:'Votre message…'});
  const out = h('p', {class:'muted', role:'status'});
  const choices = (me.commerciaux || []).map(u => ({u, cb:h('input', {type:'checkbox'})}));
  const all = h('input', {type:'checkbox', onchange:() => choices.forEach(x => { x.cb.checked = all.checked; })});
  const send = h('button', {onclick:async () => {
    const uids = choices.filter(x => x.cb.checked).map(x => x.u.uid);
    if (!uids.length) { out.textContent = 'Cochez au moins un commercial.'; return; }
    if (!text.value.trim()) { out.textContent = 'Saisissez votre message.'; return; }
    send.disabled = true; out.textContent = 'Envoi en cours…';
    try {
      const r = await api('/announcements', {method:'POST', body:{uids, body:text.value}});
      text.value = ''; out.textContent = 'Message envoyé à ' + r.sent + ' commercial(aux).';
    } catch (e) { out.textContent = e.message; }
    finally { send.disabled = false; }
  }}, 'Envoyer le message');
  return h('div', {class:'card'}, h('h3', {}, 'Message popup aux commerciaux'),
    h('p', {class:'muted'}, 'Message sans réponse. Il apparaît lorsque Call CRM est ouvert, ou à la prochaine ouverture pour les commerciaux absents.'),
    h('div', {class:'announcement-recipients'}, h('label', {}, all, ' Tous les commerciaux'),
      choices.map(x => h('label', {}, x.cb, ' ' + x.u.name))), text, send, out);
}

let checkingAnnouncements = false;
async function checkAnnouncements() {
  if (me.role !== 'commercial' || myPresence?.paused || checkingAnnouncements || popup || document.hidden) return;
  checkingAnnouncements = true;
  try {
    const rows = await api('/announcements');
    if (myPresence?.paused || popup || document.hidden || !rows.length) return;
    const message = rows[0], previousFocus = document.activeElement;
    popup = true;
    const out = h('p', {role:'status', class:'err'});
    const close = h('button', {onclick:async () => {
      close.disabled = true; out.textContent = '';
      try {
        await api('/announcements/' + message.id + '/dismiss', {method:'POST'});
        box.remove(); popup = false;
        if (previousFocus && previousFocus.isConnected) previousFocus.focus();
        checkAnnouncements();
      } catch (e) { out.textContent = 'Fermeture non enregistrée : ' + e.message; close.disabled = false; }
    }}, 'Fermer');
    const box = h('div', {class:'ccp-bg'}, h('div', {class:'ccp announcement-popup', role:'dialog', 'aria-modal':'true', 'aria-labelledby':'announcement-title',
      onkeydown:e => { if (e.key === 'Tab') { e.preventDefault(); close.focus(); } }},
      h('h3', {id:'announcement-title'}, 'Message de ' + message.sender_name),
      h('p', {class:'muted'}, fmt(Number(message.created_at))),
      h('p', {class:'announcement-body'}, message.body), close, out));
    document.body.append(box); close.focus();
  } catch (e) { /* Le message reste en attente et sera recherché au prochain passage. */ }
  finally { checkingAnnouncements = false; }
}

function adminPanels() {
  const page = root.querySelector('.crm-page');
  if (!page) return;
  const key = 'callcrm_admin_panels_v1:' + encodeURIComponent(me.uid || '');
  let saved = {};
  try { const value = JSON.parse(localStorage.getItem(key) || '{}'); if (value && typeof value === 'object' && !Array.isArray(value)) saved = value; } catch (e) {}
  const remember = (title, open) => { saved[title] = open; try { localStorage.setItem(key, JSON.stringify(saved)); } catch (e) {} };
  page.querySelectorAll(':scope > .card').forEach(card => {
    if (card.classList.contains('crm-admin-panel')) return;
    const heading = card.querySelector(':scope > h3');
    if (!heading) return;
    const title = heading.textContent;
    const panel = h('details', {class:card.className + ' crm-admin-panel'});
    const action = h('span', {class:'crm-admin-panel-action'});
    const icon = h('span', {class:'crm-admin-panel-icon', 'aria-hidden':'true'});
    const summary = h('summary', {}, heading, h('span', {class:'crm-admin-panel-state'}, action, icon));
    card.classList.remove('card'); card.classList.add('crm-admin-panel-body');
    card.replaceWith(panel); panel.append(summary, card);
    const refresh = () => { action.textContent = panel.open ? 'Réduire' : 'Agrandir'; icon.textContent = panel.open ? '−' : '+'; };
    const setOpen = open => { panel.open = open; refresh(); remember(title, open); };
    panel.open = saved[title] !== false; refresh();
    panel.addEventListener('toggle', () => { refresh(); remember(title, panel.open); });
    panel.crmSetOpen = setOpen;
  });
  if (!page.querySelector(':scope > .crm-admin-panel-tools')) {
    const setAll = open => page.querySelectorAll(':scope > .crm-admin-panel').forEach(panel => panel.crmSetOpen(open));
    const tools = h('div', {class:'bar crm-admin-panel-tools'},
      h('button', {type:'button', onclick:() => setAll(false)}, 'Tout réduire'),
      h('button', {type:'button', onclick:() => setAll(true)}, 'Tout agrandir'));
    page.querySelector('h2')?.after(tools);
  }
}

function admin() {
  const m2 = h('div', {class:'muted'}), m3 = h('span', {class:'muted'});
  const file = h('input', {type:'file', accept:'.xlsx,.xls,.csv,.txt'});
  const asg = h('select', {}, h('option', {value:'auto'}, 'Équitablement entre les commerciaux cochés'), h('option', {value:'categorie'}, 'Par catégorie (groupe de la catégorie, parmi les cochés)'));
  const impPick = commPicker('Affecter les prospects importés à (obligatoire) :'), dstPick = commPicker('Répartir entre (obligatoire) :');
  const dsel = h('select', {}, modeOpts(false));
  const allCb = h('input', {type:'checkbox'});
  const orph = h('div');
  const invSub = h('input', {value:'Accès à l’application CRM CSM'});
  const invBody = h('textarea', {rows:9}, 'Bonjour {nom},\n\nVotre accès à l’application CRM CSM est activé.\n\nAdresse : {url}\nIdentifiant : {identifiant}\nMot de passe : celui de votre compte habituel (session / messagerie CSM TAKSIT)\n\nSur smartphone, utilisez l’application « CRM CSM » fournie par la DSI.\n\nCordialement,\nDSI – CSM TAKSIT');
  const invOut = h('span', {class:'muted'});
  const invChecks = (me.commerciaux || []).map(u => ({u, cb: h('input', {type:'checkbox', checked:true})}));
  const invAll = h('input', {type:'checkbox', checked:true, onchange: () => invChecks.forEach(x => x.cb.checked = invAll.checked)});
  const invList = h('div', {style:'max-height:200px;overflow:auto;border:1px solid var(--color-border);border-radius:6px;padding:6px;margin:6px 0'},
    h('label', {}, invAll, ' Tout cocher / décocher'), ...invChecks.map(x => h('label', {style:'display:block'}, x.cb, ' ' + x.u.name)));
  const invite = async test => {
    const uids = invChecks.filter(x => x.cb.checked).map(x => x.u.uid);
    if (!test && !uids.length) { invOut.textContent = 'Cochez au moins un commercial.'; return; }
    if (!test && !confirm('Envoyer l’invitation à ' + uids.length + ' commercial(aux) ?')) return;
    invOut.textContent = 'Envoi en cours…';
    try {
      const r = await api('/invite', {method:'POST', body:{uids, subject:invSub.value, body:invBody.value, test}});
      invOut.textContent = r.sent + ' envoyé(s)' + (r.no_mail.length ? ' — sans e-mail : ' + r.no_mail.join(', ') : '') + (r.failed.length ? ' — échec : ' + r.failed.join(', ') : '');
    } catch (e) { invOut.textContent = e.message; }
  };
  show(h('h2', {}, 'Administration'),
    h('div', {class:'card'}, h('h3', {}, 'Groupes de commerciaux'),
      h('p', {class:'muted'}, 'Les commerciaux sont les membres du groupe des commerciaux ; chaque commercial doit aussi être dans le groupe de son équipe (groupes de l’AD ou de Nextcloud).'),
      me.groups.map(g => h('div', {style:'margin:6px 0'}, h('strong', {}, g.name), ' — ' + g.id + ' : ' + ((me.teams[g.id] || []).length) + ' commercial(aux)', h('div', {class:'muted'}, 'Catégories : ' + g.cats.join(', '))))),
    me.can.fields ? fieldsEditor() : '',
    me.can.fields ? statusesEditor() : '',
    me.can.fields ? settingsCard() : '',
    me.can.fields ? simCard() : '',
    me.can.assign ? transferCard() : '',
    me.can.assign ? bulkStatusCard() : '',
    me.can.audit ? auditCard() : '',
    !me.can.invite ? '' : h('div', {class:'card'}, h('h3', {}, 'Inviter les commerciaux par e-mail'),
      h('p', {class:'muted'}, 'Envoie à chaque commercial coché son adresse d’accès et son identifiant de connexion. Variables : {nom}, {identifiant}, {url}. Prérequis : serveur e-mail configuré dans Nextcloud (Paramètres d’administration → Paramètres de base).'),
      invSub, invBody, invList,
      h('div', {class:'bar'}, h('button', {onclick: () => invite(true)}, 'M’envoyer un test'), h('button', {class:'primary', onclick: () => invite(false)}, 'Envoyer aux commerciaux cochés'), invOut)),
    h('div', {class:'card'}, h('h3', {}, 'Importer des prospects (Excel ou CSV)'),
      h('p', {class:'muted'}, 'Colonnes, dans cet ordre : Nom Prénom ; Téléphone ; Wilaya ; Catégorie produit ; Type emploi ; Salaire ; Commentaire. La 1re ligne (en-tête) est ignorée. Fichiers Excel (.xlsx, .xls) acceptés directement : 1re feuille, colonnes reconnues par leur titre, doublons de numéros supprimés. Les numéros, wilayas (français ou arabe), catégories et types d’emploi sont corrigés automatiquement ; ce qui n’est pas reconnu est recopié dans le commentaire.'),
      file, impPick.el, asg,
      h('button', {class:'primary', onclick: async () => {
        const f0 = file.files[0];
        if (!f0) { m2.textContent = 'Choisissez un fichier Excel ou CSV.'; return; }
        const who = impPick.values();
        if (!who.length) { m2.textContent = 'Sélectionnez au moins un commercial à qui affecter les prospects.'; return; }
        const fd = new FormData(); fd.append('assign', asg.value); fd.append('uids', JSON.stringify(who));
        m2.textContent = 'Import en cours…';
        try {
          let pre = '';
          if (/\.xlsx?$/i.test(f0.name)) {
            const c = await excelToCsv(f0);
            fd.append('file', new Blob([c.csv], {type:'text/csv'}), 'import.csv'); fd.append('extras', JSON.stringify(c.extras));
            pre = `${c.total} ligne(s) lue(s), ${c.dupInFile} doublon(s) dans le fichier supprimé(s)` + (c.empty ? `, ${c.empty} ligne(s) vide(s) ignorée(s)` : '') + ' — ';
          } else { fd.append('file', f0); }
          const r = await api('/import', {method:'POST', body:fd});
          m2.replaceChildren(pre + `${r.imported} importés, ${r.duplicates} déjà dans la base (doublons), ${r.skipped} sans nom, ${r.invalid} numéros invalides`
            + (r.unrecognized ? `, ${r.unrecognized} avec valeur non reconnue (voir commentaire)` : '') + (r.unassigned ? `, ${r.unassigned} sans commercial (groupe vide ou catégorie vide).` : '.'),
            ...r.samples.map(x => h('div', {class:'err'}, x)));
        } catch (e) { m2.textContent = e.message; }
      }}, 'Importer'), m2),
    h('div', {class:'card'}, h('h3', {}, 'Répartir les prospects non assignés'), dstPick.el, dsel,
      h('button', {onclick: () => { const w = dstPick.values(); if (!w.length) { m3.textContent = 'Sélectionnez au moins un commercial.'; return; } distribute(dsel.value, m3, w); }}, 'Répartir'), m3,
      h('hr'),
      h('p', {class:'muted'}, 'Prospects encore assignés à des personnes qui ne sont plus dans le groupe des commerciaux (superviseurs, comptes supprimés…) : les réattribuer aux commerciaux actuels, avec le mode de répartition choisi ci-dessus.'),
      h('label', {style:'display:block;margin:6px 0'}, allCb, ' Y compris les prospects déjà appelés (sinon seulement « À traiter »)'),
      h('button', {onclick: async () => {
        if (!confirm('Réattribuer ces prospects aux commerciaux actuels ?')) return;
        m3.textContent = '…';
        try { const r = await api('/release', {method:'POST', body:{mode:dsel.value, all:allCb.checked}}); m3.textContent = r.reassigned + ' prospect(s) réattribué(s)' + (r.not_assigned ? ' — ' + r.not_assigned + ' sans commercial disponible' : '') + '.'; }
        catch (e) { m3.textContent = e.message; }
      }}, 'Réattribuer aux commerciaux actuels')),
    orph,
    h('p', {class:'muted'}, 'Profils : définis par les groupes (AD ou Nextcloud) associés aux rôles administrateur, superviseur, commercial et lecture seule.'));
  if(me.can.fields)root.querySelector('.crm-page')?.append(documentSettingsCard(),collectSettingsCard());
  if(me.can.assign)root.querySelector('.crm-page')?.append(documentsCard());
  adminPanels();
  api('/recordings').then(rows => {
    orph.replaceChildren(...(rows.length ? [h('h3', {}, 'Enregistrements non rattachés à une fiche'),
      h('p', {class:'muted'}, 'Numéro introuvable dans la base, ou appelé depuis un contact enregistré.'), ...rows.map(recRow)] : []));
  }).catch(() => {});
}

// ---- WhatsApp (boîte partagée) ----
let waBoxSel = null, waView = 0, waLast = -1;
const waTicks = m => m.dir !== 'out' ? '' : ({sent:' ✓', delivered:' ✓✓', read:' ✓✓', failed:' ⚠'}[m.status] || '');
const waUrl = id => API + '/wa/file/' + id;
/** Numéro, ou mention « nom d'utilisateur WhatsApp » quand Meta ne transmet pas le numéro */
const waWho = c => c.phone || (c.username ? '👤 nom d’utilisateur WhatsApp (numéro masqué)' : '+' + c.wa_id);
const hhmm = t => t ? new Date(t * 1000).toLocaleTimeString('fr-FR', {hour:'2-digit', minute:'2-digit'}) : '';
function waWin(c) {
  if (!c.last_in_at) return h('span', {class:'muted'}, 'Le client n’a pas encore écrit');
  return c.window_open ? h('span', {class:'wa-ok'}, '🟢 Réponse possible jusqu’à ' + fmt(c.window_ends))
    : h('span', {class:'wa-ko'}, '⏳ Fenêtre de 24 h expirée');
}
async function paintWa() {
  try {
    const c = await api('/wa/counters');
    const btn = root.querySelector('.nav button[data-k="wa"]');
    if (btn) { btn.querySelector('.ovd')?.remove(); const n = Number(c.unread || 0) || Number(c.queue || 0); if (n) btn.append(h('span', {class:'ovd', title: me.can.all ? 'Messages en file d’attente' : 'Messages non lus'}, String(n))); }
    const n = Number(c.unread || 0);
    if (waLast >= 0 && n > waLast && window.Notification && Notification.permission === 'granted') new Notification('WhatsApp', {body: (n - waLast) + ' nouveau(x) message(s) client'});
    waLast = n;
  } catch (e) {}
}
async function waList(off = 0, keep) {
  const boxes = me.can.all ? [['queue', 'File d’attente'], ['open', 'Ouvertes'], ['all', 'Toutes'], ['resolved', 'Lues']] : [['mine', 'Mes conversations'], ['resolved', 'Lues']];
  if (!waBoxSel || !boxes.some(b => b[0] === waBoxSel)) waBoxSel = boxes[0][0];
  const st = keep || {q:'', user:''};
  const q = h('input', {type:'search', placeholder:'Rechercher (nom, numéro, message)…', value: st.q});
  const u = me.can.all ? h('select', {}, h('option', {value:''}, 'Tous commerciaux'), me.commerciaux.map(x => h('option', {value:x.uid, selected: st.user === x.uid}, x.name))) : null;
  const box = h('div', {}, skel());
  const tabs = h('div', {class:'bar'}, boxes.map(([k, l]) => h('button', {class: waBoxSel === k ? 'primary' : '', onclick: () => { waBoxSel = k; waList(0, {q:q.value, user: u ? u.value : ''}); }}, l)));
  const load = async () => {
    try {
      const p = new URLSearchParams({box: waBoxSel, q: q.value.trim(), user: u ? u.value : '', offset: off});
      const r = await api('/wa?' + p);
      if (!r.rows.length) { box.replaceChildren(h('p', {class:'muted'}, waBoxSel === 'queue' ? 'Aucune conversation en attente d’attribution.' : 'Aucune conversation.')); return; }
      box.replaceChildren(...r.rows.map(c => h('div', {class:'row' + (c.unread ? ' wa-unread' : ''), onclick: () => waConv(c.id, () => waList(off, {q:q.value, user: u ? u.value : ''}))},
        h('div', {style:'min-width:0;flex:1'},
          h('strong', {}, c.prospect_name || c.name || c.phone || c.wa_id), ' ', h('span', {class:'muted'}, waWho(c)),
          c.prospect_id ? '' : h('span', {class:'badge', style:'margin-left:6px;background:#e9a100;color:#000'}, 'Inconnu'),
          h('div', {class:'muted wa-prev'}, c.preview || ''),
          h('div', {class:'muted'}, c.owner_name ? '👤 ' + c.owner_name : '👥 File d’attente', ' · ', waWin(c))),
        h('div', {style:'text-align:right;flex:none'}, h('div', {class:'muted'}, fmt(c.last_msg_at)), c.unread ? h('span', {class:'ovd'}, String(c.unread)) : '',
          c.status === 'resolu' ? h('div', {class:'muted'}, '✔ Lue') : ''))),
        r.total > off + r.limit ? h('button', {onclick: () => waList(off + r.limit, {q:q.value, user: u ? u.value : ''})}, 'Suivantes →') : '',
        off > 0 ? h('button', {onclick: () => waList(Math.max(0, off - r.limit), {q:q.value, user: u ? u.value : ''})}, '← Précédentes') : '',
        h('div', {class:'muted'}, r.total + ' conversation(s)'));
    } catch (e) { box.replaceChildren(h('p', {class:'err'}, e.message)); }
  };
  let t; q.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => { off = 0; load(); }, 350); });
  if (u) u.addEventListener('change', () => { off = 0; load(); });
  show(h('h2', {}, '💬 WhatsApp'), tabs, h('div', {class:'bar'}, q, u || ''), box);
  load(); paintWa();
  const myView = ++waView;
  const iv = setInterval(() => { if (waView !== myView || !document.body.contains(box)) return clearInterval(iv); load(); }, 20000);
}
function waMedia(m) {
  if (!m.file) return '';
  const url = waUrl(m.id), mime = m.mime || '';
  if (m.type === 'image' || m.type === 'sticker' || mime.startsWith('image/')) return h('a', {href:url, target:'_blank', rel:'noopener'}, h('img', {src:url, class:'wa-img', loading:'lazy', alt: m.file_name || 'photo'}));
  if (m.type === 'audio' || mime.startsWith('audio/')) return h('audio', {controls:true, preload:'none', src:url, style:'width:100%'});
  if (m.type === 'video' || mime.startsWith('video/')) return h('video', {controls:true, preload:'none', src:url, style:'max-width:100%'});
  return h('a', {href:url, target:'_blank', rel:'noopener', class:'wa-doc'}, '📄 ' + (m.file_name || 'Document'));
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
      c.name && c.prospect_name && c.name !== c.prospect_name ? h('div', {class:'muted'}, 'Nom WhatsApp : ' + c.name) : '',
      h('div', {class:'muted'}, c.owner_name ? '👤 ' + c.owner_name : '👥 File d’attente', ' · ', waWin(c), c.status === 'resolu' ? ' · ✔ Lue' : ''),
      c.prospect_id ? h('button', {onclick: () => detail(c.prospect_id)}, '📇 Ouvrir la fiche' + (c.prospect_status ? ' (' + (ST[c.prospect_status] || c.prospect_status) + ')' : '')) : '');
    const atBottom = thread.scrollHeight - thread.scrollTop - thread.clientHeight < 60;
    thread.replaceChildren(...dd.messages.map(m => h('div', {class:'wa-b ' + (m.dir === 'out' ? 'out' : 'in')},
      waMedia(m), m.body ? h('div', {class:'wa-t'}, m.body) : '',
      h('div', {class:'wa-meta'}, (m.dir === 'out' && m.user_name ? m.user_name + ' · ' : '') + fmt(m.at), h('span', {class: m.status === 'read' ? 'wa-read' : m.status === 'failed' ? 'wa-ko' : ''}, waTicks(m))))));
    if (atBottom || !thread.dataset.done) { thread.scrollTop = thread.scrollHeight; thread.dataset.done = 1; }
  };
  paint(d);
  const c0 = d.conv;
  // ---- réponse ----
  const txt = h('textarea', {rows:3, placeholder:'Votre réponse…'});
  const file = h('input', {type:'file', accept:'image/jpeg,image/png,application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt'});
  const msg = h('div', {class:'err'});
  const sendBtn = h('button', {class:'primary', onclick: async () => {
    msg.className = 'err'; msg.textContent = '';
    if (!txt.value.trim() && !file.files.length) { msg.textContent = 'Écrivez un message ou joignez un fichier.'; return; }
    const fd = new FormData(); fd.append('text', txt.value.trim()); if (file.files.length) fd.append('file', file.files[0]);
    sendBtn.disabled = true; sendBtn.textContent = 'Envoi…';
    try { await api('/wa/' + id + '/send', {method:'POST', body: fd}); txt.value = ''; file.value = ''; paint(await api('/wa/' + id)); }
    catch (e) { msg.textContent = e.message; }
    sendBtn.disabled = false; sendBtn.textContent = 'Envoyer';
  }}, 'Envoyer');
  txt.addEventListener('keydown', e => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) sendBtn.click(); });
  const reply = !d.can.reply ? h('p', {class:'muted'}, 'Consultation seule.')
    : (me.role === 'commercial' && c0.owner !== me.uid) ? h('p', {class:'muted'}, 'Conversation d’un autre commercial.')
    : c0.window_open ? h('div', {class:'card'}, txt, h('div', {class:'bar', style:'margin-top:6px;align-items:center'}, file, sendBtn), h('div', {class:'muted'}, 'Ctrl+Entrée pour envoyer · photos JPG/PNG et documents (PDF, Word, Excel) · 50 Mo max.'), msg)
    : h('div', {class:'card'}, h('p', {}, 'Le client n’a pas écrit depuis plus de 24 h : WhatsApp n’autorise plus de réponse libre depuis le CRM.'),
        c0.phone ? h('a', {class:'big', href:'tel:' + c0.phone}, '📞 Appeler ' + c0.phone) : '',
        c0.username ? '' : h('a', {class:'big', style:'background:#25D366;color:#fff', target:'_blank', rel:'noopener noreferrer', href:'https://wa.me/' + c0.wa_id}, '💬 Écrire depuis mon WhatsApp'));
  // ---- actions superviseur ----
  const amsg = h('span', {class:'muted'});
  const actions = h('div', {class:'bar', style:'align-items:center'});
  if (d.can.assign) {
    const asg = h('select', {}, h('option', {value:''}, '— File d’attente —'), me.commerciaux.map(u => h('option', {value:u.uid, selected: c0.owner === u.uid}, u.name)));
    asg.addEventListener('change', async () => { amsg.textContent = '…'; try { await api('/wa/' + id + '/assign', {method:'POST', body:{uid: asg.value}}); amsg.textContent = 'Attribuée.'; paint(await api('/wa/' + id)); paintWa(); } catch (e) { amsg.textContent = e.message; } });
    actions.append(h('label', {}, 'Attribuer à ', asg));
    if (!c0.prospect_id) {
      const nmIn = h('input', {type:'text', value: c0.name || '', placeholder:'Nom du client'});
      const cat = h('select', {}, h('option', {value:''}, 'Catégorie —'), (me.options.c2 || []).map(x => h('option', {value:x}, x)));
      actions.append(h('details', {class:'card', style:'width:100%'}, h('summary', {style:'cursor:pointer'}, '➕ Créer la fiche prospect de ce numéro'),
        h('div', {class:'bar', style:'margin-top:6px'}, nmIn, cat,
          h('button', {class:'primary', onclick: async () => {
            amsg.textContent = '…';
            try { const r = await api('/wa/' + id + '/prospect', {method:'POST', body:{name: nmIn.value.trim(), c2: cat.value, uid: asg.value}}); amsg.textContent = r.existing ? 'Fiche existante rattachée.' : 'Fiche créée.'; waConv(id, back); }
            catch (e) { amsg.textContent = e.message; }
          }}, 'Créer la fiche')), h('div', {class:'muted'}, 'La fiche est attribuée au commercial choisi ci-dessus (ou reste non assignée).')));
    }
  }
  if (d.can.reply) actions.append(h('button', {onclick: async () => { try { await api('/wa/' + id + '/resolve', {method:'POST'}); waConv(id, back); } catch (e) { amsg.textContent = e.message; } }}, c0.status === 'resolu' ? '↺ Marquer non lu' : '✔ Marquer lu'));
  actions.append(amsg);
  show(h('button', {onclick: () => (back || (() => { tab = 'wa'; waList(); }))()}, '← Retour'), head, actions, thread, reply);
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
    el.replaceChildren(h('div', {class:'card'}, h('strong', {}, '💬 Conversation WhatsApp'), c.unread ? h('span', {class:'ovd'}, String(c.unread)) : '',
      h('div', {class:'muted wa-prev'}, fmt(c.last_msg_at) + ' — ' + (c.preview || '')), h('div', {class:'muted'}, waWin(c)),
      h('button', {style:'margin-top:6px', onclick: () => waConv(c.id, () => detail(pid))}, 'Ouvrir la conversation')));
  }).catch(() => {});
  return el;
}

async function checkReminders() {
  if (!me.can.call || popup || myPresence?.paused) return;
  try {
    const rows = await api('/prospects?rappels=1');
    if (popup || myPresence?.paused) return;
    const now = Date.now() / 1000;
    const due = rows.find(r => r.assigned_to === me.uid && Number(r.callback_at) <= now && (snooze[r.id] || 0) < now);
    if (!due) return;
    popup = true;
    const close = mins => { snooze[due.id] = Date.now() / 1000 + mins * 60; saveSnooze(); box.remove(); popup = false; };
    const box = h('div', {class:'ccp-bg'}, h('div', {class:'ccp'},
      h('h3', {}, '⏰ Rappel client'),
      h('p', {}, h('strong', {}, nm(due)), ' — ' + due.phone),
      h('p', {class:'muted'}, 'Prévu : ' + fmt(Number(due.callback_at)) + (due.last_note ? ' — ' + due.last_note : '')),
      h('a', {href:'https://wa.me/' + waNum(due.phone), target:'_blank', rel:'noopener noreferrer', onclick: () => close(30), style:'display:block;text-align:center;padding:14px;font-size:18px;border-radius:10px;margin:10px 0;background:#25D366;color:#fff;text-decoration:none'}, '💬 WhatsApp'),
      h('a', {href:'tel:' + due.phone, onclick: () => close(30), style:'display:block;text-align:center;padding:14px;font-size:18px;border-radius:10px;margin:10px 0;background:var(--color-primary-element);color:var(--color-primary-element-text);text-decoration:none'}, '📞 Appeler'),
      h('button', {onclick: () => { close(30); detail(due.id); }}, 'Ouvrir la fiche'),
      h('button', {onclick: () => close(10)}, 'Plus tard (10 min)')));
    document.body.append(box);
    if (window.Notification && Notification.permission === 'granted') new Notification('Rappel client', {body: nm(due) + ' — ' + due.phone});
  } catch (e) {}
}

(async () => {
  try { me = await api('/me'); }
  catch (e) { root.textContent = e.offline ? 'Connexion indisponible : rechargez la page quand le réseau revient.' : 'Accès refusé : demandez à l’administrateur de vous ajouter à un groupe callcrm_*.'; return; }
  applyStatuses();
  document.body.append(banner); paintBanner(); flushQueue();
  if (me.role === 'commercial') {
    await updateMyPresence();
    if (!myPresence) {
      root.replaceChildren(h('p', {class:'err'}, 'Impossible de vérifier votre état de présence. Réessayez avant de reprendre Call CRM.'),
        h('button', {onclick:() => window.location.reload()}, 'Réessayer'));
      return;
    }
  }
  go(me.role === 'commercial' ? 'journee' : 'dossiers');
  setInterval(() => updateMyPresence(), 30000);
  setInterval(refreshPresenceBoard, 15000);
  setInterval(refreshPersonalTimes, 15000);
  setInterval(refreshMyDay, 15000);
  window.addEventListener('online', () => { updateMyPresence(); refreshPresenceBoard(); refreshPersonalTimes(); refreshMyDay(); });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) { updateMyPresence(); refreshPresenceBoard(); refreshPersonalTimes(); refreshMyDay(); } });
  if (window.Notification && Notification.permission === 'default') Notification.requestPermission();
  checkAnnouncements(); setInterval(checkAnnouncements, 15000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) checkAnnouncements(); });
  checkReminders(); setInterval(checkReminders, 30000);
  setInterval(paintWa, 20000);
})();
})();
