/**
 * Coordination — moteur OpenCV dans un Web Worker.
 *
 * OpenCV.js crée des fonctions à la volée (embind) et compile du WebAssembly : la politique
 * de sécurité (CSP) des pages Nextcloud l'interdit. Ce worker est un fichier statique servi
 * par le serveur web, sans cette CSP de page : OpenCV y fonctionne, et la détection ne
 * ralentit plus l'affichage de la caméra.
 *
 * Messages reçus : {type:'init', docscan, opencv} puis {type:'detect', id, w, h, buf}.
 * Messages émis : {type:'ready'}, {type:'error', message}, {type:'result', id, quad, error}.
 */
/* eslint-env worker */
let cv = null;
self.onmessage = async (event) => {
    const m = event.data || {};
    if (m.type === 'init') {
        try {
            importScripts(m.docscan);   // fonctions de détection partagées (self.CoordinationDocScan)
            importScripts(m.opencv);    // OpenCV.js (publie self.cv)
            if (!self.cv) throw new Error('module non publié');
            const ready = await self.CoordinationDocScan.whenReady(self.cv);
            cv = ready.cv;
            self.postMessage({ type: 'ready' });
        } catch (err) {
            const blocked = err && (err.name === 'EvalError' || /unsafe-eval|Content Security Policy|CompileError/i.test(String(err.message || err)));
            self.postMessage({ type: 'error', message: (blocked ? 'bloqué par la sécurité du serveur (CSP des fichiers .js) : ' : '') + (err && err.message ? err.message : String(err)) });
        }
        return;
    }
    if (m.type === 'detect') {
        let quad = null, error = null;
        try {
            if (!cv) throw new Error('OpenCV non prêt');
            quad = self.CoordinationDocScan.detectQuadCV(cv, new Uint8ClampedArray(m.buf), m.w, m.h);
        } catch (err) { error = err && err.message ? err.message : String(err); }
        self.postMessage({ type: 'result', id: m.id, quad, error });
    }
};
