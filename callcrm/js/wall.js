/**
 * Call CRM — écran du plateau commercial (TV). Lecture seule, actualisé chaque minute.
 * Chiffres du jour : entrants, sortants, top 3 des commerciaux, équipe connectée, rappels.
 */
(function () {
  'use strict';
  const root = document.getElementById('crm-wall');
  if (!root) return;
  const API = root.dataset.api;
  const REFRESH = 60000;

  const h = (tag, attrs, ...kids) => {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) {
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') el.className = v; else el.setAttribute(k, v);
    }
    for (const kid of kids.flat()) if (kid !== null && kid !== undefined && kid !== '') el.append(kid.nodeType ? kid : String(kid));
    return el;
  };
  const num = n => new Intl.NumberFormat('fr-FR').format(n || 0);
  const pct = (a, b) => b ? Math.round(100 * a / b) + ' %' : '—';
  const clock = h('time', {class: 'crm-wall-clock'});
  const tick = zone => { clock.textContent = new Intl.DateTimeFormat('fr-FR', {weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit', timeZone: zone}).format(new Date()); };
  let zone = 'Africa/Algiers', failures = 0;

  const stat = (label, value, note, tone) => h('div', {class: 'crm-wall-stat' + (tone ? ' ' + tone : '')},
    h('span', {class: 'crm-wall-label'}, label), h('strong', {}, value), note ? h('small', {}, note) : '');

  function chart(series) {
    const hours = series.filter(s => Number(s.label) >= 7 && Number(s.label) <= 18);
    const peak = Math.max(1, ...hours.map(s => s.answered + s.missed + s.lost));
    return h('div', {class: 'crm-wall-chart', role: 'img', 'aria-label': 'Appels entrants par heure'},
      hours.map(s => {
        const bar = (kind, n) => n ? h('i', {class: kind, style: 'height:' + (100 * n / peak) + '%'}) : '';
        return h('div', {class: 'crm-wall-hour'},
          h('div', {class: 'crm-wall-bars'}, bar('lost', s.lost), bar('missed', s.missed), bar('answered', s.answered)),
          h('small', {}, s.label + ' h'));
      }));
  }

  function draw(d) {
    zone = d.timezone || zone;
    const c = d.calls;
    const reached = c.answered + c.missed + c.lost;
    const unanswered = c.missed + c.lost;
    const top = d.agents.filter(a => a.processed || a.answered || a.outgoing).slice(0, 3);
    const rest = d.agents.filter(a => !top.includes(a));
    root.replaceChildren(
      h('header', {class: 'crm-wall-head'},
        h('div', {}, h('h1', {}, 'Plateau commercial'), h('p', {}, 'Aujourd’hui · mis à jour à ' + new Intl.DateTimeFormat('fr-FR', {hour: '2-digit', minute: '2-digit', timeZone: zone}).format(new Date(d.now * 1000)))),
        clock),
      h('section', {class: 'crm-wall-row'},
        stat('Appels entrants', num(c.inbound), num(c.closed) + ' hors horaires · ' + num(c.menu) + ' raccrochés à l’accueil'),
        stat('Taux de réponse', pct(c.answered, reached), num(c.answered) + ' décrochés sur ' + num(reached), 'accent'),
        stat('Manqués et perdus', num(unanswered), num(c.missed_called_back + c.lost_called_back) + ' déjà rappelés', unanswered ? 'warn' : ''),
        stat('Appels sortants', num(c.outbound), num(c.outgoing_answered) + ' aboutis · ' + pct(c.outgoing_answered, c.outbound))),
      h('section', {class: 'crm-wall-main'},
        h('div', {class: 'crm-wall-panel crm-wall-top'},
          h('h2', {}, 'Top 3 du jour'),
          top.length ? h('ol', {}, top.map((a, i) => h('li', {class: 'rank-' + (i + 1)},
            h('span', {class: 'crm-wall-rank'}, String(i + 1)),
            h('span', {class: 'crm-wall-name'}, a.name.toLowerCase()),
            h('span', {class: 'crm-wall-figures'},
              h('b', {}, num(a.processed)), ' dossiers traités',
              h('small', {}, num(a.answered) + ' décrochés · ' + num(a.outgoing) + ' sortants (' + num(a.reached) + ' aboutis)')))))
            : h('p', {class: 'crm-wall-empty'}, 'Pas encore d’activité aujourd’hui'),
          rest.length ? h('p', {class: 'crm-wall-others'}, rest.filter(a => a.processed).map(a => a.name.split(' ')[0].toLowerCase() + ' ' + a.processed).join(' · ')) : ''),
        h('div', {class: 'crm-wall-panel'},
          h('h2', {}, 'Appels entrants par heure'), chart(c.series || []),
          h('p', {class: 'crm-wall-legend'}, h('span', {class: 'answered'}, 'Décrochés'), h('span', {class: 'missed'}, 'Manqués'), h('span', {class: 'lost'}, 'Perdus')))),
      h('section', {class: 'crm-wall-row'},
        stat('Commerciaux connectés', d.team.online + ' / ' + d.team.total, d.team.pause ? d.team.pause + ' en pause' : 'aucun en pause'),
        stat('Appels manqués à rappeler', num(d.open_missed), 'en attente dans Appels manqués', d.open_missed ? 'warn' : ''),
        stat('Rappels clients en retard', num(d.late_callbacks), 'date de rappel dépassée', d.late_callbacks ? 'warn' : ''),
        stat('Dossiers traités', num(d.agents.reduce((t, a) => t + a.processed, 0)), 'résultats d’appel saisis aujourd’hui')));
    tick(zone);
  }

  async function load() {
    try {
      const r = await fetch(API, {headers: {Accept: 'application/json'}, cache: 'no-store'});
      const d = await r.json().catch(() => ({}));
      if (!r.ok) throw new Error(d.error || 'Données indisponibles');
      failures = 0;
      draw(d);
    } catch (e) {
      failures++;
      if (!root.querySelector('.crm-wall-head') || failures > 5) root.replaceChildren(h('p', {class: 'crm-wall-loading'}, e.message || 'Connexion indisponible'));
    }
  }
  load();
  setInterval(load, REFRESH);
  setInterval(() => tick(zone), 15000);
})();
