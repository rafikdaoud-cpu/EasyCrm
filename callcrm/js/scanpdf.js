/**
 * Coordination — scan de pièces en PDF depuis le smartphone, sans bibliothèque externe.
 * Les photos sont réduites et recompressées en JPEG dans le navigateur, puis assemblées
 * en un PDF A4 (une photo par page, image JPEG embarquée telle quelle : /DCTDecode).
 */
(function (root) {
    'use strict';

    const A4 = { w: 595.28, h: 841.89 }; // points PDF
    const MARGIN = 18;
    const MAX_SIDE = 1800;   // px : lisible à l'écran et à l'impression, ~250-500 Ko/page
    const QUALITY = 0.72;

    function bytesOf(str) {
        const out = new Uint8Array(str.length);
        for (let i = 0; i < str.length; i++) out[i] = str.charCodeAt(i) & 0xff;
        return out;
    }

    /** pages : [{ jpeg: Uint8Array, width, height }] → Uint8Array (PDF 1.4) */
    function buildPdf(pages) {
        if (!pages || !pages.length) throw new Error('Aucune page à assembler');
        const chunks = []; let length = 0; const offsets = [];
        const push = (data) => { const b = typeof data === 'string' ? bytesOf(data) : data; chunks.push(b); length += b.length; };
        const object = (id, parts) => { offsets[id] = length; push(id + ' 0 obj\n'); parts.forEach(push); push('\nendobj\n'); };

        push('%PDF-1.4\n'); push(new Uint8Array([0x25, 0xE2, 0xE3, 0xCF, 0xD3, 0x0A]));
        const pageIds = pages.map((_, i) => 3 + i * 3);
        object(1, ['<< /Type /Catalog /Pages 2 0 R >>']);
        object(2, ['<< /Type /Pages /Kids [' + pageIds.map(id => id + ' 0 R').join(' ') + '] /Count ' + pages.length + ' >>']);

        pages.forEach((p, i) => {
            const pageId = 3 + i * 3, contentId = pageId + 1, imageId = pageId + 2;
            const landscape = p.width > p.height;
            const pw = landscape ? A4.h : A4.w, ph = landscape ? A4.w : A4.h;
            const scale = Math.min((pw - 2 * MARGIN) / p.width, (ph - 2 * MARGIN) / p.height);
            const dw = p.width * scale, dh = p.height * scale;
            const x = (pw - dw) / 2, y = (ph - dh) / 2;
            const stream = 'q ' + dw.toFixed(2) + ' 0 0 ' + dh.toFixed(2) + ' ' + x.toFixed(2) + ' ' + y.toFixed(2) + ' cm /Im0 Do Q';
            object(pageId, ['<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + pw.toFixed(2) + ' ' + ph.toFixed(2) + '] /Resources << /XObject << /Im0 ' + imageId + ' 0 R >> >> /Contents ' + contentId + ' 0 R >>']);
            object(contentId, ['<< /Length ' + stream.length + ' >>\nstream\n' + stream + '\nendstream']);
            object(imageId, ['<< /Type /XObject /Subtype /Image /Width ' + p.width + ' /Height ' + p.height + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' + p.jpeg.length + ' >>\nstream\n', p.jpeg, '\nendstream']);
        });

        const count = 3 + pages.length * 3;
        const xref = length;
        let table = 'xref\n0 ' + count + '\n0000000000 65535 f \n';
        for (let id = 1; id < count; id++) table += String(offsets[id]).padStart(10, '0') + ' 00000 n \n';
        push(table + 'trailer\n<< /Size ' + count + ' /Root 1 0 R >>\nstartxref\n' + xref + '\n%%EOF\n');

        const out = new Uint8Array(length); let pos = 0;
        chunks.forEach(c => { out.set(c, pos); pos += c.length; });
        return out;
    }

    async function decode(file) {
        // createImageBitmap applique l'orientation EXIF des photos de smartphone.
        if (root.createImageBitmap) {
            try { return await root.createImageBitmap(file, { imageOrientation: 'from-image' }); } catch (e) { /* repli <img> */ }
        }
        const url = URL.createObjectURL(file);
        try {
            const img = new Image();
            await new Promise((ok, ko) => { img.onload = ok; img.onerror = () => ko(new Error('Image illisible')); img.src = url; });
            return img;
        } finally { URL.revokeObjectURL(url); }
    }

    /**
     * Canvas → page JPEG réduite (côté long ≤ MAX_SIDE). Option « document » : niveaux
     * de gris et contraste renforcé, plus lisible et plus léger pour du texte.
     */
    async function canvasToPage(source, documentMode) {
        const w0 = source.width, h0 = source.height;
        if (!w0 || !h0) throw new Error('Image illisible');
        const ratio = Math.min(1, MAX_SIDE / Math.max(w0, h0));
        const width = Math.max(1, Math.round(w0 * ratio)), height = Math.max(1, Math.round(h0 * ratio));
        const canvas = document.createElement('canvas'); canvas.width = width; canvas.height = height;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, width, height);
        ctx.drawImage(source, 0, 0, width, height);
        if (documentMode) {
            const data = ctx.getImageData(0, 0, width, height), px = data.data;
            for (let i = 0; i < px.length; i += 4) {
                const g = 0.299 * px[i] + 0.587 * px[i + 1] + 0.114 * px[i + 2];
                const v = Math.max(0, Math.min(255, (g - 128) * 1.35 + 140));
                px[i] = px[i + 1] = px[i + 2] = v;
            }
            ctx.putImageData(data, 0, 0);
        }
        const blob = await new Promise((ok, ko) => canvas.toBlob(b => b ? ok(b) : ko(new Error('Compression impossible')), 'image/jpeg', QUALITY));
        canvas.width = canvas.height = 0; // libère la mémoire sur les petits téléphones
        return { jpeg: new Uint8Array(await blob.arrayBuffer()), width, height, preview: URL.createObjectURL(blob) };
    }

    /** Photo (fichier) → page JPEG, orientation EXIF appliquée. */
    async function photoToPage(file, documentMode) {
        const img = await decode(file);
        const w = img.width || img.naturalWidth, h = img.height || img.naturalHeight;
        const canvas = document.createElement('canvas'); canvas.width = w; canvas.height = h;
        canvas.getContext('2d').drawImage(img, 0, 0);
        if (img.close) img.close();
        try { return await canvasToPage(canvas, documentMode); } finally { canvas.width = canvas.height = 0; }
    }

    root.CoordinationScan = { buildPdf, photoToPage, canvasToPage, MAX_SIDE, QUALITY };
})(typeof window !== 'undefined' ? window : globalThis);
