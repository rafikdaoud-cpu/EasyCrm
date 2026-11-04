/**
 * Call CRM — page publique de dépôt de documents (client, sur smartphone, sans compte).
 * Parcours : 📷 scanner chaque document (détection automatique, redressement, PDF),
 * « Envoyer et suivant », puis « 📤 Envoyer et terminer » : les documents partent dans
 * le dossier Nextcloud du conseiller. Galerie / PDF possibles pour chaque document.
 */
(function () {
    'use strict';
    const root = document.getElementById('crm-collect');
    if (!root) return;
    const API = root.dataset.api;
    let view = null, busy = false;

    function h(tag, attrs, ...children) {
        const e = document.createElement(tag);
        Object.entries(attrs || {}).forEach(([k, v]) => {
            if (k === 'class') e.className = v;
            else if (k.startsWith('on')) e.addEventListener(k.slice(2), v);
            else if (v !== false && v != null) e.setAttribute(k, v === true ? '' : v);
        });
        children.flat().forEach(c => { if (c != null && c !== false) e.append(c instanceof Node ? c : document.createTextNode(String(c))); });
        return e;
    }
    const size = (b) => b >= 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' Mo' : Math.max(1, Math.round(b / 1024)) + ' Ko';
    const date = (t) => new Date(t * 1000).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });

    async function call(path, method = 'GET', body = null, timeout = 30000) {
        const ctl = new AbortController(), timer = setTimeout(() => ctl.abort(), timeout);
        try {
            const r = await fetch(API + path, { method, body, credentials: 'same-origin', signal: ctl.signal, headers: { Accept: 'application/json' } });
            let data = {};
            try { data = await r.json(); } catch (e) { data = { error: 'Réponse inattendue du serveur.' }; }
            if (!r.ok) { const err = new Error(data.error || 'Erreur ' + r.status); err.state = data.state; err.status = r.status; throw err; }
            return data;
        } catch (e) {
            if (e.name === 'AbortError') throw new Error('Délai dépassé : vérifiez votre connexion et réessayez.');
            if (e instanceof TypeError) throw new Error('Connexion indisponible : vérifiez votre réseau (4G ou Wi-Fi) et réessayez.');
            throw e;
        } finally { clearTimeout(timer); }
    }

    function message(text, error = true) {
        const box = root.querySelector('.crm-collect-msg');
        if (!box) return;
        box.textContent = text; box.classList.toggle('is-error', error); box.hidden = !text;
        if (text) box.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    function final(icon, title, text) {
        root.replaceChildren(h('section', { class: 'crm-collect-final' }, h('div', { class: 'crm-collect-icon' }, icon), h('h1', {}, title), h('p', {}, text)));
    }

    async function load() {
        try { view = await call(''); render(); }
        catch (e) {
            if (e.state === 'submitted') final('✅', 'Documents déjà envoyés', e.message);
            else final('⚠️', 'Lien indisponible', e.message);
        }
    }

    /** PDF (tel quel) ou photos (assemblées en un PDF A4 sur le téléphone). */
    async function pdfFromFiles(files) {
        const pdfs = files.filter(f => f.type === 'application/pdf' || /\.pdf$/i.test(f.name));
        const images = files.filter(f => !pdfs.includes(f) && /^image\//.test(f.type));
        if (pdfs.length > 1 || (pdfs.length && images.length)) throw new Error('Choisissez un seul PDF, ou une ou plusieurs photos.');
        if (pdfs.length) return pdfs[0];
        if (!images.length) throw new Error('Format non pris en charge : photo ou PDF uniquement.');
        if (images.length > 10) throw new Error('10 photos maximum par document.');
        const pages = [];
        try {
            for (const f of images) pages.push(await window.CoordinationScan.photoToPage(f, false));
            return new Blob([window.CoordinationScan.buildPdf(pages)], { type: 'application/pdf' });
        } finally { pages.forEach(p => URL.revokeObjectURL(p.preview)); }
    }

    async function upload(index, blob) {
        if (blob.size > view.max_file) throw new Error('Document trop volumineux (' + size(blob.size) + ', 20 Mo maximum).');
        const form = new FormData();
        form.append('file', blob, 'document-' + (index + 1) + '.pdf');
        const r = await call('/doc/' + index, 'POST', form, 180000);
        view = r.view;
        return r;
    }

    function render() {
        const docs = view.documents;
        const required = docs.filter(d => d.required);
        const done = docs.filter(d => d.uploaded).length;
        const ready = required.every(d => d.uploaded) && done > 0;
        const missing = docs.filter(d => !d.uploaded);

        const list = docs.map(d => h('li', { class: 'crm-collect-doc' + (d.uploaded ? ' is-done' : ''), id: 'doc-' + d.index },
            h('div', { class: 'crm-collect-doc-head' },
                h('span', { class: 'crm-collect-state', 'aria-hidden': 'true' }, d.uploaded ? '✅' : (d.index + 1)),
                h('div', {}, h('strong', {}, d.label), h('small', {}, d.uploaded ? 'Envoyé · ' + size(d.size) : (d.required ? 'Obligatoire' : 'Facultatif')))),
            h('div', { class: 'crm-collect-doc-actions' },
                h('button', { type: 'button', class: 'crm-btn ' + (d.uploaded ? 'crm-btn-light' : 'crm-btn-primary'), onclick: () => guided(d.index) }, d.uploaded ? '📷 Refaire' : '📷 Scanner'),
                h('label', { class: 'crm-btn crm-btn-light crm-file' }, '🖼️ Galerie / PDF',
                    h('input', { type: 'file', accept: 'image/*,application/pdf,.pdf', multiple: true, onchange: async (e) => {
                        const files = Array.from(e.target.files || []); e.target.value = '';
                        if (!files.length || busy) return;
                        busy = true; message('⏳ Envoi de « ' + d.label + ' »…', false);
                        try { await upload(d.index, await pdfFromFiles(files)); render(); message('✅ « ' + d.label + ' » envoyé.', false); }
                        catch (err) { message(err.message); }
                        finally { busy = false; }
                    } })),
                d.uploaded ? h('button', { type: 'button', class: 'crm-btn crm-btn-link', onclick: async () => {
                    if (busy || !confirm('Retirer « ' + d.label + ' » ?')) return;
                    busy = true;
                    try { view = (await call('/doc/' + d.index + '/delete', 'POST')).view; render(); }
                    catch (err) { message(err.message); } finally { busy = false; }
                } }, 'Retirer') : null)));

        root.replaceChildren(
            h('header', { class: 'crm-collect-header' },
                h('h1', {}, '📄 Envoi de vos documents'),
                h('p', {}, 'Photographiez chaque document : la photo se prend toute seule quand le document est bien cadré. Lien valable jusqu’au ' + date(view.expires_at) + '.')),
            h('div', { class: 'crm-collect-progress', role: 'status' },
                (() => { const fill = h('span'); fill.style.width = Math.round(100 * done / docs.length) + '%'; return h('div', { class: 'crm-collect-bar', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': docs.length, 'aria-valuenow': done }, fill); })(),
                h('span', {}, done + ' / ' + docs.length + ' document' + (docs.length > 1 ? 's' : '') + ' envoyé' + (done > 1 ? 's' : ''))),
            missing.length ? h('button', { type: 'button', class: 'crm-btn crm-btn-primary crm-btn-big', onclick: () => guided(missing[0].index) }, done ? '📷 Continuer le scan' : '📷 Scanner mes documents') : null,
            h('p', { class: 'crm-collect-msg', role: 'status', 'aria-live': 'polite', hidden: true }),
            h('ol', { class: 'crm-collect-list' }, list),
            h('p', { class: 'crm-collect-tip' }, 'Conseil : posez le document à plat sur une surface sombre, bien éclairée, sans reflet.'),
            h('footer', { class: 'crm-collect-footer' },
                h('button', { type: 'button', class: 'crm-btn crm-btn-success crm-btn-big', disabled: !ready, onclick: submit },
                    ready ? '📤 Envoyer et terminer' : 'Documents obligatoires manquants : ' + required.filter(d => !d.uploaded).length)));
    }

    async function submit() {
        if (busy) return;
        const missingOptional = view.documents.filter(d => !d.uploaded && !d.required).length;
        if (!confirm('Envoyer vos documents à votre conseiller ?' + (missingOptional ? '\n' + missingOptional + ' document(s) facultatif(s) non fourni(s).' : '') + '\nVous ne pourrez plus les modifier ensuite.')) return;
        busy = true;
        const btn = root.querySelector('.crm-collect-footer button'); btn.disabled = true; btn.textContent = '⏳ Envoi en cours…';
        try {
            const r = await call('/submit', 'POST', null, 120000);
            final('✅', 'Merci !', r.count + ' document' + (r.count > 1 ? 's ont été transmis' : ' a été transmis') + ' à votre conseiller. Vous pouvez fermer cette page.');
        } catch (e) {
            busy = false; render(); message(e.message);
        }
    }

    /** Scanner guidé : document par document sans quitter la caméra. */
    function guided(startIndex) {
        const Scan = window.CoordinationDocScan;
        if (!Scan || !Scan.supported()) {
            message('Caméra indisponible dans ce navigateur : utilisez « 🖼️ Galerie / PDF » sous chaque document.');
            return;
        }
        const order = view.documents.map(d => d.index);
        const from = Math.max(0, order.indexOf(startIndex));
        // Le document choisi d'abord, puis les suivants encore manquants.
        const queue = [startIndex].concat(order.slice(from + 1).concat(order.slice(0, from)).filter(i => !view.documents[i].uploaded));
        let pos = 0, pages = [], sending = false, sent = 0;
        const doc = () => view.documents[queue[pos]];
        const release = () => { pages.forEach(p => URL.revokeObjectURL(p.preview)); pages = []; };

        const scanner = Scan.open({
            title: '', documentMode: false, maxPages: 10,
            onPage: async (canvas, documentMode) => { pages.push(await window.CoordinationScan.canvasToPage(canvas, documentMode)); review(); },
            onFallback: () => message('Caméra refusée : utilisez « 🖼️ Galerie / PDF » sous chaque document.'),
            onClose: () => { release(); render(); if (sent) message('✅ ' + sent + ' document' + (sent > 1 ? 's envoyés' : ' envoyé') + '.', false); },
        });

        function enter(i) {
            pos = i; release(); scanner.resetCount();
            scanner.setTitle('Document ' + (pos + 1) + '/' + queue.length + ' · ' + doc().label);
            scanner.resume();
        }
        function next() { if (pos + 1 < queue.length) enter(pos + 1); else finish(); }

        function review() {
            const d = doc(), last = pos + 1 >= queue.length;
            const msg = h('p', { class: 'co-guided-msg', role: 'status', 'aria-live': 'polite' });
            const actions = h('div', { class: 'co-guided-actions' },
                h('button', { type: 'button', class: 'co-guided-primary', onclick: async () => {
                    if (sending) return;
                    sending = true; actions.querySelectorAll('button').forEach(b => { b.disabled = true; });
                    msg.classList.remove('is-error'); msg.textContent = '⏳ Envoi…';
                    try {
                        const blob = new Blob([window.CoordinationScan.buildPdf(pages)], { type: 'application/pdf' });
                        await upload(d.index, blob);
                        sent++; if (navigator.vibrate) navigator.vibrate([30, 40, 30]);
                        sending = false; next();
                    } catch (e) {
                        sending = false; actions.querySelectorAll('button').forEach(b => { b.disabled = false; });
                        msg.classList.add('is-error'); msg.textContent = '⚠️ ' + e.message + ' Les pages restent prêtes : réessayez.';
                    }
                } }, last ? '✅ Envoyer ce document' : '✅ Envoyer et suivant'),
                pages.length < 10 ? h('button', { type: 'button', onclick: () => scanner.resume() }, '➕ Ajouter une page (verso)') : null,
                h('button', { type: 'button', onclick: () => { release(); scanner.resetCount(); scanner.resume(); } }, '↺ Reprendre la photo'),
                h('button', { type: 'button', class: 'co-guided-link', onclick: () => next() }, 'Passer ce document'));
            scanner.showPanel(h('div', { class: 'co-guided' },
                h('div', { class: 'co-guided-head' }, h('strong', {}, d.label), h('span', {}, ' · ' + pages.length + ' page' + (pages.length > 1 ? 's' : ''))),
                h('div', { class: 'co-guided-thumbs' }, pages.map((p, i) => h('figure', {},
                    h('img', { src: p.preview, alt: 'Page ' + (i + 1) }), h('figcaption', {}, 'Page ' + (i + 1))))),
                msg, actions));
        }

        function finish() {
            const missing = view.documents.filter(d => d.required && !d.uploaded);
            scanner.setTitle('Scan terminé');
            scanner.showPanel(h('div', { class: 'co-guided' },
                h('div', { class: 'co-guided-head' }, h('strong', {}, missing.length ? '⚠️ Documents obligatoires restants' : '✅ Tous les documents obligatoires sont envoyés')),
                missing.length ? h('ul', { class: 'co-guided-missing' }, missing.map(d => h('li', {}, d.label))) : h('p', {}, 'Dernière étape : appuyez sur « 📤 Envoyer et terminer ».'),
                h('div', { class: 'co-guided-actions' }, h('button', { type: 'button', class: 'co-guided-primary', onclick: () => scanner.close() }, 'Continuer'))));
        }

        enter(0);
    }

    load();
})();
