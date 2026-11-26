// Read the first board page's generated summary. Fetch markup only after the reader asks to see it.
(function () {
	'use strict';
	var box = document.getElementById('nichan-board-activity');
	var form = document.forms.postcontrols;
	if (!box || !form || !window.fetch) return;
	var showThreads = box.querySelector('button');
	var en = window.nichan_lang === 'en';
	var initialLast = 0, pending = [], timer, etag, polling = false;
	var delay = 30000;
	form.querySelectorAll('.thread[data-last-reply]').forEach(function (thread) {
		initialLast = Math.max(initialLast, Number(thread.dataset.lastReply));
	});

	function newPost(node) {
		if (window.jQuery) window.jQuery(document).trigger('new_post', node);
	}

	function fetchPage(url) {
		return fetch(url, {cache: 'no-cache', credentials: 'same-origin'}).then(function (r) {
			if (!r.ok) throw new Error('Page unavailable');
			return r.text();
		}).then(function (html) { return new DOMParser().parseFromString(html, 'text/html'); });
	}

	function addReplies(thread, button) {
		var link = thread.querySelector('.op .post_no');
		if (!link) return;
		button.disabled = true;
		fetchPage(link.href.split('#')[0]).then(function (doc) {
			var full = doc.getElementById(thread.id);
			if (!full) throw new Error('Thread unavailable');
			var replies = full.querySelectorAll(':scope > .post.reply');
			var last = Number(thread.dataset.lastReply);
			replies.forEach(function (post) {
				var id = Number(post.id.slice(6));
				if (id <= last || document.getElementById(post.id)) return;
				var copy = document.importNode(post, true);
				thread.insertBefore(copy, button);
				thread.insertBefore(document.createElement('br'), button);
				newPost(copy);
			});
			if (replies.length) thread.dataset.lastReply = Math.max(last, Number(replies[replies.length - 1].id.slice(6)));
			thread.dataset.replies = replies.length;
			button.remove();
			etag = null;
		}).catch(function () {
			button.textContent = en ? 'Retry loading replies' : 'Спробувати завантажити відповіді ще раз';
		}).finally(function () { button.disabled = false; });
	}

	function replyNotice(thread, row) {
		var button = thread.querySelector(':scope > .nichan-live-replies');
		var last = Number(thread.dataset.lastReply);
		if (row.last <= last) {
			if (button && !button.disabled) button.remove();
			thread.dataset.replies = row.replies;
			return;
		}
		if (!button) {
			button = document.createElement('button');
			button.type = 'button';
			button.className = 'nichan-live-replies';
			thread.insertBefore(button, thread.querySelector(':scope > br.clear'));
			button.addEventListener('click', function () { addReplies(thread, button); });
		}
		// Simultaneous deletions can make a count difference smaller than the number of arrivals.
		var diff = row.replies - Number(thread.dataset.replies);
		button.textContent = (en ? 'Show new replies' : 'Показати нові відповіді') + (diff > 0 ? ' (+' + diff + ')' : '');
	}

	function showPending() {
		showThreads.hidden = pending.length === 0;
		showThreads.textContent = (en ? 'Show new threads' : 'Показати нові нитки') + ' (+' + pending.length + ')';
	}
	showThreads.addEventListener('click', function () {
		showThreads.disabled = true;
		fetchPage(location.pathname).then(function (doc) {
			var fresh = doc.forms.postcontrols;
			if (!fresh) throw new Error('Board unavailable');
			// Walk backwards so pinned threads keep their positions and each addition has a stable anchor.
			var visible = form.querySelectorAll('.thread[data-last-reply]');
			var before = visible.length ? visible[visible.length - 1].nextSibling : form.querySelector('[name="board"]').nextSibling;
			Array.from(fresh.querySelectorAll('.thread[data-last-reply]')).reverse().forEach(function (thread) {
				var existing = document.getElementById(thread.id);
				if (existing) { before = existing; return; }
				if (!pending.includes(Number(thread.id.slice(7)))) return;
				var copy = document.importNode(thread, true);
				form.insertBefore(copy, before);
				before = copy;
				newPost(copy);
			});
			pending = [];
			showPending();
			etag = null;
		}).catch(function () {
			showThreads.textContent = en ? 'Retry loading threads' : 'Спробувати завантажити нитки ще раз';
		}).finally(function () { showThreads.disabled = false; });
	});

	function schedule(ms) {
		clearTimeout(timer);
		if (!document.hidden) timer = setTimeout(poll, ms);
	}
	function poll() {
		if (document.hidden || polling) return;
		polling = true;
		fetch(box.dataset.feed, {cache: 'no-cache', headers: etag ? {'If-None-Match': etag} : {}})
			.then(async function (r) {
				if (r.status === 304) return;
				if (!r.ok) throw new Error('Summary unavailable');
				var rows = await r.json();
				var unseen = [];
				rows.forEach(function (row) {
					var thread = document.getElementById('thread_' + row.id);
					if (thread) replyNotice(thread, row);
					else if (row.id > initialLast) unseen.push(row.id);
				});
				pending = unseen;
				showPending();
				etag = r.headers.get('ETag');
			}).then(function () { delay = 30000; })
			.catch(function () { delay = Math.min(delay * 2, 300000); })
			.finally(function () { polling = false; schedule(delay); });
	}
	document.addEventListener('visibilitychange', function () { schedule(1000); });
	schedule(delay);
})();
