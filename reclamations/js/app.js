(() => {
const root = document.getElementById('reclam');
const API = root.dataset.api;
const tok = () => document.head.dataset.requesttoken || (window.OC && OC.requestToken) || '';
const fmt = t => t ? new Date(t * 1000).toLocaleString('fr-FR', {day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit'}) : '';
const fmtd = t => t ? new Date(t * 1000).toLocaleDateString('fr-FR') : '';
const h = (t, a = {}, ...c) => {
  const e = document.createElement(t);
  for (const [k, v] of Object.entries(a)) {
    if (k === 'class') e.className = v;
    else if (k === 'value') e.value = v;
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
  const r = await fetch(API + path, o);
  const j = await r.json().catch(() => ({}));
  if (!r.ok) throw new Error(j.error || ('Erreur ' + r.status));
  return j;
}
let me, tab = 'nouvelles', filt = {q:'', type:'', assigned:'', status:'', service:''};
const KINDS = {
  aprendre: {t:'À prendre (mes groupes)', p:{togrp:1}},
  miennes: {t:'Mes réclamations', p:{mine:1}},
  nouvelles: {t:'Nouvelles', p:{status:'nouvelle'}},
  encours: {t:'En cours', p:{statuses:'en_cours,attente_client,attente_fournisseur'}},
  retard: {t:'En retard', p:{late:1}},
  resolues: {t:'Résolues', p:{status:'resolue'}},
  toutes: {t:'Toutes les réclamations', all:1, p:{}},
};
const ST = () => me.status;
const show = (...n) => { root.replaceChildren(nav(), ...n.flat()); };
function nav() {
  const c = me.counts || {}, n = k => ({nouvelles:c.nouvelle, encours:(c.en_cours||0)+(c.attente_client||0)+(c.attente_fournisseur||0), retard:me.late, resolues:c.resolue})[k];
  const t = [...((me.my_services || []).length ? [['aprendre', 'À prendre']] : []), ['miennes', 'Mes réclamations'], ['nouvelles', 'Nouvelles'], ['encours', 'En cours'], ['retard', 'En retard'], ['resolues', 'Résolues'], ['toutes', 'Toutes'], ['stats', 'Statistiques']];
  if (me.can.import) t.push(['admin', 'Administration']);
  const b = t.map(([k, l]) => h('button', {class: tab === k ? 'on' : '', onclick: () => go(k)}, l + (n(k) ? ' (' + n(k) + ')' : '')));
  if (me.can.create) b.push(h('button', {class:'primary', onclick: () => form()}, '＋ Nouvelle réclamation'));
  return h('div', {class:'nav'}, b);
}
async function refresh() { try { const m = await api('/me'); me = m; } catch (e) {} }
function go(k) { tab = k; if (k === 'stats') stats(); else if (k === 'admin') admin(); else list(k); }

/** Options « Affectée à » : tous les utilisateurs, ou seulement les membres du service choisi. */
function agentOpts(service, cur) {
  const sv = (me.services || []).find(x => x.name === service);
  const list = sv ? me.agents.filter(u => sv.members.includes(u.uid) || u.uid === cur) : me.agents;
  return [h('option', {value:''}, 'Non affectée'), ...list.map(u => h('option', {value:u.uid, selected: u.uid === cur}, u.name))];
}
/** Liste des groupes seule : pour qui saisit ou traite sans pouvoir affecter une personne. */
function groupOnly(cur) {
  return h('select', {}, h('option', {value:''}, 'Aucun groupe'), (me.services || []).map(x => h('option', {value:x.name, selected: x.name === cur}, x.label || x.name)));
}
/** Couple de listes liées : groupe puis utilisateur. */
function assignPair(cur, curSvc) {
  const sv = groupOnly(curSvc);
  const ag = h('select', {}, agentOpts(curSvc, cur));
  sv.addEventListener('change', () => { const keep = ag.value; ag.replaceChildren(...agentOpts(sv.value, keep)); });
  return {sv, ag};
}
async function list(k) {
  const K = KINDS[k];
  const box = h('div', {}, [1, 2, 3, 4].map(() => h('div', {class:'skel'})));
  const q = h('input', {type:'search', placeholder:'Rechercher (réf., client, téléphone, facture, produit)…', value:filt.q});
  const bar = h('div', {class:'bar'}, q);
  const ty = h('select', {}, h('option', {value:''}, 'Tous types'), me.types.map(x => h('option', {value:x, selected: filt.type === x}, x)));
  bar.append(ty);
  let s, a;
  if (K.all) { s = h('select', {}, h('option', {value:''}, 'Tous statuts'), Object.entries(ST()).map(([v, l]) => h('option', {value:v, selected: filt.status === v}, l))); bar.append(s); }
  if (me.can.all) { a = h('select', {}, h('option', {value:''}, 'Tous les agents'), h('option', {value:'-'}, 'Non affectées'), me.agents.map(u => h('option', {value:u.uid, selected: filt.assigned === u.uid}, u.name))); bar.append(a); }
  let sv;
  if (me.can.all && (me.services || []).length) { sv = h('select', {}, h('option', {value:''}, 'Tous les groupes'), me.services.map(x => h('option', {value:x.name, selected: filt.service === x.name}, x.name))); bar.append(sv); }
  const load = async () => {
    filt = {q:q.value, type:ty.value, status: s ? s.value : '', assigned: a ? a.value : '', service: sv ? sv.value : ''};
    const p = new URLSearchParams({q:filt.q, type:filt.type, ...K.p});
    if (filt.status) p.set('status', filt.status);
    if (filt.service) p.set('service', filt.service);
    if (filt.assigned === '-') p.set('unassigned', 1); else if (filt.assigned) p.set('assigned', filt.assigned);
    try {
      const rows = await api('/claims?' + p);
      box.replaceChildren(...(rows.length ? rows.map(row) : [h('div', {class:'empty'}, h('strong', {}, 'Aucune réclamation ici'), h('div', {class:'muted'}, 'Changez de filtre ou créez une nouvelle réclamation.'))]));
    } catch (e) { box.replaceChildren(h('p', {class:'err'}, e.message)); }
  };
  [q, ty, s, a, sv].forEach(e => e && e.addEventListener('change', load));
  show(h('h2', {}, K.t), bar, box); load();
}
const isOpen = s => ['nouvelle', 'en_cours', 'attente_client', 'attente_fournisseur'].includes(s);
const isLate = r => isOpen(r.status) && r.due_at && Number(r.due_at) < Date.now() / 1000;
function row(r) {
  return h('div', {class:'row', onclick: () => detail(r.id)},
    h('div', {class:'main', style:'white-space:normal'}, h('strong', {}, r.ref + ' — ' + r.client_name),
      h('div', {class:'muted'}, [r.type, r.product, r.invoice_no && ('Facture ' + r.invoice_no), r.phone].filter(Boolean).join(' · ')),
      r.assigned_name || r.service ? h('div', {class:'muted'}, [r.service && '🏢 ' + r.service, r.assigned_name && '👤 ' + r.assigned_name].filter(Boolean).join(' · ')) : '',
      r.created_name ? h('div', {class:'muted'}, 'Saisie par' + ' : ' + r.created_name) : ''),
    h('div', {class:'side'}, h('span', {class:'badge ' + r.status}, ST()[r.status]),
      h('div', {class:'prio-' + r.priority}, r.priority),
      r.due_at && isOpen(r.status) ? h('div', {class: isLate(r) ? 'late' : 'muted'}, '⏰ ' + fmtd(Number(r.due_at))) : ''));
}

const toLocal = t => { if (!t) return ''; const d = new Date(t * 1000); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0, 16); };
const sel = (opts, val, blank) => h('select', {}, blank !== undefined ? h('option', {value:''}, blank) : '', opts.map(o => h('option', {value:o, selected: o === val}, o)));

function form(c) {
  const v = c || {priority:'Normale', type:'Panne / SAV'};
  const f = {
    client_name: h('input', {value:v.client_name || ''}), phone: h('input', {type:'tel', value:v.phone || ''}),
    wilaya: sel(me.wilayas, v.wilaya, '—'), invoice_no: h('input', {value:v.invoice_no || ''}), product: h('input', {value:v.product || ''}),
    category: sel(me.categories, v.category, '—'), type: sel(me.types, v.type), priority: sel(me.priorities, v.priority),
    description: h('textarea', {rows:5}, v.description || ''),
    due: h('input', {type:'datetime-local', value: toLocal(Number(v.due_at))}),
  };
  const pr = me.can.assign ? assignPair(v.assigned_to, v.service) : null, ag = pr && pr.ag, svs = pr ? pr.sv : groupOnly(v.service);
  const msg = h('div', {class:'err'});
  const L = (l, e, cls) => h('label', {class:cls || ''}, l, e);
  const save = async () => {
    const body = {};
    for (const [k, e] of Object.entries(f)) body[k] = e.value;
    body.due_at = f.due.value ? Math.floor(new Date(f.due.value).getTime() / 1000) : 0;
    delete body.due;
    body.service = svs.value;
    if (ag) body.assigned_to = ag.value;
    try {
      if (c) { await api('/claims/' + c.id + '/update', {method:'POST', body}); await refresh(); detail(c.id); }
      else { const r = await api('/claims', {method:'POST', body}); await refresh(); detail(r.id); }
    } catch (e) { msg.textContent = e.message; }
  };
  show(h('h2', {}, c ? 'Modifier ' + c.ref : 'Nouvelle réclamation'),
    h('div', {class:'card'}, h('div', {class:'g2'},
      L('Client *', f.client_name), L('Téléphone', f.phone), L('Wilaya', f.wilaya), L('N° de facture', f.invoice_no),
      L('Produit', f.product), L('Catégorie produit', f.category), L('Type de réclamation', f.type), L('Priorité (délai : Urgente 2 j, Haute 4 j, Normale 7 j)', f.priority),
      L('Échéance (vide = calculée selon la priorité)', f.due), me.services.length ? L('Groupe (prévenu par mail)', svs) : '', ag ? L('Affectée à', ag) : '',
      L('Description *', f.description, 'full')),
      msg, h('button', {class:'primary', onclick: save}, 'Enregistrer'), ' ', h('button', {onclick: () => c ? detail(c.id) : go(tab)}, 'Annuler')));
}

const FLOW = [['nouvelle', 'Nouvelle'], ['en_cours', 'En cours'], ['resolue', 'Résolue'], ['cloturee', 'Clôturée']];
function steps(st) {
  const i = st === 'attente_client' || st === 'attente_fournisseur' ? 1 : FLOW.findIndex(x => x[0] === st);
  if (i < 0) return '';
  return h('div', {class:'steps'}, FLOW.map((x, n) => h('span', {class: n < i ? 'done' : n === i ? 'cur' : ''}, x[1])));
}
async function detail(id) {
  let d;
  try { d = await api('/claims/' + id); } catch (e) { return show(h('p', {class:'err'}, e.message)); }
  const c = d.claim;
  const row2 = (l, v) => v ? h('div', {}, h('span', {class:'muted'}, l + ' : '), v) : '';
  const left = h('div', {},
    h('h2', {}, c.ref, ' ', h('span', {class:'badge ' + c.status}, ST()[c.status])),
    steps(c.status),
    h('div', {class:'card'}, h('strong', {}, c.client_name),
      row2('Téléphone', c.phone ? h('a', {href:'tel:' + c.phone}, c.phone) : ''), row2('Wilaya', c.wilaya),
      row2('Facture', c.invoice_no), row2('Produit', c.product), row2('Catégorie', c.category), row2('Type', c.type),
      row2('Priorité', h('span', {class:'prio-' + c.priority}, c.priority)),
      row2('Échéance', c.due_at ? h('span', {class: isLate(c) ? 'late' : ''}, fmt(Number(c.due_at)) + (isLate(c) ? ' (en retard)' : '')) : ''),
      row2('Groupe', c.service), row2('Prise par', c.assigned_name || (c.service ? 'personne pour l’instant' : '')), row2('Saisie par', c.created_name), row2('Enregistrée le', fmt(Number(c.created_at))),
      row2('Clôture', fmt(Number(c.closed_at)))),
    h('div', {class:'card'}, h('strong', {}, 'Description'), h('div', {style:'white-space:pre-wrap'}, c.description || '')),
    c.resolution ? h('div', {class:'card'}, h('strong', {}, 'Solution / motif'), h('div', {style:'white-space:pre-wrap'}, c.resolution)) : '',
    h('button', {onclick: () => go(tab)}, '← Retour'), ' ',
    me.can.handle ? h('button', {onclick: () => form(c)}, 'Modifier') : '', ' ',
    me.can.delete ? h('button', {onclick: async () => { if (confirm('Supprimer définitivement ?')) { await api('/claims/' + id + '/delete', {method:'POST'}); await refresh(); go(tab); } }}, 'Supprimer') : '');
  const right = h('div', {});
  const canTake = c.service && !c.assigned_to && isOpen(c.status) && ((me.my_services || []).includes(c.service) || me.can.assign);
  if (canTake) {
    const tmsg = h('div', {class:'err'});
    right.append(h('div', {class:'card take'}, h('strong', {}, 'Réclamation du groupe ' + c.service),
      h('p', {class:'muted'}, 'Personne ne l’a encore prise. En cliquant, vous en devenez responsable jusqu’à la fin du traitement.'), tmsg,
      h('button', {class:'primary', onclick: async () => {
        try { await api('/claims/' + id + '/action', {method:'POST', body:{take:1}}); await refresh(); detail(id); } catch (e) { tmsg.textContent = e.message; }
      }}, 'Prendre la réclamation')));
  }
  if (me.can.handle) {
    const st = h('select', {}, Object.entries(ST()).filter(([k]) => k !== 'nouvelle' || c.status === 'nouvelle').map(([k, l]) => h('option', {value:k, selected: k === c.status}, l)));
    const note = h('textarea', {rows:3, placeholder:'Note, action menée, solution… (obligatoire pour Résolue / Rejetée)'});
    const pr = me.can.assign ? assignPair(c.assigned_to, c.service) : null, ag = pr && pr.ag, svs = pr ? pr.sv : groupOnly(c.service);
    const msg = h('div', {class:'err'});
    right.append(h('div', {class:'card'}, h('strong', {}, 'Traitement'),
      h('label', {}, 'Statut', st), me.services.length ? h('label', {}, 'Groupe', svs) : '', ag ? h('label', {}, 'Affectation', ag) : '', h('label', {}, 'Note', note), msg,
      h('button', {class:'primary', style:'margin-top:8px', onclick: async () => {
        const body = {note:note.value, status: st.value === c.status ? '' : st.value};
        if (svs.value !== (c.service || '')) body.service = svs.value;
        if (ag) body.assigned_to = ag.value;
        try { await api('/claims/' + id + '/action', {method:'POST', body}); await refresh(); detail(id); } catch (e) { msg.textContent = e.message; }
      }}, 'Enregistrer')));
  }
  right.append(h('h3', {}, 'Historique'), ...d.events.map(e => h('div', {class:'ev'},
    h('div', {class:'muted'}, fmt(Number(e.created_at)) + ' — ' + e.user_name + (e.status ? ' — ' + ST()[e.status] : '')), e.note ? h('div', {style:'white-space:pre-wrap'}, e.note) : '')));
  show(h('div', {class:'cols'}, left, right));
}

async function stats() {
  const from = h('input', {type:'date'}), to = h('input', {type:'date'}), box = h('div', {}, 'Chargement…');
  const params = () => new URLSearchParams({from:from.value, to:to.value, tz: -new Date().getTimezoneOffset()});
  const load = async () => {
    try {
      const s = await api('/stats?' + params());
      const tbl = (title, obj, lab) => h('div', {class:'card'}, h('strong', {}, title), ...Object.entries(obj).sort((a, b) => b[1] - a[1]).map(([k, n]) => h('div', {}, (lab ? lab[k] || k : k) + ' : ', h('strong', {}, n))));
      box.replaceChildren(h('div', {class:'kpi'},
        h('div', {class:'card'}, h('strong', {}, s.total), 'Réclamations'), h('div', {class:'card'}, h('strong', {class: s.late ? 'late' : ''}, s.late), 'En retard'),
        h('div', {class:'card'}, h('strong', {}, s.avg_days === null ? '—' : s.avg_days + ' j'), 'Délai moyen de traitement')),
        h('div', {class:'cols'}, h('div', {}, tbl('Par statut', s.by.status, ST()), tbl('Par type', s.by.type)), h('div', {}, tbl('Par catégorie produit', s.by.category), tbl('Par agent', s.by.agent))));
    } catch (e) { box.replaceChildren(h('p', {class:'err'}, e.message)); }
  };
  const xl = async () => {
    try {
      const r = await fetch(API + '/export?' + params(), {headers:{requesttoken: tok()}});
      if (!r.ok) throw new Error('Erreur ' + r.status);
      const blob = await r.blob();
      if (window.CRMApp && window.CRMApp.saveFile) {
        const rd = new FileReader();
        rd.onload = () => window.CRMApp.saveFile('reclamations.xlsx', String(rd.result).split(',')[1]);
        rd.readAsDataURL(blob); return;
      }
      const u = URL.createObjectURL(blob), a = h('a', {href:u, download:'reclamations.xlsx'});
      document.body.append(a); a.click(); a.remove(); URL.revokeObjectURL(u);
    } catch (e) { alert(e.message); }
  };
  [from, to].forEach(e => e.addEventListener('change', load));
  show(h('h2', {}, 'Statistiques'), h('div', {class:'bar'}, h('label', {}, 'Du ', from), h('label', {}, 'Au ', to), h('button', {class:'primary', onclick: xl}, '⬇ Télécharger Excel')), box); load();
}

function servicesCard() {
  let list = (me.services || []).map(x => x.name);
  const out = h('span', {class:'muted'}), box = h('div'), add = h('input', {placeholder:'Nom exact du groupe AD (ex. GRP-SAV)', style:'min-width:260px'});
  const draw = () => box.replaceChildren(...list.map((gid, i) => {
    const g = (me.services || []).find(x => x.name === gid);
    return h('div', {style:'display:flex;gap:10px;align-items:center;border-top:1px solid var(--color-border);padding:6px 0'},
      h('strong', {}, gid), h('span', {class:'muted'}, g ? g.members.length + ' membre(s) dans l’annuaire' : 'à enregistrer'),
      h('button', {title:'Retirer', onclick: () => { list.splice(i, 1); draw(); }}, 'Retirer'));
  }));
  draw();
  return h('div', {class:'card'}, h('strong', {}, 'Groupes d’affectation'),
    h('p', {class:'muted'}, 'Groupes de l’annuaire (AD) auxquels on affecte une réclamation. Leurs membres reçoivent un mail, puis l’un d’eux clique sur « Prendre ». Les membres sont lus dans l’AD : rien à mettre à jour ici quand quelqu’un arrive ou part.'),
    box,
    h('div', {style:'margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap'}, add,
      h('button', {onclick: () => { const v = add.value.trim(); if (v && !list.includes(v)) { list.push(v); add.value = ''; draw(); } }}, '+ Ajouter'),
      h('button', {class:'primary', onclick: async () => {
        out.textContent = '…';
        try { const r = await api('/services', {method:'POST', body:{services:list.map(name => ({name}))}}); me.services = r.services; list = r.services.map(x => x.name); draw(); out.textContent = '✔ Groupes enregistrés.'; }
        catch (e) { out.textContent = e.message; }
      }}, 'Enregistrer'), out));
}
function admin() {
  const out = h('div', {}), file = h('input', {type:'file', accept:'.csv,.txt'});
  show(h('h2', {}, 'Administration'),
    me.can.config ? servicesCard() : '',
    h('div', {class:'card'}, h('strong', {}, 'Importer des réclamations (CSV)'),
      h('p', {class:'muted'}, 'Colonnes, dans cet ordre : Client ; Téléphone ; Wilaya ; N° facture ; Produit ; Catégorie ; Type (' + me.types.join(' / ') + ') ; Description ; Priorité (facultatif). La 1re ligne (en-tête) est ignorée. Excel : Enregistrer sous → CSV UTF-8. Téléphone, wilaya (français ou arabe) et catégorie sont corrigés automatiquement.'),
      file, h('button', {class:'primary', onclick: async () => {
        if (!file.files[0]) { out.textContent = 'Choisissez un fichier.'; return; }
        const fd = new FormData(); fd.append('file', file.files[0]); out.textContent = 'Import en cours…';
        try { const r = await api('/import', {method:'POST', body:fd}); await refresh(); out.textContent = r.imported + ' importée(s), ' + r.skipped + ' ignorée(s) (client ou description vide), ' + r.unrecognized + ' type(s) non reconnu(s).'; }
        catch (e) { out.textContent = e.message; }
      }}, 'Importer'), out),
    h('div', {class:'card'}, h('strong', {}, 'Rôles (groupes Nextcloud)'),
      h('p', {class:'muted'}, 'reclam_admin / administrateur Nextcloud : tout. reclam_superviseur : voit tout, affecte, clôture. reclam_agent : saisit et traite ses réclamations (affectées ou saisies par lui). reclam_lecture : consultation seule. Tout autre utilisateur connecté : voit toutes les réclamations, les saisit et les modifie (sans affecter, clôturer ni supprimer). Délais selon priorité : Urgente ' + me.sla.Urgente + ' j, Haute ' + me.sla.Haute + ' j, Normale ' + me.sla.Normale + ' j.')));
}

(async () => {
  try { me = await api('/me'); } catch (e) { root.textContent = e.message; return; }
  // Lien du mail : #r<id> ouvre directement la réclamation.
  const m = location.hash.match(/^#r(\d+)$/);
  if ((me.my_services || []).length) tab = 'aprendre';
  if (m) detail(Number(m[1])); else go(tab);
})();
})();
