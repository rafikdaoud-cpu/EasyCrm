/* Réclamations — interface (JavaScript natif, sans compilation) */
(function () {
	'use strict';

	const BASE = (window.OC && OC.generateUrl) ? OC.generateUrl('/apps/reclamations') : ((window.OC && OC.webroot) || '') + '/index.php/apps/reclamations';
	const TOKEN = () => (window.OC && OC.requestToken) || document.head.dataset.requesttoken || '';
	const STEPS = ['nouveau', 'qualifiee', 'en_cours', 'resolu', 'cloture'];
	const STEP_LABELS = { nouveau: 'Nouveau', qualifiee: 'Qualifiée', en_cours: 'En cours', resolu: 'Résolu', cloture: 'Clôturé' };

	const S = {
		meta: null,
		stats: null,
		rows: [],
		view: 'open', // dashboard | open | mine | late | all | s:<statut>
		q: '',
		type: '',
		urgency: '',
		current: null,
	};

	let root;

	// ------------------------------------------------------------ utilitaires
	const h = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const dt = (ts) => ts ? new Date(ts * 1000).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
	const d = (ts) => ts ? new Date(ts * 1000).toLocaleDateString('fr-FR') : '';
	const lab = (dict, k) => (S.meta && S.meta[dict] && S.meta[dict][k]) || '';
	const sizeFmt = (b) => b > 1048576 ? (b / 1048576).toFixed(1) + ' Mo' : Math.max(1, Math.round(b / 1024)) + ' Ko';

	function dur(sec) {
		const a = Math.abs(sec);
		const j = Math.floor(a / 86400);
		const hh = Math.floor((a % 86400) / 3600);
		const mm = Math.floor((a % 3600) / 60);
		if (j > 0) return j + ' j ' + (hh ? hh + ' h' : '');
		if (hh > 0) return hh + ' h ' + (mm ? mm + ' min' : '');
		return mm + ' min';
	}

	function slaHtml(r) {
		if (r.status === 'en_attente') return '<span class="sla">⏸ en pause (' + h(dur(r.remaining || 0)) + ' restants)</span>';
		if (r.late_take) return '<span class="sla late">Prise en charge en retard</span>';
		if (r.remaining == null) return r.resolved_at ? '<span class="sla">Résolu le ' + h(d(r.resolved_at)) + '</span>' : '';
		if (r.remaining < 0) return '<span class="sla late">Retard de ' + h(dur(r.remaining)) + '</span>';
		const warn = r.remaining < 86400 ? ' warn' : '';
		return '<span class="sla' + warn + '">Reste ' + h(dur(r.remaining)) + '</span>';
	}

	const badgeS = (s) => '<span class="b s-' + h(s) + '">' + h(lab('statuses', s)) + '</span>';
	const badgeU = (u) => '<span class="b u-' + h(u) + '">' + h(lab('urgencies', u)) + '</span>';

	function options(dict, selected, empty) {
		let o = empty !== undefined ? '<option value="">' + h(empty) + '</option>' : '';
		for (const [k, v] of Object.entries(dict)) {
			o += '<option value="' + h(k) + '"' + (k === selected ? ' selected' : '') + '>' + h(v) + '</option>';
		}
		return o;
	}
	function userOptions(selected, empty) {
		let o = '<option value="">' + h(empty || '— Non affecté —') + '</option>';
		for (const u of S.meta.users) {
			o += '<option value="' + h(u.id) + '"' + (u.id === selected ? ' selected' : '') + '>' + h(u.name) + '</option>';
		}
		return o;
	}

	async function api(path, method, body) {
		const opts = { method: method || 'GET', headers: { requesttoken: TOKEN() } };
		if (body instanceof FormData) {
			opts.body = body;
		} else if (body !== undefined) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(body);
		}
		const res = await fetch(BASE + path, opts);
		let data = {};
		try { data = await res.json(); } catch (e) { /* réponse vide */ }
		if (!res.ok) throw new Error(data.error || data.message || ('Erreur ' + res.status));
		return data;
	}

	function toast(msg, err) {
		if (window.OCP && OCP.Toast) {
			err ? OCP.Toast.error(msg) : OCP.Toast.success(msg);
		} else if (err) {
			alert(msg);
		}
	}

	function formData(form) {
		const o = {};
		for (const el of form.elements) {
			if (el.name && !el.disabled) o[el.name] = el.value;
		}
		return o;
	}

	// ------------------------------------------------------------ chargement
	function queryString() {
		const p = new URLSearchParams();
		if (S.view === 'open') p.set('open', '1');
		if (S.view === 'mine') { p.set('mine', '1'); p.set('open', '1'); }
		if (S.view === 'late') p.set('late', '1');
		if (S.view.startsWith('s:')) p.set('status', S.view.slice(2));
		if (S.q) p.set('q', S.q);
		if (S.type) p.set('type', S.type);
		if (S.urgency) p.set('urgency', S.urgency);
		return p.toString();
	}

	async function refresh() {
		try {
			const [stats, rows] = await Promise.all([
				api('/api/stats'),
				S.view === 'dashboard' ? Promise.resolve([]) : api('/api/reclamations?' + queryString()),
			]);
			S.stats = stats;
			S.rows = rows;
			render();
		} catch (e) {
			toast(e.message, true);
		}
	}

	// ------------------------------------------------------------ rendu principal
	function render() {
		const st = S.stats || { by_status: {} };
		const navItem = (view, label, count, alert) =>
			'<a data-view="' + h(view) + '" class="' + (S.view === view ? 'active' : '') + '"><span>' + h(label) + '</span>' +
			(count != null ? '<span class="cnt' + (alert && count ? ' alert' : '') + '">' + count + '</span>' : '') + '</a>';

		let nav = '<nav class="recl-nav">' +
			'<button class="primary recl-new" data-act="new">+ Nouvelle réclamation</button>' +
			navItem('dashboard', 'Tableau de bord') +
			navItem('open', 'Ouvertes', st.open) +
			navItem('mine', 'Mes réclamations') +
			navItem('late', 'En retard', st.late, true) +
			'<h4>Par étape</h4>';
		for (const k of Object.keys(S.meta.statuses)) {
			nav += navItem('s:' + k, S.meta.statuses[k], st.by_status[k] || 0);
		}
		nav += '<h4>Archives</h4>' + navItem('all', 'Toutes les réclamations') + '</nav>';

		root.innerHTML = nav + '<main class="recl-main">' + (S.view === 'dashboard' ? renderDashboard() : renderList()) + '</main>';
	}

	function viewTitle() {
		if (S.view === 'open') return 'Réclamations ouvertes';
		if (S.view === 'mine') return 'Mes réclamations';
		if (S.view === 'late') return 'Réclamations en retard';
		if (S.view === 'all') return 'Toutes les réclamations';
		if (S.view.startsWith('s:')) return lab('statuses', S.view.slice(2));
		return '';
	}

	function renderList() {
		let html = '<div class="recl-toolbar"><h2>' + h(viewTitle()) + ' <small style="font-weight:400;color:var(--color-text-maxcontrast)">(' + S.rows.length + ')</small></h2>' +
			'<input type="search" data-f="q" placeholder="Client, n° dossier, référence, produit…" value="' + h(S.q) + '">' +
			'<select data-f="type">' + options(S.meta.types, S.type, 'Tous les types') + '</select>' +
			'<select data-f="urgency">' + options(S.meta.urgencies, S.urgency, 'Toutes urgences') + '</select>' +
			'<button data-act="export" title="Exporter la liste affichée (CSV pour Excel)">Exporter CSV</button></div>';

		if (!S.rows.length) {
			return html + '<div class="recl-empty">Aucune réclamation dans cette vue.</div>';
		}
		html += '<table class="recl-table"><thead><tr><th>Réf.</th><th>Client</th><th class="hide-sm">Produit</th><th class="hide-sm">Type</th>' +
			'<th>Urgence</th><th>Étape</th><th class="hide-sm">Responsable</th><th>Délai</th></tr></thead><tbody>';
		for (const r of S.rows) {
			html += '<tr data-id="' + r.id + '">' +
				'<td class="ref">' + h(r.ref) + '<span class="sub">' + h(d(r.created_at)) + '</span></td>' +
				'<td><strong>' + h(r.client_name) + '</strong>' + (r.dossier_no ? '<span class="sub">Dossier ' + h(r.dossier_no) + '</span>' : '') + '</td>' +
				'<td class="hide-sm">' + h(r.product) + '</td>' +
				'<td class="hide-sm">' + h(lab('types', r.type)) + '</td>' +
				'<td>' + badgeU(r.urgency) + '</td>' +
				'<td>' + badgeS(r.status) + (r.reopen_count ? ' <span class="b" title="Réouvertures">↻ ' + r.reopen_count + '</span>' : '') + '</td>' +
				'<td class="hide-sm">' + h(r.assigned_name || '—') + '</td>' +
				'<td>' + slaHtml(r) + '</td></tr>';
		}
		return html + '</tbody></table>';
	}

	function bars(title, dict, counts) {
		const entries = Object.keys(dict).map((k) => [dict[k], counts[k] || 0]).sort((a, b) => b[1] - a[1]);
		const max = Math.max(1, ...entries.map((e) => e[1]));
		let out = '<div class="recl-card"><h3>' + h(title) + '</h3>';
		for (const [l, n] of entries) {
			out += '<div class="recl-bar"><span>' + h(l) + '</span><div class="track"><div class="fill" style="width:' + Math.round(100 * n / max) + '%"></div></div><span class="n">' + n + '</span></div>';
		}
		return out + '</div>';
	}

	function renderDashboard() {
		const s = S.stats;
		const kpi = (v, l, cls) => '<div class="recl-kpi ' + (cls || '') + '"><div class="v">' + h(v) + '</div><div class="l">' + h(l) + '</div></div>';
		const slaCls = s.sla_ok_pct == null ? '' : (s.sla_ok_pct >= 80 ? 'good' : 'bad');
		return '<div class="recl-toolbar"><h2>Tableau de bord</h2><button data-act="export-all">Exporter tout (CSV)</button></div>' +
			'<div class="recl-kpis">' +
			kpi(s.open, 'Réclamations ouvertes') +
			kpi(s.late, 'En retard', s.late ? 'bad' : 'good') +
			kpi(s.high_open, 'Urgence haute ouvertes', s.high_open ? 'bad' : '') +
			kpi(s.waiting, 'En attente') +
			kpi(s.month_created, 'Ouvertes ce mois') +
			kpi(s.month_closed, 'Clôturées ce mois') +
			kpi(s.avg_resolution_h == null ? '—' : (s.avg_resolution_h >= 48 ? (s.avg_resolution_h / 24).toFixed(1) + ' j' : s.avg_resolution_h + ' h'), 'Délai moyen de résolution') +
			kpi(s.sla_ok_pct == null ? '—' : s.sla_ok_pct + ' %', 'Résolues dans les délais', slaCls) +
			kpi(s.avg_satisfaction == null ? '—' : s.avg_satisfaction + ' / 5', 'Satisfaction client') +
			kpi(s.total_cost ? Math.round(s.total_cost).toLocaleString('fr-FR') + ' DA' : '—', 'Coût total des réclamations') +
			kpi(s.reopened, 'Réouvertes au moins une fois', s.reopened ? 'bad' : '') +
			'</div><div class="recl-bars">' +
			bars('Par type de réclamation', S.meta.types, s.by_type) +
			bars('Par cause racine', S.meta.rootCauses, s.by_cause) +
			bars('Par étape', S.meta.statuses, s.by_status) +
			'</div>';
	}

	// ------------------------------------------------------------ fenêtres
	function overlay(inner, center) {
		closeOverlay();
		const o = document.createElement('div');
		o.className = 'recl-overlay' + (center ? ' center' : '');
		o.innerHTML = inner;
		o.addEventListener('mousedown', (e) => { if (e.target === o) center ? o.remove() : closeDetail(); });
		document.body.appendChild(o);
		return o;
	}
	function closeOverlay() {
		document.querySelectorAll('.recl-overlay.center').forEach((x) => x.remove());
	}
	function closeDetail() {
		document.querySelectorAll('.recl-overlay').forEach((x) => x.remove());
		S.current = null;
		if (location.hash) history.replaceState(null, '', location.pathname + location.search);
	}

	// --- nouvelle réclamation
	function openNew() {
		const m = S.meta;
		const o = overlay('<div class="recl-modal"><div class="recl-head"><h2>Nouvelle réclamation</h2><button class="x" data-close>✕</button></div>' +
			'<form class="recl-form" autocomplete="off">' +
			'<div><label>Nom du client <span class="req">*</span></label><input name="client_name" required maxlength="255"></div>' +
			'<div><label>Téléphone</label><input name="client_phone" type="tel" maxlength="64"></div>' +
			'<div><label>N° de dossier / facture</label><input name="dossier_no" maxlength="128"></div>' +
			'<div><label>Produit</label><input name="product" maxlength="255"></div>' +
			'<div><label>Type de réclamation <span class="req">*</span></label><select name="type" required>' + options(m.types, '', '— Choisir —') + '</select></div>' +
			'<div><label>Urgence <span class="req">*</span></label><select name="urgency" required>' + options(m.urgencies, 'moyenne') + '</select>' +
			'<div class="hint" data-sla></div></div>' +
			'<div><label>Canal de réception</label><select name="channel">' + options(m.channels, 'telephone', '—') + '</select></div>' +
			'<div><label>Service concerné</label><select name="service">' + options(m.services, '', '—') + '</select></div>' +
			'<div class="full"><label>Description de la réclamation <span class="req">*</span></label><textarea name="description" required></textarea></div>' +
			'<div class="full"><label>Affecter directement à (facultatif)</label><select name="assigned_to">' + userOptions('') + '</select></div>' +
			'<div class="full recl-err"></div>' +
			'<div class="full recl-btns"><button type="button" data-close>Annuler</button><button type="submit" class="primary">Enregistrer</button></div>' +
			'</form></div>', true);

		const form = o.querySelector('form');
		const slaHint = () => {
			const s = m.sla[form.elements.namedItem("urgency").value];
			o.querySelector('[data-sla]').textContent = s ? 'Prise en charge sous ' + s[0] + ' h, résolution sous ' + (s[1] >= 48 ? s[1] / 24 + ' jours' : s[1] + ' h') : '';
		};
		form.elements.namedItem("urgency").addEventListener('change', slaHint);
		slaHint();
		o.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => o.remove()));
		form.elements.namedItem("client_name").focus();
		form.addEventListener('submit', async (e) => {
			e.preventDefault();
			const btn = form.querySelector('[type=submit]');
			btn.disabled = true;
			try {
				const r = await api('/api/reclamations', 'POST', formData(form));
				o.remove();
				toast('Réclamation ' + r.ref + ' créée');
				await refresh();
				openDetail(r.id);
			} catch (err) {
				form.querySelector('.recl-err').textContent = err.message;
				btn.disabled = false;
			}
		});
	}

	// --- fiche détail
	async function openDetail(id) {
		try {
			const r = await api('/api/reclamations/' + id);
			S.current = r;
			if (location.hash !== '#/r/' + id) history.replaceState(null, '', '#/r/' + id);
			renderDetail(false);
		} catch (e) {
			toast(e.message, true);
			closeDetail();
		}
	}

	function stepsHtml(r) {
		let cur = r.status === 'en_attente' ? 'en_cours' : r.status;
		if (r.status === 'rejetee') {
			return '<div class="recl-steps rej"><div class="done">Nouveau</div><div class="cur">Rejetée</div></div>';
		}
		const idx = STEPS.indexOf(cur);
		return '<div class="recl-steps">' + STEPS.map((s, i) =>
			'<div class="' + (i < idx ? 'done' : (i === idx ? 'cur' : '')) + '">' + h(s === 'en_cours' && r.status === 'en_attente' ? 'En attente' : STEP_LABELS[s]) + '</div>').join('') + '</div>';
	}

	function transitionLabel(from, to) {
		if (to === 'en_cours') return from === 'en_attente' ? 'Reprendre le traitement' : (from === 'resolu' ? 'Réouvrir (client insatisfait)' : 'Démarrer le traitement');
		return ({ qualifiee: 'Qualifier et affecter', rejetee: 'Rejeter', en_attente: 'Mettre en attente', resolu: 'Marquer comme résolue', cloture: 'Clôturer', nouveau: 'Réexaminer' })[to] || to;
	}

	function field(label, value, full) {
		return '<div' + (full ? ' class="full"' : '') + '><label>' + h(label) + '</label><div class="ro">' + (value === '' || value == null ? '—' : value) + '</div></div>';
	}

	function renderDetail(edit) {
		const r = S.current;
		const m = S.meta;
		const canEdit = r.status !== 'cloture' || m.isManager;
		let html = '<div class="recl-panel">' +
			'<div class="recl-head"><div><h2>' + h(r.ref) + ' — ' + h(r.client_name) + '</h2>' +
			'<div class="meta">' + badgeS(r.status) + badgeU(r.urgency) + '<span class="b">' + h(lab('types', r.type)) + '</span>' +
			(r.late ? '<span class="b late">En retard</span>' : '') + (r.reopen_count ? '<span class="b">Réouverte ' + r.reopen_count + '×</span>' : '') + '</div></div>' +
			'<button class="x" data-act="close-detail" title="Fermer">✕</button></div>' + stepsHtml(r);

		if (r.allowed.length) {
			html += '<div class="recl-actions"><span class="lbl">Étape suivante — ' + slaHtml(r) + '</span>';
			for (const to of r.allowed) {
				const primary = !['rejetee', 'en_attente'].includes(to) && !(to === 'en_cours' && r.status === 'resolu');
				html += '<button data-tr="' + h(to) + '" class="' + (primary ? 'primary' : '') + '">' + h(transitionLabel(r.status, to)) + '</button>';
			}
			html += '</div>';
		}

		if (r.status === 'en_attente' && r.wait_reason) {
			html += '<div class="recl-actions"><span class="lbl">En attente depuis le ' + h(dt(r.paused_since)) + '</span>' + h(r.wait_reason) + '</div>';
		}

		html += '<div class="recl-section"><h3 style="display:flex;align-items:center">Informations' +
			(canEdit && !edit ? '<button style="margin-left:auto" data-act="edit">Modifier</button>' : '') + '</h3>';

		if (edit) {
			html += '<form class="recl-form" data-edit>' +
				'<div><label>Nom du client *</label><input name="client_name" value="' + h(r.client_name) + '" required></div>' +
				'<div><label>Téléphone</label><input name="client_phone" value="' + h(r.client_phone) + '"></div>' +
				'<div><label>N° de dossier / facture</label><input name="dossier_no" value="' + h(r.dossier_no) + '"></div>' +
				'<div><label>Produit</label><input name="product" value="' + h(r.product) + '"></div>' +
				'<div><label>Type *</label><select name="type">' + options(m.types, r.type) + '</select></div>' +
				'<div><label>Urgence *</label><select name="urgency">' + options(m.urgencies, r.urgency) + '</select><div class="hint">Changer l\'urgence recalcule l\'échéance</div></div>' +
				'<div><label>Canal</label><select name="channel">' + options(m.channels, r.channel || '', '—') + '</select></div>' +
				'<div><label>Service</label><select name="service">' + options(m.services, r.service || '', '—') + '</select></div>' +
				'<div><label>Responsable</label><select name="assigned_to">' + userOptions(r.assigned_to || '') + '</select></div>' +
				'<div><label>Cause racine</label><select name="root_cause">' + options(m.rootCauses, r.root_cause || '', '—') + '</select></div>' +
				'<div><label>Solution</label><select name="solution">' + options(m.solutions, r.solution || '', '—') + '</select></div>' +
				'<div><label>Coût (DA)</label><input name="cost" inputmode="decimal" value="' + h(r.cost == null ? '' : r.cost) + '"></div>' +
				'<div class="full"><label>Description *</label><textarea name="description">' + h(r.description) + '</textarea></div>' +
				'<div class="full"><label>Détail de la solution</label><textarea name="solution_detail">' + h(r.solution_detail) + '</textarea></div>' +
				'<div class="full recl-err"></div>' +
				'<div class="full recl-btns"><button type="button" data-act="cancel-edit">Annuler</button><button type="submit" class="primary">Enregistrer</button></div></form>';
		} else {
			html += '<div class="recl-form">' +
				field('Client', h(r.client_name)) + field('Téléphone', r.client_phone ? '<a href="tel:' + h(r.client_phone) + '">' + h(r.client_phone) + '</a>' : '') +
				field('N° de dossier / facture', h(r.dossier_no)) + field('Produit', h(r.product)) +
				field('Canal', h(lab('channels', r.channel))) + field('Ouverte par', h(r.created_by_name) + ' le ' + h(dt(r.created_at))) +
				field('Description', h(r.description), true) + '</div>';
		}
		html += '</div>';

		if (!edit) {
			html += '<div class="recl-section"><h3>Traitement</h3><div class="recl-form">' +
				field('Service', h(lab('services', r.service))) + field('Responsable', h(r.assigned_name)) +
				field('Prise en charge attendue avant', h(dt(r.take_due_at))) + field('Résolution attendue avant', h(dt(r.due_at))) +
				field('Qualifiée le', h(dt(r.qualified_at))) + field('Résolue le', h(dt(r.resolved_at))) +
				field('Solution', h(lab('solutions', r.solution))) + field('Cause racine', h(lab('rootCauses', r.root_cause))) +
				field('Coût', r.cost != null ? h(r.cost.toLocaleString('fr-FR')) + ' DA' : '') +
				field('Satisfaction client', r.satisfaction ? '★'.repeat(r.satisfaction) + '☆'.repeat(5 - r.satisfaction) : '') +
				(r.solution_detail ? field('Détail de la solution', h(r.solution_detail), true) : '') +
				(r.reject_reason ? field('Motif de rejet', h(r.reject_reason), true) : '') +
				(r.closed_at ? field('Clôturée le', h(dt(r.closed_at))) : '') +
				'</div></div>';
		}

		html += '<div class="recl-section"><h3>Pièces jointes (' + r.attachments.length + ')</h3><ul class="recl-files">';
		for (const f of r.attachments) {
			const url = BASE + '/api/attachments/' + f.id;
			const canDel = f.user_id === m.me || m.isManager;
			html += '<li><a href="' + h(url) + '?inline=1" target="_blank" rel="noopener">' + h(f.filename) + '</a>' +
				'<span class="sz">' + h(sizeFmt(f.size)) + ' · ' + h(f.user_name) + ' · ' + h(d(f.created_at)) + '</span>' +
				(canDel ? '<button data-delfile="' + f.id + '" title="Supprimer">🗑</button>' : '') + '</li>';
		}
		html += '</ul><label class="button" style="display:inline-block;cursor:pointer">+ Ajouter photos / documents' +
			'<input type="file" data-upload multiple hidden accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.mp4,.mov,.zip"></label></div>';

		html += '<div class="recl-section"><h3>Historique et commentaires</h3><ul class="recl-hist">';
		for (const ev of r.history) {
			let title = '';
			if (ev.action === 'status') title = h(lab('statuses', ev.from_status)) + ' → <strong>' + h(lab('statuses', ev.to_status)) + '</strong>';
			else if (ev.action === 'create') title = '<strong>Création</strong>';
			else if (ev.action === 'comment') title = '<strong>Commentaire</strong>';
			else if (ev.action === 'file') title = 'Pièce jointe';
			else title = 'Modification';
			html += '<li class="' + h(ev.action) + '"><div class="when">' + h(dt(ev.created_at)) + ' · ' + h(ev.user_name) + '</div>' +
				'<div>' + title + '</div>' + (ev.message ? '<div class="msg">' + h(ev.message) + '</div>' : '') + '</li>';
		}
		html += '</ul><form class="recl-comment" data-comment><textarea name="message" placeholder="Ajouter un commentaire (appel client, échange fournisseur…)"></textarea>' +
			'<button type="submit">Publier</button></form></div>';

		if (m.isManager) {
			html += '<div class="recl-section" style="text-align:right"><button data-act="delete" class="error">Supprimer la réclamation</button></div>';
		}
		html += '</div>';

		let o = document.querySelector('.recl-overlay:not(.center)');
		if (!o) {
			o = document.createElement('div');
			o.className = 'recl-overlay';
			o.addEventListener('mousedown', (e) => { if (e.target === o) closeDetail(); });
			document.body.appendChild(o);
		}
		const scroll = o.firstElementChild ? o.firstElementChild.scrollTop : 0;
		o.innerHTML = html;
		o.firstElementChild.scrollTop = scroll;
		bindDetail(o);
	}

	function bindDetail(o) {
		const r = S.current;
		o.querySelector('[data-act=close-detail]').addEventListener('click', closeDetail);
		const editBtn = o.querySelector('[data-act=edit]');
		if (editBtn) editBtn.addEventListener('click', () => renderDetail(true));
		const cancel = o.querySelector('[data-act=cancel-edit]');
		if (cancel) cancel.addEventListener('click', () => renderDetail(false));

		const ef = o.querySelector('form[data-edit]');
		if (ef) {
			ef.addEventListener('submit', async (e) => {
				e.preventDefault();
				try {
					S.current = await api('/api/reclamations/' + r.id, 'PUT', formData(ef));
					toast('Modifications enregistrées');
					renderDetail(false);
					refresh();
				} catch (err) {
					ef.querySelector('.recl-err').textContent = err.message;
				}
			});
		}

		o.querySelectorAll('[data-tr]').forEach((b) => b.addEventListener('click', () => openTransition(b.dataset.tr)));

		const cf = o.querySelector('form[data-comment]');
		cf.addEventListener('submit', async (e) => {
			e.preventDefault();
			const msg = cf.elements.namedItem("message").value.trim();
			if (!msg) return;
			try {
				S.current = await api('/api/reclamations/' + r.id + '/comment', 'POST', { message: msg });
				renderDetail(false);
			} catch (err) { toast(err.message, true); }
		});

		o.querySelector('[data-upload]').addEventListener('change', async (e) => {
			const files = Array.from(e.target.files || []);
			for (const f of files) {
				const fd = new FormData();
				fd.append('file', f);
				try {
					S.current = await api('/api/reclamations/' + r.id + '/attachments', 'POST', fd);
				} catch (err) { toast(f.name + ' : ' + err.message, true); }
			}
			renderDetail(false);
		});

		o.querySelectorAll('[data-delfile]').forEach((b) => b.addEventListener('click', async () => {
			if (!confirm('Supprimer cette pièce jointe ?')) return;
			try {
				S.current = await api('/api/attachments/' + b.dataset.delfile, 'DELETE');
				renderDetail(false);
			} catch (err) { toast(err.message, true); }
		}));

		const del = o.querySelector('[data-act=delete]');
		if (del) del.addEventListener('click', async () => {
			if (!confirm('Supprimer définitivement ' + r.ref + ' et tout son historique ?')) return;
			try {
				await api('/api/reclamations/' + r.id, 'DELETE');
				toast(r.ref + ' supprimée');
				closeDetail();
				refresh();
			} catch (err) { toast(err.message, true); }
		});
	}

	// --- changement d'étape
	function openTransition(to) {
		const r = S.current;
		const m = S.meta;
		let body = '';
		let help = '';
		switch (to) {
			case 'qualifiee':
				help = 'Vérifiez le dossier (facture/BL, garantie, photos), confirmez l\'urgence et affectez un responsable.';
				body = '<div><label>Responsable <span class="req">*</span></label><select name="assigned_to" required>' + userOptions(r.assigned_to || '', '— Choisir —') + '</select></div>' +
					'<div><label>Service</label><select name="service">' + options(m.services, r.service || '', '—') + '</select></div>' +
					'<div><label>Urgence confirmée</label><select name="urgency">' + options(m.urgencies, r.urgency) + '</select></div>' +
					'<div class="full"><label>Commentaire</label><textarea name="comment"></textarea></div>';
				break;
			case 'rejetee':
				help = 'Réclamation non fondée (hors garantie, mauvaise utilisation, doublon…). Le motif sera visible dans l\'historique.';
				body = '<div class="full"><label>Motif du rejet <span class="req">*</span></label><textarea name="reject_reason" required></textarea></div>';
				break;
			case 'en_attente':
				help = 'Le délai est mis en pause jusqu\'à la reprise du traitement.';
				body = '<div class="full"><label>Qu\'attendez-vous ? <span class="req">*</span></label><input name="wait_reason" required placeholder="Pièce détachée commandée, retour fournisseur, client injoignable…"></div>';
				break;
			case 'resolu':
				help = 'Indiquez la solution apportée au client. La réclamation pourra être clôturée après confirmation du client.';
				body = '<div><label>Solution apportée <span class="req">*</span></label><select name="solution" required>' + options(m.solutions, r.solution || '', '— Choisir —') + '</select></div>' +
					'<div><label>Cause racine</label><select name="root_cause">' + options(m.rootCauses, r.root_cause || '', '—') + '</select></div>' +
					'<div><label>Coût de la réclamation (DA)</label><input name="cost" inputmode="decimal" value="' + h(r.cost == null ? '' : r.cost) + '" placeholder="Pièces, transport, remboursement…"></div>' +
					'<div class="full"><label>Détail de la solution</label><textarea name="solution_detail">' + h(r.solution_detail) + '</textarea></div>';
				break;
			case 'cloture':
				help = 'Le client a confirmé que le problème est réglé (ou n\'a pas répondu après relance).';
				body = '<div class="full"><label>Satisfaction du client</label><div class="recl-stars" data-stars>' +
					[1, 2, 3, 4, 5].map((n) => '<button type="button" data-star="' + n + '">★</button>').join('') +
					'</div><input type="hidden" name="satisfaction" value=""></div>' +
					'<div class="full"><label>Commentaire</label><textarea name="comment"></textarea></div>';
				break;
			case 'en_cours':
				if (r.status === 'resolu') {
					help = 'Le client n\'est pas satisfait de la solution : la réclamation repart en traitement.';
					body = '<div class="full"><label>Motif de la réouverture <span class="req">*</span></label><textarea name="comment" required></textarea></div>';
				} else {
					body = '<div class="full"><label>Commentaire</label><textarea name="comment"></textarea></div>';
				}
				break;
			default:
				body = '<div class="full"><label>Commentaire</label><textarea name="comment"></textarea></div>';
		}

		const o = overlay('<div class="recl-modal"><div class="recl-head"><h2>' + h(transitionLabel(r.status, to)) + '</h2><button class="x" data-close>✕</button></div>' +
			(help ? '<p style="color:var(--color-text-maxcontrast);margin:-6px 0 14px">' + h(help) + '</p>' : '') +
			'<form class="recl-form">' + body + '<div class="full recl-err"></div>' +
			'<div class="full recl-btns"><button type="button" data-close>Annuler</button><button type="submit" class="primary">Valider</button></div></form></div>', true);

		const form = o.querySelector('form');
		o.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => o.remove()));
		const stars = o.querySelector('[data-stars]');
		if (stars) {
			stars.addEventListener('click', (e) => {
				const n = e.target.dataset.star;
				if (!n) return;
				form.elements.namedItem("satisfaction").value = n;
				stars.querySelectorAll('button').forEach((b) => b.classList.toggle('on', +b.dataset.star <= +n));
			});
		}
		const first = form.querySelector('select, input:not([type=hidden]), textarea');
		if (first) first.focus();

		form.addEventListener('submit', async (e) => {
			e.preventDefault();
			const btn = form.querySelector('[type=submit]');
			btn.disabled = true;
			try {
				const data = formData(form);
				data.to = to;
				S.current = await api('/api/reclamations/' + r.id + '/transition', 'POST', data);
				o.remove();
				toast(r.ref + ' : ' + lab('statuses', S.current.status));
				renderDetail(false);
				refresh();
			} catch (err) {
				form.querySelector('.recl-err').textContent = err.message;
				btn.disabled = false;
			}
		});
	}

	// ------------------------------------------------------------ événements
	function bindRoot() {
		root.addEventListener('click', (e) => {
			const v = e.target.closest('[data-view]');
			if (v) {
				S.view = v.dataset.view;
				refresh();
				return;
			}
			const act = e.target.closest('[data-act]');
			if (act) {
				const a = act.dataset.act;
				if (a === 'new') openNew();
				if (a === 'export') window.location.href = BASE + '/api/export?' + queryString();
				if (a === 'export-all') window.location.href = BASE + '/api/export';
				return;
			}
			const row = e.target.closest('tr[data-id]');
			if (row) openDetail(+row.dataset.id);
		});

		let timer;
		root.addEventListener('input', (e) => {
			if (e.target.dataset.f === 'q') {
				clearTimeout(timer);
				timer = setTimeout(async () => {
					S.q = e.target.value;
					await refresh();
					const input = root.querySelector('[data-f=q]');
					if (input) { input.focus(); input.setSelectionRange(input.value.length, input.value.length); }
				}, 350);
			}
		});
		root.addEventListener('change', (e) => {
			const f = e.target.dataset.f;
			if (f === 'type' || f === 'urgency') {
				S[f] = e.target.value;
				refresh();
			}
		});
		document.addEventListener('keydown', (e) => {
			if (e.key !== 'Escape') return;
			const modal = document.querySelector('.recl-overlay.center');
			if (modal) modal.remove();
			else if (S.current) closeDetail();
		});
		window.addEventListener('hashchange', routeFromHash);
	}

	function routeFromHash() {
		const m = location.hash.match(/^#\/r\/(\d+)/);
		if (m && (!S.current || S.current.id !== +m[1])) openDetail(+m[1]);
	}

	async function init() {
		root = document.getElementById('recl-app');
		if (!root) return;
		try {
			S.meta = await api('/api/meta');
		} catch (e) {
			root.innerHTML = '<div class="recl-empty">Impossible de charger l\'application : ' + h(e.message) + '</div>';
			return;
		}
		bindRoot();
		await refresh();
		routeFromHash();
		setInterval(() => { if (!document.hidden && !S.current) refresh(); }, 60000);
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
