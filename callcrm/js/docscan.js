/**
 * Coordination — scanner de documents en direct (option A, sans bibliothèque).
 *
 * Caméra arrière dans le navigateur, détection de la feuille à chaque image
 * (≈ 10 par seconde sur une version réduite à 320 px), cadre affiché en surimpression,
 * déclenchement automatique quand la feuille reste immobile ≈ 1 s, puis redressement
 * (perspective) de la photo en pleine résolution.
 *
 * Détection : niveaux de gris, flou, seuil d'Otsu, plus grande zone claire (ou sombre)
 * qui ne touche pas le bord, enveloppe convexe, quadrilatère d'aire maximale, contrôles
 * de forme. Fonctionne bien pour une feuille claire sur un fond plus sombre.
 */
(function (root) {
    'use strict';

    // Adresse de ce fichier, pour trouver js/vendor/opencv.js à côté, quel que soit le dossier d'apps.
    const SELF_SRC = (typeof document !== 'undefined' && document.currentScript && document.currentScript.src) || '';

    const DETECT_SIDE = 320;     // px de l'image d'analyse (détecteur rapide)
    const CV_DETECT_SIDE = 480;  // px de l'image d'analyse (OpenCV)
    const DETECT_EVERY = 100;    // ms entre deux analyses
    const STABLE_FRAMES = 8;     // ≈ 0,8 à 1 s immobile avant déclenchement
    const MOVE_TOLERANCE = 0.02; // déplacement max d'un coin (fraction de la largeur)
    const MAX_SIDE = 1800;       // px du côté long de la page redressée

    // ---------- Analyse d'image (fonctions pures, testées sous Node) ----------

    function toGray(rgba, w, h) {
        const g = new Uint8Array(w * h);
        for (let i = 0, j = 0; i < g.length; i++, j += 4) g[i] = (rgba[j] * 77 + rgba[j + 1] * 150 + rgba[j + 2] * 29) >> 8;
        return g;
    }

    function boxBlur(src, w, h, r) {
        const tmp = new Uint8Array(w * h), out = new Uint8Array(w * h), n = 2 * r + 1;
        for (let y = 0; y < h; y++) {
            const row = y * w; let acc = 0;
            for (let x = -r; x <= r; x++) acc += src[row + Math.min(w - 1, Math.max(0, x))];
            for (let x = 0; x < w; x++) {
                tmp[row + x] = acc / n;
                acc += src[row + Math.min(w - 1, x + r + 1)] - src[row + Math.max(0, x - r)];
            }
        }
        for (let x = 0; x < w; x++) {
            let acc = 0;
            for (let y = -r; y <= r; y++) acc += tmp[Math.min(h - 1, Math.max(0, y)) * w + x];
            for (let y = 0; y < h; y++) {
                out[y * w + x] = acc / n;
                acc += tmp[Math.min(h - 1, y + r + 1) * w + x] - tmp[Math.max(0, y - r) * w + x];
            }
        }
        return out;
    }

    function otsu(g) {
        const hist = new Float64Array(256);
        for (let i = 0; i < g.length; i++) hist[g[i]]++;
        let sum = 0; for (let i = 0; i < 256; i++) sum += i * hist[i];
        let sumB = 0, wB = 0, best = -1, t = 127;
        for (let i = 0; i < 256; i++) {
            wB += hist[i]; if (!wB) continue;
            const wF = g.length - wB; if (!wF) break;
            sumB += i * hist[i];
            const d = sumB / wB - (sum - sumB) / wF, between = wB * wF * d * d;
            if (between > best) { best = between; t = i; }
        }
        return t;
    }

    /** Plus grande composante (4-connexe) du masque qui ne touche pas le bord de l'image. */
    function innerComponent(mask, w, h) {
        const label = new Int32Array(w * h), stack = new Int32Array(w * h);
        let id = 0, best = null;
        for (let s = 0; s < w * h; s++) {
            if (!mask[s] || label[s]) continue;
            id++; let sp = 0, area = 0, touches = false;
            stack[sp++] = s; label[s] = id;
            while (sp) {
                const p = stack[--sp], x = p % w, y = (p - x) / w;
                area++;
                if (x === 0 || y === 0 || x === w - 1 || y === h - 1) touches = true;
                if (x > 0 && mask[p - 1] && !label[p - 1]) { label[p - 1] = id; stack[sp++] = p - 1; }
                if (x < w - 1 && mask[p + 1] && !label[p + 1]) { label[p + 1] = id; stack[sp++] = p + 1; }
                if (y > 0 && mask[p - w] && !label[p - w]) { label[p - w] = id; stack[sp++] = p - w; }
                if (y < h - 1 && mask[p + w] && !label[p + w]) { label[p + w] = id; stack[sp++] = p + w; }
            }
            if (!touches && (!best || area > best.area)) best = { id, area };
        }
        return best ? { label, id: best.id, area: best.area } : null;
    }

    function cross(o, a, b) { return (a[0] - o[0]) * (b[1] - o[1]) - (a[1] - o[1]) * (b[0] - o[0]); }

    function convexHull(points) {
        const p = points.slice().sort((a, b) => a[0] - b[0] || a[1] - b[1]);
        if (p.length < 3) return p;
        const lower = [], upper = [];
        for (const q of p) { while (lower.length >= 2 && cross(lower[lower.length - 2], lower[lower.length - 1], q) <= 0) lower.pop(); lower.push(q); }
        for (let i = p.length - 1; i >= 0; i--) { const q = p[i]; while (upper.length >= 2 && cross(upper[upper.length - 2], upper[upper.length - 1], q) <= 0) upper.pop(); upper.push(q); }
        return lower.slice(0, -1).concat(upper.slice(0, -1));
    }

    function polyArea(q) {
        let a = 0;
        for (let i = 0; i < q.length; i++) { const p = q[i], n = q[(i + 1) % q.length]; a += p[0] * n[1] - n[0] * p[1]; }
        return Math.abs(a) / 2;
    }

    function isConvex(q) {
        let sign = 0;
        for (let i = 0; i < 4; i++) {
            const c = cross(q[i], q[(i + 1) % 4], q[(i + 2) % 4]);
            if (c === 0) return false;
            if (sign && Math.sign(c) !== sign) return false;
            sign = Math.sign(c);
        }
        return true;
    }

    function angleAt(a, b, c) {
        const v1 = [a[0] - b[0], a[1] - b[1]], v2 = [c[0] - b[0], c[1] - b[1]];
        const cos = (v1[0] * v2[0] + v1[1] * v2[1]) / (Math.hypot(...v1) * Math.hypot(...v2));
        return Math.acos(Math.max(-1, Math.min(1, cos))) * 180 / Math.PI;
    }

    /** Quadrilatère d'aire maximale inscrit dans l'enveloppe : coins HG, HD, BD, BG. */
    function quadFromHull(hull) {
        const pick = (f) => hull.reduce((best, p) => f(p) > f(best) ? p : best, hull[0]);
        let q = [pick(p => -(p[0] + p[1])), pick(p => p[0] - p[1]), pick(p => p[0] + p[1]), pick(p => p[1] - p[0])];
        let area = isConvex(q) ? polyArea(q) : 0;
        for (let iter = 0; iter < 3; iter++) {
            let improved = false;
            for (let i = 0; i < 4; i++) {
                for (const p of hull) {
                    const cand = q.slice(); cand[i] = p;
                    if (!isConvex(cand)) continue;
                    const a = polyArea(cand);
                    if (a > area + 1e-9) { q = cand; area = a; improved = true; }
                }
            }
            if (!improved) break;
        }
        return area > 0 ? q : null;
    }

    function tryPolarity(blur, w, h, t, bright) {
        const mask = new Uint8Array(w * h);
        for (let i = 0; i < mask.length; i++) mask[i] = bright ? blur[i] > t : blur[i] < t;
        const comp = innerComponent(mask, w, h);
        if (!comp || comp.area < 0.08 * w * h) return null;
        const pts = [];
        for (let y = 0; y < h; y++) {
            let first = -1, last = -1;
            for (let x = 0; x < w; x++) if (comp.label[y * w + x] === comp.id) { if (first < 0) first = x; last = x; }
            if (first >= 0) { pts.push([first, y]); if (last !== first) pts.push([last, y]); }
        }
        const hull = convexHull(pts);
        if (hull.length < 4) return null;
        const q = quadFromHull(hull);
        if (!q) return null;
        const qa = polyArea(q), ha = polyArea(hull);
        if (!validQuad(q, w, h)) return null;
        if (qa / ha < 0.88) return null;                         // la zone n'est pas un quadrilatère
        if (comp.area / qa < 0.55) return null;                  // trop de trous : pas une feuille
        return { quad: q, score: comp.area };
    }

    /** Contrôles de forme communs aux deux moteurs : taille, angles, côtés, convexité. */
    function validQuad(q, w, h) {
        if (!q || q.length !== 4 || !isConvex(q)) return false;
        const qa = polyArea(q);
        if (qa < 0.1 * w * h || qa > 0.97 * w * h) return false; // trop petit / toute l'image
        for (let i = 0; i < 4; i++) {
            const ang = angleAt(q[(i + 3) % 4], q[i], q[(i + 1) % 4]);
            if (ang < 45 || ang > 135) return false;
            if (Math.hypot(q[i][0] - q[(i + 1) % 4][0], q[i][1] - q[(i + 1) % 4][1]) < 0.15 * Math.min(w, h)) return false;
        }
        return true;
    }

    /** Ordonne 4 points en HG, HD, BD, BG (sens horaire à l'écran, HG = plus petit x + y). */
    function orderCorners(pts) {
        const cx = pts.reduce((a, p) => a + p[0], 0) / 4, cy = pts.reduce((a, p) => a + p[1], 0) / 4;
        const sorted = pts.slice().sort((a, b) => Math.atan2(a[1] - cy, a[0] - cx) - Math.atan2(b[1] - cy, b[0] - cx));
        let start = 0;
        sorted.forEach((p, i) => { if (p[0] + p[1] < sorted[start][0] + sorted[start][1]) start = i; });
        return sorted.slice(start).concat(sorted.slice(0, start));
    }

    // ---------- Option B : moteur OpenCV.js (chargé à la demande) ----------

    /**
     * Détection par contours : flou, Canny, dilatation, contours, polygone à 4 sommets
     * convexe le plus grand. Repère une feuille même sur un fond clair, tant que son bord
     * forme un contour. rgba : données RGBA (ImageData.data).
     */
    function detectQuadCV(cv, rgba, w, h) {
        const mats = [];
        const keep = (m) => (mats.push(m), m);
        try {
            const src = keep(cv.matFromImageData({ data: rgba, width: w, height: h }));
            const gray = keep(new cv.Mat()), blur = keep(new cv.Mat()), edges = keep(new cv.Mat());
            cv.cvtColor(src, gray, cv.COLOR_RGBA2GRAY);
            cv.GaussianBlur(gray, blur, new cv.Size(5, 5), 0);
            const kernel = keep(cv.getStructuringElement(cv.MORPH_RECT, new cv.Size(3, 3)));
            let best = null, bestArea = 0;
            // Deux réglages de Canny : contraste normal, puis faible (feuille claire sur fond clair).
            for (const [lo, hi] of [[50, 150], [15, 45]]) {
                cv.Canny(blur, edges, lo, hi);
                cv.dilate(edges, edges, kernel);
                const contours = keep(new cv.MatVector()), hier = keep(new cv.Mat());
                cv.findContours(edges, contours, hier, cv.RETR_LIST, cv.CHAIN_APPROX_SIMPLE);
                for (let i = 0; i < contours.size(); i++) {
                    const c = contours.get(i);
                    const area = cv.contourArea(c);
                    if (area > bestArea && area > 0.1 * w * h) {
                        const approx = new cv.Mat();
                        cv.approxPolyDP(c, approx, 0.02 * cv.arcLength(c, true), true);
                        if (approx.rows === 4 && cv.isContourConvex(approx)) {
                            const d = approx.data32S;
                            const q = orderCorners([[d[0], d[1]], [d[2], d[3]], [d[4], d[5]], [d[6], d[7]]]);
                            if (validQuad(q, w, h) && q.every(p => p[0] > 1 && p[1] > 1 && p[0] < w - 2 && p[1] < h - 2)) { best = q; bestArea = area; }
                        }
                        approx.delete();
                    }
                    c.delete();
                }
                if (best) break;
            }
            return best;
        } finally {
            mats.forEach(m => { try { m.delete(); } catch (e) { /* déjà libéré */ } });
        }
    }

    /**
     * Attend l'initialisation du module OpenCV. Attention : le module expose un « then »
     * qui se résout sur lui-même ; un await direct bouclerait sans fin. On attend donc
     * onRuntimeInitialized et on ne transmet le module qu'enveloppé ({ cv }).
     */
    function whenReady(cv) {
        return new Promise((ok, ko) => {
            if (!cv) { ko(new Error('OpenCV absent')); return; }
            if (cv.Mat) { ok({ cv }); return; }
            const previous = cv.onRuntimeInitialized;
            cv.onRuntimeInitialized = () => { if (previous) previous(); ok({ cv }); };
        });
    }

    let enginePromise = null;
    /**
     * Démarre le moteur OpenCV dans un Web Worker (js/docscan-worker.js) : la CSP des pages
     * Nextcloud interdit « eval » et la compilation WASM dont OpenCV.js a besoin, pas celle
     * du worker servi comme fichier statique. Résout { detect(rgba, w, h) → Promise<quad> },
     * ou rejette avec un message lisible (worker refusé, fichier absent, CSP, délai).
     */
    function loadEngine(urls) {
        if (enginePromise) return enginePromise;
        enginePromise = new Promise((resolve, reject) => {
            let worker, settled = false, seq = 0;
            const pending = new Map();
            const fail = (message) => { if (settled) return; settled = true; clearTimeout(timer); try { worker && worker.terminate(); } catch (e) { /* déjà arrêté */ } reject(new Error(message)); };
            const timer = setTimeout(() => fail('délai dépassé (réseau lent ?)'), 120000);
            try { worker = new Worker(urls.worker); }
            catch (err) { fail('worker refusé par la sécurité de la page (CSP worker-src) : ' + (err.message || err)); return; }
            worker.onerror = (e) => {
                e.preventDefault && e.preventDefault();
                if (!settled) {
                    fetch(urls.worker, { method: 'HEAD', credentials: 'same-origin' })
                        .then(r => fail(r.ok ? 'worker refusé (' + (e.message || 'CSP worker-src ?') + ')' : 'worker introuvable (HTTP ' + r.status + ')'))
                        .catch(() => fail('worker inaccessible'));
                } else {
                    pending.forEach(p => p(null)); pending.clear();
                }
            };
            worker.onmessage = (e) => {
                const m = e.data || {};
                if (m.type === 'ready' && !settled) {
                    settled = true; clearTimeout(timer);
                    resolve({
                        detect(rgba, w, h) {
                            return new Promise((done) => {
                                const id = ++seq; pending.set(id, done);
                                const buf = rgba.buffer.byteLength === rgba.length ? rgba.buffer : rgba.slice().buffer;
                                worker.postMessage({ type: 'detect', id, w, h, buf }, [buf]);
                            });
                        },
                    });
                } else if (m.type === 'error') {
                    // Fichier OpenCV absent : le worker remonte l'échec d'importScripts ; on précise le code HTTP.
                    fetch(urls.opencv, { method: 'HEAD', credentials: 'same-origin' })
                        .then(r => fail(r.ok ? m.message : 'fichier introuvable (HTTP ' + r.status + ')'))
                        .catch(() => fail(m.message));
                } else if (m.type === 'result') {
                    const done = pending.get(m.id); pending.delete(m.id);
                    if (done) done(m.error ? null : m.quad);
                }
            };
            worker.postMessage({ type: 'init', docscan: urls.docscan, opencv: urls.opencv });
        });
        enginePromise.catch(() => { enginePromise = null; });
        return enginePromise;
    }

    /** Adresses du worker, de ce script et d'OpenCV, déduites de l'emplacement de docscan.js. */
    function engineUrls() {
        const cfg = root.CoordinationDocScanConfig || {};
        const opencv = opencvUrl();
        if (!opencv) return null;
        const base = opencv.replace(/vendor\/opencv\.js(\?.*)?$/, '');
        return { opencv, worker: cfg.workerUrl || base + 'docscan-worker.js', docscan: cfg.docscanUrl || SELF_SRC || base + 'docscan.js' };
    }

    function opencvUrl() {
        const cfg = root.CoordinationDocScanConfig || {};
        if (cfg.opencvUrl !== undefined) return cfg.opencvUrl;
        if (SELF_SRC && /\/js\/docscan\.js(\?|$)/.test(SELF_SRC)) return SELF_SRC.replace(/\/js\/docscan\.js(\?.*)?$/, '/js/vendor/opencv.js');
        if (root.OC && OC.appswebroots && OC.appswebroots.coordination) return OC.appswebroots.coordination + '/js/vendor/opencv.js';
        if (root.OC && OC.linkTo) return OC.linkTo('coordination', 'js/vendor/opencv.js');
        return null;
    }

    /** Coins [[x,y]×4] (HG, HD, BD, BG) de la feuille dans l'image grise, ou null. */
    function detectQuad(gray, w, h) {
        const blur = boxBlur(boxBlur(gray, w, h, 2), w, h, 2);
        const t = otsu(blur);
        const bright = tryPolarity(blur, w, h, t, true);
        const dark = bright ? null : tryPolarity(blur, w, h, t, false);
        const found = bright || dark;
        return found ? found.quad : null;
    }

    /** Homographie (h33 = 1) envoyant les 4 points from[i] sur to[i]. */
    function homography(from, to) {
        const A = [], b = [];
        for (let i = 0; i < 4; i++) {
            const [x, y] = from[i], [u, v] = to[i];
            A.push([x, y, 1, 0, 0, 0, -u * x, -u * y]); b.push(u);
            A.push([0, 0, 0, x, y, 1, -v * x, -v * y]); b.push(v);
        }
        for (let c = 0; c < 8; c++) {
            let piv = c;
            for (let r = c + 1; r < 8; r++) if (Math.abs(A[r][c]) > Math.abs(A[piv][c])) piv = r;
            [A[c], A[piv]] = [A[piv], A[c]]; [b[c], b[piv]] = [b[piv], b[c]];
            if (Math.abs(A[c][c]) < 1e-12) throw new Error('Points dégénérés');
            for (let r = c + 1; r < 8; r++) {
                const f = A[r][c] / A[c][c];
                for (let k = c; k < 8; k++) A[r][k] -= f * A[c][k];
                b[r] -= f * b[c];
            }
        }
        const h = new Array(8);
        for (let r = 7; r >= 0; r--) {
            let s = b[r];
            for (let k = r + 1; k < 8; k++) s -= A[r][k] * h[k];
            h[r] = s / A[r][r];
        }
        return h.concat(1);
    }

    function applyH(H, x, y) {
        const d = H[6] * x + H[7] * y + H[8];
        return [(H[0] * x + H[1] * y + H[2]) / d, (H[3] * x + H[4] * y + H[5]) / d];
    }

    /** Taille de la page redressée d'après les longueurs des côtés, plafonnée. */
    function outputSize(q, maxSide) {
        const d = (a, b) => Math.hypot(a[0] - b[0], a[1] - b[1]);
        let W = Math.max(d(q[0], q[1]), d(q[3], q[2])), H = Math.max(d(q[0], q[3]), d(q[1], q[2]));
        const s = Math.min(1, maxSide / Math.max(W, H));
        return [Math.max(1, Math.round(W * s)), Math.max(1, Math.round(H * s))];
    }

    /** Redresse (interpolation bilinéaire) la zone quad de l'image RGBA src en W×H. */
    function warpPixels(src, sw, sh, quad, W, H) {
        const out = new Uint8ClampedArray(W * H * 4);
        const M = homography([[0, 0], [W, 0], [W, H], [0, H]], quad);
        for (let v = 0; v < H; v++) {
            for (let u = 0; u < W; u++) {
                const d = M[6] * (u + 0.5) + M[7] * (v + 0.5) + 1;
                let x = (M[0] * (u + 0.5) + M[1] * (v + 0.5) + M[2]) / d - 0.5;
                let y = (M[3] * (u + 0.5) + M[4] * (v + 0.5) + M[5]) / d - 0.5;
                x = Math.max(0, Math.min(sw - 1.001, x)); y = Math.max(0, Math.min(sh - 1.001, y));
                const x0 = x | 0, y0 = y | 0, fx = x - x0, fy = y - y0;
                const i00 = (y0 * sw + x0) * 4, i10 = i00 + 4, i01 = i00 + sw * 4, i11 = i01 + 4;
                const o = (v * W + u) * 4;
                for (let c = 0; c < 3; c++) {
                    const top = src[i00 + c] + (src[i10 + c] - src[i00 + c]) * fx;
                    const bot = src[i01 + c] + (src[i11 + c] - src[i01 + c]) * fx;
                    out[o + c] = top + (bot - top) * fy;
                }
                out[o + 3] = 255;
            }
        }
        return out;
    }

    // ---------- Interface caméra (navigateur) ----------

    function supported() {
        return !!(root.isSecureContext && root.navigator && navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    }

    function el(tag, cls, text) {
        const e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text !== undefined) e.textContent = text;
        return e;
    }

    /**
     * Ouvre le scanner plein écran.
     * opts : { title, documentMode, maxPages, onPage(canvas) → Promise, onFallback(), onDocMode(bool), onClose(count) }
     */
    /** Textes de l'écran du scanner (français par défaut ; opts.text remplace tout ou partie, ex. arabe). */
    const TEXT = {
        dialog: 'Scanner de document', title: 'Scan', close: 'Fermer le scanner', opening: 'Ouverture de la caméra…',
        shutter: 'Prendre la photo maintenant', engineOpenCV: 'Détection OpenCV', engineLoading: 'Détection rapide · chargement OpenCV…',
        engineLight: 'Détection rapide', engineError: (e) => 'Détection rapide · OpenCV ' + e,
        pages: (n) => n + ' page' + (n > 1 ? 's' : ''), bw: (on) => on ? '◐ N&B : oui' : '◐ N&B : non', auto: (on) => on ? '⚡ Auto : oui' : '⚡ Auto : non',
        done: (n) => n ? `Terminer (${n})` : 'Terminer', fallback: '📷 Utiliser l’appareil photo du téléphone', retry: '↻ Réessayer',
        denied: 'Accès à la caméra refusé. Autorisez la caméra pour ce site dans les réglages du navigateur, ou utilisez l’appareil photo du téléphone.',
        unavailable: 'Caméra indisponible sur cet appareil ou ce navigateur.', maxPages: (n) => `${n} pages maximum : appuyez sur Terminer.`,
        place: 'Posez la pièce à plat sur un fond sombre, entièrement visible.', taken: '✓ Page prise. Retournez la pièce pour le verso, ou Terminer.',
        detectedManual: 'Document détecté : appuyez sur le déclencheur.', hold: 'Document détecté : ne bougez plus…', shooting: 'Prise de vue…',
        captureFail: 'Prise de vue impossible : réessayez.',
    };

    function open(opts) {
        const T = Object.assign({}, TEXT, opts.text || {});
        const maxPages = Math.max(0, opts.maxPages ?? 10);
        let stream = null, timer = 0, closed = false, capturing = false, count = 0;
        let auto = true, docMode = !!opts.documentMode;
        let lastQuad = null, stable = 0, armed = true, lostFrames = 0, capturedQuad = null;
        let engine = null, engineState = 'light', engineError = '', detecting = false;
        const urls = engineUrls();

        const overlay = el('div', 'co-docscan');
        overlay.setAttribute('role', 'dialog'); overlay.setAttribute('aria-modal', 'true'); overlay.setAttribute('aria-label', T.dialog);
        if (opts.dir) overlay.setAttribute('dir', opts.dir);
        if (opts.lang) overlay.setAttribute('lang', opts.lang);
        const top = el('div', 'co-docscan-top');
        const title = el('div', 'co-docscan-title');
        const titleText = el('span', 'co-docscan-title-text', opts.title || T.title);
        title.appendChild(titleText);
        const counter = el('span', 'co-docscan-count', '');
        title.appendChild(counter);
        const docBtn = el('button', 'co-docscan-chip', ''); docBtn.type = 'button';
        const engineTag = el('span', 'co-docscan-engine', '');
        const closeBtn = el('button', 'co-docscan-close', '✕'); closeBtn.type = 'button'; closeBtn.setAttribute('aria-label', T.close);
        top.append(title, docBtn, closeBtn);
        title.appendChild(engineTag);

        const stage = el('div', 'co-docscan-stage');
        const video = el('video', 'co-docscan-video');
        video.setAttribute('playsinline', ''); video.setAttribute('muted', ''); video.muted = true; video.setAttribute('autoplay', '');
        const canvas = el('canvas', 'co-docscan-overlay');
        const hint = el('div', 'co-docscan-hint', T.opening); hint.setAttribute('role', 'status'); hint.setAttribute('aria-live', 'polite');
        const flash = el('div', 'co-docscan-flash');
        const error = el('div', 'co-docscan-error'); error.hidden = true;
        // Panneau de vérification (mode guidé) : aperçu, valider, suivant… posé sur la caméra.
        const panel = el('div', 'co-docscan-panel'); panel.hidden = true;
        stage.append(video, canvas, hint, flash, error, panel);
        let paused = false;

        const bottom = el('div', 'co-docscan-bottom');
        const autoBtn = el('button', 'co-docscan-chip', ''); autoBtn.type = 'button';
        const shutter = el('button', 'co-docscan-shutter', ''); shutter.type = 'button'; shutter.setAttribute('aria-label', T.shutter);
        const doneBtn = el('button', 'co-docscan-done', ''); doneBtn.type = 'button';
        bottom.append(autoBtn, shutter, doneBtn);
        overlay.append(top, stage, bottom);

        const detectCanvas = document.createElement('canvas');
        const detectCtx = detectCanvas.getContext('2d', { willReadFrequently: true });

        const refreshButtons = () => {
            engineTag.textContent = engineState === 'opencv' ? T.engineOpenCV : engineState === 'loading' ? T.engineLoading
                : engineError ? T.engineError(engineError) : T.engineLight;
            counter.textContent = count ? ' · ' + T.pages(count) : '';
            docBtn.textContent = T.bw(docMode);
            docBtn.setAttribute('aria-pressed', String(docMode));
            autoBtn.textContent = T.auto(auto);
            autoBtn.setAttribute('aria-pressed', String(auto));
            doneBtn.textContent = T.done(count);
            shutter.disabled = capturing || count >= maxPages || !stream;
        };
        const say = (text) => { if (hint.textContent !== text) hint.textContent = text; };

        function stop() {
            clearInterval(timer); timer = 0;
            if (stream) stream.getTracks().forEach(t => t.stop());
            stream = null; video.srcObject = null;
        }

        function close() {
            if (closed) return;
            closed = true; stop();
            document.removeEventListener('visibilitychange', onVisibility);
            overlay.remove();
            document.documentElement.classList.remove('co-docscan-open');
            if (opts.onClose) opts.onClose(count);
        }

        function showError(message) {
            stop();
            error.replaceChildren();
            const p = el('p', '', message);
            const fallback = el('button', 'co-docscan-fallback', T.fallback); fallback.type = 'button';
            fallback.addEventListener('click', () => { const cb = opts.onFallback; close(); if (cb) cb(); });
            const retry = el('button', 'co-docscan-chip', T.retry); retry.type = 'button';
            retry.addEventListener('click', () => { error.hidden = true; start(); });
            error.append(p, fallback, retry);
            error.hidden = false; hint.hidden = true;
            refreshButtons();
        }

        async function start() {
            hint.hidden = false; say(T.opening);
            try {
                stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } } });
            } catch (err) {
                const denied = err && (err.name === 'NotAllowedError' || err.name === 'SecurityError');
                showError(denied ? T.denied : T.unavailable);
                return;
            }
            if (closed) { stop(); return; }
            video.srcObject = stream;
            try { await video.play(); } catch (e) { /* lecture auto déjà en cours */ }
            refreshButtons();
            timer = setInterval(tick, DETECT_EVERY);
            if (!urls && !engineError) { engineError = 'introuvable (adresse inconnue)'; refreshButtons(); }
            if (urls && !engine && engineState !== 'loading') {
                engineState = 'loading'; refreshButtons();
                loadEngine(urls).then((lib) => { if (closed) return; engine = lib; engineState = 'opencv'; stable = 0; lastQuad = null; refreshButtons(); })
                    .catch((err) => { console.warn('OpenCV indisponible, détection rapide conservée :', err); engineState = 'light'; engineError = err && err.message ? err.message : 'indisponible'; refreshButtons(); });
            }
        }

        function videoRect() {
            const cw = stage.clientWidth, ch = stage.clientHeight, vw = video.videoWidth, vh = video.videoHeight;
            const s = Math.min(cw / vw, ch / vh);
            return { x: (cw - vw * s) / 2, y: (ch - vh * s) / 2, s, cw, ch };
        }

        function draw(quad, progress) {
            if (!video.videoWidth) return;
            const dpr = root.devicePixelRatio || 1;
            const r = videoRect();
            if (canvas.width !== Math.round(r.cw * dpr) || canvas.height !== Math.round(r.ch * dpr)) { canvas.width = Math.round(r.cw * dpr); canvas.height = Math.round(r.ch * dpr); }
            const ctx = canvas.getContext('2d');
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            ctx.clearRect(0, 0, r.cw, r.ch);
            if (!quad) return;
            const k = video.videoWidth / detectCanvas.width;
            ctx.beginPath();
            quad.forEach((p, i) => { const x = r.x + p[0] * k * r.s, y = r.y + p[1] * k * r.s; i ? ctx.lineTo(x, y) : ctx.moveTo(x, y); });
            ctx.closePath();
            const ok = progress >= 1;
            ctx.fillStyle = ok ? 'rgba(34,197,94,.28)' : 'rgba(250,204,21,.18)';
            ctx.strokeStyle = ok || progress > 0.5 ? '#22c55e' : '#facc15';
            ctx.lineWidth = 4; ctx.fill(); ctx.stroke();
        }

        function moved(a, b, w) {
            let m = 0;
            for (let i = 0; i < 4; i++) m = Math.max(m, Math.hypot(a[i][0] - b[i][0], a[i][1] - b[i][1]));
            return m / w;
        }

        function tick() {
            if (closed || capturing || paused || !video.videoWidth) return;
            if (engine && detecting) return; // une analyse OpenCV est déjà en cours dans le worker
            const k = (engine ? CV_DETECT_SIDE : DETECT_SIDE) / Math.max(video.videoWidth, video.videoHeight);
            const w = Math.round(video.videoWidth * k), h = Math.round(video.videoHeight * k);
            if (detectCanvas.width !== w || detectCanvas.height !== h) { detectCanvas.width = w; detectCanvas.height = h; }
            detectCtx.drawImage(video, 0, 0, w, h);
            const rgba = detectCtx.getImageData(0, 0, w, h).data;
            if (engine) {
                detecting = true;
                engine.detect(rgba, w, h).then((quad) => { detecting = false; if (!closed && !capturing && !paused) handle(quad, w); }, () => { detecting = false; });
                return;
            }
            handle(detectQuad(toGray(rgba, w, h), w, h), w);
        }

        /** Suite commune aux deux moteurs : stabilité, cadre, consignes, déclenchement. */
        function handle(quad, w) {
            if (w !== detectCanvas.width) return; // résultat d'une analyse à une autre échelle
            if (quad && lastQuad && lastQuad.w !== w) lastQuad = null; // changement de moteur : autre échelle
            if (quad) quad.w = w;

            if (!quad) { lostFrames++; stable = 0; lastQuad = null; } else { lostFrames = 0; }
            if (quad && lastQuad && moved(quad, lastQuad, w) < MOVE_TOLERANCE) stable++; else if (quad) stable = 0;
            if (quad) lastQuad = quad;
            // Après une prise, attendre que la feuille change (retournée, retirée) avant de redéclencher.
            if (!armed && (lostFrames >= 3 || (quad && capturedQuad && moved(quad, capturedQuad, w) > 0.1))) armed = true;

            const progress = Math.min(1, stable / STABLE_FRAMES);
            draw(quad, auto && armed ? progress : 0);
            if (count >= maxPages) say(T.maxPages(maxPages));
            else if (!quad) say(armed ? T.place : T.taken);
            else if (!auto) say(T.detectedManual);
            else if (!armed) say(T.taken);
            else say(progress < 1 ? T.hold : T.shooting);

            if (auto && armed && quad && stable >= STABLE_FRAMES && count < maxPages) capture(quad);
        }

        async function capture(detectedQuad) {
            if (capturing || closed || !video.videoWidth) return;
            capturing = true; refreshButtons();
            try {
                const vw = video.videoWidth, vh = video.videoHeight;
                const frame = document.createElement('canvas'); frame.width = vw; frame.height = vh;
                const fctx = frame.getContext('2d', { willReadFrequently: true });
                fctx.drawImage(video, 0, 0, vw, vh);
                flash.classList.remove('is-on'); void flash.offsetWidth; flash.classList.add('is-on');
                if (root.navigator && navigator.vibrate) navigator.vibrate(40);
                let page;
                if (detectedQuad) {
                    const k = vw / detectCanvas.width;
                    const quad = detectedQuad.map(p => [p[0] * k, p[1] * k]);
                    const [W, H] = outputSize(quad, MAX_SIDE);
                    const pixels = warpPixels(fctx.getImageData(0, 0, vw, vh).data, vw, vh, quad, W, H);
                    page = document.createElement('canvas'); page.width = W; page.height = H;
                    page.getContext('2d').putImageData(new ImageData(pixels, W, H), 0, 0);
                } else {
                    page = frame; // déclenchement manuel sans détection : image entière
                }
                if (page !== frame) frame.width = frame.height = 0; // libère la mémoire
                await opts.onPage(page, docMode);
                count++;
                capturedQuad = detectedQuad; armed = false; stable = 0;
            } catch (err) {
                console.error('DocScan capture:', err);
                say(T.captureFail);
            } finally {
                capturing = false; refreshButtons();
            }
        }

        function onVisibility() {
            if (closed) return;
            if (document.hidden) stop();
            else if (!stream && error.hidden) start();
        }

        closeBtn.addEventListener('click', close);
        doneBtn.addEventListener('click', close);
        shutter.addEventListener('click', () => capture(lastQuad));
        autoBtn.addEventListener('click', () => { auto = !auto; stable = 0; refreshButtons(); });
        docBtn.addEventListener('click', () => { docMode = !docMode; if (opts.onDocMode) opts.onDocMode(docMode); refreshButtons(); });
        overlay.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
        document.addEventListener('visibilitychange', onVisibility);

        document.documentElement.classList.add('co-docscan-open');
        document.body.appendChild(overlay);
        refreshButtons();
        closeBtn.focus();
        start();
        return {
            close,
            /** Suspend l'analyse (la vidéo continue : la reprise est immédiate). */
            pause() { paused = true; draw(null, 0); },
            resume() {
                paused = false; stable = 0; lastQuad = null; capturedQuad = null; armed = true;
                panel.hidden = true; panel.replaceChildren(); hint.hidden = false; bottom.hidden = false;
                refreshButtons();
            },
            setTitle(text) { titleText.textContent = text; },
            resetCount() { count = 0; refreshButtons(); },
            showPanel(node) { paused = true; draw(null, 0); panel.replaceChildren(node); panel.hidden = false; hint.hidden = true; bottom.hidden = true; },
            isClosed() { return closed; },
        };
    }

    root.CoordinationDocScan = { supported, open, opencvUrl, engineUrls, detectQuad, detectQuadCV, loadEngine, whenReady, orderCorners, validQuad, homography, applyH, warpPixels, outputSize, toGray, DETECT_SIDE };
})(typeof window !== 'undefined' ? window : globalThis);
