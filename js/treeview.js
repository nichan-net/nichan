/*
 * treeview.js
 * https://github.com/vichan-devel/vichan/blob/master/js/treeview.js
 *
 * Released under the MIT license
 * Copyright (c) 2014 Marcin Łabanowski <marcin@6irc.net>
 *
 * Usage:
 *   $config['additional_javascript'][] = 'js/jquery.min.js';
 *   $config['additional_javascript'][] = 'js/treeview.js';
 *
 */

if (active_page == 'thread' || active_page == 'ukko' || active_page == 'index')
$(function() {
	if (window.Options && Options.get_tab('general')) {
		var selector = '#treeview-global>input';
		Options.extend_tab("general", "<label id='treeview-global'><input type='checkbox' /> "+_('Use tree view by default')+"</label>");
		$(selector).on('change', function() {
			if (localStorage.treeview === 'true') {
				localStorage.treeview = 'false';
			} else {
				localStorage.treeview = 'true';
			}
		});
		if (localStorage.treeview === 'true') {
			$(selector).attr('checked', 'checked');
		}
	}
});

if (active_page == 'thread')
$(function() {
	var treeview = function(enable) {
		if (enable === true) {
			$('.post.reply').each(function(){
				var references = [];
				$(this).find('.body a').each(function(){
					if ($(this).html().match('^&gt;&gt;[0-9]+$')) {
						references.push(parseInt($(this).html().replace('&gt;&gt;', '')));
					}
				});
				var maxref = references.reduce(function(a,b) { return a > b ? a : b; }, 0);

				var parent_post = $("#reply_"+maxref);
				if (parent_post.length == 0) return;

				var margin = parseInt(parent_post.css("margin-left"))+32;

				var post = $(this);
				var br = post.next();

				post.detach().css("margin-left", margin).insertAfter(parent_post.next());
				br.detach().insertAfter(post);
			});
		} else {
			$('.post.reply').sort(function(a,b) {
				return parseInt(a.id.replace('reply_', '')) - parseInt(b.id.replace('reply_', ''));
			}).each(function () {
				var post = $(this);
				var br = post.next();
				post.detach().css('margin-left', '').appendTo('.thread');
				br.detach().insertAfter(post);
			});
		}
	}

	var enabled = localStorage.treeview === 'true';
	var label = window.nichan_lang === 'en' ? 'Tree view' : 'Деревовидний перегляд';
	var button = $('<a href="#" role="button" id="treeview" class="nichan-tree-toggle"><i class="fa fa-sitemap" aria-hidden="true"></i></a>')
		.attr({ title: label, 'aria-label': label, 'aria-pressed': String(enabled) });
	// nichan.js builds the tools row after mute-images.js. Either script can finish first.
	var tools = $('.nichan-tools').first();
	if (tools.length)
		button.insertBefore(tools.find('.nichan-filter-toggle'));
	else
		$('<div class="nichan-tree-host" style="text-align:right"></div>').append(button).insertBefore('hr:first');
	button.on('click', function(e) {
		e.preventDefault();
		enabled = !enabled;
		treeview(enabled);
		button.attr('aria-pressed', String(enabled));
	});
	button.on('keydown', function(e) {
		if (e.key === ' ') { e.preventDefault(); button.trigger('click'); }
	});
	if (enabled)
		treeview(true);
});
