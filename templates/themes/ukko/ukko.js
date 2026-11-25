/*
 * /all/ (the "ukko" theme): fresh threads of every board. More threads are loaded while scrolling; the threads
 * of a board can be hidden with the link next to its name (kept in localStorage.hiddenboards, which the
 * visitor's settings page edits too). Copied to /all/ukko.js when the theme is built.
 */
(function () {

var cache = [],
	thread = false,
	loading = false,
	ukkotimer = false;

var T = {
	hide: '(сховати нитки з цієї дошки)',
	show: '(показати нитки з цієї дошки)',
	end: 'Більше ниток немає',
	loading: 'Завантаження...'
};

var hiddenboards = {};
try { hiddenboards = JSON.parse(localStorage.hiddenboards || '{}') || {}; } catch (e) {}
var storeboards = function () {
	try { localStorage.hiddenboards = JSON.stringify(hiddenboards); } catch (e) {}
};

// "/n/ - Розмови про Нічан" (the titles come from the page: window.ukkoTitles)
var boardHeader = function (board) {
	var title = (window.ukkoTitles || {})[board];
	var h = $('<h2 class="ukko-board"><a></a> </h2>');
	h.find('a').attr('href', '/' + board + '/').text('/' + board + '/' + (title ? ' - ' + title : ''));
	return h;
};

$(document).ready(function () {
	// only the page numbers at the bottom: the menus at the top are ".pages" too
	var $pages = $('.nichan-ukko-status, .bar.bottom .pages');

	var addukkohide = function () {
		var ukkohide = $('<a href="javascript:void(0);" class="unimportant ukkohide"></a>');
		var board = $(this).next().data("board");
		var hr = $("<hr />");

		$(this).append(ukkohide);
		$(this).append(hr);

		if (hiddenboards[board] !== true) {
			ukkohide.text(T.hide);
			hr.hide();
		} else {
			ukkohide.text(T.show);
			$(this).next().hide();
		}
		ukkohide.click(function () {
			hiddenboards[board] = (hiddenboards[board] !== true);
			if (hiddenboards[board] !== true) {
				$('[data-board="' + board + '"]:not([data-cached="yes"])').show().prev()
					.find('.ukkohide').text(T.hide).parent().find('hr').hide();
			} else {
				$('[data-board="' + board + '"]:not([data-cached="yes"])').hide().prev()
					.find('.ukkohide').text(T.show).parent().find('hr').show();
			}
			storeboards();
			return false;
		});
	};
	$("h2.ukko-board").each(addukkohide);

	$pages.hide();
	var loadnext = function () {
		if (overflow.length == 0) {
			$pages.show().text(T.end);
		}
		while ($(window).scrollTop() + $(window).height() + 1000 > $(document).height() && !loading && overflow.length > 0) {
			var page = modRoot + overflow[0].board + '/' + overflow[0].page;
			thread = $('div#thread_' + overflow[0].id + '[data-board="' + overflow[0].board + '"]');
			if (thread.length > 0 && thread.attr("data-cached") !== 'yes') { // already present
				overflow.shift();
				continue;
			}

			var boardheader = boardHeader(overflow[0].board);

			if ($.inArray(page, cache) != -1) {
				if (thread.length > 0) {
					$('div[id*="thread_"]').last().after(thread.attr('data-board', overflow[0].board).attr("data-cached", "no").css('display', 'block'));
					boardheader.insertBefore(thread);
					addukkohide.call(boardheader);
					$(document).trigger('new_post', thread);
				}
				overflow.shift();
			} else {
				loading = true;
				$pages.show().text(T.loading);
				$.get(page, function (data) {
					cache.push(page);

					$(data).find('div[id*="thread_"]').each(function () {
						var checkout = $(this).attr('id').replace('thread_', '');
						if ($('div#thread_' + checkout + '[data-board="' + overflow[0].board + '"]').length == 0) {
							$('form[name="postcontrols"]').prepend($(this).css('display', 'none').attr("data-cached", "yes").attr('data-board', overflow[0].board));
						}
					});

					thread = $('div#thread_' + overflow[0].id + '[data-board="' + overflow[0].board + '"][data-cached="yes"]');

					if (thread.length > 0) {
						$('div[id*="thread_"]').last().after(thread.attr('data-board', overflow[0].board).attr("data-cached", "no").css('display', 'block'));
						boardheader.insertBefore(thread);
						addukkohide.call(boardheader);
						$(document).trigger('new_post', thread);
					}
					overflow.shift();

					loading = false;
					$pages.hide().text('');
				});
				break;
			}
		}
		clearTimeout(ukkotimer);
		ukkotimer = setTimeout(loadnext, 1000);
	};

	$(window).on('scroll', loadnext);

	ukkotimer = setTimeout(loadnext, 1000);
});

})();
