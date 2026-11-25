/* One thread's files: an in-page preview, original/download links and the owning post. */
(function () {
	'use strict';
	var source = document.getElementById('nichan-gallery-data');
	if (!source) return;
	var data = JSON.parse(source.textContent), files = data.files, L = data.labels;
	var root = document.querySelector('.nichan-gallery'), preview = root.querySelector('.nichan-gallery-preview');
	if (!files.length || !preview) return;
	var items = root.querySelectorAll('.nichan-gallery-item'), media = root.querySelector('.nichan-gallery-media');
	var previous = root.querySelector('.ng-previous'), next = root.querySelector('.ng-next'), current = -1;
	var revealed = {};
	root.classList.add('nichan-gallery-ready');
	function size(bytes) {
		var units = ['B', 'KB', 'MB', 'GB'], n = 0;
		while (bytes >= 1024 && n < units.length - 1) { bytes /= 1024; n++; }
		return (n ? bytes.toFixed(1) : bytes) + ' ' + units[n];
	}
	function notice(text) {
		media.replaceChildren();
		var p = document.createElement('p'); p.textContent = text; media.appendChild(p);
	}
	function show(index, scroll) {
		if (index < 0 || index >= files.length) return;
		var old = media.querySelector('video, audio');
		if (old) { old.pause(); old.removeAttribute('src'); old.load(); }
		current = index;
		var file = files[index];
		Array.prototype.forEach.call(items, function (item, i) { item.setAttribute('aria-current', i === index ? 'true' : 'false'); });
		previous.disabled = index === 0; next.disabled = index === files.length - 1;
		root.querySelector('.ng-counter').textContent = (index + 1) + ' / ' + files.length;
		root.querySelector('.ng-name').textContent = file.name;
		root.querySelector('.ng-details').textContent = file.ext + ' · ' + size(file.size) + (file.width && file.height ? ' · ' + file.width + '×' + file.height : '');
		root.querySelector('.ng-text').textContent = file.text;
		var post = root.querySelector('.ng-post'); post.href = file.post_url; post.textContent = L.post + ' No.' + file.post;
		root.querySelector('.ng-open').href = file.url;
		var download = root.querySelector('.ng-download'); download.href = file.url; download.setAttribute('download', file.name);
		media.replaceChildren();
		if (file.spoiler && !revealed[file.key]) {
			notice(L.spoiler);
			var reveal = document.createElement('button'); reveal.type = 'button'; reveal.textContent = L.reveal;
			reveal.addEventListener('click', function () { revealed[file.key] = true; show(index, false); });
			media.appendChild(reveal);
		} else if (file.kind === 'image') {
			var image = document.createElement('img'); image.alt = file.name;
			image.addEventListener('error', function () { if (current === index) notice(L.missing); });
			image.src = file.url; media.appendChild(image);
		} else if (file.kind === 'video' || file.kind === 'audio') {
			var player = document.createElement(file.kind); player.controls = true; player.preload = 'metadata';
			if (file.kind === 'video') { player.playsInline = true; if (file.thumb) player.poster = file.thumb; }
			player.addEventListener('error', function () { if (current === index) notice(L.missing); });
			player.src = file.url; media.appendChild(player);
		} else {
			var icon = document.createElement('i'); icon.className = 'fa fa-' + file.icon; icon.setAttribute('aria-hidden', 'true');
			media.appendChild(icon);
			var link = document.createElement('a'); link.href = file.url; link.target = '_blank'; link.rel = 'noopener'; link.textContent = L.open + ' · ' + file.ext;
			media.appendChild(link);
		}
		try { history.replaceState(null, '', '#' + file.key); } catch (e) {}
		if (scroll && window.innerWidth <= 768) preview.scrollIntoView({ block: 'start', behavior: 'smooth' });
	}
	Array.prototype.forEach.call(items, function (item, index) {
		item.addEventListener('click', function (e) {
			if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
			e.preventDefault(); show(index, true);
		});
		var thumb = item.querySelector('img');
		if (thumb) { var removeBroken = function () { thumb.remove(); }; thumb.addEventListener('error', removeBroken); if (thumb.complete && !thumb.naturalWidth) removeBroken(); }
	});
	previous.addEventListener('click', function () { show(current - 1, false); });
	next.addEventListener('click', function () { show(current + 1, false); });
	document.addEventListener('keydown', function (e) {
		if (e.altKey || e.ctrlKey || e.metaKey || e.shiftKey || /^(INPUT|TEXTAREA|SELECT|VIDEO|AUDIO)$/.test(e.target.tagName) || e.target.isContentEditable) return;
		if (e.key === 'ArrowLeft') { e.preventDefault(); show(current - 1, false); }
		if (e.key === 'ArrowRight') { e.preventDefault(); show(current + 1, false); }
	});
	function fromHash() { var key = location.hash.slice(1), at = files.findIndex(function (file) { return file.key === key; }); show(at < 0 ? 0 : at, false); }
	window.addEventListener('hashchange', fromHash);
	fromHash();
})();
