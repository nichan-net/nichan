<?php
	require 'info.php';

	// The catalog of every public board (<board>/catalog.html) and its feed of new threads (<board>/index.rss).
	// Rebuilt after every post, so the reply counts stay fresh; sorting, search and the picture size are
	// done in the browser by the page itself. Hidden boards get no catalog (an old one is deleted).
	function catalog_build($action, $settings, $board) {
		global $config;

		// Possible values for $action: all, news, boards, post, post-thread, post-delete
		$boards = nichan_public_board_uris();

		if ($action == 'all' || $action == 'boards') {
			foreach (listBoards() as $b) {
				if (!in_array($b['uri'], $boards)) {
					file_unlink($config['dir']['home'] . $b['uri'] . '/catalog.html');
					file_unlink($config['dir']['home'] . $b['uri'] . '/index.rss');
				}
			}
			foreach ($boards as $uri)
				catalog_build_one($uri, $settings);
		} elseif (in_array($action, array('post', 'post-thread', 'post-delete')) && in_array($board, $boards)) {
			catalog_build_one($board, $settings);
		}
	}

	function catalog_build_one($uri, $settings) {
		global $config;
		$action = generation_strategy('sb_catalog', array($uri));
		if ($action == 'delete') {
			file_unlink($config['dir']['home'] . $uri . '/catalog.html');
			file_unlink($config['dir']['home'] . $uri . '/index.rss');
		} elseif ($action == 'rebuild') {
			$b = new Catalog();
			$b->build($settings, $uri);
		}
	}

	// Wrap functions in a class so they don't interfere with normal Tinyboard operations
	class Catalog {
		public function build($settings, $board_name) {
			global $config, $board;

			if (!isset($board) || $board['uri'] != $board_name) {
				if (!openBoard($board_name)) {
					error(sprintf(_("Board %s doesn't exist"), $board_name));
				}
			}

			// the threads with their reply and picture counts, in one pass
			$query = query(sprintf("SELECT `op`.`id`, `op`.`thread`, `op`.`subject`, `op`.`body`, `op`.`body_nomarkup`, `op`.`files`, `op`.`embed`,
				`op`.`time`, `op`.`bump`, `op`.`sticky`, `op`.`locked`, `op`.`slug`,
				COUNT(`r`.`id`) AS `reply_count`, IFNULL(SUM(`r`.`num_files`), 0) AS `image_count`
				FROM ``posts_%s`` AS `op` LEFT JOIN ``posts_%s`` AS `r` ON `r`.`thread` = `op`.`id`
				LEFT JOIN ``nichan_archive`` AS `ar` ON `ar`.`board` = %s AND `ar`.`thread` = `op`.`id`
				WHERE `op`.`thread` IS NULL AND `ar`.`thread` IS NULL GROUP BY `op`.`id` ORDER BY `op`.`sticky` DESC, `op`.`bump` DESC",
				$board_name, $board_name, $GLOBALS['pdo']->quote($board_name))) or error(db_error());

			$threads = array();
			while ($post = $query->fetch(PDO::FETCH_ASSOC)) {
				$post['link'] = $config['root'] . $board['dir'] . $config['dir']['res'] . link_for($post);
				$post['thumb'] = null;
				foreach ((array)json_decode((string)$post['files']) as $file) {
					if (!is_object($file) || !isset($file->file) || $file->file == 'deleted')
						continue;
					if (isset($file->thumb) && $file->thumb == 'spoiler')
						$post['thumb'] = $config['root'] . $config['spoiler_image'];
					elseif (isset($file->thumb) && $file->thumb != 'file' && $file->thumb != '')
						$post['thumb'] = $config['uri_thumb'] . $file->thumb;
					if ($post['thumb'])
						break;
				}
				// old threads with a YouTube video instead of a picture: its preview
				if (!$post['thumb'] && $post['embed'] && preg_match('~^https?://(?:\w+\.)?(?:youtube\.com/watch\?v=|youtu\.be/)([a-zA-Z0-9_-]{10,11})~', $post['embed'], $m))
					$post['thumb'] = 'https://i.ytimg.com/vi/' . $m[1] . '/mqdefault.jpg';
				$post['snippet'] = nichan_plain_text($post['body_nomarkup'], 160);
				$post['pubdate'] = date('r', $post['time']);
				$post['file'] = $post['thumb'] ? $post['thumb'] : $config['root'] . $config['image_deleted'];
				$threads[] = $post;
			}

			file_write($config['dir']['home'] . $board_name . '/catalog.html', Element('themes/catalog/catalog.html', Array(
				'settings' => $settings,
				'config' => $config,
				'boardlist' => createBoardlist(),
				'threads' => $threads,
				'board' => $board,
				'en' => strpos($config['locale'], 'en') === 0,
				'link' => $config['root'] . $board['dir']
			)));

			// the feed: the newest threads first
			$feed = $threads;
			usort($feed, function ($a, $b) { return $b['time'] - $a['time']; });
			file_write($config['dir']['home'] . $board_name . '/index.rss', Element('themes/catalog/index.rss', Array(
				'config' => $config,
				'recent_posts' => array_slice($feed, 0, 50),
				'board' => $board
			)));
		}
	};
