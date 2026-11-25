<?php
	require 'info.php';

	/*
	 * sitemap.xml for search engines (nichan version):
	 *  - every public board (hidden and staff-only boards never appear), as /board/
	 *  - every thread of those boards with <lastmod> = time of its latest post
	 *  - the front page, statistics and FAQ pages
	 * Addresses use $config['nichan_public_url'], so a copy on another host (new.example.org)
	 * still writes the real site's addresses. Rebuilt on new threads and deletions, and on new
	 * replies at most every 15 minutes, so <lastmod> stays close to the truth.
	 */
	function sitemap_build($action, $settings, $board) {
		global $config;

		if ($action != 'all') {
			if (!in_array($action, array('post', 'post-thread', 'post-delete')))
				return;
			$min_age = $action == 'post' ? 900 : (isset($settings['regen_time']) ? (int)$settings['regen_time'] : 0);
			if ($min_age > 0 && ($last_gen = @filemtime($settings['path'])) && time() - $last_gen < $min_age)
				return; // too soon
		}

		$action = generation_strategy('sb_sitemap', array());
		if ($action == 'delete') {
			file_unlink($settings['path']);
			return;
		}
		if ($action != 'rebuild')
			return;

		$base = rtrim(isset($config['nichan_public_url']) ? $config['nichan_public_url'] : $settings['url'], '/') . $config['root'];
		$w3c = function ($t) { return gmdate('Y-m-d\TH:i:s\Z', (int)$t); };
		$urls = array();
		$newest = 0;

		foreach (nichan_sort_boards(nichan_boards_for(false)) as $b) {
			$uri = $b['uri'];
			// latest post of every thread in one pass
			$query = query(sprintf("SELECT COALESCE(`thread`, `id`) AS `tid`, MAX(`time`) AS `lastmod` FROM ``posts_%s`` GROUP BY `tid`", $uri)) or error(db_error());
			$lastmod = array();
			foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row)
				$lastmod[$row['tid']] = (int)$row['lastmod'];
			$query = query(sprintf("SELECT `id`, `slug` FROM ``posts_%s`` WHERE `thread` IS NULL ORDER BY `bump` DESC", $uri)) or error(db_error());
			$threads = $query->fetchAll(PDO::FETCH_ASSOC);

			$board_newest = $lastmod ? max($lastmod) : 0;
			$newest = max($newest, $board_newest);
			$urls[] = array($base . sprintf($config['board_path'], $uri), $board_newest);
			foreach ($threads as $t) {
				if (!isset($lastmod[$t['id']]))
					continue;
				$urls[] = array($base . sprintf($config['board_path'], $uri) . $config['dir']['res'] . link_for($t), $lastmod[$t['id']]);
			}
		}
		array_unshift($urls, array($base, $newest));
		foreach (array('statistics.php', 'rules.php', 'intrules.php') as $page)
			$urls[] = array($base . $page, 0);

		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach (array_slice($urls, 0, 50000) as $u) {
			$xml .= '<url><loc>' . htmlspecialchars($u[0], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>' .
				($u[1] ? '<lastmod>' . $w3c($u[1]) . '</lastmod>' : '') . "</url>\n";
		}
		$xml .= "</urlset>\n";
		file_write($settings['path'], $xml);
	}
