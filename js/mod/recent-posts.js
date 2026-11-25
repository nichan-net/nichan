/*
 * recent-posts.js
 *
 * Recent posts controlling script
 *
 * Released under the WTFPL license
 * Copyright (c) 2014 sinuca <#55ch@rizon.net>
 *
 * Requires jquery
 * incomplete
 *
 */

$(document).ready(function(){
	
	// (nichan: the key of each hidden post is its own number; the old list keyed by the thread's number hid
	// every post of a thread once one was dismissed, so it is left behind under its old name)
	if (!localStorage.nichanHiddenRecentPosts)
		localStorage.nichanHiddenRecentPosts = '{}';

	if (!localStorage.recentpostscount)
		localStorage.recentpostscount = 25;

	// Load data from HTML5 localStorage
	var hidden_data = JSON.parse(localStorage.nichanHiddenRecentPosts);

	var store_data_posts = function() {
		localStorage.nichanHiddenRecentPosts = JSON.stringify(hidden_data);
	}

	// Delete old hidden posts (7+ days old)
	for (var key in hidden_data) {
		for (var id in hidden_data[key]) {
			if (hidden_data[key][id] < Math.round(Date.now() / 1000) - 60 * 60 * 24 * 7) {
				delete hidden_data[key][id];
				store_data_posts();
			}
		}
	}

	var do_hide_posts = function() {
		var data = $(this).attr('id');
		var splitted = data.split('-');
		var id = splitted[2];
		var post_container = $(this).parent();

		var board = post_container.data("board");
		
		if (!hidden_data[board]) {
			hidden_data[board] = {};
		}

		$('<a class="hide-post-link" href="javascript:void(0)"> Сховати </a>')
		.insertBefore(post_container.find('a.eita-link:first'))
		.click(function(){
			hidden_data[board][id] = Math.round(Date.now() / 1000);
			store_data_posts();

			post_container.closest('hr').hide();
			post_container.children().hide();
		});
		if(hidden_data[board][id])
			post_container.find('a.hide-post-link').click();
	}

	$('a.eita-link').each(do_hide_posts);

	$('#erase-local-data').click(function(){
		hidden_data = {};
		store_data_posts();
		$(this).html('Завантаження…');
		location.reload();
	});

});