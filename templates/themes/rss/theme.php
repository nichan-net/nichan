<?php
	require 'info.php';
	
	function rss_recentposts_build($action, $settings, $board) {
		// Possible values for $action:
		//	- all (rebuild everything, initialization)
		//	- news (news has been updated)
		//	- boards (board list changed)
		//	- post (a post has been made)
		//	- post-thread (a thread has been made)
		
		$b = new RSSRecentPosts();
		$b->build($action, $settings);
	}
	
	// Wrap functions in a class so they don't interfere with normal Tinyboard operations
	class RSSRecentPosts {
		public function build($action, $settings) {
			global $config;
			
			if ($action == 'all' || $action == 'post' || $action == 'post-thread' || $action == 'post-delete')
				file_write($config['dir']['home'] . $settings['xml'], $this->homepage($settings));
		}
		
		// Build news page
		public function homepage($settings) {
			global $config, $board;
			
			$recent_posts = array();
			$limit = max(0, (int)$settings['limit_posts']);
			$excluded = explode(' ', $settings['exclude']);
			$selects = array();
			foreach (nichan_boards_for(false) as $b) {
				if (!in_array($b['uri'], $excluded, true) && $limit)
					$selects[] = sprintf("(SELECT `id`, `thread`, `time`, `body`, '%s' AS `board` FROM ``posts_%s`` ORDER BY `time` DESC LIMIT %d)", $b['uri'], $b['uri'], $limit);
			}
			// Only the newest $limit rows of any board can appear in this feed.
			$query = null;
			if ($selects)
				$query = query('SELECT * FROM (' . implode(' UNION ALL ', $selects) . ') AS recent ORDER BY `time` DESC LIMIT ' . $limit) or error(db_error());

			while ($query && ($post = $query->fetch(PDO::FETCH_ASSOC))) {
				openBoard($post['board']);
				
				$post['link'] = $config['root'] . $board['dir'] . $config['dir']['res'] . sprintf($config['file_page'], ($post['thread'] ? $post['thread'] : $post['id'])) . '#' . $post['id'];
				$post['snippet'] = pm_snippet($post['body'], 30);
				$post['board_name'] = $board['name'];
				
				$recent_posts[] = $post;
			}
			
			return Element('themes/rss/rss.xml', Array(
				'settings' => $settings,
				'config' => $config,
				//'boardlist' => createBoardlist(),
				//'recent_images' => $recent_images,
				'recent_posts' => $recent_posts,
				//'stats' => $stats
			));
		}
	};
	
?>
