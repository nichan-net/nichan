/*
 * nichan-dm.js - demotivator and meme maker for /demotivator/. Everything happens on a canvas in
 * the browser; long texts are wrapped onto new lines and shrunk until they fit.
 */
(function () {
	'use strict';

	var $ = function (id) { return document.getElementById(id); };
	var canvas = $('dm-canvas'), ctx = canvas.getContext('2d');
	var img = null, fileName = 'nichan';

	function mode() { return document.querySelector('input[name="dm-mode"]:checked').value; }

	/* ---------- loading the picture ---------- */

	function loadFile(file) {
		if (!file || !/^image\//.test(file.type)) return;
		fileName = (file.name || 'nichan').replace(/\.[^.]+$/, '').slice(0, 40) || 'nichan';
		var url = URL.createObjectURL(file);
		var im = new Image();
		im.onload = function () {
			img = im;
			$('dm-drop-text').textContent = (file.name || 'картинка') + ' — ' + im.naturalWidth + '×' + im.naturalHeight + ' (натисніть, щоб замінити)';
			render();
		};
		im.onerror = function () { alert('Не вдалося відкрити цю картинку.'); };
		im.src = url;
	}

	$('dm-file').addEventListener('change', function () { loadFile(this.files[0]); });
	var drop = $('dm-drop');
	['dragenter', 'dragover'].forEach(function (ev) {
		drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); });
	});
	['dragleave', 'drop'].forEach(function (ev) {
		drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); });
	});
	drop.addEventListener('drop', function (e) { loadFile(e.dataTransfer.files[0]); });
	document.addEventListener('paste', function (e) {
		var items = (e.clipboardData || {}).items || [];
		for (var i = 0; i < items.length; i++) {
			if (items[i].type.indexOf('image/') === 0) { loadFile(items[i].getAsFile()); e.preventDefault(); return; }
		}
	});

	/* ---------- text layout ---------- */

	// Splits text into lines no wider than maxWidth (explicit line breaks are kept). If a single
	// word is wider than maxWidth, returns null so the caller can try a smaller font.
	function wrap(text, maxWidth) {
		var lines = [];
		var paragraphs = String(text).replace(/\r/g, '').split('\n');
		for (var p = 0; p < paragraphs.length; p++) {
			var words = paragraphs[p].split(/\s+/).filter(Boolean), line = '';
			if (!words.length) { lines.push(''); continue; }
			for (var i = 0; i < words.length; i++) {
				if (ctx.measureText(words[i]).width > maxWidth) return null;
				var test = line ? line + ' ' + words[i] : words[i];
				if (ctx.measureText(test).width > maxWidth && line) {
					lines.push(line);
					line = words[i];
				} else {
					line = test;
				}
			}
			lines.push(line);
		}
		while (lines.length && lines[lines.length - 1] === '') lines.pop();
		return lines;
	}

	// Largest font size (from `size` down to `min`) at which the text wraps into at most maxLines
	// lines of maxWidth and fits into maxHeight.
	function fit(text, font, size, min, maxWidth, maxLines, maxHeight, lineHeight) {
		for (var s = size; s >= min; s = Math.floor(s * 0.92)) {
			ctx.font = font(s);
			var lines = wrap(text, maxWidth);
			if (lines && lines.length <= maxLines && lines.length * s * lineHeight <= maxHeight)
				return { size: s, lines: lines };
		}
		ctx.font = font(min);
		return { size: min, lines: wrap(text, maxWidth) || hardWrap(text, maxWidth) };
	}

	// Last resort for very long "words" (links, keyboard mashing): break them anywhere.
	function hardWrap(text, maxWidth) {
		var lines = [], line = '';
		String(text).replace(/\s+/g, ' ').split('').forEach(function (ch) {
			if (ctx.measureText(line + ch).width > maxWidth && line) { lines.push(line); line = ch.trim(); }
			else line += ch;
		});
		if (line) lines.push(line);
		return lines;
	}

	/* ---------- demotivator ---------- */

	var SERIF = function (s) { return 'bold ' + s + 'px "Times New Roman", Times, "Liberation Serif", "Noto Serif", Georgia, serif'; };
	var SANS = function (s) { return s + 'px Arial, "Helvetica Neue", "Liberation Sans", "Noto Sans", sans-serif'; };

	function drawDemotivator() {
		var iw = Math.min(img.naturalWidth, 800);
		if (iw < 360) iw = Math.min(360, img.naturalWidth * 3);
		var ih = Math.round(img.naturalHeight * iw / img.naturalWidth);
		var pad = Math.max(40, Math.round(iw * 0.1));
		var W = iw + pad * 2, textW = W - pad;
		var title = $('dm-title').value.trim(), sub = $('dm-subtitle').value.trim();

		var t = title ? fit(title, SERIF, Math.round(iw * 0.1), 14, textW, 4, iw * 0.6, 1.15) : { size: 0, lines: [] };
		var s = sub ? fit(sub, SANS, Math.round(iw * 0.045), 11, textW, 6, iw * 0.5, 1.3) : { size: 0, lines: [] };
		var titleH = t.lines.length * t.size * 1.15, subH = s.lines.length * s.size * 1.3;
		var gap = Math.round(pad * 0.45);
		var H = Math.round(pad + ih + gap + titleH + (subH ? gap * 0.4 + subH : 0) + pad * 0.7);

		canvas.width = W;
		canvas.height = H;
		ctx.fillStyle = '#000';
		ctx.fillRect(0, 0, W, H);
		ctx.drawImage(img, pad, pad, iw, ih);
		// the classic thin white frame around the picture
		var off = Math.max(4, Math.round(iw * 0.008)), lw = Math.max(2, Math.round(iw * 0.004));
		ctx.strokeStyle = '#fff';
		ctx.lineWidth = lw;
		ctx.strokeRect(pad - off, pad - off, iw + off * 2, ih + off * 2);

		ctx.fillStyle = '#fff';
		ctx.textAlign = 'center';
		ctx.textBaseline = 'top';
		var y = pad + ih + gap;
		ctx.font = SERIF(t.size);
		t.lines.forEach(function (line) { ctx.fillText(line, W / 2, y); y += t.size * 1.15; });
		if (subH) y += gap * 0.4;
		ctx.font = SANS(s.size);
		s.lines.forEach(function (line) { ctx.fillText(line, W / 2, y); y += s.size * 1.3; });
		watermark(W, H, 'rgba(160,160,160,.8)');
	}

	/* ---------- meme ---------- */

	var IMPACT = function (s) { return '700 ' + s + 'px NichanImpact, Impact, "Arial Black", sans-serif'; };

	function drawMeme() {
		var W = Math.min(img.naturalWidth, 1000);
		if (W < 500) W = Math.min(500, img.naturalWidth * 3);
		var H = Math.round(img.naturalHeight * W / img.naturalWidth);
		canvas.width = W;
		canvas.height = H;
		ctx.drawImage(img, 0, 0, W, H);

		var margin = Math.round(W * 0.03), maxW = W - margin * 2;
		var top = $('dm-top').value.trim().toUpperCase(), bottom = $('dm-bottom').value.trim().toUpperCase();
		var start = Math.round(Math.min(W, H * 1.4) * 0.11), min = Math.max(12, Math.round(W * 0.03));
		ctx.textAlign = 'center';
		ctx.lineJoin = 'round';
		ctx.miterLimit = 2;

		function block(text, fromTop) {
			if (!text) return;
			var f = fit(text, IMPACT, start, min, maxW, 4, H * 0.3, 1.08);
			ctx.font = IMPACT(f.size);
			ctx.lineWidth = Math.max(2, f.size * 0.12);
			ctx.strokeStyle = '#000';
			ctx.fillStyle = '#fff';
			var lh = f.size * 1.08;
			ctx.textBaseline = fromTop ? 'top' : 'bottom';
			f.lines.forEach(function (line, i) {
				var y = fromTop ? margin + i * lh : H - margin - (f.lines.length - 1 - i) * lh;
				ctx.strokeText(line, W / 2, y);
				ctx.fillText(line, W / 2, y);
			});
		}
		block(top, true);
		block(bottom, false);
		watermark(W, H, 'rgba(255,255,255,.55)');
	}

	function watermark(W, H, color) {
		// the site's address is always signed, unless the admins let visitors switch it off (the checkbox is there then)
		var opt = $('dm-watermark');
		if (opt && !opt.checked) return;
		var s = Math.max(10, Math.round(W * 0.018));
		ctx.font = SANS(s);
		ctx.textAlign = 'right';
		ctx.textBaseline = 'bottom';
		ctx.fillStyle = color;
		ctx.fillText(window.nichan_site_host || location.host, W - s * 0.6, H - s * 0.4);
	}

	/* ---------- rendering & saving ---------- */

	var pending = null;
	function render() {
		var m = mode();
		$('dm-demotivator-fields').hidden = m !== 'demotivator';
		$('dm-meme-fields').hidden = m !== 'meme';
		if (!img) return;
		clearTimeout(pending);
		pending = setTimeout(function () {
			var go = function () {
				if (m === 'meme') drawMeme(); else drawDemotivator();
				canvas.hidden = false;
				$('dm-empty').hidden = true;
				$('dm-actions').hidden = false;
			};
			// make sure the Impact / Oswald font is ready before measuring text with it
			if (m === 'meme' && document.fonts && document.fonts.load)
				document.fonts.load(IMPACT(40), 'АБВЇЄҐ ABC').then(go, go);
			else go();
		}, 60);
	}

	Array.prototype.forEach.call(document.querySelectorAll('#dm-form textarea, #dm-form input'), function (el) {
		if (el.type === 'file') return;
		el.addEventListener(el.type === 'radio' || el.type === 'checkbox' ? 'change' : 'input', render);
	});

	$('dm-save').addEventListener('click', function () {
		canvas.toBlob(function (blob) {
			var a = document.createElement('a');
			a.href = URL.createObjectURL(blob);
			a.download = fileName + '-' + mode() + '.png';
			document.body.appendChild(a);
			a.click();
			setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
		}, 'image/png');
	});

	if (window.ClipboardItem && navigator.clipboard && navigator.clipboard.write) {
		$('dm-copy').hidden = false;
		$('dm-copy').addEventListener('click', function () {
			canvas.toBlob(function (blob) {
				navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]).then(function () {
					$('dm-copy').textContent = 'Скопійовано';
					setTimeout(function () { $('dm-copy').textContent = 'Копіювати'; }, 1500);
				}, function () { alert('Браузер не дозволив скопіювати картинку.'); });
			}, 'image/png');
		});
	}

	render();
})();
