/*
 * nichan.js - site features for Nichan (no dependencies; plays along with the vichan scripts).
 *
 *  - Post form editor with two modes: "Visual" (WYSIWYG) and "Text" (markup), plus markup buttons.
 *    The <textarea name="body"> stays the real field: the visual editor writes markup into it.
 *  - Post previews: hovering (or tapping) >>123, >>>/b/123 or a link to a post of this site shows
 *    that post in a popup, loading it from its thread when it isn't on the page.
 *  - Word filter: hide posts containing words or regular expressions (stored in this browser).
 *  - [l>k] turns a post's latin-script text into cyrillic right in the post (click again to undo; off unless switched on in the settings);
 *    on the boards chosen in mod.php (Board settings) a [translate] button translates the post into the browser's language instead.
 *  - [x] hides a reply (remembered in this browser); replies to a post are listed at its bottom.
 *  - Replying opens the post form under the post ("detach" makes it float); threads can
 *    update themselves every 5 seconds and show desktop notifications.
 *  - Links to YouTube, Vimeo, Dailymotion, Coub, TikTok, Instagram, SoundCloud and Telegram get a player.
 *  - reCAPTCHA widget when site-status.js says it's on.
 *  - Replies to your posts are highlighted and counted; saved threads show how many posts are new.
 *  - Drafts: the text of the post form is kept in this browser until the post goes through.
 *  - [code] blocks get syntax highlighting (js/highlight, loaded only when a page has code).
 *  - Push notifications about replies to your posts, even when the site is closed (push.php, sw.js).
 *
 * Texts are Ukrainian everywhere except on /int/ (English).
 */
(function () {
	'use strict';

	var board = window.nichan_board || '';
	// Chrome / Edge may offer to install the web app; keep the offer for the "Install the app" link.
	var installPrompt = null;
	window.addEventListener('beforeinstallprompt', function (e) {
		e.preventDefault();
		installPrompt = e;
	});
	// already opened as the installed app: no install link
	var standalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
	var EN = window.nichan_lang === 'en';
	// the site's name (mod.php?/nichan-site), set in the page by templates/header.html
	var SITE = window.nichan_site_name || (EN ? 'Nichan' : 'Нічан');
	var T = EN ? {
		visual: 'Visual', text: 'Text', bold: 'Bold', italic: 'Italic', underline: 'Underline', strike: 'Strikethrough',
		spoiler: 'Spoiler', code: 'Code', heading: 'Heading', quote: 'Quote', modeTitle: 'Editor mode',
		filter: 'Word filter', filterTitle: 'Hide posts containing these words', add: 'Add', regex: 'regular expression',
		none: 'No words yet.', badRegex: 'Invalid regular expression: ', hidden: 'Post hidden by your word filter', show: 'show',
		filterPh: 'word or /regex/', remove: 'remove', translate: 'translate', original: 'original', translating: 'translating…',
		translateFail: 'Translation failed. Open it in Google Translate?', loading: 'Loading…', notFound: 'Post not found (deleted?)',
		threadHidden: 'Thread hidden by your word filter', hiddenManual: 'Post hidden by you', hidePost: 'Hide this post', close: 'Close',
		hideThread: 'hide thread', hideThreadHint: 'Also hide whole threads whose first post contains it', replies: 'Replies:',
		detach: 'detach', attach: 'attach', autoUpdate: 'automatically', notify: 'notify about new replies', newPosts: 'new posts',
		showVideo: 'Show', videoFrom: 'Video',
		captcha: 'Captcha', captchaPh: 'the two words from the picture', captchaNew: 'Click for another picture', captchaGet: 'click to show the picture',
		menu: 'Actions', fileHide: 'Hide this picture', fileShow: 'Show this picture', hide: 'Hide', reply: 'Reply', save: 'Save thread', unsave: 'Remove from saved', report: 'Report', del: 'Delete',
		cancel: 'Cancel', sending: 'Sending…', failed: 'Something went wrong, try again.', reportTitle: 'Report post No.%s',
		reasonPh: 'Reason of the report', reasonMore: 'Details (not required)', reasonPick: 'Choose a reason.',
		reasons: ['Spam / advertising', 'Illegal content', 'Wrong board', 'Insults / harassment', 'Other'], reportBtn: 'Report', reported: 'Report sent. Thank you!', deleteTitle: 'Delete post No.%s',
		password: 'Password', fileOnly: 'file only', delBtn: 'Delete', saved: 'Saved threads', savedNone: 'No saved threads yet.',
		updateNow: 'update', noNew: 'no new posts', toYou: 'Replies to you', toYouShort: 'to you', replyForm: 'Reply', hideReply: 'Hide the reply form', newThread: 'Create thread', hideNew: 'Hide the new thread form',
		quote: 'Quote', myPosts: 'My posts', myNone: 'No posts of yours yet (they are remembered in this browser).', repliesN: 'replies', allSettings: 'All settings', pendWait: 'under review', pendOk: 'approved', pendNo: 'rejected', pendOpen: 'open the thread',
		pendNote: 'Only you see this thread until a moderator approves it.', pendMine: 'Your thread', fText: 'text', fName: 'name', fTrip: 'tripcode', fImg: 'pictures only',
		fImgHint: 'Hide only the pictures of such posts', imgHidden: 'pictures hidden by your filter', opOnly: 'OP posts only', allPosts: 'all posts',
		continues: 'A continuation of thread No.%s', push: 'notify about replies to you, even when the site is closed',
		pushDenied: 'Notifications are blocked for this site in the browser settings.', pushFail: 'Could not turn on notifications.',
		pushIos: 'On iPhone notifications work in the installed app (Share → Add to Home Screen).', newShort: 'new', gone: 'deleted', replyBtn: 'Reply', install: 'Install the app',
		installTitle: 'Install ' + SITE + ' as an app', ok: 'OK',
		installAndroid: 'In Chrome press the three-dot menu (top right) → <b>Add to Home screen</b> (or <b>Install app</b>) → <b>Install</b>. ' + SITE + ' gets its own icon and opens full screen.',
		installIos: 'In Safari press <b>Share</b> (the square with an arrow) → <b>Add to Home Screen</b> → <b>Add</b>.',
		installOther: 'Open the browser menu and choose <b>Install app</b> (Chrome and Edge also show an install icon in the address bar). Browsers without such an item cannot install sites.'
	} : {
		visual: 'Візуально', text: 'Текст', bold: 'Жирний', italic: 'Курсив', underline: 'Підкреслений', strike: 'Перекреслений',
		spoiler: 'Спойлер', code: 'Код', heading: 'Заголовок', quote: 'Цитата', modeTitle: 'Режим редактора',
		filter: 'Фільтрувати слова', filterTitle: 'Ховати повідомлення з цими словами', add: 'Додати', regex: 'регулярний вираз',
		none: 'Слів ще немає.', badRegex: 'Неправильний регулярний вираз: ', hidden: 'Повідомлення приховано фільтром слів', show: 'показати',
		filterPh: 'слово або /регексп/', remove: 'видалити', translate: 'перекласти', original: 'оригінал', translating: 'перекладаю…',
		translateFail: 'Не вдалося перекласти. Відкрити в Google Перекладачі?', loading: 'Завантаження…', notFound: 'Пост не знайдено (видалено?)',
		threadHidden: 'Нитку приховано фільтром', hiddenManual: 'Повідомлення приховане вручну', hidePost: 'Сховати це повідомлення', close: 'Закрити',
		hideThread: 'ховати нитку', hideThreadHint: 'Ховати й цілі нитки, де це є в першому повідомленні', replies: 'Відповіді:',
		detach: 'відкріпити', attach: 'прикріпити', autoUpdate: 'автоматично', notify: 'сповістити про нові відповіді', newPosts: 'нові повідомлення',
		showVideo: 'Показати', videoFrom: 'Відео',
		captcha: 'Капча', captchaPh: 'два слова з картинки', captchaNew: 'Натисніть, щоб отримати іншу картинку', captchaGet: 'натисніть, щоб показати картинку',
		menu: 'Дії', fileHide: 'Сховати цю картинку', fileShow: 'Показати цю картинку', hide: 'Приховати', reply: 'Відповісти', save: 'Зберегти нитку', unsave: 'Видалити зі збереженого', report: 'Поскаржитися', del: 'Видалити',
		cancel: 'Скасувати', sending: 'Надсилаю…', failed: 'Щось пішло не так, спробуйте ще раз.', reportTitle: 'Скарга на повідомлення №%s',
		reasonPh: 'Причина скарги', reasonMore: 'Подробиці (не обов\'язково)', reasonPick: 'Виберіть причину.',
		reasons: ['Спам / реклама', 'Незаконний контент', 'Не та дошка', 'Образи / цькування', 'Інше'], reportBtn: 'Поскаржитися', reported: 'Скаргу надіслано. Дякуємо!', deleteTitle: 'Видалити повідомлення №%s',
		password: 'Пароль', fileOnly: 'лише файл', delBtn: 'Видалити', saved: 'Збережені нитки', savedNone: 'Збережених ниток ще немає.',
		updateNow: 'оновити', noNew: 'нових повідомлень немає', toYou: 'Відповідей вам', toYouShort: 'вам', replyForm: 'Відповісти', hideReply: 'Сховати форму відповіді', newThread: 'Створити нитку', hideNew: 'Сховати форму створення нової нитки',
		quote: 'Цитувати', myPosts: 'Мої дописи', myNone: 'Ваших дописів поки немає (вони запам\'ятовуються в цьому браузері).', repliesN: 'відп.', allSettings: 'Усі налаштування', pendWait: 'на розгляді', pendOk: 'схвалено', pendNo: 'відхилено', pendOpen: 'відкрити нитку',
		pendNote: 'Цю нитку бачите лише ви, поки модератор її не схвалить.', pendMine: 'Ваша нитка', fText: 'текст', fName: 'ім\'я', fTrip: 'тріпкод', fImg: 'лише картинки',
		fImgHint: 'Ховати лише картинки таких дописів', imgHidden: 'картинки приховано фільтром', opOnly: 'лише дописи ОП', allPosts: 'усі дописи',
		continues: 'Продовження нитки №%s', push: 'сповіщати про відповіді вам, навіть коли сайт закрито',
		pushDenied: 'Сповіщення для цього сайту заблоковані в налаштуваннях браузера.', pushFail: 'Не вдалося увімкнути сповіщення.',
		pushIos: 'На iPhone сповіщення працюють у встановленому застосунку (Поділитися → На початковий екран).', newShort: 'нових', gone: 'видалена', replyBtn: 'Нова відповідь', install: 'Встановити застосунок',
		installTitle: 'Встановити ' + SITE + ' як застосунок', ok: 'Зрозуміло',
		installAndroid: 'У Chrome натисніть меню з трьома крапками (угорі праворуч) → <b>Додати на головний екран</b> (або <b>Установити додаток</b>) → <b>Установити</b>. ' + SITE + ' отримає власну іконку й відкриватиметься на весь екран.',
		installIos: 'У Safari натисніть <b>Поділитися</b> (квадрат зі стрілкою) → <b>На початковий екран</b> → <b>Додати</b>.',
		installOther: 'Відкрийте меню браузера й виберіть <b>Установити додаток</b> (Chrome та Edge показують ще й значок встановлення в адресному рядку). Якщо такого пункту немає, цей браузер не вміє встановлювати сайти.'
	};

	function ready(fn) {
		if (document.readyState !== 'loading') fn();
		else document.addEventListener('DOMContentLoaded', fn);
	}
	function store(key, value) {
		try {
			if (value === undefined) return localStorage.getItem(key);
			localStorage.setItem(key, value);
		} catch (e) { return null; }
	}
	function esc(s) {
		return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}
	function onNewPosts(fn) {
		// auto-reload.js / ajax.js announce new posts through jQuery.
		if (window.jQuery) window.jQuery(document).on('new_post', function (e, post) { fn(post); });
	}

	/* ================================================================ editor */

	var BUTTONS = [
		{ id: 'bold', label: EN ? 'B' : 'Ж', style: 'font-weight:bold', md: ['**', '**'], cmd: 'bold' },
		{ id: 'italic', label: EN ? 'I' : 'К', style: 'font-style:italic', md: ['*', '*'], cmd: 'italic' },
		{ id: 'underline', label: EN ? 'U' : 'П', style: 'text-decoration:underline', md: ['__', '__'], cmd: 'underline' },
		{ id: 'strike', label: EN ? 'S' : 'З', style: 'text-decoration:line-through', md: ['~~', '~~'], cmd: 'strikeThrough' },
		{ id: 'spoiler', label: T.spoiler, md: ['%%', '%%'], wrap: 'spoiler' },
		{ id: 'code', label: T.code, md: ['[code]', '[/code]'], wrap: 'code' },
		{ id: 'math', label: '∑', title: EN ? 'LaTeX formula' : 'Формула LaTeX', md: ['[math]', '[/math]'], wrap: 'nichan-ed-math' },
		{ id: 'heading', label: T.heading, md: ['==', '=='], wrap: 'heading', line: true },
		{ id: 'quote', label: T.quote, quote: true }
	];

	// Markup (what the server understands) -> HTML for the visual editor.
	function markupToHtml(md) {
		var literals = [];
		md = md.replace(/\[(code|math)\]([\s\S]*?)\[\/\1\]/gi, function (match, tag, source) {
			var content = esc(source).replace(/\n/g, '<br>');
			literals.push(tag.toLowerCase() === 'code' ? '<code>' + content + '</code>' : '<span class="nichan-ed-math">' + content + '</span>');
			return '\u0000' + (literals.length - 1) + '\u0000';
		});
		var lines = md.split('\n').map(function (line) {
			var h = esc(line);
			var hm = /^\s*==(.+?)==\s*$/.exec(line);
			if (hm) return '<span class="heading">' + inlineMarkup(esc(hm[1])) + '</span>';
			if (/^&gt;(?!&gt;)/.test(h) || /^&gt;&gt;&gt;(?!\/)/.test(h)) return '<span class="quote">' + inlineMarkup(h) + '</span>';
			return inlineMarkup(h);
		});
		var html = lines.join('<br>');
		// a text that ends with a line break: an editable field shows the new empty line only with one more <br>
		if (/\n$/.test(md)) html += '<br>';
		return html.replace(/\u0000(\d+)\u0000/g, function (match, i) { return literals[i] || ''; });
	}
	function inlineMarkup(h) {
		return h
			.replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').replace(/\[b\](.+?)\[\/b\]/g, '<b>$1</b>')
			.replace(/__(.+?)__/g, '<u>$1</u>').replace(/\[u\](.+?)\[\/u\]/g, '<u>$1</u>')
			.replace(/~~(.+?)~~/g, '<s>$1</s>').replace(/\[s\](.+?)\[\/s\]/g, '<s>$1</s>')
			.replace(/%%(.+?)%%/g, '<span class="spoiler">$1</span>').replace(/\[spoiler\](.+?)\[\/spoiler\]/g, '<span class="spoiler">$1</span>')
			.replace(/\*(.+?)\*/g, '<i>$1</i>').replace(/\[i\](.+?)\[\/i\]/g, '<i>$1</i>');
	}

	// The cursor at the end of the visual editor: on the new empty line when the text ends with a line break
	// (before the extra <br> that shows that line).
	function caretToEnd(vis) {
		vis.focus();
		var r = document.createRange();
		if (vis.lastChild && vis.lastChild.nodeName === 'BR' && vis.lastChild.previousSibling && vis.lastChild.previousSibling.nodeName === 'BR') r.setStartBefore(vis.lastChild);
		else { r.selectNodeContents(vis); r.collapse(false); }
		r.collapse(true);
		var s = window.getSelection();
		s.removeAllRanges();
		s.addRange(r);
	}

	// Visual editor DOM -> markup.
	var BLOCK = /^(DIV|P|LI|UL|OL|BLOCKQUOTE|H[1-6])$/;
	function htmlToMarkup(root) {
		var out = '';
		function nl() { if (out && out.slice(-1) !== '\n') out += '\n'; }
		function wrapped(node, a, b) {
			var inner = sub(node);
			if (!inner.replace(/\s/g, '')) { out += inner; return; }
			// Markup never spans lines on the server, so a multi-line fragment gets markers per line;
			// spaces stay outside the markers, otherwise the markup doesn't match.
			out += inner.split('\n').map(function (line) {
				if (!line.replace(/\s/g, '')) return line;
				var m = /^(\s*)([\s\S]*?)(\s*)$/.exec(line);
				return m[1] + a + m[2] + b + m[3];
			}).join('\n');
		}
		function sub(node) {
			var saved = out;
			out = '';
			for (var c = node.firstChild; c; c = c.nextSibling) walk(c);
			var r = out;
			out = saved;
			return r;
		}
		function walk(n) {
			if (n.nodeType === 3) { out += n.nodeValue.replace(/\u200b/g, '').replace(/\u00a0/g, ' '); return; }
			if (n.nodeType !== 1) return;
			var tag = n.tagName, cls = n.className || '', st = n.getAttribute('style') || '';
			if (tag === 'BR') { out += '\n'; return; }
			if (cls === 'nichan-ed-math') { out += '[math]' + (n.innerText || n.textContent).replace(/\u200b/g, '') + '[/math]'; return; }
			if (tag === 'CODE' || tag === 'PRE') { out += '[code]' + (n.innerText || n.textContent).replace(/\u200b/g, '') + '[/code]'; return; }
			if (tag === 'B' || tag === 'STRONG' || /font-weight:\s*(bold|[6-9]00)/.test(st)) return wrapped(n, '**', '**');
			if (tag === 'I' || tag === 'EM' || /font-style:\s*italic/.test(st)) return wrapped(n, '*', '*');
			if (tag === 'U' || /text-decoration[^;]*underline/.test(st)) return wrapped(n, '__', '__');
			if (tag === 'S' || tag === 'STRIKE' || tag === 'DEL' || /line-through/.test(st)) return wrapped(n, '~~', '~~');
			if (/\bspoiler\b/.test(cls)) return wrapped(n, '%%', '%%');
			if (/\bheading\b/.test(cls)) { nl(); wrapped(n, '==', '=='); return; }
			if (BLOCK.test(tag)) {
				nl();
				for (var c = n.firstChild; c; c = c.nextSibling) walk(c);
				nl();
				return;
			}
			for (var d = n.firstChild; d; d = d.nextSibling) walk(d);
		}
		for (var c = root.firstChild; c; c = c.nextSibling) walk(c);
		return out.replace(/\n$/, '');
	}

	function setupEditor(textarea) {
		if (textarea._nichanEditor) return;
		// Quick reply clones the whole form, our controls included; drop the dead copies.
		var prev = textarea.previousElementSibling;
		while (prev && /\bnichan-ed-/.test(prev.className)) {
			var p = prev.previousElementSibling;
			prev.parentNode.removeChild(prev);
			prev = p;
		}

		var bar = document.createElement('div');
		bar.className = 'nichan-ed-bar';
		var modes = document.createElement('span');
		modes.className = 'nichan-ed-modes';
		modes.title = T.modeTitle;
		modes.innerHTML = '<button type="button" data-mode="visual">' + T.visual + '</button><button type="button" data-mode="text">' + T.text + '</button>';
		bar.appendChild(modes);
		var tools = document.createElement('span');
		tools.className = 'nichan-ed-tools';
		BUTTONS.forEach(function (b) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.textContent = b.label;
			btn.title = b.title || T[b.id];
			if (b.title) btn.setAttribute('aria-label', b.title);
			btn.setAttribute('data-tool', b.id);
			if (b.style) btn.setAttribute('style', b.style);
			tools.appendChild(btn);
		});
		bar.appendChild(tools);

		var visual = document.createElement('div');
		visual.className = 'nichan-ed-visual';
		visual.contentEditable = 'true';
		visual.setAttribute('role', 'textbox');
		visual.setAttribute('aria-multiline', 'true');

		textarea.parentNode.insertBefore(bar, textarea);
		textarea.parentNode.insertBefore(visual, textarea);

		// Look like the textarea of whatever style is selected.
		var cs = window.getComputedStyle(textarea);
		['fontFamily', 'fontSize', 'lineHeight', 'color', 'backgroundColor', 'borderTopWidth', 'borderTopStyle', 'borderTopColor',
			'borderRadius', 'paddingTop', 'paddingLeft'].forEach(function (p) { if (cs[p]) visual.style[p] = cs[p]; });
		visual.style.borderWidth = cs.borderTopWidth;
		visual.style.borderStyle = cs.borderTopStyle || 'solid';
		visual.style.borderColor = cs.borderTopColor;
		visual.style.padding = cs.paddingTop + ' ' + cs.paddingLeft;
		if (/px$/.test(cs.width) && parseFloat(cs.width) > 100) visual.style.width = cs.width;
		if (cs.backgroundColor === 'rgba(0, 0, 0, 0)') visual.style.backgroundColor = '';

		var synced = null; // markup last exchanged between the two views
		var mode = null;

		function toTextarea() {
			var md = htmlToMarkup(visual);
			if (md !== textarea.value) {
				textarea.value = md;
				synced = md;
				// quick-reply.js keeps its copy of the form in sync through these events
				textarea.dispatchEvent(new Event('input', { bubbles: true }));
			}
			synced = md;
		}
		function fromTextarea() {
			visual.innerHTML = markupToHtml(textarea.value);
			synced = textarea.value;
		}
		function setMode(m, save) {
			// the size the user dragged one view to goes to the other
			if (mode === 'visual' && m === 'text' && visual.offsetHeight) { textarea.style.boxSizing = 'border-box'; textarea.style.height = visual.offsetHeight + 'px'; }
			if (mode === 'text' && m === 'visual' && textarea.offsetHeight) visual.style.height = textarea.offsetHeight + 'px';
			mode = m;
			var vis = m === 'visual';
			if (vis) fromTextarea();
			visual.style.display = vis ? '' : 'none';
			textarea.style.display = vis ? 'none' : '';
			Array.prototype.forEach.call(modes.children, function (b) {
				b.setAttribute('aria-pressed', b.getAttribute('data-mode') === m ? 'true' : 'false');
			});
			if (save) store('nichan_editor_mode', m);
		}

		modes.addEventListener('click', function (e) {
			var m = e.target.getAttribute && e.target.getAttribute('data-mode');
			if (!m || m === mode) return;
			if (mode === 'visual') toTextarea();
			setMode(m, true);
			(m === 'visual' ? visual : textarea).focus();
		});

		tools.addEventListener('mousedown', function (e) { e.preventDefault(); }); // keep the selection
		tools.addEventListener('click', function (e) {
			var id = e.target.getAttribute && e.target.getAttribute('data-tool');
			if (!id) return;
			var b = BUTTONS.filter(function (x) { return x.id === id; })[0];
			if (mode === 'visual') visualTool(b); else textTool(b);
		});

		function visualTool(b) {
			visual.focus();
			try { document.execCommand('styleWithCSS', false, false); } catch (e) {}
			if (b.cmd) {
				document.execCommand(b.cmd, false, null);
			} else if (b.quote) {
				var s = window.getSelection().toString();
				document.execCommand('insertHTML', false, '<br><span class="quote">&gt;' + esc(s) + '</span><br>');
			} else {
				var sel = window.getSelection();
				if (!sel.rangeCount) return;
				var range = sel.getRangeAt(0);
				var el = document.createElement(b.wrap === 'code' ? 'code' : 'span');
				if (b.wrap !== 'code') el.className = b.wrap;
				if (range.collapsed) {
					el.appendChild(document.createTextNode('\u200b'));
					range.insertNode(el);
					range.setStart(el.firstChild, 1);
					range.collapse(true);
				} else {
					el.appendChild(range.extractContents());
					range.insertNode(el);
					range.selectNodeContents(el);
				}
				sel.removeAllRanges();
				sel.addRange(range);
			}
			toTextarea();
		}

		function textTool(b) {
			var v = textarea.value, s = textarea.selectionStart, e = textarea.selectionEnd;
			var sel = v.slice(s, e), rep;
			if (b.quote) {
				rep = (s > 0 && v[s - 1] !== '\n' ? '\n' : '') + (sel || '').split('\n').map(function (l) { return '>' + l; }).join('\n');
			} else if (b.line) {
				rep = (s > 0 && v[s - 1] !== '\n' ? '\n' : '') + b.md[0] + sel + b.md[1] + '\n';
			} else {
				rep = b.md[0] + sel + b.md[1];
			}
			textarea.focus();
			textarea.value = v.slice(0, s) + rep + v.slice(e);
			var caret = sel || b.quote ? s + rep.length : s + rep.indexOf(b.md[1] || '\n', (b.md[0] || '').length);
			textarea.selectionStart = textarea.selectionEnd = caret;
			textarea.dispatchEvent(new Event('input', { bubbles: true }));
		}

		visual.addEventListener('input', toTextarea);
		visual.addEventListener('blur', toTextarea);
		visual.addEventListener('paste', function (e) {
			// Paste as plain text: pasted web pages would bring styles the markup can't express.
			var text = (e.clipboardData || window.clipboardData).getData('text');
			if (text === undefined) return;
			e.preventDefault();
			document.execCommand('insertText', false, text);
		});
		visual.addEventListener('keydown', function (e) {
			if (e.key !== 'Enter') return;
			if (e.ctrlKey || e.metaKey) {
				toTextarea();
				var btn = textarea.form && textarea.form.querySelector('input[type="submit"][name="post"]');
				if (btn) btn.click();
				return;
			}
			// Our own line break: a new line typed at the end of bold / spoiler / etc. starts plain,
			// and browsers don't wrap lines in <div>s we would then have to untangle.
			e.preventDefault();
			var sel = window.getSelection();
			if (!sel.rangeCount) return;
			var r = sel.getRangeAt(0);
			r.deleteContents();
			var el = r.endContainer.nodeType === 3 ? r.endContainer.parentNode : r.endContainer, outer = null;
			while (el && el !== visual && el.tagName !== 'CODE') {
				if (/^(B|STRONG|I|EM|U|S|STRIKE|DEL|SPAN)$/.test(el.tagName)) {
					var rest = document.createRange();
					rest.setStart(r.endContainer, r.endOffset);
					rest.setEnd(el, el.childNodes.length);
					if (rest.toString().replace(/\u200b/g, '') !== '') break;
					outer = el;
				}
				el = el.parentNode;
			}
			if (outer) {
				// step out of the formatting, anchored in an empty text node so the browser keeps us there
				var anchor = document.createTextNode('\u200b');
				outer.parentNode.insertBefore(anchor, outer.nextSibling);
				r.setStart(anchor, 1);
				r.collapse(true);
				sel.removeAllRanges();
				sel.addRange(r);
			}
			if (!document.execCommand('insertLineBreak', false, null))
				document.execCommand('insertHTML', false, '<br>\u200b');
			toTextarea();
		});
		visual.addEventListener('focus', function () {
			// citeReply() and friends look for #body
			if (!document.getElementById('body') || document.getElementById('body') === textarea) return;
			var other = document.getElementById('body');
			other.removeAttribute('id');
			textarea.id = 'body';
		});

		// The textarea also changes behind our back: citeReply(), quick reply sync, clearing after
		// posting. Pick those changes up.
		setInterval(function () {
			if (mode === 'visual' && textarea.value !== synced && document.activeElement !== visual) fromTextarea();
		}, 400);
		if (window.jQuery) window.jQuery(window).on('cite', function () {
			setTimeout(function () {
				if (mode !== 'visual' || textarea.value === synced) return;
				fromTextarea();
				caretToEnd(visual);
			}, 50);
		});
		if (textarea.form) textarea.form.addEventListener('submit', function () { if (mode === 'visual') toTextarea(); }, true);

		textarea._nichanEditor = true;
		setMode(store('nichan_editor_mode') || 'visual', false);
	}

	function setupEditors() {
		// (the post forms, and the text of a news entry in mod.php)
		Array.prototype.forEach.call(document.querySelectorAll('form[name="post"] textarea[name="body"], form.nichan-news-form textarea[name="body"]'), setupEditor);
	}

	/* ============================================================ previews */

	var postCache = {};    // url -> Promise<Document>
	var previews = [];     // open popups, innermost last
	// this site, under the address set in mod.php?/nichan-site (links written with it count as this site's)
	var SITE_HOST = window.nichan_site_host || window.location.hostname;
	var HOSTS = [location.host, SITE_HOST, 'www.' + SITE_HOST];

	// Figures out which post a link points to: {board, id, url} or null.
	function postRef(a) {
		var text = (a.textContent || '').trim(), href = a.getAttribute('href') || '', m;
		var ownBoard = (a.closest('[data-board]') || {}).getAttribute ? a.closest('[data-board]').getAttribute('data-board') : board;
		if ((m = /^>>>\/([a-z0-9_]+)\/(\d+)$/i.exec(text))) return { board: m[1], id: m[2], url: href };
		if ((m = /^>>(\d+)$/.exec(text))) return { board: ownBoard || board, id: m[1], url: href };
		// "/b/res/1.html#5", "https://example.org/b/res/1.html#5", mod pages: "?/b/res/1.html#5"
		m = /^(?:https?:\/\/([^\/]+))?(?:\/[^?#]*\?|\?)?\/([a-z0-9_]+)\/res\/(\d+)(?:\+50)?\.html(?:#q?(\d+))?$/i.exec(href);
		if (m && (!m[1] || HOSTS.indexOf(m[1]) >= 0)) return { board: m[2], id: m[4] || m[3], url: href };
		return null;
	}

	function findPost(root, ref) {
		var scope = root.querySelector ? root : document;
		var sel = '#reply_' + ref.id + ', #op_' + ref.id;
		var cands = scope.querySelectorAll(sel);
		for (var i = 0; i < cands.length; i++) {
			var b = cands[i].closest('[data-board]');
			if (!b || b.getAttribute('data-board') === ref.board) return cands[i];
		}
		return null;
	}

	function loadPost(ref) {
		var local = findPost(document, ref);
		if (local && !local.closest('.nichan-preview')) return Promise.resolve(local);
		var url = ref.url.replace(/#.*$/, '');
		if (!url) return Promise.resolve(null);
		if (!postCache[url]) {
			postCache[url] = fetch(url, { credentials: 'same-origin' }).then(function (r) {
				return r.ok ? r.text() : '';
			}).then(function (html) {
				return new DOMParser().parseFromString(html, 'text/html');
			}).catch(function () { return null; });
		}
		return postCache[url].then(function (doc) { return doc ? findPost(doc, ref) : null; });
	}

	function closePreviewsFrom(i) {
		while (previews.length > i) {
			var p = previews.pop();
			if (p.el.parentNode) p.el.parentNode.removeChild(p.el);
		}
	}

	function openPreview(a, ref, x, y) {
		// Only the popups this link lives in stay open.
		var level = 0;
		for (var i = 0; i < previews.length; i++) if (previews[i].el.contains(a)) level = i + 1;
		closePreviewsFrom(level);

		var pop = document.createElement('div');
		pop.className = 'nichan-preview post reply';
		pop.innerHTML = '<em>' + T.loading + '</em>';
		document.body.appendChild(pop);
		var entry = { el: pop, link: a };
		previews.push(entry);
		place(pop, a, x, y);

		loadPost(ref).then(function (post) {
			if (previews.indexOf(entry) < 0) return;
			if (!post) { pop.innerHTML = '<em>' + T.notFound + '</em>'; return; }
			var clone = post.cloneNode(true);
			clone.removeAttribute('id');
			Array.prototype.forEach.call(clone.querySelectorAll('[id]'), function (n) { n.removeAttribute('id'); });
			Array.prototype.forEach.call(clone.querySelectorAll('input.delete, .controls, .nichan-post-tool'), function (n) { n.remove(); });
			pop.innerHTML = '';
			if (clone.classList.contains('op')) {
				// an OP's files sit outside its div.post in vichan; bring the first image along
				var files = post.parentNode && post.parentNode.querySelector('.files');
				if (files) pop.appendChild(files.cloneNode(true));
			}
			while (clone.firstChild) pop.appendChild(clone.firstChild);
			applyFilters(pop, true);
			place(pop, a, x, y);
		});
	}

	function place(pop, a, x, y) {
		var r = a.getBoundingClientRect();
		var vw = document.documentElement.clientWidth, vh = document.documentElement.clientHeight;
		pop.style.maxWidth = Math.min(700, vw - 16) + 'px';
		var w = pop.offsetWidth, h = pop.offsetHeight;
		var left = (x !== undefined ? x : r.left) + 12;
		if (left + w > vw - 8) left = Math.max(8, vw - w - 8);
		var top = r.bottom + 4;
		if (top + h > vh - 8) top = Math.max(8, r.top - h - 4);
		if (top + h > vh - 8) top = Math.max(8, vh - h - 8);
		pop.style.left = (left + window.pageXOffset) + 'px';
		pop.style.top = (top + window.pageYOffset) + 'px';
	}

	var hoverTimer = null, touchLink = null;
	var noHover = window.matchMedia && window.matchMedia('(hover: none)').matches;

	function previewLinkFrom(target) {
		var a = target.closest && target.closest('a');
		if (!a || !a.closest('div.body, .nichan-replies, .nichan-preview') || a.closest('.toolong')) return null;
		var ref = postRef(a);
		return ref ? { a: a, ref: ref } : null;
	}

	function setupPreviews() {
		document.addEventListener('mouseover', function (e) {
			if (noHover) return;
			var l = previewLinkFrom(e.target);
			if (l) {
				clearTimeout(hoverTimer);
				if (!previews.length || previews[previews.length - 1].link !== l.a)
					openPreview(l.a, l.ref, e.clientX);
				return;
			}
			if (e.target.closest && e.target.closest('.nichan-preview')) { clearTimeout(hoverTimer); return; }
			clearTimeout(hoverTimer);
			hoverTimer = setTimeout(function () { closePreviewsFrom(0); }, 350);
		});
		// Phones: first tap shows the post, a second tap on the same link follows it.
		document.addEventListener('click', function (e) {
			var l = previewLinkFrom(e.target);
			if (!l) {
				if (previews.length && !(e.target.closest && e.target.closest('.nichan-preview'))) closePreviewsFrom(0);
				return;
			}
			if (!noHover) return;
			if (touchLink === l.a) { touchLink = null; return; }
			e.preventDefault();
			e.stopPropagation();
			touchLink = l.a;
			openPreview(l.a, l.ref);
		}, true);
		relabelLinks(document);
		onNewPosts(relabelLinks);
	}

	// Old posts contain full links to posts of this site; show them as >>>/board/id like new ones.
	function relabelLinks(root) {
		Array.prototype.forEach.call((root.querySelectorAll ? root : document).querySelectorAll('div.body a[href]'), function (a) {
			var text = (a.textContent || '').trim();
			if (!/^https?:\/\//i.test(text)) return;
			var m = /^https?:\/\/([^\/]+)\/([a-z0-9_]+)\/res\/(\d+)(?:\+50)?\.html(?:#q?(\d+))?$/i.exec(text);
			if (!m || HOSTS.indexOf(m[1]) < 0) return;
			a.textContent = '>>>/' + m[2] + '/' + (m[4] || m[3]);
			a.removeAttribute('target');
			a.removeAttribute('rel');
			a.setAttribute('href', '/' + m[2] + '/res/' + m[3] + '.html' + (m[4] ? '#' + m[4] : ''));
		});
	}

	/* ========================================================== word filter */

	// Entries: {p: text, re: regular expression?, thread: also hide whole threads whose first post matches}
	function loadFilters() {
		var list;
		try { list = JSON.parse(store('nichan_word_filter') || 'null'); } catch (e) { list = null; }
		if (!list) {
			// carry over the words of the old filter (filtr-sliv.js)
			var old = [];
			try { old = JSON.parse(store('slowka') || '[]') || []; } catch (e) {}
			list = old.map(function (w) { return { p: String(w), re: false, thread: false }; });
			store('nichan_word_filter', JSON.stringify(list));
		}
		return list;
	}
	function saveFilters(list) { store('nichan_word_filter', JSON.stringify(list)); }
	function compileFilters() {
		return loadFilters().map(function (f) {
			var t;
			if (f.re) {
				try {
					var r = new RegExp(f.p, 'i');
					t = function (s) { return r.test(s); };
				} catch (e) { return null; }
			} else {
				var w = f.p.toLowerCase();
				t = function (s) { return s.toLowerCase().indexOf(w) >= 0; };
			}
			return { test: t, thread: !!f.thread, field: f.field || 'text', img: !!f.img };
		}).filter(Boolean);
	}

	// what a filter entry looks at: the text (with the subject), the name or the tripcode
	function postField(post, field) {
		if (field === 'name') { var n = post.querySelector('p.intro .name'); return n ? n.textContent.trim() : ''; }
		if (field === 'trip') { var t = post.querySelector('p.intro .trip'); return t ? t.textContent.trim() : ''; }
		return postText(post);
	}
	function matches(tests, post, want) {
		var cache = {};
		return tests.some(function (t) {
			if (!want(t)) return false;
			if (!(t.field in cache)) cache[t.field] = postField(post, t.field);
			return cache[t.field] !== '' && t.test(cache[t.field]);
		});
	}
	// "pictures only" entries: the post stays, its pictures go (a link shows them)
	function hidePictures(post, on) {
		var link = post.querySelector('.nichan-img-show');
		post.classList.toggle('nichan-hide-img', on);
		if (on && !link) {
			link = document.createElement('div');
			link.className = 'nichan-img-show unimportant';
			link.innerHTML = esc(T.imgHidden) + ' <a href="javascript:void(0)">[' + T.show + ']</a>';
			link.querySelector('a').addEventListener('click', function () { post.setAttribute('data-nichan-img-shown', '1'); hidePictures(post, false); });
			var intro = post.querySelector('p.intro');
			if (intro) intro.parentNode.insertBefore(link, intro.nextSibling);
		} else if (!on && link) link.remove();
	}
	function postText(post) {
		var body = post.querySelector('div.body');
		var subj = post.querySelector('.subject');
		return (subj ? subj.textContent + '\n' : '') + (body ? (body.getAttribute('data-nichan-original-text') || body.textContent) : '');
	}

	// A small "hidden" bar in place of a post or thread, with a link to show it anyway.
	function makeStub(target, text, onShow, kind) {
		var prev = target.previousElementSibling;
		if (prev && prev.classList && prev.classList.contains('nichan-stub') && prev.getAttribute('data-kind') === kind) return prev;
		var stub = document.createElement('div');
		stub.className = 'nichan-filtered nichan-stub';
		stub.setAttribute('data-kind', kind);
		stub.innerHTML = esc(text) + ' <a href="javascript:void(0)">[' + T.show + ']</a>';
		stub.querySelector('a').addEventListener('click', function () { stub.remove(); onShow(); });
		target.parentNode.insertBefore(stub, target);
		return stub;
	}
	function removeStub(target, kind) {
		var prev = target.previousElementSibling;
		if (prev && prev.classList && prev.classList.contains('nichan-stub') && prev.getAttribute('data-kind') === kind) prev.remove();
	}

	function applyFilters(root, quiet) {
		var tests = compileFilters();
		var hidden = hiddenPosts();
		Array.prototype.forEach.call((root || document).querySelectorAll('div.post'), function (post) {
			var inPreview = !!post.closest('.nichan-preview');
			if (inPreview && !quiet) return;
			if (!post.hasAttribute('data-nichan-img-shown'))
				hidePictures(post, matches(tests, post, function (t) { return t.img; }));
			if (post.classList.contains('op')) {
				// OPs only react to entries marked "hide thread"; then the whole thread goes.
				var thread = post.closest('.thread');
				if (!thread || inPreview) return;
				var hitThread = matches(tests, post, function (t) { return t.thread && !t.img; });
				if (hitThread && !thread.hasAttribute('data-nichan-shown')) {
					thread.style.display = 'none';
					makeStub(thread, T.threadHidden, function () { thread.setAttribute('data-nichan-shown', '1'); thread.style.display = ''; }, 'thread');
				} else if (!hitThread) {
					removeStub(thread, 'thread');
					if (thread.style.display === 'none') thread.style.display = '';
				}
				return;
			}
			var manual = !inPreview && !!hidden[postKey(post)];
			var hit = !manual && matches(tests, post, function (t) { return !t.img; });
			if ((hit || manual) && !post.hasAttribute('data-nichan-shown')) {
				post.style.display = 'none';
				removeStub(post, manual ? 'filter' : 'manual');
				makeStub(post, manual ? T.hiddenManual : T.hidden, function () {
					if (manual) unhidePost(post);
					else post.setAttribute('data-nichan-shown', '1');
					post.style.display = '';
				}, manual ? 'manual' : 'filter');
			} else if (!hit && !manual) {
				removeStub(post, 'filter');
				removeStub(post, 'manual');
				// (a thread folded with [–] on a board page, hide-threads.js: its posts stay hidden until [+])
				var folded = post.closest('.thread');
				if (post.style.display === 'none' && !(folded && folded.querySelector('.unhide-thread-link'))) post.style.display = '';
			}
		});
	}

	// The panel floats over the page, so it needs the style's own (opaque) background colour.
	function pageBackground() {
		var els = [document.body, document.documentElement];
		for (var i = 0; i < els.length; i++) {
			var c = getComputedStyle(els[i]).backgroundColor;
			if (c && c !== 'transparent' && !/rgba\(.*,\s*0\)$/.test(c)) return c;
		}
		return '#fff';
	}

	function setupFilterUI() {
		var anchor = document.getElementById('mute_images');
		var host = anchor ? anchor.parentNode : null;
		var wrap = document.createElement('div');
		wrap.className = 'nichan-filter';
		// a row of icons (their names show on hover and as the panels' titles)
		wrap.innerHTML = '<div class="nichan-tools">' +
			'<a class="nichan-filter-toggle" href="javascript:void(0)" title="' + T.filter + '"><i class="fa fa-filter"></i></a>' +
			'<a class="nichan-saved-toggle" href="javascript:void(0)" title="' + T.saved + '"><i class="fa fa-bookmark"></i><span class="nichan-saved-count"></span></a>' +
			'<a class="nichan-myposts-toggle" href="javascript:void(0)" title="' + T.myPosts + '"><i class="fa fa-user"></i></a>' +
			'<a href="/settings.php' + (EN ? '?lang=en' : '') + '" title="' + T.allSettings + '"><i class="fa fa-cog"></i></a></div>' +
			'<div class="nichan-filter-panel nichan-myposts-panel" style="display:none"><button type="button" class="nichan-filter-close" title="' + T.close + '">×</button>' +
			'<div class="nichan-filter-title">' + T.myPosts + '</div><ul class="nichan-filter-list nichan-myposts"></ul></div>' +
			'<div class="nichan-filter-panel nichan-saved-panel" style="display:none">' +
			'<button type="button" class="nichan-filter-close" title="' + T.close + '">×</button>' +
			'<div class="nichan-filter-title">' + T.saved + '</div><ul class="nichan-filter-list nichan-saved-list"></ul></div>' +
			'<div class="nichan-filter-panel nichan-words-panel" style="display:none">' +
			'<button type="button" class="nichan-filter-close" title="' + T.close + '">×</button>' +
			'<div class="nichan-filter-title">' + T.filterTitle + '</div>' +
			'<form class="nichan-filter-add"><select class="f-field"><option value="text">' + T.fText + '</option><option value="name">' + T.fName + '</option><option value="trip">' + T.fTrip + '</option></select> ' +
			'<input type="text" placeholder="' + T.filterPh + '"> ' +
			'<label title="' + T.fImgHint + '"><input type="checkbox" class="f-img"> ' + T.fImg + '</label> ' +
			'<label><input type="checkbox" class="f-re"> ' + T.regex + '</label> ' +
			'<label title="' + T.hideThreadHint + '"><input type="checkbox" class="f-thread"> ' + T.hideThread + '</label> ' +
			'<button type="submit">' + T.add + '</button></form>' +
			'<ul class="nichan-filter-list"></ul></div>';
		if (host && host.parentNode) {
			// "мати в кімнаті" (mute-images.js) becomes the first icon of the row
			host.parentNode.insertBefore(wrap, host.nextSibling);
			var tools = wrap.querySelector('.nichan-tools');
			tools.insertBefore(anchor, tools.firstChild);
			host.remove();
		} else {
			var hr = document.querySelector('hr');
			if (!hr) return;
			hr.parentNode.insertBefore(wrap, hr);
		}
		var tree = document.getElementById('treeview');
		if (tree) {
			var treeHost = tree.parentNode;
			wrap.querySelector('.nichan-tools').insertBefore(tree, wrap.querySelector('.nichan-filter-toggle'));
			if (treeHost.classList.contains('nichan-tree-host')) treeHost.remove();
		}
		// (every panel shares .nichan-filter-panel for its looks: each is found by its own class)
		var panel = wrap.querySelector('.nichan-words-panel'), list = panel.querySelector('.nichan-filter-list');
		var savedPanel = wrap.querySelector('.nichan-saved-panel'), savedList = wrap.querySelector('.nichan-saved-list');
		savedLink = wrap.querySelector('.nichan-saved-toggle');
		renderSaved = function () {
			var items = savedThreads();
			showSavedTotal();
			savedList.innerHTML = items.length ? '' : '<li class="unimportant">' + T.savedNone + '</li>';
			items.forEach(function (it) {
				var li = document.createElement('li');
				li.innerHTML = '<a class="s-link"></a> <a href="javascript:void(0)" class="s-del" title="' + T.unsave + '">[x]</a>';
				var a = li.querySelector('.s-link');
				a.href = it.u;
				a.textContent = '/' + it.b + '/ ' + it.id + ' — ' + it.t;
				var c = savedCounts[it.b + ':' + it.id];
				if (c) {
					var n = document.createElement('span');
					n.className = 'nichan-saved-new';
					n.textContent = c.gone ? ' (' + T.gone + ')' : c.n ? ' +' + c.n + ' ' + T.newShort + (c.you ? ', ' + c.you + ' ' + T.toYouShort : '') : '';
					if (c.you) n.classList.add('to-you');
					a.parentNode.insertBefore(n, a.nextSibling);
				}
				li.querySelector('.s-del').addEventListener('click', function () { unsaveThread({ board: it.b, id: it.id }); });
				savedList.appendChild(li);
			});
		};
		wrap.querySelector('.nichan-saved-toggle').addEventListener('click', function () {
			panel.style.display = wrap.querySelector('.nichan-myposts-panel').style.display = 'none';
			savedPanel.style.display = savedPanel.style.display === 'none' ? '' : 'none';
			if (savedPanel.style.display === '') { savedPanel.style.backgroundColor = pageBackground(); renderSaved(); checkSaved(true); }
		});
		savedPanel.querySelector('.nichan-filter-close').addEventListener('click', function () { savedPanel.style.display = 'none'; });
		var myPanel = wrap.querySelector('.nichan-myposts-panel');
		wrap.querySelector('.nichan-myposts-toggle').addEventListener('click', function () {
			panel.style.display = savedPanel.style.display = 'none';
			myPanel.style.display = myPanel.style.display === 'none' ? '' : 'none';
			if (myPanel.style.display === '') { myPanel.style.backgroundColor = pageBackground(); renderMyPosts(myPanel.querySelector('ul')); }
		});
		myPanel.querySelector('.nichan-filter-close').addEventListener('click', function () { myPanel.style.display = 'none'; });
		var form = wrap.querySelector('form'), input = form.querySelector('input[type=text]');
		var isRe = form.querySelector('.f-re'), isThread = form.querySelector('.f-thread'), fField = form.querySelector('.f-field'), isImg = form.querySelector('.f-img');

		function render() {
			var items = loadFilters();
			list.innerHTML = items.length ? '' : '<li class="unimportant">' + T.none + '</li>';
			items.forEach(function (f, i) {
				var li = document.createElement('li');
				li.innerHTML = '<code></code> <label title="' + T.hideThreadHint + '"><input type="checkbox"' + (f.thread ? ' checked' : '') + '> ' +
					T.hideThread + '</label> <a href="javascript:void(0)" title="' + T.remove + '">[×]</a>';
				li.querySelector('code').textContent = (f.field === 'name' ? T.fName + ': ' : f.field === 'trip' ? T.fTrip + ': ' : '') + (f.re ? '/' + f.p + '/' : f.p) + (f.img ? ' (' + T.fImg + ')' : '');
				li.querySelector('input').addEventListener('change', function () {
					var all = loadFilters();
					all[i].thread = this.checked;
					saveFilters(all);
					applyFilters(document);
				});
				li.querySelector('a').addEventListener('click', function () {
					var all = loadFilters();
					all.splice(i, 1);
					saveFilters(all);
					render();
					applyFilters(document);
				});
				list.appendChild(li);
			});
		}
		wrap.querySelector('.nichan-filter-toggle').addEventListener('click', function () {
			savedPanel.style.display = myPanel.style.display = 'none';
			panel.style.display = panel.style.display === 'none' ? '' : 'none';
			if (panel.style.display === '') { panel.style.backgroundColor = pageBackground(); render(); input.focus(); }
		});
		panel.querySelector('.nichan-filter-close').addEventListener('click', function () { panel.style.display = 'none'; });
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var p = input.value.trim(), re = isRe.checked;
			var m = /^\/(.+)\/$/.exec(p);
			if (m) { p = m[1]; re = true; }
			if (!p) return;
			if (re) {
				try { new RegExp(p, 'i'); } catch (err) { alert(T.badRegex + err.message); return; }
			}
			var all = loadFilters();
			all.push({ p: p, re: re, thread: isThread.checked && !isImg.checked, field: fField.value, img: isImg.checked });
			saveFilters(all);
			input.value = '';
			isRe.checked = isThread.checked = isImg.checked = false;
			render();
			applyFilters(document);
		});
	}

	/* ========================================================= hidden posts */

	// Posts hidden with [x] next to the post number, remembered per board in this browser.
	function postKey(post) {
		var t = post.closest('[data-board]');
		return (t ? t.getAttribute('data-board') : board) + ':' + (post.id || '').replace(/^(reply|op)_/, '');
	}
	function hiddenPosts() {
		try { return JSON.parse(store('nichan_hidden_posts') || '{}') || {}; } catch (e) { return {}; }
	}
	function hidePost(post) {
		var h = hiddenPosts();
		h[postKey(post)] = Math.floor(Date.now() / 1000);
		// keep the list from growing forever
		var keys = Object.keys(h);
		if (keys.length > 3000)
			keys.sort(function (a, b) { return h[a] - h[b]; }).slice(0, keys.length - 3000).forEach(function (k) { delete h[k]; });
		store('nichan_hidden_posts', JSON.stringify(h));
		post.removeAttribute('data-nichan-shown');
		applyFilters(post.parentNode);
	}
	function unhidePost(post) {
		var h = hiddenPosts();
		delete h[postKey(post)];
		store('nichan_hidden_posts', JSON.stringify(h));
	}
	/* ============================================================ post menu */

	// A menu icon next to every post number: hide, reply, report, delete, and for the first post of
	// a thread also save / unsave the thread. (The old per-post checkboxes are for staff only now.)
	var FA = !!document.querySelector('link[href*="font-awesome"]');
	function addPostMenus(root) {
		Array.prototype.forEach.call((root.querySelectorAll ? root : document).querySelectorAll('div.post > p.intro'), function (intro) {
			if (intro.querySelector('.nichan-menu-btn') || intro.closest('.nichan-preview')) return;
			var nums = intro.querySelectorAll('a.post_no');
			var after = nums[nums.length - 1];
			if (!after) return;
			var btn = document.createElement('a');
			btn.href = 'javascript:void(0)';
			btn.className = 'nichan-menu-btn';
			btn.title = T.menu;
			// a triangle pointing right, straight after the post number
			btn.innerHTML = FA ? '<i class="fa fa-play"></i>' : '&#9654;&#xFE0E;';
			after.parentNode.insertBefore(btn, after.nextSibling);
			// the first post of a thread: a bookmark before the triangle saves / unsaves the thread
			if (intro.parentNode.classList.contains('op')) {
				var save = document.createElement('a');
				save.href = 'javascript:void(0)';
				save.className = 'nichan-menu-btn nichan-save-btn';
				btn.parentNode.insertBefore(save, btn);
				setSaveButton(save);
			}
		});
	}
	function setSaveButton(save) {
		var post = save.closest('div.post');
		var on = post ? isSaved(postInfo(post)) : false;
		save.title = on ? T.unsave : T.save;
		save.classList.toggle('saved', on);
		save.innerHTML = FA ? '<i class="fa fa-bookmark' + (on ? '' : '-o') + '"></i>' : (on ? '&#9733;' : '&#9734;');
	}
	// (the saved list changes from the panel of saved threads too)
	function refreshSaveButtons() {
		Array.prototype.forEach.call(document.querySelectorAll('.nichan-save-btn'), setSaveButton);
	}

	var menuEl = null;
	function closeMenu() {
		if (menuEl) { menuEl.remove(); menuEl = null; }
	}
	function postInfo(post) {
		var thread = post.closest('.thread');
		var nums = post.querySelectorAll('p.intro a.post_no');
		var link = nums[0] ? nums[0].getAttribute('href') || '' : '';
		return {
			board: thread ? thread.getAttribute('data-board') : board,
			id: postIdOf(post),
			op: post.classList.contains('op'),
			url: link.replace(/^\?\/?/, '/').replace(/#.*$/, ''),
			replyLink: nums[1] || null
		};
	}
	function openMenu(btn) {
		closeMenu();
		var post = btn.closest('div.post');
		if (!post) return;
		var info = postInfo(post);
		// the quick reply always comes first
		var items = [[T.reply, function () { replyTo(post); }]];
		if (!info.op) items.push([T.hide, function () { hidePost(post); }]);
		else if (post.closest('.thread') && post.closest('.thread').querySelector('.hide-thread-link'))
			items.push([T.hide, function () { post.closest('.thread').querySelector('.hide-thread-link').click(); }]);
		if (!window.inMod) {
			items.push([T.report, function () { reportDialog(info); }]);
			items.push([T.del, function () { deleteDialog(info, post); }]);
		}
		menuEl = document.createElement('div');
		menuEl.className = 'nichan-menu';
		menuEl.style.backgroundColor = pageBackground();
		items.forEach(function (it) {
			var a = document.createElement('a');
			a.href = 'javascript:void(0)';
			a.textContent = it[0];
			a.addEventListener('click', function (e) { e.preventDefault(); closeMenu(); it[1](); });
			menuEl.appendChild(a);
		});
		document.body.appendChild(menuEl);
		var r = btn.getBoundingClientRect();
		var left = Math.min(r.left + window.pageXOffset, window.pageXOffset + document.documentElement.clientWidth - menuEl.offsetWidth - 6);
		menuEl.style.left = Math.max(4, left) + 'px';
		menuEl.style.top = (r.bottom + window.pageYOffset + 2) + 'px';
	}
	function setupPostMenus() {
		document.addEventListener('click', function (e) {
			var save = e.target.closest && e.target.closest('.nichan-save-btn');
			if (save) {
				e.preventDefault();
				closeMenu();
				var post = save.closest('div.post'), info = postInfo(post);
				if (isSaved(info)) unsaveThread(info);
				else saveThread(info, post);
				return;
			}
			var btn = e.target.closest && e.target.closest('.nichan-menu-btn');
			if (btn) {
				e.preventDefault();
				if (menuEl && menuEl.getAttribute('data-for') === postIdOf(btn.closest('div.post') || {})) { closeMenu(); return; }
				openMenu(btn);
				if (menuEl) menuEl.setAttribute('data-for', postIdOf(btn.closest('div.post')));
				return;
			}
			if (menuEl && !(e.target.closest && e.target.closest('.nichan-menu'))) closeMenu();
		});
		document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeMenu(); closeDialog(); } });
	}

	// Report / delete dialogs: the same requests the old forms under the posts sent.
	var dialogEl = null;
	function closeDialog() {
		if (dialogEl) { dialogEl.remove(); dialogEl = null; }
	}
	function dialog(title, bodyHtml, button, onSubmit) {
		closeDialog();
		dialogEl = document.createElement('div');
		dialogEl.className = 'nichan-dialog-back';
		dialogEl.innerHTML = '<form class="nichan-dialog"><button type="button" class="nichan-dialog-close" title="' + T.close + '">×</button>' +
			'<div class="nichan-dialog-title"></div>' + bodyHtml +
			'<div class="nichan-dialog-msg"></div><p class="nichan-dialog-buttons"><button type="submit"></button> <button type="button" class="d-cancel">' + T.cancel + '</button></p></form>';
		var form = dialogEl.querySelector('form');
		form.style.backgroundColor = pageBackground();
		form.querySelector('.nichan-dialog-title').textContent = title;
		form.querySelector('button[type=submit]').textContent = button;
		form.querySelector('.nichan-dialog-close').addEventListener('click', closeDialog);
		form.querySelector('.d-cancel').addEventListener('click', closeDialog);
		if (onSubmit === closeDialog) form.querySelector('.d-cancel').remove(); // a plain notice: one button
		dialogEl.addEventListener('click', function (e) { if (e.target === dialogEl) closeDialog(); });
		form.addEventListener('submit', function (e) { e.preventDefault(); onSubmit(form); });
		document.body.appendChild(dialogEl);
		var first = form.querySelector('textarea, input[type=password]');
		if (first) first.focus();
		return form;
	}
	function sendPostAction(form, fields, done) {
		var msg = form.querySelector('.nichan-dialog-msg'), submit = form.querySelector('button[type=submit]');
		var data = new FormData();
		Object.keys(fields).forEach(function (k) { data.append(k, fields[k]); });
		data.append('json_response', '1');
		submit.disabled = true;
		msg.textContent = T.sending;
		fetch('/post.php', { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (text) {
			submit.disabled = false;
			var res;
			try { res = JSON.parse(text); } catch (e) { res = { error: T.failed }; }
			if (res && res.error) { msg.innerHTML = ''; msg.appendChild(document.createTextNode(String(res.error).replace(/<[^>]*>/g, ''))); return; }
			done();
		}).catch(function () { submit.disabled = false; msg.textContent = T.failed; });
	}
	// Ready reasons (the moderators see the same words every time) and details; "Other" needs details.
	function reportDialog(info) {
		var last = T.reasons.length - 1;
		var radios = T.reasons.map(function (r, i) {
			return '<label class="nichan-reason"><input type="radio" name="preset" value="' + i + '"> ' + esc(r) + '</label>';
		}).join('');
		dialog(T.reportTitle.replace('%s', info.id), '<div class="nichan-reasons">' + radios + '</div>' +
			'<textarea name="reason" rows="2" maxlength="250" placeholder="' + T.reasonMore + '"></textarea>', T.reportBtn, function (form) {
			var picked = form.querySelector('input[name=preset]:checked');
			var more = form.reason.value.trim();
			var msg = form.querySelector('.nichan-dialog-msg');
			if (!picked) { msg.textContent = T.reasonPick; return; }
			var i = parseInt(picked.value, 10);
			if (i === last && !more) { msg.textContent = T.reasonPh; form.reason.focus(); return; }
			var reason = i === last ? more : T.reasons[i] + (more ? ': ' + more : '');
			var fields = { board: info.board, reason: reason, report: '1' };
			fields['delete_' + info.id] = 'on';
			sendPostAction(form, fields, function () {
				form.innerHTML = '<p class="nichan-dialog-title">' + T.reported + '</p>';
				setTimeout(closeDialog, 1500);
			});
		});
	}
	function deleteDialog(info, post) {
		var pw = '';
		try { pw = localStorage.password || ''; } catch (e) {}
		var form = dialog(T.deleteTitle.replace('%s', info.id), '<p><label>' + T.password + ' <input type="password" name="password" size="16" maxlength="18"></label></p>' +
			'<p><label><input type="checkbox" name="file"> ' + T.fileOnly + '</label></p>', T.delBtn, function (form) {
			var fields = { board: info.board, password: form.password.value, 'delete': '1' };
			fields['delete_' + info.id] = 'on';
			if (form.file.checked) fields.file = 'on';
			var fileOnly = form.file.checked;
			sendPostAction(form, fields, function () {
				closeDialog();
				if (fileOnly) { location.reload(); return; }
				if (info.op && window.active_page === 'thread') { location.href = '/' + info.board + '/'; return; }
				var target = info.op ? post.closest('.thread') : post;
				if (target.nextSibling && target.nextSibling.nodeName === 'BR') target.nextSibling.remove();
				target.remove();
			});
		});
		form.password.value = pw;
	}

	// Saved threads, across all boards, remembered in this browser.
	function savedThreads() {
		try { return JSON.parse(store('nichan_saved_threads') || '[]') || []; } catch (e) { return []; }
	}
	function isSaved(info) {
		return savedThreads().some(function (s) { return s.b === info.board && s.id === info.id; });
	}
	function saveThread(info, post) {
		var list = savedThreads().filter(function (s) { return !(s.b === info.board && s.id === info.id); });
		var subj = post.querySelector('.subject'), body = post.querySelector('div.body');
		var title = ((subj && subj.textContent.trim()) || (body && body.textContent.trim()) || ('#' + info.id)).replace(/\s+/g, ' ').slice(0, 80);
		list.unshift({ b: info.board, id: info.id, t: title, u: info.url || ('/' + info.board + '/res/' + info.id + '.html'), at: Math.floor(Date.now() / 1000),
			seen: maxPostId(post.closest('.thread') || post) });
		store('nichan_saved_threads', JSON.stringify(list.slice(0, 500)));
		renderSaved();
		refreshSaveButtons();
	}
	function unsaveThread(info) {
		store('nichan_saved_threads', JSON.stringify(savedThreads().filter(function (s) { return !(s.b === info.board && s.id === info.id); })));
		renderSaved();
		refreshSaveButtons();
	}
	var renderSaved = function () {};

	function maxPostId(root) {
		var ids = Array.prototype.map.call(root.querySelectorAll('div.post'), function (p) { return parseInt(postIdOf(p), 10) || 0; });
		return Math.max.apply(null, ids.concat([0]));
	}

	// New posts in the saved threads since they were last opened (and how many answer your posts),
	// from the threads' JSON files; checked at most once a minute.
	var savedCounts = {}, savedLink = null;
	function showSavedTotal() {
		if (!savedLink) return;
		var n = 0, you = 0;
		savedThreads().forEach(function (s) { var c = savedCounts[s.b + ':' + s.id]; if (c && !c.gone) { n += c.n; you += c.you; } });
		savedLink.querySelector('.nichan-saved-count').textContent = n ? ' (+' + n + (you ? ', ' + you + ' ' + T.toYouShort : '') + ')' : '';
		savedLink.classList.toggle('to-you', you > 0);
	}
	function loadSavedCounts() {
		var cache = null;
		try { cache = JSON.parse(store('nichan_saved_counts') || 'null'); } catch (e) {}
		if (cache && cache.c) savedCounts = cache.c;
		return cache;
	}
	function checkSaved(force) {
		var list = savedThreads().slice(0, 30);
		if (!list.length) return;
		var cache = loadSavedCounts();
		showSavedTotal();
		if (cache && Date.now() - cache.t < (force ? 15000 : 60000)) return;
		store('nichan_saved_counts', JSON.stringify({ t: Date.now(), c: savedCounts }));
		Promise.all(list.map(function (s) {
			return fetch('/' + s.b + '/res/' + s.id + '.json', { credentials: 'same-origin', cache: 'no-cache' }).then(function (r) {
				if (r.status === 404) return { gone: true };
				return r.ok ? r.json() : null;
			}).then(function (j) {
				if (!j) return;
				if (j.gone) { savedCounts[s.b + ':' + s.id] = { gone: true, n: 0, you: 0 }; return; }
				var posts = j.posts || [], mine = ownIds(s.b), c = { n: 0, you: 0 };
				if (s.seen === undefined) { // saved before counting existed: start from now
					markSeen(s.b, s.id, Math.max.apply(null, posts.map(function (p) { return p.no; }).concat([0])));
				} else {
					posts.forEach(function (p) {
						if (p.no <= s.seen) return;
						c.n++;
						var m, re = /&gt;&gt;(\d+)/g, hit = false;
						while ((m = re.exec(p.com || ''))) if (mine.indexOf(m[1]) >= 0) hit = true;
						if (hit) c.you++;
					});
				}
				savedCounts[s.b + ':' + s.id] = c;
			}).catch(function () {});
		})).then(function () {
			store('nichan_saved_counts', JSON.stringify({ t: Date.now(), c: savedCounts }));
			renderSaved();
			showSavedTotal();
		});
	}
	function markSeen(b, id, seen) {
		var list = savedThreads(), changed = false;
		list.forEach(function (s) {
			if (s.b === b && s.id === id && (s.seen === undefined || seen > s.seen)) { s.seen = seen; changed = true; }
		});
		if (!changed) return;
		store('nichan_saved_threads', JSON.stringify(list));
		var cache = loadSavedCounts(), c = savedCounts[b + ':' + id];
		if (c && !c.gone) { c.n = 0; c.you = 0; store('nichan_saved_counts', JSON.stringify({ t: cache ? cache.t : 0, c: savedCounts })); }
		showSavedTotal();
	}
	// on a saved thread's page everything shown counts as seen
	function markThreadSeen() {
		var form = threadPageForm(), thread = document.querySelector('div.thread');
		if (!form || !thread) return;
		var b = (form.querySelector('input[name="board"]') || {}).value || board;
		markSeen(b, form.querySelector('input[name="thread"]').value, maxPostId(thread));
	}

	/* ======================================================= replies to you */

	// Your posts are remembered by show-own-posts.js (localStorage.own_posts, per board).
	function ownIds(b) {
		try { return ((JSON.parse(store('own_posts') || '{}') || {})[b] || []).map(String); } catch (e) { return []; }
	}
	var youBox = null, youAt = -1;
	function markRepliesToYou() {
		var mine = {}, found = [];
		Array.prototype.forEach.call(document.querySelectorAll('.thread div.post'), function (post) {
			if (post.closest('.nichan-preview')) return;
			var thread = post.closest('.thread');
			var b = (thread && thread.getAttribute('data-board')) || board;
			var ids = mine[b] || (mine[b] = ownIds(b));
			var hit = ids.length && ids.indexOf(postIdOf(post)) < 0 && Array.prototype.some.call(post.querySelectorAll('div.body a'), function (a) {
				var m = /^>>(\d+)$/.exec((a.textContent || '').trim());
				return m && ids.indexOf(m[1]) >= 0;
			});
			post.classList.toggle('nichan-to-you', !!hit);
			if (hit) found.push(post);
		});
		if (!found.length) { if (youBox) youBox.style.display = 'none'; return; }
		if (!youBox) {
			youBox = document.createElement('a');
			youBox.href = 'javascript:void(0)';
			youBox.className = 'nichan-you-box';
			youBox.title = T.toYou;
			youBox.addEventListener('click', function () {
				var list = document.querySelectorAll('div.post.nichan-to-you');
				if (!list.length) return;
				youAt = (youAt + 1) % list.length;
				list[youAt].scrollIntoView({ block: 'center' });
				list[youAt].classList.add('highlighted');
				setTimeout(function (p) { p.classList.remove('highlighted'); }.bind(null, list[youAt]), 1500);
			});
			document.body.appendChild(youBox);
		}
		youBox.textContent = T.toYou + ': ' + found.length;
		youBox.style.display = '';
	}

	/* =============================================================== push */

	// The browser's push subscription goes to push.php together with this visitor's posts (from
	// show-own-posts.js); the site then sends a notification when someone answers one of them.
	function pushSupported() {
		return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && !window.inMod;
	}
	function pushPost(fields) {
		var data = new FormData();
		Object.keys(fields).forEach(function (k) { data.append(k, fields[k]); });
		return fetch('/push.php', { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) { return r.ok; });
	}
	function ownPostList() {
		var list = [];
		try {
			var own = JSON.parse(store('own_posts') || '{}') || {};
			Object.keys(own).forEach(function (b) { (own[b] || []).slice(-100).forEach(function (id) { list.push([b, parseInt(id, 10)]); }); });
		} catch (e) {}
		return list.slice(-300);
	}
	function b64uToBytes(s) {
		var raw = atob(s.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((s.length + 3) % 4));
		var out = new Uint8Array(raw.length);
		for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
		return out;
	}
	function pushOn() {
		if (/iPhone|iPad/.test(navigator.userAgent) && !standalone) return Promise.reject(new Error(T.pushIos));
		return Notification.requestPermission().then(function (p) {
			if (p !== 'granted') throw new Error('denied');
			return Promise.all([navigator.serviceWorker.register('/sw.js').then(function () { return navigator.serviceWorker.ready; }),
				fetch('/push.php?key').then(function (r) { return r.json(); })]);
		}).then(function (res) {
			var reg = res[0], key = res[1].key;
			return reg.pushManager.getSubscription().then(function (old) {
				return old || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64uToBytes(key) });
			});
		}).then(function (sub) {
			return pushPost({ action: 'subscribe', sub: JSON.stringify(sub), posts: JSON.stringify(ownPostList()), lang: EN ? 'en' : 'uk' });
		}).then(function (ok) {
			if (!ok) throw new Error('server');
			store('nichan_push', '1');
			return true;
		});
	}
	function pushOff() {
		store('nichan_push', '0');
		return navigator.serviceWorker.ready.then(function (reg) { return reg.pushManager.getSubscription(); }).then(function (sub) {
			if (!sub) return false;
			pushPost({ action: 'unsubscribe', endpoint: sub.endpoint });
			return sub.unsubscribe().then(function () { return false; });
		});
	}
	// a new post of this visitor: its replies will notify too
	function pushOwnPost(id) {
		if (store('nichan_push') !== '1' || !pushSupported() || !id) return;
		navigator.serviceWorker.ready.then(function (reg) { return reg.pushManager.getSubscription(); }).then(function (sub) {
			if (sub) pushPost({ action: 'own', endpoint: sub.endpoint, board: board, id: id });
		}).catch(function () {});
	}

	/* ========================================================== forms shown */

	// The post form of a thread opens with "Відповісти" and of a board page with "Створити нитку"; each
	// button then says how to hide it again. Both start closed.
	function setupFormButtons() {
		var boardBtn = document.querySelector('.createbutton:not(.nichan-replybtn) a');
		var hidden = document.getElementById('hiddenform');
		if (boardBtn && hidden) {
			var setBoard = function (open) {
				hidden.className = open ? 'unhidden' : 'hidden';
				boardBtn.textContent = '[' + (open ? T.hideNew : T.newThread) + ']';
			};
			boardBtn.removeAttribute('href');
			boardBtn.href = 'javascript:void(0)';
			boardBtn.addEventListener('click', function (e) {
				e.preventDefault();
				if (inline) { closeInline(); setBoard(true); return; } // (the form was under a post)
				setBoard(hidden.className !== 'unhidden');
			});
			onInlineOpen = function () { setBoard(false); };
			// closed at first (a draft stays inside); open only for "Створити продовження"
			setBoard(/[?&]continue=/.test(location.search));
		}
		var replyBtn = document.querySelector('.nichan-replybtn a'), wrap = document.querySelector('.nichan-replywrap');
		if (replyBtn && wrap) {
			var setReply = function (open) {
				wrap.classList.toggle('open', open);
				replyBtn.textContent = '[' + (open ? T.hideReply : T.replyForm) + ']';
			};
			openReplyForm = function () { setReply(true); };
			replyBtn.addEventListener('click', function () {
				// the quick reply closes and its form (with what was typed) opens here
				if (inline) { closeInline(); setReply(true); return; }
				setReply(!wrap.classList.contains('open'));
			});
			onInlineOpen = function () { setReply(false); };
			setReply(false); // closed at first (a draft stays inside)
			// the reply was posted and is on the page now: the form closes, as the quick reply does
			if (window.jQuery) window.jQuery(document).on('ajax_after_post', function () { setTimeout(function () { setReply(false); }, 0); });
		}
	}
	var openReplyForm = function () {};
	var onInlineOpen = function () {}; // (the form leaves its place for the quick reply)

	/* ================================================================ header */

	// The top menu follows the page over everything (default) or, pinned with the pin, stays at the top of
	// the page. Either way the page starts below it, so the board's title isn't hidden under it.
	// Boards the visitor hid from the top menu (settings page): their links and dropdown entries go away.
	function navHidden() {
		try { var v = JSON.parse(store('nichan_nav_hidden') || '[]'); return Array.isArray(v) ? v : []; } catch (e) { return []; }
	}
	function applyNavHidden() {
		var hidden = navHidden();
		Array.prototype.forEach.call(document.querySelectorAll('.nichan-nav-boards'), function (box) {
			var first = true;
			Array.prototype.forEach.call(box.querySelectorAll('a[data-board]'), function (a) {
				var off = hidden.indexOf(a.getAttribute('data-board')) >= 0, sep = a.previousElementSibling;
				a.style.display = off ? 'none' : '';
				if (sep && sep.classList.contains('nichan-nav-sep')) sep.style.display = off || first ? 'none' : '';
				if (!off) first = false;
			});
		});
		Array.prototype.forEach.call(document.querySelectorAll('.pages.topleft select'), function (sel) {
			if (!sel.nichanAll) sel.nichanAll = Array.prototype.slice.call(sel.options);
			while (sel.options.length) sel.remove(0);
			sel.nichanAll.forEach(function (o) { if (hidden.indexOf(o.getAttribute('data-board')) < 0) sel.appendChild(o); });
			sel.selectedIndex = 0;
		});
	}

	function setupHeader() {
		var menus = document.querySelectorAll('.pages.topleft, .pages.topright');
		if (!menus.length) return;
		// phones: the right part of the menu ([ Telegram / ФМ / settings ] and the pin) goes into the left
		// part's line, which wraps when it has to, instead of lying over it
		var left = document.querySelector('.pages.topleft'), right = document.querySelector('.pages.topright');
		var rightHome = right && document.createComment('top menu, right part');
		if (right) right.parentNode.insertBefore(rightHome, right);
		function placeRight() {
			if (!left || !right) return;
			var narrow = window.innerWidth <= 768;
			if (narrow && right.parentNode !== left) { left.appendChild(right); right.classList.add('nichan-tr-inline'); }
			else if (!narrow && right.parentNode === left) { rightHome.parentNode.insertBefore(right, rightHome.nextSibling); right.classList.remove('nichan-tr-inline'); }
		}
		placeRight();
		window.addEventListener('resize', placeRight);
		function room() {
			var h = 0, pinned = document.documentElement.classList.contains('nichan-header-pinned');
			// (the bottom edge: some styles put the menu a little below the top)
			Array.prototype.forEach.call(menus, function (m) { var r = m.getBoundingClientRect(); h = Math.max(h, pinned ? r.height : r.bottom); });
			document.documentElement.style.scrollPaddingTop = (h + 4) + 'px';
			// on narrow screens the menu takes the page's first lines: move the page down under it
			// (with priority: some styles set the page's padding with !important)
			if (document.body.classList.contains('portal-board-nav')) {
				var bottom = 0;
				Array.prototype.forEach.call(menus, function (menu) {
					var position = window.getComputedStyle(menu).position;
					if (position === 'absolute' || position === 'fixed') {
						bottom = Math.max(bottom, menu.getBoundingClientRect().bottom + (position === 'absolute' ? window.pageYOffset : 0));
					}
				});
				document.documentElement.style.setProperty('--portal-mast-height', Math.ceil(bottom + 4) + 'px');
			} else if (window.innerWidth <= 800) document.body.style.setProperty('padding-top', (h + 4) + 'px', 'important');
			else document.body.style.removeProperty('padding-top');
		}
		room();
		window.addEventListener('resize', room);
		window.addEventListener('load', room);
		// the menu grows when its icon font arrives or when it wraps onto a second line: the page moves with it
		if (window.ResizeObserver) { var ro = new ResizeObserver(function () { room(); }); Array.prototype.forEach.call(menus, function (m) { ro.observe(m); }); }
		if (window.jQuery) window.jQuery(window).on('stylesheet', function () { setTimeout(room, 150); });
		Array.prototype.forEach.call(document.querySelectorAll('.nichan-pin'), function (pin) {
			pin.addEventListener('click', function () {
				var on = !document.documentElement.classList.contains('nichan-header-pinned');
				document.documentElement.classList.toggle('nichan-header-pinned', on);
				store('nichan_header_pinned', on ? '1' : '0');
				room();
			});
		});
	}

	/* ================================================================ pictures */

	// Pictures open the same way everywhere, previews of quoted posts included: in the post (default) or in
	// a new tab (settings.php).
	function setupPictureClicks() {
		document.addEventListener('click', function (e) {
			if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey) return;
			var mode = store('nichan_img_open'), full = mode === 'full';
			// (a video's thumbnail is a small <video> in the link to the player page)
			var img = e.target.closest && e.target.closest('a > img.post-image, a > img.nichan-full, a > video.post-image');
			var a = img && img.parentNode;
			if (!a) return;
			// a video: its thumbnail is a small <video>, a picture, or (no ffmpeg on the server: MP4s) the file icon; the
			// link goes to the player page either way
			var isVideo = img.tagName === 'VIDEO' || /player\.php$/.test(a.pathname);
			if (isVideo && !full) {
				if (mode === 'tab') { e.stopPropagation(); a.target = '_blank'; return; } // (the player page)
				e.preventDefault();
				e.stopPropagation();
				toggleInlineVideo(a, img);
				return;
			}
			if (full && !a.closest('.nichan-viewer')) {
				e.preventDefault();
				e.stopPropagation(); // (inline-expanding.js and the video player stay out of it)
				// a video's link opens player.php?v=<file>: the file itself is shown
				var src = a.href, m = /[?&]v=([^&]+)/.exec(a.search || '');
				if (/player\.php$/.test(a.pathname) && m) src = decodeURIComponent(m[1]);
				openViewer(src, img.tagName === 'IMG' ? img.getAttribute('data-thumb') || img.src : '');
				return;
			}
			if (a.classList.contains('file')) return; // (videos keep their player)
			if (store('nichan_img_open') === 'tab') {
				e.stopPropagation(); // (inline-expanding.js stays out of it; the link opens a new tab)
				a.target = '_blank';
				return;
			}
			if (!a.closest('.nichan-preview')) return; // posts on the page: inline-expanding.js
			e.preventDefault();
			e.stopPropagation();
			if (img.classList.contains('nichan-full')) {
				img.src = img.getAttribute('data-thumb');
				img.classList.remove('nichan-full');
			} else {
				img.setAttribute('data-thumb', img.src);
				img.src = a.href;
				img.classList.add('nichan-full');
			}
		}, true);
	}

	// "На весь екран" (settings): the picture over the whole page, as big as it is or as the screen allows;
	// a click anywhere on it closes it. A video opens in a player the same way (its own clicks are the
	// player's, so the dark background, the cross or Esc close it).
	var viewer = null;
	function closeViewer() {
		if (!viewer) return;
		var v = viewer.querySelector('video');
		if (v) { try { v.pause(); } catch (e) {} v.removeAttribute('src'); }
		viewer.parentNode.removeChild(viewer);
		viewer = null;
		document.documentElement.classList.remove('nichan-viewer-open');
	}
	function openViewer(src, thumb) {
		closeViewer();
		var video = /\.(webm|mp4|m4v|ogv|mov)([?#]|$)/i.test(src);
		viewer = document.createElement('div');
		viewer.className = 'nichan-viewer' + (video ? ' nichan-viewer-video' : '');
		var el, player = null;
		if (video) {
			// (zoomed as a whole: the player and the layer that catches clicks on the picture)
			el = videoWithHitLayer(src);
			player = el.querySelector('video');
		} else {
			el = document.createElement('img');
			el.alt = '';
			// the thumbnail, stretched, until the picture itself has loaded
			if (thumb) { el.src = thumb; el.className = 'nichan-viewer-loading'; }
			var full = new Image();
			full.onload = function () { el.src = src; el.className = ''; };
			full.src = src;
			if (!thumb) el.src = src;
		}
		var x = document.createElement('a');
		x.href = 'javascript:void(0)';
		x.className = 'nichan-viewer-close';
		x.title = T.close;
		x.textContent = '×';
		viewer.appendChild(el);
		viewer.appendChild(x);
		// the wheel zooms around the pointer; every opening starts at the normal size
		var scale = 1, tx = 0, ty = 0;
		viewer.addEventListener('wheel', function (e) {
			e.preventDefault();
			var next = Math.min(20, Math.max(0.2, scale * Math.pow(1.0015, -e.deltaY * (e.deltaMode === 1 ? 33 : 1))));
			// the untransformed centre of the picture, and the pointer: the point under the pointer stays there
			var cx = el.offsetLeft + el.offsetWidth / 2, cy = el.offsetTop + el.offsetHeight / 2;
			tx = e.clientX - cx - (next / scale) * (e.clientX - cx - tx);
			ty = e.clientY - cy - (next / scale) * (e.clientY - cy - ty);
			scale = next;
			if (Math.abs(scale - 1) < 0.02) { scale = 1; tx = 0; ty = 0; }
			el.style.transform = scale === 1 ? '' : 'translate(' + tx + 'px, ' + ty + 'px) scale(' + scale + ')';
		}, { passive: false });
		viewer.addEventListener('click', function (e) {
			// anywhere closes it, except the player's own buttons and progress bar (the only part of the
			// video the layer over it leaves open)
			if (player && e.target === player) return;
			e.preventDefault();
			closeViewer();
		});
		document.body.appendChild(viewer);
		document.documentElement.classList.add('nichan-viewer-open');
	}
	// A player with a transparent layer over all of it but the controls at the bottom: browsers pause a video
	// on a click themselves, so the clicks that should close it are caught by the layer instead.
	function videoWithHitLayer(src) {
		var box = document.createElement('div');
		box.className = 'nichan-video-box';
		var v = document.createElement('video');
		v.controls = true;
		v.autoplay = true;
		v.loop = true;
		v.src = src;
		var hit = document.createElement('div');
		hit.className = 'nichan-video-hit';
		box.appendChild(v);
		box.appendChild(hit);
		return box;
	}

	// "У дописі" (the default): a video opens in its post instead of the player page, as big as it is but
	// within the post and the screen; a click on it (not on its controls) puts the thumbnail back.
	function toggleInlineVideo(a, thumb) {
		var m = /[?&]v=([^&]+)/.exec(a.search || '');
		var src = /player\.php$/.test(a.pathname) && m ? decodeURIComponent(m[1]) : a.href;
		var box = videoWithHitLayer(src), v = box.querySelector('video');
		box.classList.add('nichan-inline-video');
		box.querySelector('.nichan-video-hit').addEventListener('click', function (e) {
			e.preventDefault();
			e.stopPropagation();
			try { v.pause(); } catch (err) {}
			v.removeAttribute('src');
			box.parentNode.removeChild(box);
			a.style.display = '';
			if (thumb.play) try { thumb.play(); } catch (err) {}
		});
		a.style.display = 'none';
		if (thumb.pause) try { thumb.pause(); } catch (err) {}
		a.parentNode.insertBefore(box, a.nextSibling);
	}
	document.addEventListener('keydown', function (e) { if (viewer && e.key === 'Escape') closeViewer(); });

	/* ================================================================ quoting */

	// Selecting text in a post shows "Цитувати": it opens the reply form under that post with >>id and the
	// selected lines quoted.
	function setupQuoteSelection() {
		var btn = null, target = null, text = '';
		function hide() { if (btn) btn.style.display = 'none'; }
		function show() {
			var sel = window.getSelection();
			text = sel ? sel.toString().trim() : '';
			if (!text || !sel.rangeCount) return hide();
			var node = sel.anchorNode && (sel.anchorNode.nodeType === 1 ? sel.anchorNode : sel.anchorNode.parentNode);
			var body = node && node.closest && node.closest('div.post div.body');
			target = body && body.closest('div.post');
			if (!target || target.closest('.nichan-preview') || !document.querySelector('form[name="post"]')) return hide();
			// only what is inside this post's text (a selection may run on into the next post)
			var range = sel.getRangeAt(0).cloneRange();
			if (!body.contains(range.startContainer)) range.setStart(body, 0);
			if (!body.contains(range.endContainer)) range.setEnd(body, body.childNodes.length);
			// line breaks of a post are <br>, which the selection's plain text leaves out
			var frag = document.createElement('div');
			frag.appendChild(range.cloneContents());
			Array.prototype.forEach.call(frag.querySelectorAll('br'), function (br) { br.parentNode.replaceChild(document.createTextNode('\n'), br); });
			text = frag.textContent.trim();
			if (!text) return hide();
			var rect = sel.getRangeAt(0).getBoundingClientRect();
			if (!btn) {
				btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'nichan-quote-btn';
				btn.textContent = T.quote;
				btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
				btn.addEventListener('click', function () {
					var quoted = text.split(/\r?\n/).map(function (l) { return '>' + l.trim(); }).filter(function (l) { return l !== '>'; }).join('\n');
					hide();
					// (else vichan's citeReply would paste the still selected text once more, unquoted)
					try { window.getSelection().removeAllRanges(); } catch (e) {}
					replyTo(target);
					setTimeout(function () {
						var ta = document.getElementById('body') || document.querySelector('form[name="post"] textarea[name="body"]');
						if (!ta) return;
						ta.value = ta.value.replace(/\s*$/, '') + '\n' + quoted + '\n';
						if (window.jQuery) window.jQuery(window).trigger('cite');
					}, 80);
				});
				document.body.appendChild(btn);
			}
			btn.style.display = '';
			btn.style.left = Math.max(4, rect.left + window.scrollX + rect.width / 2 - 40) + 'px';
			btn.style.top = (rect.bottom + window.scrollY + 6) + 'px';
		}
		document.addEventListener('mouseup', function (e) { if (btn && e.target === btn) return; setTimeout(show, 10); });
		document.addEventListener('touchend', function () { setTimeout(show, 250); });
		document.addEventListener('selectionchange', function () { if (btn && !String(window.getSelection() || '').trim()) hide(); });
	}

	/* ================================================================ my posts */

	// The visitor's own posts (remembered by show-own-posts.js), newest first, with the number of replies.
	function renderMyPosts(list) {
		var own = ownPostList();
		if (!own.length) { list.innerHTML = '<li class="unimportant">' + esc(T.myNone) + '</li>'; return; }
		list.innerHTML = '<li class="unimportant">' + esc(T.loading) + '</li>';
		var data = new FormData();
		data.append('q', JSON.stringify(own));
		fetch('/myposts.php', { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : []; }).then(function (posts) {
			list.innerHTML = posts.length ? '' : '<li class="unimportant">' + esc(T.myNone) + '</li>';
			posts.forEach(function (p) {
				var li = document.createElement('li');
				var a = document.createElement('a');
				a.href = p.url;
				a.textContent = '/' + p.board + '/ ' + p.id;
				li.appendChild(a);
				var info = document.createElement('span');
				info.className = 'unimportant';
				var d = new Date(p.time * 1000);
				info.textContent = ' · ' + d.toLocaleDateString() + (p.replies ? ' · ' + p.replies + ' ' + T.repliesN : '') + ' — ';
				li.appendChild(info);
				if (p.replies) info.classList.add('to-you');
				li.appendChild(document.createTextNode((p.subject ? p.subject + ': ' : '') + (p.text || '')));
				list.appendChild(li);
			});
		}).catch(function () { list.innerHTML = '<li class="unimportant">' + esc(T.failed) + '</li>'; });
	}

	/* ============================================================ settings page */

	// What "Мої дані" -> export takes along (and import accepts): the site's settings and data of this browser.
	var EXPORT_SKIP = /^nichan_(visitor|push|saved_counts|pending_threads|visit)$/; // (belong to this device)
	function exportKeys() {
		var keys = [];
		try {
			Object.keys(localStorage).forEach(function (k) {
				if ((/^nichan_/.test(k) && !EXPORT_SKIP.test(k)) || /^(own_posts|stylesheet|imagesmuted|password|hiddenboards)$/.test(k)) keys.push(k);
			});
		} catch (e) {}
		return keys.sort();
	}

	// The visitor's settings, in a window over the page (the gear in the top menu, or settings.php): tabs on the
	// left, everything kept in this browser (localStorage).
	var settingsBox = null;
	function closeSettings() {
		if (!settingsBox) return;
		settingsBox.parentNode.removeChild(settingsBox);
		settingsBox = null;
		document.documentElement.classList.remove('nichan-settings-open');
	}
	function openSettings(tab) {
		closeSettings();
		var L = EN ? {
			title: 'Settings', threads: 'Threads', notifyOpen: 'Desktop notifications about new replies in open threads', pushClosed: 'Notify about replies to you, even when the site is closed', pushNo: '(this browser cannot do it)', buttons: 'Buttons', btnLk: 'Latin-to-Cyrillic converter [l>k]', btnTr: 'Translator from any language [translate] (on the boards chosen by the admins)', tMain: 'Main', tBoards: 'Boards', tCode: 'Own code', tFilters: 'Filters', tData: 'My data', close: 'Close',
			files: 'Open files', inPost: 'in the post', inTab: 'in a new window', inFull: 'in a window over the page', mute: 'Hide pictures (show them with a click)',
			style: 'Style', view: 'Board view', viewStd: 'standard', viewCompact: 'compact', font: 'Text size', menu: 'Top menu', unpinned: 'follows the page', pinned: 'pinned',
			homeLayout: 'Home', homeModern: 'New', homeClassic: 'Old',
			editor: 'Post editor', visual: 'visual', text: 'text', auto: 'Update threads automatically', embeds: 'Show players for video links',
			boardsHint: 'Unticked boards disappear from the top menu or from /all/ (only in this browser).', board: 'Board', inMenu: 'in the menu', inAll: 'on /all/',
			css: 'My CSS', cssHint: 'Your own styles for every page of the site (only in this browser).',
			js: 'My JavaScript', jsHint: 'Runs on every page of the site, only in this browser.',
			jsWarn: 'Never paste code somebody sent you: this is how sessions and passwords are stolen.',
			save: 'Save', saved: 'Saved.', savedReload: 'Saved. It runs from the next page on.',
			filtersHint: 'Posts with these words are hidden. The same list as «Word filter» on the boards.',
			data: 'Kept in this browser', savedThreads: 'saved threads', myPosts: 'my posts', hidden: 'hidden posts', drafts: 'drafts', clear: 'clear', sure: 'Clear it?',
			exp: 'Export and import', expHint: 'Moves your saved threads, my posts, filters and settings to another browser or phone: copy the code there and press «Import».',
			doExp: 'Export', doFile: 'Save as a file', doImp: 'Import', impSure: 'Replace the settings of this browser with the imported ones?', impBad: 'This is not a Nichan settings code.', impOk: 'Imported. The page will reload.'
		} : {
			title: 'Налаштування', threads: 'Нитки', notifyOpen: 'Сповіщати про нові відповіді у відкритих нитках на робочому столі', pushClosed: 'Сповіщати про відповіді вам, навіть коли сайт закрито', pushNo: '(цей браузер цього не вміє)', buttons: 'Кнопки', btnLk: 'Перекладач з латинкойобської [l>k]', btnTr: 'Перекладач з усіх мов [translate] (на дошках, визначених адмінами)', tMain: 'Основні', tBoards: 'Видимість дошок', tCode: 'Власний код', tFilters: 'Фільтри', tData: 'Мої дані', close: 'Закрити',
			files: 'Відкривати файли', inPost: 'у дописі', inTab: 'у новому вікні', inFull: 'у спливаючому вікні', mute: 'Ховати картинки (показ — кліком)',
			style: 'Стиль', view: 'Вигляд дошок', viewStd: 'стандартний', viewCompact: 'стиснутий', font: 'Розмір тексту', menu: 'Верхнє меню', unpinned: 'рухається зі сторінкою', pinned: 'закріплене',
			homeLayout: 'Головна', homeModern: 'Нова', homeClassic: 'Стара',
			editor: 'Редактор допису', visual: 'візуальний', text: 'текст', auto: 'Оновлювати нитки автоматично', embeds: 'Показувати плеєр для посилань на відео',
			boardsHint: 'Дошки без галочки зникають з верхнього меню або з /all/ (лише в цьому браузері).', board: 'Дошка', inMenu: 'у меню', inAll: 'на /all/',
			css: 'Мій CSS', cssHint: 'Власні стилі для всіх сторінок сайту (лише в цьому браузері).',
			js: 'Мій JavaScript', jsHint: 'Виконується на всіх сторінках сайту, лише в цьому браузері.',
			jsWarn: 'Не вставляйте код, який вам хтось надіслав: так крадуть сесії й паролі.',
			save: 'Зберегти', saved: 'Збережено.', savedReload: 'Збережено. Спрацює з наступної сторінки.',
			filtersHint: 'Дописи з цими словами приховуються. Це той самий список, що й «Фільтрувати слова» на дошках.',
			data: 'Збережено в цьому браузері', savedThreads: 'збережених ниток', myPosts: 'моїх дописів', hidden: 'прихованих дописів', drafts: 'чернеток', clear: 'очистити', sure: 'Очистити?',
			exp: 'Експорт і імпорт', expHint: 'Переносить збережені нитки, мої дописи, фільтри й налаштування в інший браузер чи на телефон: скопіюйте код туди й натисніть «Імпортувати».',
			doExp: 'Експортувати', doFile: 'Зберегти файлом', doImp: 'Імпортувати', impSure: 'Замінити налаштування цього браузера імпортованими?', impBad: 'Це не код налаштувань цього сайту.', impOk: 'Імпортовано. Сторінка перезавантажиться.'
		};
		function opt(value, current, label) { return '<option value="' + value + '"' + (value === current ? ' selected' : '') + '>' + esc(label) + '</option>'; }
		function box(key, on, label) { return '<label class="ns-row"><input type="checkbox" data-key="' + key + '"' + (on ? ' checked' : '') + '> ' + esc(label) + '</label>'; }
		// a switch between two words: off = the left one, on = the right one
		function toggle(cls, on, offLabel, onLabel) {
			return '<span class="ns-toggle-wrap"><span class="ns-off">' + esc(offLabel) + '</span> <label class="ns-toggle"><input type="checkbox" class="' + cls + '"' + (on ? ' checked' : '') +
				'><span></span></label> <span class="ns-on">' + esc(onLabel) + '</span></span>';
		}
		var styleNames = window.styles ? Object.keys(window.styles) : [];
		var curStyle = store('stylesheet') || window.selectedstyle || '';
		var home = document.documentElement.dataset;
		var layout = home.homeLayout || (store('nichan_home_layout') === 'classic' ? 'classic' : 'modern');
		var font = store('nichan_font_size') || '100';
		var imgMode = /^(tab|full)$/.test(store('nichan_img_open') || '') ? store('nichan_img_open') : 'post';
		var navBoards = Array.prototype.map.call(document.querySelectorAll('.nichan-nav-boards a[data-board]'), function (a) { return { uri: a.getAttribute('data-board'), title: a.getAttribute('title') || '' }; });
		var allHidden = {};
		try { allHidden = JSON.parse(store('hiddenboards') || '{}') || {}; } catch (e) {}
		var countOf = function (key, fn) { try { return fn(JSON.parse(store(key) || 'null')); } catch (e) { return 0; } };
		var drafts = 0;
		try { Object.keys(localStorage).forEach(function (k) { if (k.indexOf('nichan_draft:') === 0) drafts++; }); } catch (e) {}

		var tabs = [['main', 'fa-home', L.tMain], ['boards', 'fa-eye', L.tBoards], ['code', 'fa-code', L.tCode], ['filters', 'fa-filter', L.tFilters], ['data', 'fa-database', L.tData]];
		var panels = {
			main:
				'<fieldset><legend>' + L.tMain + '</legend>' +
				'<p class="ns-row">' + L.files + ': <select class="s-img">' + opt('post', imgMode, L.inPost) + opt('tab', imgMode, L.inTab) + opt('full', imgMode, L.inFull) + '</select></p>' +
				'<label class="ns-row"><input type="checkbox" class="s-mute"' + (store('imagesmuted') === 'true' ? ' checked' : '') + '> ' + L.mute + '</label>' +
				'<p class="ns-row">' + L.style + ': <select class="s-style">' + styleNames.map(function (n) { return opt(n, curStyle, n); }).join('') + '</select></p>' +
				'<label class="ns-row">' + L.homeLayout + ': <select class="s-home-layout">' + opt('modern', layout, L.homeModern) + opt('classic', layout, L.homeClassic) + '</select></label>' +
				'<p class="ns-row">' + L.view + ': ' + toggle('s-view', boardView() === 'compact', L.viewStd, L.viewCompact) + '</p>' +
				'<p class="ns-row">' + L.font + ': <select class="s-font">' + ['85', '90', '100', '110', '120', '130'].map(function (v) { return opt(v, font, v + '%'); }).join('') + '</select></p>' +
				'<p class="ns-row">' + L.menu + ': ' + toggle('s-menu', store('nichan_header_pinned') === '1', L.unpinned, L.pinned) + '</p>' +
				'<p class="ns-row">' + L.editor + ': ' + toggle('s-editor', store('nichan_editor_mode') === 'text', L.visual, L.text) + '</p>' +
				'</fieldset><fieldset><legend>' + L.threads + '</legend>' +
				box('nichan_auto_update', store('nichan_auto_update') === '1', L.auto) +
				'<label class="ns-row"><input type="checkbox" class="s-notify"' + (store('nichan_notify') === '1' && window.Notification && Notification.permission === 'granted' ? ' checked' : '') + (window.Notification ? '' : ' disabled') + '> ' + esc(L.notifyOpen) + '</label>' +
				'<label class="ns-row"><input type="checkbox" class="s-push"' + (pushSupported() && store('nichan_push') === '1' && Notification.permission === 'granted' ? ' checked' : '') + (pushSupported() ? '' : ' disabled') + '> ' + esc(L.pushClosed) +
					(pushSupported() ? '' : ' <span class="unimportant">' + esc(L.pushNo) + '</span>') + '</label><span class="s-push-msg unimportant"></span>' +
				box('nichan_embeds', store('nichan_embeds') !== '0', L.embeds) + '</fieldset>' +
				'<fieldset><legend>' + L.buttons + '</legend>' + box('nichan_btn_lk', store('nichan_btn_lk') === '1', L.btnLk) +
				box('nichan_btn_translate', store('nichan_btn_translate') !== '0', L.btnTr) + '</fieldset>',
			boards:
				'<fieldset><legend>' + L.tBoards + '</legend><p class="unimportant">' + L.boardsHint + '</p><table class="s-boards"><tr><th>' + L.board + '</th><th>' + L.inMenu + '</th><th>' + L.inAll + '</th></tr>' +
				navBoards.map(function (b) {
					return '<tr><td><b>/' + esc(b.uri) + '/</b> ' + esc(b.title) + '</td><td><input type="checkbox" class="s-nav" data-board="' + esc(b.uri) + '"' + (navHidden().indexOf(b.uri) < 0 ? ' checked' : '') + '></td><td>' +
						(b.uri === 'all' ? '' : '<input type="checkbox" class="s-all" data-board="' + esc(b.uri) + '"' + (allHidden[b.uri] === true ? '' : ' checked') + '>') + '</td></tr>';
				}).join('') + '</table></fieldset>',
			code:
				'<fieldset><legend>' + L.css + '</legend><p class="unimportant">' + L.cssHint + '</p><textarea class="s-css" rows="7" spellcheck="false"></textarea>' +
				'<p><button type="button" class="s-css-save">' + L.save + '</button> <span class="s-css-msg unimportant"></span></p></fieldset>' +
				'<fieldset><legend>' + L.js + '</legend><p class="unimportant">' + L.jsHint + '</p><p class="ns-warn"><i class="fa fa-exclamation-triangle"></i> ' + L.jsWarn + '</p>' +
				'<textarea class="s-js" rows="7" spellcheck="false"></textarea>' +
				'<p><button type="button" class="s-js-save">' + L.save + '</button> <span class="s-js-msg unimportant"></span></p></fieldset>',
			filters:
				'<fieldset><legend>' + L.tFilters + '</legend><p class="unimportant">' + L.filtersHint + '</p><ul class="s-filters"></ul>' +
				'<p><select class="f-field"><option value="text">' + T.fText + '</option><option value="name">' + T.fName + '</option><option value="trip">' + T.fTrip + '</option></select> ' +
				'<input type="text" class="f-p" placeholder="' + T.filterPh + '"> <label><input type="checkbox" class="f-re"> ' + T.regex + '</label> ' +
				'<label><input type="checkbox" class="f-thread"> ' + T.hideThread + '</label> <label><input type="checkbox" class="f-img"> ' + T.fImg + '</label> ' +
				'<button type="button" class="f-add">' + T.add + '</button></p></fieldset>',
			data:
				'<fieldset><legend>' + L.data + '</legend><ul class="s-data"></ul></fieldset>' +
				'<fieldset><legend>' + L.exp + '</legend><p class="unimportant">' + L.expHint + '</p>' +
				'<p><button type="button" class="s-exp">' + L.doExp + '</button> <button type="button" class="s-file">' + L.doFile + '</button></p>' +
				'<textarea class="s-code" rows="4" spellcheck="false"></textarea><p><button type="button" class="s-imp">' + L.doImp + '</button> <span class="s-imp-msg"></span></p></fieldset>'
		};

		settingsBox = document.createElement('div');
		settingsBox.className = 'nichan-settings-overlay';
		settingsBox.innerHTML = '<div class="nichan-settings-dialog" role="dialog" aria-label="' + L.title + '">' +
			'<nav class="ns-tabs">' + tabs.map(function (t) { return '<a href="javascript:void(0)" data-tab="' + t[0] + '"><i class="fa ' + t[1] + '"></i><span>' + esc(t[2]) + '</span></a>'; }).join('') + '</nav>' +
			'<div class="ns-main"><div class="ns-head"><span class="ns-title"></span><a href="javascript:void(0)" class="ns-close" title="' + L.close + '">×</a></div>' +
			'<div class="ns-panels">' + tabs.map(function (t) { return '<div class="ns-panel" data-panel="' + t[0] + '">' + panels[t[0]] + '</div>'; }).join('') + '</div></div></div>';
		document.body.appendChild(settingsBox);
		document.documentElement.classList.add('nichan-settings-open');
		var root = settingsBox.querySelector('.nichan-settings-dialog');
		function q(sel) { return root.querySelector(sel); }
		// the style's colours: the page's background, and its link colour for the title, tabs and switches
		function paint() {
			root.style.setProperty('--ns-bg', pageBackground());
			var link = document.querySelector('.pages.topleft a, div.post a, a');
			root.style.setProperty('--ns-accent', link ? getComputedStyle(link).color : '#1d63c4');
		}
		paint();
		var sheet = document.getElementById('stylesheet');
		if (sheet) sheet.addEventListener('load', paint);
		function show(name) {
			if (!panels[name]) name = 'main';
			Array.prototype.forEach.call(root.querySelectorAll('.ns-tabs a'), function (a) { a.classList.toggle('active', a.getAttribute('data-tab') === name); });
			Array.prototype.forEach.call(root.querySelectorAll('.ns-panel'), function (p) { p.style.display = p.getAttribute('data-panel') === name ? '' : 'none'; });
			q('.ns-title').textContent = tabs.filter(function (t) { return t[0] === name; })[0][2];
			try { sessionStorage.nichan_settings_tab = name; } catch (e) {}
		}
		Array.prototype.forEach.call(root.querySelectorAll('.ns-tabs a'), function (a) { a.addEventListener('click', function () { show(a.getAttribute('data-tab')); }); });
		var lastTab = '';
		try { lastTab = sessionStorage.nichan_settings_tab || ''; } catch (e) {}
		show(tab || lastTab || 'main');
		q('.ns-close').addEventListener('click', closeSettings);
		settingsBox.addEventListener('click', function (e) { if (e.target === settingsBox) closeSettings(); });

		// Основні
		q('.s-img').addEventListener('change', function () { store('nichan_img_open', this.value); });
		q('.s-mute').addEventListener('change', function () {
			// the eye of mute-images.js on the page switches it there at once (and remembers it)
			var eye = document.getElementById('mute_images');
			if (eye) { if (document.body.classList.contains('muteimages') !== this.checked) eye.click(); }
			else store('imagesmuted', this.checked ? 'true' : 'false');
		});
		q('.s-style').addEventListener('change', function () {
			if (typeof window.changeStyle === 'function') window.changeStyle(this.value);
			syncStyleSwitch();
			setTimeout(paint, 300);
		});
		q('.s-font').addEventListener('change', function () {
			store('nichan_font_size', this.value);
			// (the stylesheets give sizes in pt/px, so the posts' text is zoomed: nichan.css, html.nichan-text-scaled)
			var d = document.documentElement;
			d.classList.toggle('nichan-text-scaled', this.value !== '100');
			if (this.value === '100') d.style.removeProperty('--nichan-text'); else d.style.setProperty('--nichan-text', this.value / 100);
		});
		q('.s-menu').addEventListener('change', function () {
			store('nichan_header_pinned', this.checked ? '1' : '0');
			document.documentElement.classList.toggle('nichan-header-pinned', this.checked);
			window.dispatchEvent(new Event('resize'));
		});
		q('.s-view').addEventListener('change', function () { setBoardView(this.checked ? 'compact' : 'standard'); });
		q('.s-home-layout').addEventListener('change', function () {
			store('nichan_home_layout', this.value);
			if (home.homeLayout && this.value !== home.homeLayout)
				location.replace(home.otherHome + location.search + location.hash);
		});
		q('.s-editor').addEventListener('change', function () { store('nichan_editor_mode', this.checked ? 'text' : 'visual'); });
		Array.prototype.forEach.call(root.querySelectorAll('input[data-key]'), function (c) { c.addEventListener('change', function () {
			store(c.getAttribute('data-key'), c.checked ? '1' : '0');
			if (/^nichan_btn_/.test(c.getAttribute('data-key'))) refreshPostTools(); // the buttons come or go on this page at once
			// the bar under a thread starts or stops updating at once
			var barAuto = document.querySelector('.nichan-thread-updates .u-auto');
			if (c.getAttribute('data-key') === 'nichan_auto_update' && barAuto && barAuto.checked !== c.checked) { barAuto.checked = c.checked; barAuto.dispatchEvent(new Event('change')); }
		}); });
		// notifications: the browser asks for permission; the bar under a thread shows the same state
		q('.s-notify').addEventListener('change', function () {
			var box = this;
			function done(on) {
				box.checked = on;
				store('nichan_notify', on ? '1' : '0');
				var bar = document.querySelector('.nichan-thread-updates .u-notify');
				if (bar) bar.checked = on;
			}
			if (box.checked && Notification.permission !== 'granted') Notification.requestPermission().then(function (p) { done(p === 'granted'); });
			else done(box.checked);
		});
		q('.s-push').addEventListener('change', function () {
			var box = this, msg = q('.s-push-msg');
			msg.textContent = '';
			(box.checked ? pushOn() : pushOff()).then(function (ok) {
				box.checked = !!ok;
				var bar = document.querySelector('.nichan-thread-updates .u-push');
				if (bar) bar.checked = !!ok;
			}, function (err) {
				box.checked = false;
				var why = err && err.message;
				msg.textContent = why === 'denied' ? T.pushDenied : why === T.pushIos ? T.pushIos : T.pushFail;
			});
		});

		// Видимість дошок
		Array.prototype.forEach.call(root.querySelectorAll('.s-nav'), function (c) { c.addEventListener('change', function () {
			var list = navHidden().filter(function (u) { return u !== c.getAttribute('data-board'); });
			if (!c.checked) list.push(c.getAttribute('data-board'));
			store('nichan_nav_hidden', JSON.stringify(list));
			applyNavHidden();
		}); });
		Array.prototype.forEach.call(root.querySelectorAll('.s-all'), function (c) { c.addEventListener('change', function () {
			var h = {};
			try { h = JSON.parse(store('hiddenboards') || '{}') || {}; } catch (e) {}
			if (c.checked) delete h[c.getAttribute('data-board')]; else h[c.getAttribute('data-board')] = true;
			store('hiddenboards', JSON.stringify(h));
		}); });

		// Власний код
		q('.s-css').value = store('nichan_custom_css') || '';
		q('.s-css-save').addEventListener('click', function () {
			var css = q('.s-css').value;
			store('nichan_custom_css', css);
			var el = document.getElementById('nichan-custom-css');
			if (!el) { el = document.createElement('style'); el.id = 'nichan-custom-css'; document.head.appendChild(el); }
			el.textContent = css;
			q('.s-css-msg').textContent = L.saved;
		});
		q('.s-js').value = store('nichan_custom_js') || '';
		q('.s-js-save').addEventListener('click', function () {
			store('nichan_custom_js', q('.s-js').value);
			q('.s-js-msg').textContent = L.savedReload;
		});

		// Фільтри
		function renderFilters() {
			var ul = q('.s-filters'), items = loadFilters();
			ul.innerHTML = items.length ? '' : '<li class="unimportant">' + T.none + '</li>';
			items.forEach(function (fl, i) {
				var li = document.createElement('li');
				li.innerHTML = '<code></code> <a href="javascript:void(0)">[×]</a>';
				li.querySelector('code').textContent = (fl.field === 'name' ? T.fName + ': ' : fl.field === 'trip' ? T.fTrip + ': ' : '') + (fl.re ? '/' + fl.p + '/' : fl.p) +
					(fl.thread ? ' (' + T.hideThread + ')' : '') + (fl.img ? ' (' + T.fImg + ')' : '');
				li.querySelector('a').addEventListener('click', function () { var all = loadFilters(); all.splice(i, 1); saveFilters(all); renderFilters(); });
				ul.appendChild(li);
			});
		}
		renderFilters();
		q('.f-add').addEventListener('click', function () {
			var p = q('.f-p').value.trim(), re = q('.f-re').checked, m = /^\/(.+)\/$/.exec(p);
			if (m) { p = m[1]; re = true; }
			if (!p) return;
			if (re) { try { new RegExp(p, 'i'); } catch (err) { alert(T.badRegex + err.message); return; } }
			var all = loadFilters();
			all.push({ p: p, re: re, thread: q('.f-thread').checked && !q('.f-img').checked, field: q('.f-field').value, img: q('.f-img').checked });
			saveFilters(all);
			q('.f-p').value = '';
			renderFilters();
		});

		// Мої дані
		function renderData() {
			var rows = [
				[L.savedThreads, savedThreads().length, function () { store('nichan_saved_threads', '[]'); }],
				[L.myPosts, ownPostList().length, function () { try { localStorage.removeItem('own_posts'); } catch (e) {} }],
				[L.hidden, countOf('nichan_hidden_posts', function (h) { return h ? Object.keys(h).length : 0; }), function () { try { localStorage.removeItem('nichan_hidden_posts'); } catch (e) {} }],
				[L.drafts, drafts, function () { try { Object.keys(localStorage).forEach(function (k) { if (k.indexOf('nichan_draft:') === 0) localStorage.removeItem(k); }); } catch (e) {} drafts = 0; }]
			];
			var ul = q('.s-data');
			ul.innerHTML = '';
			rows.forEach(function (r) {
				var li = document.createElement('li');
				li.textContent = r[1] + ' ' + r[0] + ' ';
				if (r[1]) {
					var a = document.createElement('a');
					a.href = 'javascript:void(0)';
					a.textContent = '[' + L.clear + ']';
					a.addEventListener('click', function () { if (confirm(L.sure)) { r[2](); renderData(); } });
					li.appendChild(a);
				}
				ul.appendChild(li);
			});
		}
		renderData();
		function code() {
			var data = {};
			exportKeys().forEach(function (k) { data[k] = store(k); });
			return 'NICHAN1:' + btoa(unescape(encodeURIComponent(JSON.stringify(data))));
		}
		q('.s-exp').addEventListener('click', function () { var t = q('.s-code'); t.value = code(); t.select(); });
		q('.s-file').addEventListener('click', function () {
			var a = document.createElement('a');
			a.href = URL.createObjectURL(new Blob([code()], { type: 'text/plain' }));
			a.download = 'nichan-settings-' + new Date().toISOString().slice(0, 10) + '.txt';
			document.body.appendChild(a);
			a.click();
			a.remove();
		});
		q('.s-imp').addEventListener('click', function () {
			var msg = q('.s-imp-msg'), raw = q('.s-code').value.trim(), data = null;
			try { if (raw.indexOf('NICHAN1:') === 0) data = JSON.parse(decodeURIComponent(escape(atob(raw.slice(8))))); } catch (e) { data = null; }
			if (!data || typeof data !== 'object') { msg.textContent = L.impBad; return; }
			if (!confirm(L.impSure)) return;
			Object.keys(data).forEach(function (k) {
				if (((/^nichan_/.test(k) && !EXPORT_SKIP.test(k)) || /^(own_posts|stylesheet|imagesmuted|password|hiddenboards)$/.test(k)) && typeof data[k] === 'string') store(k, data[k]);
			});
			msg.textContent = L.impOk;
			setTimeout(function () { location.reload(); }, 800);
		});
	}
	document.addEventListener('keydown', function (e) { if (settingsBox && e.key === 'Escape') closeSettings(); });
	// the gear of the top menu and every link to settings.php open the window instead of the page
	document.addEventListener('click', function (e) {
		if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey) return;
		var a = e.target.closest && e.target.closest('a.nichan-settings-open, a[href$="/settings.php"], a[href$="/settings.php?lang=en"]');
		if (!a) return;
		e.preventDefault();
		openSettings();
	});
	// settings.php itself: the window opens at once (and again from the button on the page)
	function setupSettingsPage() {
		var root = document.getElementById('nichan-settings');
		if (!root) return;
		root.innerHTML = '<p style="text-align:center"><button type="button" class="nichan-settings-open-btn">' + (EN ? 'Open the settings' : 'Відкрити налаштування') + '</button></p>';
		root.querySelector('button').addEventListener('click', function () { openSettings(); });
		openSettings();
	}

	// Boards have a dedicated slot beside pagination; other board-engine pages use the footer edge.
	function syncStyleSwitch() {
		var cur = store('stylesheet') || window.selectedstyle || '';
		Array.prototype.forEach.call(document.querySelectorAll('.nichan-style-switch select, .nichan-settings-dialog .s-style'), function (s) { s.value = cur; });
	}
	function setupStyleSwitch() {
		if (!window.styles || document.body.classList.contains('nichan-portal') || document.querySelector('.nichan-style-switch')) return;
		var box = document.createElement('div');
		box.className = 'nichan-style-switch';
		box.innerHTML = '<label>' + (EN ? 'Style' : 'Стиль') + ': <select></select></label>';
		var sel = box.querySelector('select');
		Object.keys(window.styles).forEach(function (n) { var o = document.createElement('option'); o.value = n; o.textContent = n; sel.appendChild(o); });
		sel.addEventListener('change', function () { if (typeof window.changeStyle === 'function') window.changeStyle(sel.value); syncStyleSwitch(); });
		var footer = document.querySelector('footer.portal-foot');
		var slot = document.querySelector('.nichan-theme-slot');
		if (slot) slot.appendChild(box);
		else if (footer) footer.parentNode.insertBefore(box, footer);
		else document.body.appendChild(box);
		syncStyleSwitch();
	}

	/* ============================================================ front page */

	// The front page refreshes its newest pictures, popular threads and newest posts every minute while
	// it is open and visible (from the page itself, which the site rebuilds after every post).
	// News with an end time leaves the page by itself when that time comes, even before the page is built again.
	function hideExpiredNews() {
		var now = Date.now() / 1000;
		[].forEach.call(document.querySelectorAll('.nichan-news-list li[data-until]'), function (li) {
			var until = +li.getAttribute('data-until');
			if (until && until <= now) li.parentNode.removeChild(li);
		});
		[].forEach.call(document.querySelectorAll('.nichan-news-list'), function (ul) {
			var box = ul.closest('.nichan-front-news, .nichan-news-others');
			if (box && !ul.querySelector('li')) box.style.display = 'none';
		});
	}
	function setupNewsExpiry() {
		if (!document.querySelector('.nichan-news-list')) return;
		hideExpiredNews();
		setInterval(hideExpiredNews, 30000);
	}

	function setupFrontRefresh(refreshSince) {
		if (window.active_page !== 'recent' || !document.getElementById('front-posts')) return;
		var feed = document.querySelector('.portal-feed, .classic-home #front-posts');
		if (feed) feed.addEventListener('click', function (e) {
			var spoiler = e.target.closest('.portal-excerpt .spoiler, .classic-home #front-posts .spoiler');
			if (spoiler && !spoiler.classList.contains('portal-revealed') && window.matchMedia('(hover: none)').matches) {
				e.preventDefault();
				spoiler.classList.add('portal-revealed');
			}
		});
		var ids = ['front-news', 'front-popular', 'front-threads', 'front-posts', 'front-images', 'front-post-count', 'front-totals'];
		var popularTimer;
		function watchPopularExpiry() {
			clearTimeout(popularTimer);
			var section = document.querySelector('#front-popular [data-expires]');
			if (!section) return false;
			var remaining = Number(section.dataset.expires) * 1000 - Date.now();
			if (remaining <= 0) {
				section.remove();
				return true;
			}
			popularTimer = setTimeout(refresh, remaining);
			return false;
		}
		function refresh() {
			if (document.hidden) return;
			watchPopularExpiry();
			if (refreshSince) refreshSince();
			fetch(location.pathname, { credentials: 'same-origin', cache: 'no-cache' }).then(function (r) { return r.ok ? r.text() : ''; }).then(function (html) {
				if (!html) return;
				var doc = new DOMParser().parseFromString(html, 'text/html');
				ids.forEach(function (id) {
					var fresh = doc.getElementById(id), old = document.getElementById(id);
					if (fresh && old && fresh.innerHTML !== old.innerHTML)
						old.innerHTML = fresh.innerHTML;
				});
				watchPopularExpiry();
				hideExpiredNews();
			}).catch(function () {});
		}
		if (watchPopularExpiry()) refresh();
		setInterval(refresh, 60000);
		document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
		window.addEventListener('pageshow', function (event) { if (event.persisted) refresh(); });
	}

	/* ============================================== threads under premoderation */

	// A new thread that waits for a moderator is shown on its board page, only in its author's browser,
	// marked "на розгляді"; when a moderator decides, the mark becomes "схвалено" (with a link) or "відхилено".
	var PEND = 'nichan_pending_threads';
	function pendingList() {
		try { return JSON.parse(store(PEND) || '[]') || []; } catch (e) { return []; }
	}
	function pendingSave(list) { store(PEND, JSON.stringify(list.slice(-20))); }
	if (window.jQuery) window.jQuery(document).on('nichan_pending', function (e, r) {
		var p = r && r.nichan_pending;
		if (!p) return;
		var list = pendingList().filter(function (x) { return x.id !== p.id; });
		p.status = 'pending';
		list.push(p);
		pendingSave(list);
		try { localStorage.removeItem('nichan_draft:' + p.board + ':new'); } catch (err) {}
	});
	function setupPendingThreads() {
		var form = document.querySelector('form[name="post"]');
		var first = document.querySelector('div.thread');
		if (!board || !form || form.querySelector('input[name="thread"]:not([data-nichan-temp])')) return;
		var day = 86400;
		var now = Date.now() / 1000;
		// forgotten after a while: decided ones after 3 days, waiting ones after 2 weeks
		var list = pendingList().filter(function (p) { return now - (p.decidedAt || p.time) < (p.status === 'pending' ? 14 : 3) * day; });
		pendingSave(list);
		var mine = list.filter(function (p) { return p.board === board; });
		if (!mine.length) return;
		var box = document.createElement('div');
		box.className = 'nichan-pending-box';
		var anchor = first || form.nextSibling;
		(first ? first.parentNode : form.parentNode).insertBefore(box, anchor);
		function render() {
			box.innerHTML = '';
			pendingList().filter(function (p) { return p.board === board; }).forEach(function (p) {
				var card = document.createElement('div');
				card.className = 'nichan-pending status-' + p.status; // (not "post": the post tools and filters leave it alone)
				card.innerHTML = (p.thumb ? '<img src="' + esc(p.thumb) + '" alt="">' : '') +
					'<p class="intro"><span class="nichan-pending-mark"></span> <strong class="subject"></strong> <span class="unimportant"></span>' +
					(p.status !== 'pending' ? ' <a href="javascript:void(0)" class="nichan-pending-close" title="' + T.close + '">[×]</a>' : '') + '</p>' +
					'<div class="body"></div>';
				card.querySelector('.nichan-pending-mark').textContent = p.status === 'approved' ? T.pendOk : p.status === 'rejected' ? T.pendNo : T.pendWait;
				card.querySelector('.subject').textContent = p.subject || T.pendMine;
				card.querySelector('.unimportant').textContent = p.status === 'pending' ? T.pendNote : '';
				card.querySelector('.body').textContent = p.text || '';
				if (p.status === 'approved' && p.url) {
					var a = document.createElement('a');
					a.href = p.url;
					a.textContent = '[' + T.pendOpen + ']';
					card.querySelector('.intro').appendChild(document.createTextNode(' '));
					card.querySelector('.intro').appendChild(a);
				}
				var close = card.querySelector('.nichan-pending-close');
				if (close) close.addEventListener('click', function () {
					pendingSave(pendingList().filter(function (x) { return x.id !== p.id; }));
					render();
				});
				box.appendChild(card);
			});
		}
		function check() {
			var waiting = pendingList().filter(function (p) { return p.board === board && p.status === 'pending'; });
			if (!waiting.length) return;
			fetch('/pending.php?q=' + waiting.map(function (p) { return p.id + ':' + p.token; }).join(','), { credentials: 'same-origin', cache: 'no-store' })
				.then(function (r) { return r.ok ? r.json() : {}; }).then(function (res) {
					var all = pendingList(), changed = false;
					all.forEach(function (p) {
						var st = res[p.id];
						if (!st || st.status === 'pending' || p.status !== 'pending') return;
						p.status = st.status === 'unknown' ? 'rejected' : st.status;
						p.url = st.url || null;
						p.decidedAt = Date.now() / 1000;
						changed = true;
					});
					if (changed) { pendingSave(all); render(); }
				}).catch(function () {});
		}
		render();
		check();
		setInterval(check, 60000);
	}

	/* =============================================================== online */

	// "Зараз на сайті / на дошці / у цій нитці": a visible page tells online.php once a minute where it is
	// (a random id of this browser, no IP) and shows the numbers it gets back.
	function setupOnline() {
		var spots = document.querySelectorAll('.nichan-online');
		if (!spots.length || window.inMod) return;
		var id = store('nichan_visitor');
		if (!/^[0-9a-f]{16}$/.test(id || '')) {
			id = '';
			for (var i = 0; i < 16; i++) id += '0123456789abcdef'[Math.floor(Math.random() * 16)];
			store('nichan_visitor', id);
		}
		var thread = window.active_page === 'thread' ? window.thread_id : '';
		// the front page's list of boards gets "(N онлайн)"; a board page, «Хто тут?» when someone else is on it
		var boardSpans = document.querySelectorAll('.nichan-board-online[data-board]');
		var who = document.querySelector('.nichan-whohere'), whoList = document.querySelector('.nichan-whohere-list');
		var W = EN ? { online: 'online', you: '(including you)', pages: 'Not in a thread' } : { online: 'онлайн', you: '(з тобою)', pages: 'Не в нитці' };
		var last = 0;
		function ping(force) {
			if (document.hidden || (!force && Date.now() - last < 30000)) return;
			last = Date.now();
			var data = new FormData();
			data.append('v', id);
			data.append('b', board);
			data.append('t', thread);
			if (boardSpans.length) data.append('all', '1');
			if (whoList && whoList.style.display !== 'none') data.append('here', '1');
			fetch('/online.php', { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (res) {
				if (!res) return;
				Array.prototype.forEach.call(spots, function (spot) {
					var fields = spot.querySelectorAll('[data-online]');
					var filled = Array.prototype.every.call(fields, function (el) {
						var n = res[el.getAttribute('data-online')];
						if (n === null || n === undefined) return false;
						el.textContent = n;
						return true;
					});
					if (fields.length && filled) spot.style.display = '';
				});
				if (res.boards) Array.prototype.forEach.call(boardSpans, function (s) {
					var n = res.boards[s.getAttribute('data-board')];
					s.textContent = n > 0 ? ' (' + n + ' ' + W.online + ')' : '';
				});
				if (who) {
					var others = res.board > 1;
					who.style.display = others ? '' : 'none';
					if (!others) whoList.style.display = 'none';
				}
				if (res.here && whoList) showHere(res.here);
			}).catch(function () {});
		}
		function showHere(here) {
			var line = function (link, n, mine) {
				return '<div>' + link + ' — ' + n + ' ' + W.online + (mine ? ' ' + W.you : '') + '</div>';
			};
			var html = here.threads.map(function (t) {
				return line('<a href="' + esc(t.url) + '">#' + t.id + '</a> ' + esc(t.title), t.n, t.mine);
			}).join('');
			if (here.pages) html += line(W.pages, here.pages, here.pagesMine);
			whoList.innerHTML = html;
		}
		if (who) who.querySelector('a').addEventListener('click', function () {
			var open = whoList.style.display === 'none';
			whoList.style.display = open ? '' : 'none';
			if (open) { whoList.innerHTML = '…'; ping(true); }
		});
		ping();
		setInterval(ping, 60000);
		document.addEventListener('visibilitychange', function () { ping(); });
	}

	/* ===================================================== since the last visit */

	// This browser remembers when the visitor was last here (nichan_visit = {last, since}).
	// A pause of over 30 minutes starts a new visit, whose "since" is the end of the previous one; since.php
	// tells what was posted after it. The front page shows it, the home icon in the menu gets the count.
	// Nothing new: nothing is shown.
	var S = EN ? {
		title: 'While you were away', intro: 'Since your last visit %s ago, %p.',
		limited: 'Your last visit was %s ago. In the last %w, %p.',
		posts: ['new post appeared', 'new posts appeared', 'new posts appeared'],
		newThreads: 'New threads:', replies: ['reply', 'replies', 'replies'], untitled: 'Thread No.',
		min: ['minute', 'minutes', 'minutes'], hour: ['hour', 'hours', 'hours'], day: ['day', 'days', 'days'],
		badge: 'New since your last visit', close: 'Close: all of it counts as seen'
	} : {
		title: 'Поки тебе не було', intro: 'Після твого останнього візиту %s тому, %p.',
		limited: 'Твій останній візит був %s тому. За останні %w %p.',
		posts: ['новий допис', 'нові дописи', 'нових дописів'],
		newThreads: 'Нові нитки:', replies: ['відповідь', 'відповіді', 'відповідей'], untitled: 'Нитка №',
		min: ['хвилину', 'хвилини', 'хвилин'], hour: ['годину', 'години', 'годин'], day: ['день', 'дні', 'днів'],
		badge: 'Нове з вашого минулого візиту', close: 'Закрити: усе це вважається переглянутим'
	};
	function plural(n, forms) {
		if (EN) return n === 1 ? forms[0] : forms[1];
		var d = n % 10, h = n % 100;
		return forms[d === 1 && h !== 11 ? 0 : d >= 2 && d <= 4 && (h < 10 || h >= 20) ? 1 : 2];
	}
	function countText(n, forms) { return n + ' ' + plural(n, forms); }
	function agoText(seconds) {
		var m = Math.max(1, Math.round(seconds / 60));
		if (m < 60) return countText(m, S.min);
		var h = Math.round(m / 60);
		return h < 24 ? countText(h, S.hour) : countText(Math.round(h / 24), S.day);
	}
	function visitSince() {
		var now = Math.floor(Date.now() / 1000), v = null;
		try { v = JSON.parse(store('nichan_visit') || 'null'); } catch (e) {}
		if (!v || typeof v.last !== 'number') v = { last: now, since: 0 }; // the first visit: nothing to compare with
		else if (now - v.last > 30 * 60) v = { last: now, since: v.last };
		else v.last = now;
		store('nichan_visit', JSON.stringify(v));
		return v.since || 0;
	}
	function setupSince() {
		if (window.inMod) return;
		var since = visitSince();
		// (a page left open for long still counts as being here)
		setInterval(function () { if (!document.hidden) visitSince(); }, 60000);

		var home = document.querySelector('.pages.topleft .nichan-nav-group');
		var front = window.active_page === 'recent' && document.getElementById('since');
		if (!since || (!front && !home)) return;

		var closed = false, pending = false, content, previousHtml;
		if (front) {
			var close = document.createElement('button');
			close.type = 'button';
			close.className = 'nichan-since-close';
			close.title = S.close;
			close.setAttribute('aria-label', S.close);
			close.textContent = '×';
			content = document.createElement('div');
			front.appendChild(close);
			front.appendChild(content);
			close.addEventListener('click', function () {
				closed = true;
				var now = Math.floor(Date.now() / 1000);
				store('nichan_visit', JSON.stringify({ last: now, since: now }));
				try { sessionStorage.removeItem('nichan_since_v2'); } catch (e) {}
				front.hidden = true;
				var selected = document.querySelector('.portal-feed input[name="feed"]:checked');
				if (selected) selected.focus();
			});
		}

		var show = function (res) {
			var hidden = navHidden(), posts = 0;
			Object.keys(res.boards || {}).forEach(function (b) {
				if (hidden.indexOf(b) !== -1) return;
				posts += res.boards[b].posts;
			});
			if (home && posts && !front) {
				home.querySelector('a').insertAdjacentHTML('afterend', ' <a class="nichan-since-badge" href="/#since" title="' + esc(S.badge) + '">+' + posts + '</a>');
			}
			if (!front) return;
			if (!posts) { front.hidden = true; return; }
			var now = Date.now() / 1000;
			var activity = countText(posts, S.posts);
			if (!EN) activity = plural(posts, ["з'явився", "з'явилося", "з'явилося"]) + ' ' + activity;
			var intro = (res.since > since ? S.limited : S.intro)
				.replace('%s', agoText(now - since)).replace('%w', agoText(now - res.since)).replace('%p', activity);
			var updated = [], created = [];
			res.threads.forEach(function (t) {
				if (hidden.indexOf(t.board) !== -1) return;
				var link = '<a href="' + esc(t.url) + '">/' + esc(t.board) + '/ - ' + esc(t.title || S.untitled + t.id) + '</a>';
				if (t.new) {
					var replies = typeof t.replies === 'number' ? t.replies : t.posts - 1;
					created.push('<li>' + link + (replies ? ' (' + countText(replies, S.replies) + ')' : '') + '</li>');
				} else {
					updated.push('<li>' + link + ' (+' + t.posts + ')</li>');
				}
			});
			var html = '<h2 id="since-title">' + S.title + '</h2><p>' + intro + '</p>';
			if (updated.length) html += '<ul>' + updated.join('') + '</ul>';
			if (created.length) html += '<p><strong>' + S.newThreads + '</strong></p><ul>' + created.join('') + '</ul>';
			if (html !== previousHtml) content.innerHTML = html;
			front.hidden = false;
			if (previousHtml === undefined && location.hash === '#since') front.scrollIntoView();
			previousHtml = html;
		};
		// The badge can reuse a recent summary; the homepage reads the current thread counts.
		if (!front) {
			var cached = null;
			try { cached = JSON.parse(sessionStorage.getItem('nichan_since_v2') || 'null'); } catch (e) {}
			if (cached && cached.since === since && Date.now() - cached.at < 60000) { show(cached.res); return; }
		}
		function refresh() {
			if (closed || pending) return;
			pending = true;
			fetch('/since.php?t=' + since + (front ? '' : '&summary=1'), { credentials: 'same-origin', cache: 'no-store' })
				.then(function (r) { return r.ok ? r.json() : null; }).then(function (res) {
					if (!res || closed) return;
					try { sessionStorage.setItem('nichan_since_v2', JSON.stringify({ since: since, at: Date.now(), res: res })); } catch (e) {}
					show(res);
				}).catch(function () {}).then(function () { pending = false; });
		}
		refresh();
		if (front) return refresh;
	}

	/* ====================================================== form «Параметри» */

	// Sage, ОП-мітка, «Спитати долю». The OP mark and fate are never kept: unchecked on every page (the browser
	// may bring a checked box back on reload) and after each post. Sage is kept for the thread it was sent in
	// (nichan_sage = {"board:thread": time}, forgotten after 30 days) and is unchecked everywhere else.
	var SAGE_KEY = 'nichan_sage';
	function sageThreads() {
		try { var o = JSON.parse(store(SAGE_KEY) || '{}'); return o && typeof o === 'object' ? o : {}; } catch (e) { return {}; }
	}
	function formThreadKey(form) {
		var t = form.querySelector('input[name="thread"]');
		var b = form.querySelector('input[name="board"]');
		return t && t.value ? (b ? b.value : board) + ':' + t.value : '';
	}
	function formOption(form, name) {
		var el = form.querySelector(name === 'email' ? 'input[name="email"][type="checkbox"]' : 'input[name="' + name + '"]');
		return el ? { box: el, label: el.closest('label') } : null;
	}
	function applyFormOptions(form) {
		var key = formThreadKey(form), sage = formOption(form, 'email'), op = formOption(form, 'nichan_opmark'), fate = formOption(form, 'nichan_fate');
		if (sage) {
			sage.box.value = 'sage'; // (main.js may have put an old e-mail field's value there)
			sage.box.checked = !!(key && sageThreads()[key]);
			if (sage.label) sage.label.style.display = key ? '' : 'none'; // (a reply only)
		}
		if (op) {
			op.box.checked = false;
			if (op.label) op.label.style.display = key ? 'none' : ''; // (a new thread only)
		}
		if (fate) fate.box.checked = false;
	}
	function setupFormOptions() {
		Array.prototype.forEach.call(document.querySelectorAll('form[name="post"]'), function (form) {
			applyFormOptions(form);
			// Sage: remembered for this thread when sent with it, forgotten when sent without
			form.addEventListener('submit', function () {
				var key = formThreadKey(form), sage = formOption(form, 'email');
				if (sage) sage.box.value = 'sage';
				if (!key || !sage) return;
				var all = sageThreads(), now = Date.now();
				Object.keys(all).forEach(function (k) { if (now - all[k] > 30 * 86400000) delete all[k]; });
				if (sage.box.checked) all[key] = now;
				else delete all[key];
				store(SAGE_KEY, JSON.stringify(all));
			}, true);
		});
		// the post went through: the OP mark and fate don't stay checked for the next one
		if (window.jQuery) window.jQuery(document).on('ajax_after_post', function () {
			Array.prototype.forEach.call(document.querySelectorAll('form[name="post"] input[name="nichan_opmark"], form[name="post"] input[name="nichan_fate"]'), function (el) { el.checked = false; });
		});
	}

	/* ========================================================= continuation */

	// A thread at its reply limit links to the board page with ?continue=<id>: the new-thread form is
	// filled in (subject with the next number, a link to the old thread) and remembers what it continues.
	function setupContinuation() {
		var m = /[?&]continue=(\d+)/.exec(location.search);
		var form = document.querySelector('form[name="post"]');
		if (!m || !form || form.querySelector('input[name="thread"]')) return;
		var id = m[1];
		var hidden = document.createElement('input');
		hidden.type = 'hidden';
		hidden.name = 'nichan_continues';
		hidden.value = id;
		form.appendChild(hidden);
		var note = document.createElement('div');
		note.className = 'nichan-thread-note';
		note.textContent = T.continues.replace('%s', id);
		form.parentNode.insertBefore(note, form);
		var body = form.querySelector('textarea[name="body"]'), subj = form.querySelector('input[name="subject"]');
		if (body && body.value.indexOf('>>>/' + board + '/' + id) < 0) body.value = '>>>/' + board + '/' + id + '\n' + body.value;
		fetch('/' + board + '/res/' + id + '.json', { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
			var old = j && j.posts && j.posts[0] && j.posts[0].sub;
			if (!old || !subj || subj.value) return;
			var n = /^(.*?)\s*#(\d+)$/.exec(old);
			subj.value = n ? n[1] + ' #' + (parseInt(n[2], 10) + 1) : old + ' #2';
		}).catch(function () {});
	}

	/* =============================================================== drafts */

	// The text and subject of a post form are kept in localStorage (one draft per board and thread)
	// until the post goes through, so closing the tab or an error doesn't lose them.
	var DRAFT = 'nichan_draft:';
	function draftKey(form) {
		var b = (form.querySelector('input[name="board"]') || {}).value || board;
		var t = form.querySelector('input[name="thread"]');
		return DRAFT + b + ':' + (t && t.value ? t.value : 'new');
	}
	function setupDrafts() {
		try {
			var week = Date.now() - 7 * 86400000;
			Object.keys(localStorage).forEach(function (k) {
				if (k.indexOf(DRAFT) !== 0) return;
				var d = null;
				try { d = JSON.parse(localStorage.getItem(k)); } catch (e) {}
				if (!d || !(d.t > week)) localStorage.removeItem(k);
			});
		} catch (e) { return; }
		Array.prototype.forEach.call(document.querySelectorAll('form[name="post"]'), function (form) {
			var body = form.querySelector('textarea[name="body"]'), subj = form.querySelector('input[name="subject"]');
			if (!body) return;
			if (!body.value.trim()) {
				var d = null;
				try { d = JSON.parse(store(draftKey(form)) || 'null'); } catch (e) {}
				if (d) {
					body.value = d.b || '';
					if (subj && !subj.value && d.s) subj.value = d.s;
				}
			}
			var last = { k: draftKey(form), b: body.value, s: subj ? subj.value : '' }, sent = null;
			setInterval(function () {
				var k = draftKey(form), v = body.value, sv = subj ? subj.value : '';
				if (k === last.k && v === last.b && sv === last.s) return;
				last = { k: k, b: v, s: sv };
				if (!v.trim() && !sv.trim()) { try { localStorage.removeItem(k); } catch (e) {} return; }
				store(k, JSON.stringify({ b: v, s: sv, t: Date.now() }));
			}, 1000);
			form.addEventListener('submit', function () { sent = draftKey(form); }, true);
			// ajax.js: the post went through
			if (window.jQuery) window.jQuery(document).on('ajax_after_post', function () {
				if (!sent) return;
				try { localStorage.removeItem(sent); } catch (e) {}
				last = { k: draftKey(form), b: body.value, s: subj ? subj.value : '' };
				sent = null;
			});
		});
	}

	/* ============================================================== replies */

	// ">>123" links to the replies of a post, in italics at the bottom of the post
	// (instead of show-backlinks.js, which put them next to the post number).
	function postIdOf(post) { return (post.id || '').replace(/^(reply|op)_/, ''); }
	function updateReplies() {
		var map = {};
		var posts = Array.prototype.filter.call(document.querySelectorAll('.thread div.post'), function (p) { return !p.closest('.nichan-preview'); });
		posts.forEach(function (post) {
			var myId = postIdOf(post);
			var thread = post.closest('.thread');
			var b = thread ? thread.getAttribute('data-board') : board;
			Array.prototype.forEach.call(post.querySelectorAll('div.body a'), function (a) {
				var m = /^>>(\d+)$/.exec((a.textContent || '').trim());
				if (!m || m[1] === myId) return;
				var key = b + ':' + m[1];
				map[key] = map[key] || [];
				if (map[key].indexOf(myId) < 0) map[key].push(myId);
			});
		});
		posts.forEach(function (post) {
			var thread = post.closest('.thread');
			var key = (thread ? thread.getAttribute('data-board') : board) + ':' + postIdOf(post);
			var ids = map[key] || [];
			var box = null;
			for (var c = post.lastElementChild; c; c = c.previousElementSibling) {
				if (c.classList.contains('nichan-replies')) { box = c; break; }
			}
			if (!ids.length) { if (box) box.remove(); return; }
			if (!box) {
				box = document.createElement('div');
				box.className = 'nichan-replies';
				post.appendChild(box);
			}
			box.innerHTML = T.replies + ' ' + ids.map(function (id) {
				return '<em><a href="#' + id + '" onclick="highlightReply(\'' + id + '\', event);">&gt;&gt;' + id + '</a></em>';
			}).join(' ');
		});
	}

	/* ======================================================= [l>k] / translate */

	var LATYNKA = [
		['tcc', 'чч'], ['tss', 'цц'],
		['já', 'я́'], ['jú', 'ю́'], ['jé', 'є́'], ['jí', 'ї́'], ['jý', 'ї́'],
		['á', 'а́'], ['é', 'е́'], ['ý', 'и́'], ['ú', 'у́'], ['í', 'і́'], ['ó', 'о́'],
		['ctc', 'щ'], ['ts', 'ц'], ['tc', 'ч'], ['ja', 'я'], ['ju', 'ю'], ['je', 'є'], ['ji', 'ї'], ['jy', 'ї'],
		['v̆', 'ў'], ['ŭ', 'ў'],
		['a', 'а'], ['b', 'б'], ['v', 'в'], ['h', 'г'], ['q', 'ґ'], ['d', 'д'], ['e', 'е'], ['g', 'ж'], ['z', 'з'],
		['y', 'и'], ['i', 'і'], ['k', 'к'], ['l', 'л'], ['m', 'м'], ['n', 'н'], ['o', 'о'], ['p', 'п'], ['r', 'р'],
		['s', 'с'], ['t', 'т'], ['u', 'у'], ['f', 'ф'], ['x', 'х'], ['c', 'ш'], ['j', 'й']
	];
	var SOFT = 'ндтцчзсжшлрНДТЦЧЗСЖШЛР';
	function latynkaToCyr(s) {
		LATYNKA.forEach(function (p) {
			s = s.split(p[0]).join(p[1]);
			var up = p[0].charAt(0).toUpperCase() + p[0].slice(1);
			if (up !== p[0]) s = s.split(up).join(p[1].toUpperCase());
		});
		return s.replace(new RegExp('([' + SOFT + '])й', 'g'), '$1ь').replace(new RegExp('([' + SOFT + '])Й', 'g'), '$1Ь');
	}

	function convertTextNodes(root, fn) {
		var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
			acceptNode: function (n) { return n.parentNode.closest('a, pre, code') ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT; }
		});
		var nodes = [], n;
		while ((n = walker.nextNode())) nodes.push(n);
		nodes.forEach(function (t) { t.nodeValue = fn(t.nodeValue); });
	}

	function toggleOriginal(body, btn, label) {
		body.innerHTML = body.getAttribute('data-nichan-original');
		body.removeAttribute('data-nichan-original');
		body.removeAttribute('data-nichan-original-text');
		btn.textContent = '[' + label + ']';
	}

	function latynkaClick(post, btn) {
		var body = post.querySelector('div.body');
		if (!body) return;
		if (body.hasAttribute('data-nichan-original')) return toggleOriginal(body, btn, 'l>k');
		body.setAttribute('data-nichan-original', body.innerHTML);
		body.setAttribute('data-nichan-original-text', body.textContent);
		convertTextNodes(body, latynkaToCyr);
		btn.textContent = '[k>l]';
	}

	function targetLang() {
		var l = (navigator.languages && navigator.languages[0]) || navigator.language || 'en';
		return l.split('-')[0].toLowerCase();
	}

	// Google's free web endpoint: no key needed, works from the browser. Falls back to opening
	// translate.google.com with the text.
	function translateClick(post, btn) {
		var body = post.querySelector('div.body');
		if (!body) return;
		if (body.hasAttribute('data-nichan-original')) return toggleOriginal(body, btn, T.translate);
		var text = body.innerText.trim();
		if (!text) return;
		var tl = targetLang();
		btn.textContent = '[' + T.translating + ']';
		fetch('https://translate.googleapis.com/translate_a/single?client=gtx&sl=auto&tl=' + encodeURIComponent(tl) + '&dt=t&q=' + encodeURIComponent(text.slice(0, 4500)))
			.then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
			.then(function (data) {
				var out = (data[0] || []).map(function (seg) { return seg[0]; }).join('');
				body.setAttribute('data-nichan-original', body.innerHTML);
				body.setAttribute('data-nichan-original-text', body.textContent);
				body.innerHTML = esc(out).replace(/\n/g, '<br>');
				btn.textContent = '[' + T.original + ']';
			})
			.catch(function () {
				btn.textContent = '[' + T.translate + ']';
				if (confirm(T.translateFail))
					window.open('https://translate.google.com/?sl=auto&tl=' + encodeURIComponent(tl) + '&op=translate&text=' + encodeURIComponent(text.slice(0, 4500)), '_blank', 'noopener');
			});
	}

	// Settings -> "Кнопки": [l>k] is off unless switched on, [translate] (/int/) is on unless switched off.
	function postToolOn(isInt) { return isInt ? store('nichan_btn_translate') !== '0' : store('nichan_btn_lk') === '1'; }
	function refreshPostTools() {
		Array.prototype.forEach.call(document.querySelectorAll('div.post .intro .nichan-post-tool'), function (b) {
			var body = b.closest('div.post').querySelector('div.body');
			if (body && body.hasAttribute('data-nichan-original')) toggleOriginal(body, b, '');
			if (b.previousSibling && b.previousSibling.nodeType === 3) b.parentNode.removeChild(b.previousSibling);
			b.parentNode.removeChild(b);
		});
		addPostTools(document);
	}
	// boards whose posts get [translate] (mod.php -> Board settings, via site-status.js); [l>k] on the others
	function translateBoard(uri) {
		var list = window.nichanStatus && window.nichanStatus.translate;
		return list ? list.indexOf(uri) >= 0 : uri === 'int';
	}
	function addPostTools(root) {
		Array.prototype.forEach.call((root.querySelectorAll ? root : document).querySelectorAll('div.post .intro'), function (intro) {
			if (intro.querySelector('.nichan-post-tool') || intro.closest('.nichan-preview')) return;
			var post = intro.closest('div.post');
			var postBoard = (post.closest('[data-board]') || {}).getAttribute ? post.closest('[data-board]').getAttribute('data-board') : board;
			var tr = translateBoard(postBoard || board);
			if (!postToolOn(tr)) return;
			var b = document.createElement('a');
			b.href = 'javascript:void(0)';
			b.className = 'nichan-post-tool';
			if (tr) {
				b.textContent = '[' + T.translate + ']';
				b.title = EN ? 'Translate into your browser language' : 'Перекласти мовою браузера';
				b.addEventListener('click', function () { translateClick(post, b); });
			} else {
				b.textContent = '[l>k]';
				b.title = 'Перебуквнути латинку в кирилицю';
				b.addEventListener('click', function () { latynkaClick(post, b); });
			}
			intro.appendChild(document.createTextNode(' '));
			intro.appendChild(b);
		});
	}

	/* ========================================================= inline reply */

	// Clicking a post number opens the page's own post form right under that post (no copy of the
	// form, so the editor, file picker and captcha keep working). "detach" turns it into a floating
	// window; on board index pages the click opens the thread instead.
	var inline = null; // {wrap, home, form, post}

	function threadPageForm() {
		var form = document.querySelector('form[name="post"]');
		return form && form.querySelector('input[name="thread"]:not([data-nichan-temp])') ? form : null;
	}

	// Board index: the page's new-thread form becomes a reply to the post's thread while it sits under
	// the post (ajax.js then opens the thread after posting); closing turns it back.
	function indexReplyForm(post) {
		var form = document.querySelector('form[name="post"]');
		var thread = post && post.closest('.thread');
		if (!form || !thread) return null;
		var input = form.querySelector('input[name="thread"][data-nichan-temp]');
		if (!input) {
			input = document.createElement('input');
			input.type = 'hidden';
			input.name = 'thread';
			input.setAttribute('data-nichan-temp', '1');
			form.appendChild(input);
			form.setAttribute('data-nichan-goto', '1');
			var submit = form.querySelector('input[type="submit"][name="post"]');
			if (submit) { submit.setAttribute('data-nichan-orig', submit.value); submit.value = T.replyBtn; }
		}
		input.value = thread.id.replace(/^thread_/, '');
		applyFormOptions(form); // (a reply now: Sage as last used in that thread, no OP mark)
		var op = thread.querySelector('div.post.op a.post_no');
		swapAntibot(form, input.value, op ? op.getAttribute('href').replace(/#.*$/, '') : '/' + (thread.getAttribute('data-board') || board) + '/res/' + input.value + '.html');
		return form;
	}

	// The engine's anti-bot fields are made for one page: the index form's ones are only good for a
	// new thread. For a reply, borrow the hidden fields of the thread's own page while the form is a reply.
	var STANDARD_FIELDS = /^(board|thread|name|email|subject|body|password|file\d*|spoiler|json_response|post|user_flag|no_country|embed|nichan_captcha|nichan_captcha_id|nichan_capcode|nichan_opmark|nichan_fate|nichan_continues|g-recaptcha-response|mod|raw|sticky|lock|tag|page|file_url)$/;
	var antibot = null; // {thread, box, stash}
	function antibotFields(form) {
		return Array.prototype.filter.call(form.querySelectorAll('input[name], textarea[name], select[name]'), function (el) {
			return !STANDARD_FIELDS.test(el.name) && el.type !== 'submit' && !el.closest('.nichan-antibot');
		});
	}
	function swapAntibot(form, threadId, url) {
		if (antibot && antibot.thread === threadId) return;
		unswapAntibot(form);
		var mine = antibot = { thread: threadId, box: null, stash: [] };
		var submit = form.querySelector('input[type="submit"][name="post"]');
		if (submit) submit.disabled = true;
		fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.text() : ''; }).then(function (html) {
			if (antibot !== mine || !html) return;
			var tf = new DOMParser().parseFromString(html, 'text/html').querySelector('form[name="post"]');
			if (!tf) return;
			antibotFields(form).forEach(function (el) {
				mine.stash.push({ el: el, parent: el.parentNode, next: el.nextSibling });
				el.parentNode.removeChild(el);
			});
			mine.box = document.createElement('div');
			mine.box.className = 'nichan-antibot';
			mine.box.style.display = 'none';
			antibotFields(tf).forEach(function (el) { mine.box.appendChild(document.importNode(el, true)); });
			form.appendChild(mine.box);
		}).catch(function () {}).then(function () {
			if (submit && antibot === mine) submit.disabled = false;
		});
	}
	function unswapAntibot(form) {
		if (!antibot) return;
		if (antibot.box) antibot.box.remove();
		antibot.stash.reverse().forEach(function (s) { s.parent.insertBefore(s.el, s.next && s.next.parentNode === s.parent ? s.next : null); });
		var submit = form.querySelector('input[type="submit"][name="post"]');
		if (submit) submit.disabled = false;
		antibot = null;
	}
	function restoreIndexForm(form) {
		var input = form.querySelector('input[name="thread"][data-nichan-temp]');
		if (!input) return;
		unswapAntibot(form);
		input.remove();
		applyFormOptions(form); // (the new-thread form again)
		form.removeAttribute('data-nichan-goto');
		var submit = form.querySelector('input[type="submit"][data-nichan-orig]');
		if (submit) { submit.value = submit.getAttribute('data-nichan-orig'); submit.removeAttribute('data-nichan-orig'); }
	}
	// "Reply" in the post menu: the form under the post, on thread pages and board index pages alike.
	function replyTo(post) {
		var id = postIdOf(post);
		var ok = threadPageForm() ? openInline(post, id) : openInline(post, id, indexReplyForm(post));
		if (ok) citeInto(id);
	}

	function closeInline() {
		if (!inline) return;
		restoreIndexForm(inline.form);
		inline.home.parentNode.insertBefore(inline.form, inline.home);
		inline.home.remove();
		inline.wrap.remove();
		inline = null;
	}

	function openInline(post, id, indexForm) {
		var form = indexForm || threadPageForm();
		if (!form || !post) return false;
		if (!inline) {
			onInlineOpen();
			var home = document.createComment('post form home');
			form.parentNode.insertBefore(home, form);
			var wrap = document.createElement('div');
			wrap.className = 'nichan-inline-reply';
			wrap.innerHTML = '<div class="nichan-inline-bar"><span class="nichan-inline-title"></span>' +
				'<a href="javascript:void(0)" class="nichan-inline-detach"></a> <a href="javascript:void(0)" class="nichan-inline-close" title="' + T.close + '">[×]</a></div>';
			inline = { wrap: wrap, home: home, form: form, post: post };
			wrap.querySelector('.nichan-inline-close').addEventListener('click', closeInline);
			wrap.querySelector('.nichan-inline-detach').addEventListener('click', function () { setDetached(!wrap.classList.contains('detached')); });
			dragBy(wrap.querySelector('.nichan-inline-bar'), wrap);
			wrap.appendChild(form);
		}
		inline.post = post;
		inline.wrap.querySelector('.nichan-inline-title').textContent = '>>' + id;
		if (!inline.wrap.classList.contains('detached')) {
			var anchor = post.nextSibling && post.nextSibling.nodeName === 'BR' ? post.nextSibling : post;
			anchor.parentNode.insertBefore(inline.wrap, anchor.nextSibling);
		}
		setDetached(inline.wrap.classList.contains('detached'));
		return true;
	}

	function setDetached(on) {
		var w = inline.wrap;
		w.classList.toggle('detached', on);
		w.querySelector('.nichan-inline-detach').textContent = '[' + (on ? T.attach : T.detach) + ']';
		if (on) {
			w.style.left = w.style.top = '';
			document.body.appendChild(w);
		} else {
			w.style.left = w.style.top = '';
			var post = inline.post;
			var anchor = post.nextSibling && post.nextSibling.nodeName === 'BR' ? post.nextSibling : post;
			anchor.parentNode.insertBefore(w, anchor.nextSibling);
		}
	}

	function dragBy(handle, box) {
		handle.addEventListener('mousedown', function (e) {
			if (!box.classList.contains('detached') || e.target.tagName === 'A') return;
			e.preventDefault();
			var r = box.getBoundingClientRect(), dx = e.clientX - r.left, dy = e.clientY - r.top;
			function move(ev) {
				box.style.left = Math.max(0, Math.min(window.innerWidth - 60, ev.clientX - dx)) + 'px';
				box.style.top = Math.max(0, Math.min(window.innerHeight - 30, ev.clientY - dy)) + 'px';
				box.style.right = box.style.bottom = 'auto';
			}
			function up() { document.removeEventListener('mousemove', move); document.removeEventListener('mouseup', up); }
			document.addEventListener('mousemove', move);
			document.addEventListener('mouseup', up);
		});
	}

	function citeInto(id) {
		var ta = inline && inline.form.querySelector('textarea[name="body"]');
		if (!ta) return;
		document.querySelectorAll('textarea#body').forEach(function (t) { if (t !== ta) t.removeAttribute('id'); });
		ta.id = 'body';
		if (ta.value.indexOf('>>' + id + '\n') < 0 && typeof window.citeReply === 'function') window.citeReply(id);
		else if (ta.value.indexOf('>>' + id) < 0) ta.value += '>>' + id + '\n';
		ta.dispatchEvent(new Event('input', { bubbles: true }));
		var vis = inline.form.querySelector('.nichan-ed-visual');
		setTimeout(function () {
			var target = vis && vis.style.display !== 'none' ? vis : ta;
			if (target === vis) caretToEnd(vis);
			else target.focus();
		}, 60);
	}

	function setupInlineReply() {
		// the quick reply (the form under a post or floating) closes once the post went through
		if (window.jQuery) window.jQuery(document).on('ajax_after_post', function () {
			if (inline) setTimeout(closeInline, 0);
		});
		document.addEventListener('click', function (e) {
			var a = e.target.closest && e.target.closest('a.post_no');
			if (!a || !/citeReply/.test(a.getAttribute('onclick') || '')) return;
			var m = /citeReply\((\d+)/.exec(a.getAttribute('onclick'));
			if (!m) return;
			var post = a.closest('div.post');
			if (post && post.closest('.nichan-preview')) return;
			// Board index: go to the thread (the link does that); don't paste into the new-thread form.
			if (!threadPageForm()) { e.stopPropagation(); return; }
			e.preventDefault();
			e.stopPropagation();
			if (openInline(post, m[1])) citeInto(m[1]);
		}, true);
		// Arrived from an index page with #q123: open the form under that post.
		var qm = /^#q(\d+)$/.exec(location.hash);
		if (qm && threadPageForm()) {
			var post = document.getElementById('reply_' + qm[1]) || document.getElementById('op_' + qm[1]);
			if (post && openInline(post, qm[1])) {
				citeInto(qm[1]);
				inline.wrap.scrollIntoView({ block: 'center' });
			}
		}
	}

	/* ============================================== auto update + notifications */

	// Two checkboxes at the end of a thread (both off by default, remembered in this browser):
	// fetch new posts every 5 seconds, and show a desktop notification when new posts arrive.
	var markOpPosts = function () {};
	function setupThreadUpdates() {
		var thread = document.querySelector('div.thread');
		if (window.active_page !== 'thread' || !thread || window.inMod) return;
		var slot = document.querySelector('.nichan-update-slot');
		var bar = document.createElement('div');
		bar.className = 'nichan-thread-updates';
		bar.innerHTML = '<span class="u-group"><button type="button" class="u-now">' + T.updateNow + '</button> ' +
			'<label><input type="checkbox" class="u-auto"> ' + T.autoUpdate + '</label> <span class="u-countdown"></span></span>' +
			'<span class="u-group"><label><input type="checkbox" class="u-notify"> ' + T.notify + '</label> ' +
			'<label title="' + T.push + '"><input type="checkbox" class="u-push"> ' + (EN ? 'even when the site is closed' : 'навіть якщо сайт закрито') +
			'</label></span><span class="u-status unimportant" role="status"></span>';
		if (slot) slot.appendChild(bar);
		else thread.parentNode.insertBefore(bar, thread.nextSibling);
		// "OP posts only" in threads whose author marks their replies ## OP
		var opLink = document.createElement('a');
		opLink.href = 'javascript:void(0)';
		opLink.className = 'u-oponly';
		opLink.style.display = 'none';
		opLink.addEventListener('click', function () {
			var on = !thread.classList.contains('nichan-op-only');
			thread.classList.toggle('nichan-op-only', on);
			opLink.textContent = '[' + (on ? T.allPosts : T.opOnly) + ']';
		});
		opLink.textContent = '[' + T.opOnly + ']';
		bar.insertBefore(opLink, bar.querySelector('.u-status'));
		bar.insertBefore(document.createTextNode(' '), bar.querySelector('.u-status'));
		markOpPosts = function () {
			var any = false;
			Array.prototype.forEach.call(thread.querySelectorAll('div.post.reply'), function (p) {
				var cap = p.querySelector('p.intro .capcode');
				var op = !!cap && /##\s*OP\b/.test(cap.textContent);
				p.classList.toggle('nichan-op-post', op);
				if (op) any = true;
			});
			opLink.style.display = any ? '' : 'none';
		};
		markOpPosts();
		var auto = bar.querySelector('.u-auto'), notify = bar.querySelector('.u-notify'), status = bar.querySelector('.u-status');
		auto.checked = store('nichan_auto_update') === '1';
		notify.disabled = !window.Notification;
		notify.checked = store('nichan_notify') === '1' && window.Notification && Notification.permission === 'granted';
		var timer = null, nextUpdate = 0, countdown = bar.querySelector('.u-countdown');
		var unseen = 0, title = document.title, busy = false;
		var threadETag = null;

		function lastId() {
			var id = Number(window.thread_id) || 0;
			Array.prototype.forEach.call(thread.children, function (p) {
				if (p.matches('div.post.reply:not(.inline):not(.post-hover)')) id = Math.max(id, Number(postIdOf(p)) || 0);
			});
			return id;
		}
		function poll(manual) {
			if (busy) return;
			if (manual === true) status.textContent = T.loading;
			busy = true;
			// The published HTML (including a last-50 page) carries both replies and counts.
			var etag;
			fetch(location.pathname, { credentials: 'same-origin', cache: 'no-cache',
				headers: threadETag ? { 'If-None-Match': threadETag } : {}
			}).then(function (r) {
				etag = r.headers.get('ETag');
				if (r.status === 304 || (r.ok && etag && etag === threadETag)) return null;
				if (!r.ok) throw new Error('Thread page unavailable');
				return r.text();
			}).then(function (html) {
				busy = false;
				if (html === null) { if (manual === true) status.textContent = T.noNew; return; }
				if (!html) throw new Error('Empty thread page');
				var doc = new DOMParser().parseFromString(html, 'text/html');
				if (!doc.getElementById(thread.id)) throw new Error('Thread missing from response');
				var max = lastId(), added = [];
				if (window.jQuery) window.jQuery(document).trigger('thread_refreshed', [doc.querySelector('#thread_stats .nichan-bump-remaining')]);
				var after = thread.querySelector('div.post.op');
				Array.prototype.forEach.call(thread.children, function (p) {
					if (p.matches('div.post.reply:not(.inline):not(.post-hover)')) after = p;
				});
				Array.prototype.forEach.call(doc.querySelectorAll('div.thread > div.post.reply'), function (p) {
					if ((parseInt(postIdOf(p), 10) || 0) <= max || document.getElementById(p.id)) return;
					var node = document.importNode(p, true), br = document.createElement('br');
					var ref = after.nextSibling && after.nextSibling.nodeName === 'BR' ? after.nextSibling : after;
					ref.parentNode.insertBefore(br, ref.nextSibling);
					ref.parentNode.insertBefore(node, ref.nextSibling);
					after = node;
					added.push(node);
				});
				threadETag = etag;
				if (!added.length) { if (manual === true) status.textContent = T.noNew; return; }
				added.forEach(function (node) {
					if (window.jQuery) window.jQuery(document).trigger('new_post', node);
				});
				afterNewPosts(document);
				status.textContent = '+' + added.length;
				if (document.hidden) {
					unseen += added.length;
					document.title = '(' + unseen + ') ' + title;
					if (notify.checked && window.Notification && Notification.permission === 'granted') {
						var text = (added[added.length - 1].querySelector('div.body') || {}).textContent || '';
						var n = new Notification(T.newPosts + ': ' + unseen, { body: text.trim().slice(0, 140), tag: 'nichan-' + location.pathname });
						n.onclick = function () { window.focus(); n.close(); };
					}
				}
			}).catch(function () { busy = false; if (manual === true) status.textContent = T.failed; });
		}
		function setAuto() {
			store('nichan_auto_update', auto.checked ? '1' : '0');
			clearInterval(timer);
			if (!auto.checked) { countdown.textContent = ''; return; }
			nextUpdate = Date.now() + 5000;
			function tick() {
				var now = Date.now();
				if (now >= nextUpdate) { nextUpdate = now + 5000; poll(); }
				countdown.textContent = '(' + Math.ceil((nextUpdate - now) / 1000) + (EN ? ' s)' : ' с)');
			}
			tick();
			timer = setInterval(tick, 1000);
		}
		var pushBox = bar.querySelector('.u-push');
		pushBox.disabled = !pushSupported();
		if (!pushBox.disabled) {
			pushBox.checked = store('nichan_push') === '1' && Notification.permission === 'granted';
			pushBox.addEventListener('change', function () {
				(pushBox.checked ? pushOn() : pushOff()).then(function (ok) { pushBox.checked = ok; }, function (err) {
					pushBox.checked = false;
					var why = err && err.message;
					status.textContent = why === 'denied' ? T.pushDenied : why === T.pushIos ? T.pushIos : T.pushFail;
				});
			});
		}
		auto.addEventListener('change', setAuto);
		bar.querySelector('.u-now').addEventListener('click', function () { if (auto.checked) setAuto(); poll(true); });
		notify.addEventListener('change', function () {
			if (notify.checked && window.Notification && Notification.permission !== 'granted') {
				Notification.requestPermission().then(function (p) {
					notify.checked = p === 'granted';
					store('nichan_notify', notify.checked ? '1' : '0');
				});
				return;
			}
			if (!window.Notification) notify.checked = false;
			store('nichan_notify', notify.checked ? '1' : '0');
		});
		document.addEventListener('visibilitychange', function () {
			if (!document.hidden) { unseen = 0; document.title = title; }
		});
		// after posting from this page, show the new post right away
		if (window.jQuery) window.jQuery(document).ajaxComplete(function (e, xhr, opts) {
			if (opts && /post\.php/.test(opts.url || '')) setTimeout(poll, 300);
		});
		setAuto();
	}

	/* =============================================================== embeds */

	// Links to video / audio sites become players. The player loads only when clicked, so pages
	// stay light and nothing is fetched from those sites until the visitor wants it.
	var EMBEDS = [
		{ name: 'YouTube', re: /^https?:\/\/(?:www\.|m\.|music\.)?(?:youtube\.com\/(?:watch\?(?:.*&)?v=|shorts\/|live\/|embed\/)|youtu\.be\/)([\w-]{11})/i,
			src: function (m) { return 'https://www.youtube-nocookie.com/embed/' + m[1] + '?autoplay=1&rel=0'; },
			thumb: function (m) { return 'https://i.ytimg.com/vi/' + m[1] + '/mqdefault.jpg'; }, ratio: 16 / 9 },
		{ name: 'Vimeo', re: /^https?:\/\/(?:www\.)?vimeo\.com\/(?:video\/)?(\d{3,12})/i,
			src: function (m) { return 'https://player.vimeo.com/video/' + m[1] + '?autoplay=1'; }, ratio: 16 / 9 },
		{ name: 'Dailymotion', re: /^https?:\/\/(?:www\.)?(?:dailymotion\.com\/video|dai\.ly)\/([a-z0-9]{5,12})/i,
			src: function (m) { return 'https://www.dailymotion.com/embed/video/' + m[1] + '?autoplay=1'; }, ratio: 16 / 9 },
		{ name: 'Coub', re: /^https?:\/\/(?:www\.)?coub\.com\/view\/([a-z0-9]+)/i,
			src: function (m) { return 'https://coub.com/embed/' + m[1] + '?muted=false&autostart=true'; }, ratio: 1 },
		{ name: 'TikTok', re: /^https?:\/\/(?:www\.)?tiktok\.com\/@[\w.-]+\/video\/(\d+)/i,
			src: function (m) { return 'https://www.tiktok.com/embed/v2/' + m[1]; }, ratio: 9 / 16, max: 325 },
		{ name: 'Instagram', re: /^https?:\/\/(?:www\.)?instagram\.com\/(?:[\w.]+\/)?(p|reel|reels|tv)\/([\w-]+)/i,
			src: function (m) { return 'https://www.instagram.com/' + (m[1] === 'reels' ? 'reel' : m[1]) + '/' + m[2] + '/embed'; }, ratio: 4 / 5, max: 400 },
		{ name: 'SoundCloud', re: /^https?:\/\/(?:www\.|m\.)?soundcloud\.com\/[\w-]+\/[\w-]+/i,
			src: function (m) { return 'https://w.soundcloud.com/player/?url=' + encodeURIComponent(m[0]) + '&auto_play=true&visual=false'; }, height: 166 },
		{ name: 'Telegram', re: /^https?:\/\/t\.me\/(?:s\/)?([a-z0-9_]{4,})\/(\d+)/i, telegram: true }
	];

	function embedLinks(root) {
		if (store('nichan_embeds') === '0') return; // (settings.php: players off)
		Array.prototype.forEach.call((root.querySelectorAll ? root : document).querySelectorAll('div.body a[href^="http"]'), function (a) {
			if (a.hasAttribute('data-nichan-embed') || a.closest('.nichan-preview')) return;
			var href = a.getAttribute('href');
			for (var i = 0; i < EMBEDS.length; i++) {
				var m = EMBEDS[i].re.exec(href);
				if (m) { a.setAttribute('data-nichan-embed', '1'); addPlayer(a, EMBEDS[i], m); return; }
			}
		});
	}

	function addPlayer(a, p, m) {
		var box = document.createElement('div');
		box.className = 'nichan-embed';
		if (p.telegram) {
			// Telegram posts are shown by Telegram's own widget script.
			box.innerHTML = '<button type="button" class="nichan-embed-open">▶ ' + T.showVideo + ': Telegram</button>';
			box.querySelector('button').addEventListener('click', function () {
				box.innerHTML = '';
				var s = document.createElement('script');
				s.async = true;
				s.src = 'https://telegram.org/js/telegram-widget.js?22';
				s.setAttribute('data-telegram-post', m[1] + '/' + m[2]);
				s.setAttribute('data-width', '100%');
				box.appendChild(s);
			});
		} else {
			var thumb = p.thumb ? '<img src="' + p.thumb(m) + '" alt="" loading="lazy">' : '';
			box.innerHTML = '<button type="button" class="nichan-embed-open' + (thumb ? ' has-thumb' : '') + '" title="' + p.name + '">' + thumb +
				'<span>▶ ' + (p.height ? p.name : T.videoFrom + ' ' + p.name) + '</span></button>';
			box.querySelector('button').addEventListener('click', function () {
				var f = document.createElement('iframe');
				f.src = p.src(m);
				f.setAttribute('allowfullscreen', '');
				f.setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture; fullscreen');
				f.setAttribute('loading', 'lazy');
				f.setAttribute('frameborder', '0');
				var w = Math.min(p.max || 480, box.parentNode ? box.parentNode.clientWidth || 480 : 480);
				f.style.width = w + 'px';
				f.style.maxWidth = '100%';
				f.style.height = (p.height || Math.round(w / p.ratio)) + 'px';
				box.innerHTML = '';
				box.appendChild(f);
			});
		}
		var after = a.nextSibling && a.nextSibling.nodeName === 'BR' ? a.nextSibling : a;
		after.parentNode.insertBefore(box, after.nextSibling);
	}

	// Everything that has to run again when posts are added to the page.
	// An eye before "File" of every file: that one picture only, as «Сховати картинки» does for all (almost see-through
	// until hovered). Remembered in this browser (nichan_muted_files = {"board:post:n": time}, 30 days).
	var MUTED_FILES = 'nichan_muted_files';
	function mutedFiles() {
		try { var o = JSON.parse(store(MUTED_FILES) || '{}'); return o && typeof o === 'object' ? o : {}; } catch (e) { return {}; }
	}
	// (a first post's files sit in the thread before the post itself, a reply's inside it)
	function fileKey(file) {
		var thread = file.closest('.thread'), post = file.closest('div.post') || (thread && thread.querySelector('div.post.op'));
		var files = Array.prototype.slice.call(file.parentNode.children).filter(function (c) { return c.classList.contains('file'); });
		return post ? (thread ? thread.getAttribute('data-board') : board) + ':' + postIdOf(post) + ':' + files.indexOf(file) : '';
	}
	function setFileMuted(file, on) {
		file.classList.toggle('nichan-file-muted', on);
		var a = file.querySelector('.nichan-file-mute');
		if (!a) return;
		a.title = on ? T.fileShow : T.fileHide;
		a.innerHTML = FA ? '<i class="fa ' + (on ? 'fa-eye' : 'fa-eye-slash') + '"></i>' : (on ? '&#128065;' : '&#8856;');
	}
	function addFileMute(root) {
		var muted = mutedFiles();
		Array.prototype.forEach.call((root.querySelectorAll ? root : document).querySelectorAll('div.file p.fileinfo'), function (info) {
			var file = info.closest('div.file');
			if (info.querySelector('.nichan-file-mute') || info.closest('.nichan-preview')) return;
			var a = document.createElement('a');
			a.href = 'javascript:void(0)';
			a.className = 'nichan-file-mute';
			info.insertBefore(a, info.firstChild);
			setFileMuted(file, !!muted[fileKey(file)]);
		});
	}
	function setupFileMute() {
		document.addEventListener('click', function (e) {
			var a = e.target.closest && e.target.closest('.nichan-file-mute');
			if (!a) return;
			e.preventDefault();
			var file = a.closest('div.file'), key = fileKey(file), all = mutedFiles(), now = Date.now();
			Object.keys(all).forEach(function (k) { if (now - all[k] > 30 * 86400000) delete all[k]; });
			var on = !file.classList.contains('nichan-file-muted');
			if (on) all[key] = now;
			else delete all[key];
			store(MUTED_FILES, JSON.stringify(all));
			setFileMuted(file, on);
		});
	}

	/* ============================================================ board view */

	// Board pages look "standard" or "compact" (nichan_board_view; the switch after «Каталог | Архів», and settings).
	// Compact: the first post of a thread with all its files and the first sentences of its text (a note under
	// it unfolds the rest or opens the thread); replies with their header, how many files they have and the start
	// of their text (a click shows the whole reply). What is built stays on the page; the view is a class on <html>.
	var OP_CHARS = 300, REPLY_CHARS = 140;
	var V = EN ? { files: ['file attached', 'files attached'], long: 'Post too long. Click <a href="javascript:void(0)" class="nichan-cexpand">here</a> to view the full text, or <a class="nichan-open-thread" href="%s">here</a> to open the thread.', whole: 'Show the whole reply', compact: 'compact', standard: 'standard', toStandard: 'Switch to the standard view', toCompact: 'Switch to the compact view' }
		: { files: ['файл прикріплено', 'файли прикріплено', 'файлів прикріплено'], long: 'Допис задовгий. Натисніть <a href="javascript:void(0)" class="nichan-cexpand">тут</a>, щоб переглянути текст повністю, або <a class="nichan-open-thread" href="%s">тут</a>, щоб розкрити нитку.', whole: 'Показати відповідь повністю', compact: 'стиснуто', standard: 'стандартно', toStandard: 'Перемкнути на стандартний вигляд', toCompact: 'Перемкнути на стиснутий вигляд' };
	function isBoardPage() { return window.active_page === 'index' || window.active_page === 'ukko'; }
	// the visitor's choice; until they make one, the site's default (mod.php -> Сайт: назва й меню, via site-status.js)
	function boardView() {
		var v = store('nichan_board_view') || (window.nichanStatus && window.nichanStatus.view) || 'standard';
		return v === 'compact' ? 'compact' : 'standard';
	}
	function setBoardView(mode) {
		store('nichan_board_view', mode);
		applyBoardView();
	}
	function applyBoardView() {
		var compact = isBoardPage() && boardView() === 'compact';
		document.documentElement.classList.toggle('nichan-compact', compact);
		if (compact) buildCompact(document);
		// the switch says the view in use; a click turns to the other one
		Array.prototype.forEach.call(document.querySelectorAll('.nichan-view-toggle'), function (a) {
			a.textContent = compact ? V.compact : V.standard;
			a.title = compact ? V.toStandard : V.toCompact;
		});
	}
	// Where to cut plain text of $n characters or more: at the end of a sentence past half of them, else at a space.
	function cutAt(text, n) {
		if (text.length <= n) return -1;
		var part = text.slice(0, n), m, best = -1, re = /[.!?…](\s|$)/g;
		while ((m = re.exec(part))) if (m.index + 1 >= n / 2) best = m.index + 1;
		if (best > 0) return best;
		var sp = part.lastIndexOf(' ');
		return sp > n / 2 ? sp : n;
	}
	// The body cut to $at characters of its text, markup kept (the text nodes after the cut are dropped).
	function cutBody(body, at) {
		var clone = body.cloneNode(true);
		Array.prototype.forEach.call(clone.querySelectorAll('.toolong'), function (t) { t.remove(); });
		var walker = document.createTreeWalker(clone, NodeFilter.SHOW_TEXT), node, seen = 0, drop = [], done = false;
		while ((node = walker.nextNode())) {
			if (done) { drop.push(node); continue; }
			var len = node.nodeValue.length;
			if (seen + len >= at) { node.nodeValue = node.nodeValue.slice(0, at - seen).replace(/\s+$/, '') + '…'; done = true; }
			seen += len;
		}
		drop.forEach(function (n) { n.remove(); });
		// (elements left empty after the cut, such as a spoiler or <br>s at the end)
		var last;
		while ((last = clone.lastChild) && (last.nodeType === 1 && !last.textContent.trim() && !/^(IMG)$/.test(last.tagName))) last.remove();
		return clone;
	}
	function plainText(body) {
		var clone = body.cloneNode(true);
		// (a moderator's note or a ban message is not the author's text)
		Array.prototype.forEach.call(clone.querySelectorAll('.nichan-modnote, .public_ban'), function (t) { t.remove(); });
		Array.prototype.forEach.call(clone.querySelectorAll('.toolong, br'), function (t) { t.replaceWith(' '); });
		return clone.textContent.replace(/\s+/g, ' ').trim();
	}
	function buildCompact(root) {
		if (!isBoardPage()) return;
		Array.prototype.forEach.call((root.querySelectorAll ? root : document).querySelectorAll('.thread div.post'), function (post) {
			if (post.hasAttribute('data-nichan-compact') || post.closest('.nichan-preview')) return;
			post.setAttribute('data-nichan-compact', '1');
			var body = post.querySelector('div.body');
			if (!body) return;
			if (post.classList.contains('op')) {
				var text = plainText(body), at = cutAt(text, OP_CHARS);
				if (at < 0) return; // (short enough as it is)
				var cut = cutBody(body, at);
				cut.classList.add('nichan-cbody');
				var link = post.querySelector('p.intro a.post_no');
				var note = document.createElement('span');
				note.className = 'toolong nichan-ctoolong';
				note.innerHTML = V.long.replace('%s', esc(link ? link.getAttribute('href').replace(/#.*$/, '') : '#'));
				cut.appendChild(note);
				body.parentNode.insertBefore(cut, body.nextSibling);
				post.classList.add('nichan-has-cbody');
				return;
			}
			// a reply: how many files, and the start of its text
			var files = post.querySelectorAll('div.file').length, text2 = plainText(body), at2 = cutAt(text2, REPLY_CHARS);
			var box = document.createElement('div');
			box.className = 'nichan-csnip';
			box.title = V.whole;
			box.innerHTML = (files ? '<div class="nichan-cfiles unimportant">' + files + ' ' + plural(files, V.files) + '</div>' : '') +
				(text2 ? '<div class="nichan-ctext">' + esc(at2 < 0 ? text2 : text2.slice(0, at2).replace(/\s+$/, '') + '…') + '</div>' : '');
			body.parentNode.insertBefore(box, body);
		});
	}
	function setupBoardView() {
		if (!isBoardPage()) return;
		Array.prototype.forEach.call(document.querySelectorAll('.nichan-view-switch'), function (s) { s.style.display = ''; });
		document.addEventListener('click', function (e) {
			var sw = e.target.closest && e.target.closest('.nichan-view-toggle');
			if (sw) { e.preventDefault(); setBoardView(boardView() === 'compact' ? 'standard' : 'compact'); return; }
			// «тут» under a cut first post: its whole text
			var more = e.target.closest && e.target.closest('.nichan-cexpand');
			if (more) { e.preventDefault(); more.closest('div.post').setAttribute('data-nichan-full', '1'); return; }
			// a click on a cut reply: the whole reply
			var snip = e.target.closest && e.target.closest('html.nichan-compact .nichan-csnip');
			if (snip && !(e.target.closest('a'))) snip.closest('div.post').setAttribute('data-nichan-full', '1');
		});
		applyBoardView();
	}

	function afterNewPosts(root) {
		applyFilters(root);
		addPostTools(root);
		addPostMenus(root);
		addFileMute(root);
		if (document.documentElement.classList.contains('nichan-compact')) buildCompact(root);
		embedLinks(root);
		relabelLinks(root);
		updateReplies();
		markRepliesToYou();
		markThreadSeen();
		highlightCode(root);
		markOpPosts();
	}

	/* ======================================================== code highlight */

	// [code] blocks (<pre class="prettyprint">, lines split by <br>) are coloured by highlight.js when the
	// language is recognised with some confidence; ASCII art and plain text stay as they are.
	var HL_LANGS = ['javascript', 'typescript', 'python', 'php', 'bash', 'shell', 'c', 'cpp', 'csharp', 'java', 'go', 'rust',
		'sql', 'json', 'xml', 'css', 'ini', 'yaml', 'kotlin', 'lua', 'ruby', 'powershell', 'diff', 'makefile', 'dockerfile'];
	var hlLoading = null;
	function loadHighlight() {
		if (window.hljs) return Promise.resolve();
		if (!hlLoading) hlLoading = new Promise(function (ok, fail) {
			// dark page = light text (the background may be a picture)
			var m = /(\d+),\s*(\d+),\s*(\d+)/.exec(getComputedStyle(document.body).color);
			var dark = m ? (0.299 * m[1] + 0.587 * m[2] + 0.114 * m[3]) > 128 : true;
			var css = document.createElement('link');
			css.rel = 'stylesheet';
			css.href = '/js/highlight/' + (dark ? 'github-dark' : 'github') + '.min.css';
			document.head.appendChild(css);
			var js = document.createElement('script');
			js.src = '/js/highlight/highlight.min.js';
			js.onload = ok;
			js.onerror = fail;
			document.head.appendChild(js);
		});
		return hlLoading;
	}
	function highlightCode(root) {
		var blocks = Array.prototype.filter.call((root.querySelectorAll ? root : document).querySelectorAll('div.body pre.prettyprint'), function (pre) {
			return !pre.hasAttribute('data-hl');
		});
		if (!blocks.length) return;
		loadHighlight().then(function () {
			var langs = HL_LANGS.filter(function (l) { return window.hljs.getLanguage(l); });
			var tmp = document.createElement('div');
			blocks.forEach(function (pre) {
				if (pre.hasAttribute('data-hl')) return;
				pre.setAttribute('data-hl', '1');
				tmp.innerHTML = pre.innerHTML.replace(/<br\s*\/?>/gi, '\n');
				var text = tmp.textContent;
				if (text.length > 20000) return;
				var r = window.hljs.highlightAuto(text, langs);
				if (r.relevance < 3) return; // (ASCII art and plain text score 0)
				pre.innerHTML = r.value;
				pre.classList.add('hljs');
				pre.title = r.language || '';
			});
		}).catch(function () {});
	}

	/* ============================================================ reCAPTCHA */

	var captchaWidgets = [];
	window.nichanRenderRecaptcha = function () {
		if (!window.grecaptcha || !window.grecaptcha.render) return;
		Array.prototype.forEach.call(document.querySelectorAll('.g-recaptcha'), function (el) {
			if (el.getAttribute('data-nichan-rendered')) return;
			el.setAttribute('data-nichan-rendered', '1');
			el.innerHTML = '';
			captchaWidgets.push(window.grecaptcha.render(el, { sitekey: el.getAttribute('data-sitekey') }));
		});
	};
	// site-status.js says which captcha each board has: reCAPTCHA, or the site's picture captcha
	// with two words ("uk" / "en"). Add it to the post form.
	function captchaType() {
		var st = window.nichanStatus && window.nichanStatus.captcha;
		if (!st || !st.boards || window.inMod) return '';
		var t = st.boards[board] || '';
		return t === 'recaptcha' && !st.key ? '' : t;
	}
	function addCaptchaRow(form, content) {
		var row = form.querySelector('#upload') || form.querySelector('table tr:last-child');
		var tr = document.createElement('tr');
		tr.className = 'nichan-captcha-row';
		tr.innerHTML = '<th>' + T.captcha + '</th><td></td>';
		tr.lastChild.appendChild(content);
		if (row && row.parentNode) row.parentNode.insertBefore(tr, row);
		else form.appendChild(content);
	}
	// On boards with country rules the server selects the widget for this visitor.
	function setupCaptcha() {
		var st = window.nichanStatus && window.nichanStatus.captcha;
		var forms = document.querySelectorAll('form[name="post"]');
		if (!forms.length || window.inMod) return;
		var country = (st && st.country) || [];
		function boardDefault() {
			var type = captchaType();
			if (type) showCaptcha(type, st.key);
		}
		if (country.indexOf(board) < 0 && country.indexOf('*') < 0) return boardDefault();
		fetch('/captcha.php?need=1&board=' + encodeURIComponent(board), { credentials: 'same-origin', cache: 'no-store' })
			.then(function (r) { if (!r.ok) throw new Error(); return r.json(); })
			.then(function (d) { if (d.type) showCaptcha(d.type, d.key); })
			.catch(boardDefault);
	}
	function showCaptcha(type, key) {
		var forms = document.querySelectorAll('form[name="post"]');
		if (type === 'unavailable') {
			Array.prototype.forEach.call(forms, function (form) {
				addCaptchaRow(form, document.createTextNode(EN ? 'Captcha is temporarily unavailable.' : 'Капча тимчасово недоступна.'));
			});
			return;
		}
		if (type === 'recaptcha') {
			Array.prototype.forEach.call(forms, function (form) {
				if (form.querySelector('.g-recaptcha')) return;
				var box = document.createElement('div');
				box.className = 'g-recaptcha';
				box.setAttribute('data-sitekey', key);
				addCaptchaRow(form, box);
			});
			if (!document.querySelector('script[src*="recaptcha/api.js"]')) {
				var s = document.createElement('script');
				s.src = 'https://www.google.com/recaptcha/api.js?onload=nichanRecaptchaLoad&render=explicit&hl=' + (EN ? 'en' : 'uk');
				s.async = true;
				document.head.appendChild(s);
			} else if (window.nichanRecaptchaReady) {
				window.nichanRenderRecaptcha();
			}
			return;
		}
		// Picture captcha: fetched when the visitor starts writing (every picture is a row in the
		// database, so not for every page view), and again after each attempt to post.
		Array.prototype.forEach.call(forms, function (form) {
			if (form.querySelector('.nichan-tcaptcha')) return;
			var box = document.createElement('div');
			box.className = 'nichan-tcaptcha';
			box.innerHTML = '<a href="javascript:void(0)" class="tc-pic" title="' + T.captchaNew + '"><span class="unimportant">' + T.captchaGet + '</span></a>' +
				'<input type="text" name="nichan_captcha" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" placeholder="' + T.captchaPh + '">' +
				'<input type="hidden" name="nichan_captcha_id" value="">';
			addCaptchaRow(form, box);
			box.querySelector('.tc-pic').addEventListener('click', function () { loadTextCaptcha(box); });
			form.addEventListener('focusin', function () { if (!box.getAttribute('data-loaded')) loadTextCaptcha(box); });
		});
	}
	function loadTextCaptcha(box) {
		box.setAttribute('data-loaded', '1');
		var x = new XMLHttpRequest();
		x.open('GET', '/captcha.php?board=' + encodeURIComponent(board) + '&_=' + Date.now());
		x.onload = function () {
			var d;
			try { d = JSON.parse(x.responseText); } catch (e) { return; }
			if (!d || !d.id) return;
			var pic = box.querySelector('.tc-pic');
			pic.innerHTML = '<img alt="" src="' + d.img + '">';
			box.querySelector('input[name="nichan_captcha_id"]').value = d.id;
			box.querySelector('input[name="nichan_captcha"]').value = '';
		};
		x.send();
	}
	/* ================================================= live numbers, long posts */

	// Board speed on the front page and in board footers is baked into static pages; fetch the
	// current numbers (statistics.php?json=1, cached for a minute) and put them in.
	function setupLiveStats() {
		var box = document.querySelector('.nichan-speed-box');
		var spans = document.querySelectorAll('.nichan-live-hour, .nichan-live-month');
		if (!box && !spans.length) return;
		fetch('/statistics.php?json=1', { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
			if (!d || !d.boards) return;
			if (box && d.table) box.innerHTML = d.table;
			Array.prototype.forEach.call(spans, function (el) {
				var b = d.boards[el.getAttribute('data-board')];
				if (!b) return;
				if (el.classList.contains('nichan-live-month')) el.textContent = b.month_posters;
				else if (typeof b.per_hour === 'number') {
					var rate = b.per_hour.toFixed(2);
					el.textContent = el.hasAttribute('data-dot') ? rate : rate.replace('.', ',');
					el.parentNode.hidden = false;
				}
			});
		}).catch(function () {});
	}

	// "Post too long" on board pages: the first "here" unfolds the whole text right there (the post
	// is taken from its thread page), the second one simply opens the thread.
	function setupLongPosts() {
		document.addEventListener('click', function (e) {
			var a = e.target.closest && e.target.closest('.toolong a');
			if (!a || a.classList.contains('nichan-open-thread') || a.classList.contains('nichan-cexpand')) return;
			// (pages built before this change have a single link: it unfolds too)
			var post = a.closest('div.post'), body = post && post.querySelector('div.body');
			if (!body) return;
			e.preventDefault();
			e.stopPropagation();
			var note = a.closest('.toolong');
			if (note.getAttribute('data-loading')) return;
			note.setAttribute('data-loading', '1');
			note.style.opacity = '.5';
			var id = post.id;
			fetch(a.getAttribute('href').replace(/#.*$/, ''), { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.text() : ''; }).then(function (html) {
				var full = html && new DOMParser().parseFromString(html, 'text/html').getElementById(id);
				var fullBody = full && full.querySelector('div.body');
				if (!fullBody) { location.href = a.href; return; }
				body.innerHTML = fullBody.innerHTML;
				afterNewPosts(post);
			}).catch(function () { location.href = a.href; });
		}, true);
	}

	function installApp() {
		if (installPrompt) {
			installPrompt.prompt();
			installPrompt.userChoice.then(function () { installPrompt = null; });
			return;
		}
		var ua = navigator.userAgent;
		var how = /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1) ? T.installIos
			: /Android/.test(ua) ? T.installAndroid : T.installOther;
		dialog(T.installTitle, '<p>' + how + '</p>', T.ok, closeDialog);
	}
	// Older generated pages have a placeholder for the install link in their footer.
	function setupInstallLink() {
		if (standalone) return;
		Array.prototype.forEach.call(document.querySelectorAll('.nichan-app-footer'), function (p) {
			p.innerHTML = '<a href="javascript:void(0)" class="nichan-install">' + T.installTitle + '</a>';
			p.style.display = '';
		});
		Array.prototype.forEach.call(document.querySelectorAll('a.nichan-install'), function (link) {
			link.addEventListener('click', function (event) {
				event.preventDefault();
				installApp();
			});
		});
	}

	function resetCaptcha() {
		// a picture is used up by any attempt to post, right or wrong
		Array.prototype.forEach.call(document.querySelectorAll('.nichan-tcaptcha[data-loaded]'), loadTextCaptcha);
		if (!window.grecaptcha) return;
		captchaWidgets.forEach(function (id) { try { window.grecaptcha.reset(id); } catch (e) {} });
	}

	/* ================================================================ start */

	ready(function () {
		setupCaptcha();
		// A reCAPTCHA answer is single-use: get a fresh one after every attempt to post.
		if (window.jQuery) window.jQuery(document).ajaxComplete(function (e, xhr, opts) {
			if (opts && /post\.php/.test(opts.url || '')) resetCaptcha();
		});
		setupDrafts(); // before the editors, so the visual editor shows the restored text
		setupContinuation();
		setupFormOptions();
		setupFormButtons();
		applyNavHidden();
		setupHeader();
		setupPictureClicks();
		setupQuoteSelection();
		setupSettingsPage();
		setupStyleSwitch();
		setupOnline();
		setupPendingThreads();
		setupFrontRefresh(setupSince());
		setupNewsExpiry();
		if (window.jQuery) window.jQuery(document).on('ajax_after_post', function (e, r) { if (r && r.id) pushOwnPost(r.id); });
		setupEditors();
		setupPreviews();
		if (document.querySelector('div.post')) {
			setupFilterUI();
			afterNewPosts(document);
			checkSaved(false);
		}
		setupInlineReply();
		setupPostMenus();
		setupBoardView();
		setupFileMute();
		setupInstallLink();
		setupLiveStats();
		setupLongPosts();
		setupThreadUpdates();
		// posts added by ajax.js (after posting) or other scripts
		onNewPosts(function () { afterNewPosts(document); });
		// the visitor's own script (settings -> "Власний код"), last, in this browser only; its errors stay its own
		var ownJs = store('nichan_custom_js');
		if (ownJs) { try { (new Function(ownJs))(); } catch (e) { if (window.console) console.error('nichan: own JavaScript:', e); } }
	});
})();
