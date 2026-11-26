/* Keep moderator edits local to the form until Update is pressed.
 * Frame detection adapted from devichan js/mod/edit-post.js (2ad3162).
 * Copyright (c) 2022-2023 dead-guru. See the license notices distributed with Dead Paint.
 */
(function () {
    'use strict';
    const en = window.nichan_lang === 'en';

    // Canvas would silently discard all but one frame. Read GIF block boundaries and the
    // animation headers of PNG/WebP before offering to paint over an existing file.
    async function isStill(blob) {
        let data = new Uint8Array(await blob.slice(0, 24).arrayBuffer());
        const tag = (at, len) => String.fromCharCode(...data.subarray(at, at + len));
        if (data[0] === 0xff && data[1] === 0xd8 || tag(0, 2) === 'BM') return true;
        if (tag(0, 4) === 'RIFF' && tag(8, 4) === 'WEBP')
            return tag(12, 4) !== 'VP8X' || !(data[20] & 2);
        data = new Uint8Array(await blob.arrayBuffer());
        if (tag(1, 3) === 'PNG') {
            const view = new DataView(data.buffer);
            for (let at = 8; at + 8 <= data.length; at += view.getUint32(at) + 12) {
                if (tag(at + 4, 4) === 'acTL') return false;
                if (tag(at + 4, 4) === 'IDAT') return true;
            }
        }
        if (tag(0, 3) === 'GIF') {
            let at = 13 + (data[10] & 128 ? 3 * (2 << (data[10] & 7)) : 0);
            let frames = 0;
            while (at < data.length) {
                const block = data[at++];
                if (block === 0x3b) return frames === 1;
                if (block === 0x2c) {
                    if (++frames > 1) return false;
                    const flags = data[at + 8];
                    at += 10 + (flags & 128 ? 3 * (2 << (flags & 7)) : 0);
                } else if (block === 0x21) at++;
                else return false;
                while (at < data.length && data[at]) at += data[at] + 1;
                at++;
            }
        }
        return false;
    }

    document.querySelectorAll('.mod-image').forEach(row => {
        const input = row.querySelector('input[type=file]');
        const button = row.querySelector('.mod-paint');
        const reset = row.querySelector('.mod-reset');
        const status = row.querySelector('.mod-pending');
        const preview = row.querySelector('.mod-preview');
        const link = preview.parentNode;
        const originalPreview = preview.src;
        const canPaint = typeof DataTransfer === 'function' && typeof window.nichanLoadPaint === 'function';
        let pendingUrl;

        function updatePreview() {
            if (pendingUrl) URL.revokeObjectURL(pendingUrl);
            const file = input.files[0];
            pendingUrl = file ? URL.createObjectURL(file) : null;
            preview.src = pendingUrl || originalPreview;
            link.href = pendingUrl || row.dataset.source;
            reset.hidden = !file;
            button.hidden = !canPaint;
            status.textContent = file ? (en ? 'Pending save' : 'Очікує збереження') : '';
        }

        input.addEventListener('change', updatePreview);
        reset.addEventListener('click', () => { input.value = ''; updatePreview(); });
        button.hidden = !canPaint;
        button.addEventListener('click', async () => {
            const selected = input.files[0];
            button.disabled = true;
            try {
                let blob = selected;
                if (!blob) {
                    const response = await fetch(row.dataset.source, {cache: 'no-store'});
                    if (!response.ok) throw new Error('image');
                    blob = await response.blob();
                }
                const still = await isStill(blob);
                if (input.files[0] !== selected) return;
                if (!still) {
                    button.hidden = true;
                    status.textContent = en ? 'Use Replace for an animated image.' : 'Для анімації скористайтеся заміною файлу.';
                    return;
                }
                const side = selected ? Math.max(preview.naturalWidth, preview.naturalHeight) : Math.max(row.dataset.width, row.dataset.height);
                if (side > 2000 && !confirm(en ? 'Dead Paint will resize the longest side to 2000 pixels. Continue?' :
                    'Dead Paint зменшить довшу сторону до 2000 пікселів. Продовжити?')) return;
                const paint = await window.nichanLoadPaint(row.closest('form').dataset.paintScript);
                if (input.files[0] !== selected) return;
                paint.open(result => {
                    const name = (selected ? selected.name : preview.alt).replace(/\.[^.]*$/, '');
                    const transfer = new DataTransfer();
                    transfer.items.add(new File([result], (name || 'image') + '.png', {type: 'image/png'}));
                    input.files = transfer.files;
                    updatePreview();
                    return true;
                }, URL.createObjectURL(blob));
            } catch (err) {
                status.textContent = en ? 'Could not open the image.' : 'Не вдалося відкрити зображення.';
            } finally {
                button.disabled = false;
            }
        });
    });
})();
