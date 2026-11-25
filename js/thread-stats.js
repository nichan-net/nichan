/*
 * thread-stats.js
 *   - Adds statistics of the thread below the posts area
 *   - Shows ID post count beside each postID on hover
 * 
 * Usage:
 *   $config['additional_javascript'][] = 'js/jquery.min.js';
 *   $config['additional_javascript'][] = 'js/thread-stats.js';
 */
if (active_page == 'thread') {
$(document).ready(function(){
	//check if page uses unique ID
	var IDsupport = ($('.poster_id').length > 0);
	var thread_id = (document.location.pathname + document.location.search).split('/');
	thread_id = thread_id[thread_id.length -1].split('+')[0].split('-')[0].split('.')[0];
	
	var el = $('#thread_stats');
	if (!el.length) {
		// Previously generated thread pages have no slot beside the return link.
		el = $('<div id="thread_stats"></div>').insertBefore($('.boardlist.bottom, footer').first());
	}
	el.prepend(_('сторінка:')+' <span id="thread_stats_page">?</span>');
	if (IDsupport){
		el.prepend('<span id="thread_stats_uids">0</span> UIDs |&nbsp;');
	}
	el.prepend(''+_('зображень:')+' <span id="thread_stats_images">0</span> |&nbsp;');
	el.prepend(_('Дописів:')+' <span id="thread_stats_posts">0</span> |&nbsp;');
	// The fetched thread includes the full reply count even on the last-50 view.
	$(document).on('thread_refreshed', function (event, snapshot) {
		if (!snapshot) return;
		var line = el.find('.nichan-bump-remaining');
		if (!line.length) line = $('<div class="nichan-bump-remaining"></div>').appendTo(el);
		line.text(snapshot.textContent).attr('data-replies', snapshot.getAttribute('data-replies'));
		$('#thread_stats_posts').text(snapshot.getAttribute('data-replies'));
	});
	function update_thread_stats(){
		var op = $('#thread_'+ thread_id +' > div.post.op:not(.post-hover):not(.inline)').first();
		var replies = $('#thread_'+ thread_id +' > div.post.reply:not(.post-hover):not(.inline)');
		// post count
		$('#thread_stats_posts').text(el.find('.nichan-bump-remaining').attr('data-replies') || replies.length);
		// image count
		$('#thread_stats_images').text(replies.filter(function(){ 
			return $(this).find('> .files').text().trim() != false; 
		}).length);
		// unique ID count
		if (IDsupport) {
			var opID = op.find('> .intro > .poster_id').text();
			var ids = {};
			replies.each(function(){
				var cur = $(this).find('> .intro > .poster_id');
				var curID = cur.text();
				if (ids[curID] === undefined) {
					ids[curID] = 0;
				}
				ids[curID]++;
			});
			if (ids[opID] === undefined) {
				ids[opID] = 0;
			}
			ids[opID]++;
			var cur = op.find('>.intro >.poster_id');
			cur.find('+.posts_by_id').remove();
			cur.after('<span class="posts_by_id"> ('+ ids[cur.text()] +')</span>');
			replies.each(function(){
				cur = $(this).find('>.intro >.poster_id');
				cur.find('+.posts_by_id').remove();
				cur.after('<span class="posts_by_id"> ('+ ids[cur.text()] +')</span>');
			});
			var size = function(obj) {
				var size = 0, key;
				for (key in obj) {
					if (obj.hasOwnProperty(key)) size++;
				}
				return size;
			};
			$('#thread_stats_uids').text(size(ids));
		}
	}
	function update_thread_page() {
		var board_name = $('input[name="board"]').val();
		$.getJSON('//'+ document.location.host +'/'+ board_name +'/threads.json').success(function(data){
			var found, page = '???';
			for (var i=0;data[i];i++){
				var threads = data[i].threads;
				for (var j=0; threads[j]; j++){
					if (parseInt(threads[j].no) == parseInt(thread_id)) {
						page = data[i].page +1;
						found = true;
						break;
					}
				}
				if (found) break;
			}
			$('#thread_stats_page').text(page);
			if (!found) $('#thread_stats_page').css('color','red');
			else $('#thread_stats_page').css('color','');
		});
	}
	var refreshTimer;
	function refresh() {
		update_thread_stats();
		update_thread_page();
	}
	$('body').append('<style>.posts_by_id{display:none;}.poster_id:hover+.posts_by_id{display:initial}</style>');
	refresh();
	setInterval(update_thread_page, 30000);
	$('#update_thread').click(refresh);
	$(document).on('new_post', function () {
		clearTimeout(refreshTimer);
		refreshTimer = setTimeout(refresh, 0);
	});
});
}
