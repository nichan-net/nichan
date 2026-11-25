<?php

/*
 * Nichan site statistics: board speed, posters, daily post counts.
 *
 * Replaces the old counter.php / speed_*.php / stats*.php / statsgen*.php scripts, which read
 * credentials from a separate configuration file, ran dozens of unindexed sub-queries per page view
 * and drew charts with third-party scripts (canvasjs.com, gstatic.com). Everything here uses the
 * engine's own DB connection, indexed queries on `time`, and a small JSON file cache, so pages
 * can embed the numbers without extra requests and without anything loaded from other sites.
 */

// The public JSON endpoint can read the same cache and render its table before loading the engine.
const NICHAN_STATS_MAX_AGE = 300;

// Boards that never appear in statistics (test / hidden boards).
function nichan_stats_excluded() {
	global $config;
	return isset($config['nichan_stats_exclude']) ? $config['nichan_stats_exclude'] : array();
}

function nichan_stats_cache_file($name, $extension = '.json') {
	$dir = dirname(__DIR__) . '/tmp/cache';
	if (!is_dir($dir))
		@mkdir($dir, 0777, true);
	return $dir . '/nichan-' . $name . $extension;
}

function nichan_stats_read_cache($file, $name, $revision = null) {
	$data = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
	if (!isset($data['generated']) || !is_int($data['generated']))
		return null;
	if (($revision !== null && ($data['revision'] ?? null) !== $revision) ||
		(isset($data['expires']) && $data['expires'] <= time()))
		return null;
	if ($name === 'speed' && (!isset($data['boards'], $data['total'], $data['days']) ||
		!is_array($data['boards']) || !is_array($data['total']) || !is_array($data['days'])))
		return null;
	return $data;
}

/*
 * Returns cached data from $name, recomputing it with $compute when older than $max_age seconds.
 * A null callback only checks for a fresh copy. Refreshes use a lock shared by all writers.
 */
function nichan_stats_cached($name, $max_age, $compute, $allow_stale = false, $revision = null) {
	$file = nichan_stats_cache_file($name);
	$data = nichan_stats_read_cache($file, $name, $revision);
	if ($data !== null && ($allow_stale || $data['generated'] > time() - $max_age))
		return $data;
	if ($compute === null)
		return null;

	$lock = @fopen($file . '.lock', 'c');
	if ($lock) {
		if (!flock($lock, LOCK_EX | LOCK_NB)) {
			if ($data !== null) {
				fclose($lock);
				return $data;
			}
			flock($lock, LOCK_EX);
		}
		// Another request may have finished refreshing since the first read.
		$data = nichan_stats_read_cache($file, $name, $revision);
		if ($data !== null && $data['generated'] > time() - $max_age) {
			fclose($lock);
			return $data;
		}
	}

	$fresh = call_user_func($compute);
	$fresh['generated'] = time();
	if ($revision !== null)
		$fresh['revision'] = $revision;
	$tmp = $file . '.' . getmypid() . '.tmp';
	if (@file_put_contents($tmp, json_encode($fresh)) !== false)
		@rename($tmp, $file);

	if ($lock) {
		flock($lock, LOCK_UN);
		fclose($lock);
	}
	return $fresh;
}

function nichan_board_activity_revision($uri) {
	return (string)@file_get_contents(nichan_stats_cache_file('activity-' . $uri, '.rev'));
}

// Publish a new token without taking a reader's lock. An in-flight reader can
// only publish a snapshot tagged with its old token. File count stays bounded.
function nichan_invalidate_board_activity($uri) {
	$boards = $uri === false ? array_column(listBoards(), 'uri') : array($uri);
	foreach ($boards as $board_uri) {
		$file = nichan_stats_cache_file('activity-' . $board_uri, '.rev');
		$tmp = $file . '.' . getmypid() . '.tmp';
		if (@file_put_contents($tmp, bin2hex(random_bytes(12))) !== false && @rename($tmp, $file))
			continue;
		@unlink($tmp);
		@unlink($file);
		@unlink(nichan_stats_cache_file('since-' . $board_uri));
		@unlink(nichan_stats_cache_file('popular-board-' . $board_uri));
		error_log('Could not update activity cache revision for /' . $board_uri . '/');
	}
}

// Statistics cover exactly the public boards (Board settings in mod.php), in menu order.
function nichan_stats_boards() {
	$boards = array();
	foreach (nichan_boards_for(false) as $b)
		$boards[$b['uri']] = $b['title'];
	return $boards;
}

// Forget cached numbers (e.g. after a board became public or hidden).
function nichan_stats_flush() {
	// A refresh already computing the old board list must finish before its result is removed.
	$lock = @fopen(nichan_stats_cache_file('speed') . '.lock', 'c');
	if ($lock)
		flock($lock, LOCK_EX);
	try {
		foreach (glob(dirname(__DIR__) . '/tmp/cache/nichan-*.json') ?: array() as $f)
			@unlink($f);
	} finally {
		if ($lock)
			fclose($lock);
	}
}

/*
 * Fast numbers (refreshed every 5 minutes): posts and posters for the last 24 hours and 31 days,
 * plus posts per calendar day (site time zone) for the last 31 days.
 */
function nichan_stats($max_age = NICHAN_STATS_MAX_AGE, $allow_stale = false) {
	return nichan_stats_cached('speed', $max_age, 'nichan_stats_compute', $allow_stale);
}

function nichan_stats_compute() {
	$now = time();
	$day_ago = $now - 86400;
	$today = strtotime('today', $now);
	$days = 31;
	$from = strtotime('-' . ($days - 1) . ' days', $today);

	$empty_series = array();
	for ($t = $from; $t <= $today; $t = strtotime('+1 day', $t))
		$empty_series[date('Y-m-d', $t)] = 0;

	$result = array(
		'boards' => array(),
		'days' => array_keys($empty_series),
		'total' => array('day_posts' => 0, 'day_posters' => 0, 'month_posters' => 0, 'daily' => $empty_series),
	);

	$ip_union_day = array();
	$ip_union_month = array();
	foreach (nichan_stats_boards() as $uri => $title) {
		$query = prepare(sprintf("SELECT COUNT(*) AS `posts`, COUNT(DISTINCT `ip`) AS `posters` FROM ``posts_%s`` WHERE `time` > :t", $uri));
		$query->bindValue(':t', $day_ago, PDO::PARAM_INT);
		$query->execute() or error(db_error($query));
		$day = $query->fetch(PDO::FETCH_ASSOC);

		$query = prepare(sprintf("SELECT COUNT(DISTINCT `ip`) FROM ``posts_%s`` WHERE `time` >= :t", $uri));
		$query->bindValue(':t', $from, PDO::PARAM_INT);
		$query->execute() or error(db_error($query));
		$month_posters = (int)$query->fetchColumn();

		// Bucketing in PHP keeps day boundaries right across DST changes.
		$daily = $empty_series;
		$query = prepare(sprintf("SELECT `time` FROM ``posts_%s`` WHERE `time` >= :t", $uri));
		$query->bindValue(':t', $from, PDO::PARAM_INT);
		$query->execute() or error(db_error($query));
		while (($time = $query->fetchColumn()) !== false) {
			$d = date('Y-m-d', $time);
			if (isset($daily[$d])) {
				$daily[$d]++;
				$result['total']['daily'][$d]++;
			}
		}

		$result['boards'][$uri] = array(
			'title' => $title,
			'day_posts' => (int)$day['posts'],
			'day_posters' => (int)$day['posters'],
			'per_hour' => round($day['posts'] / 24, 2),
			'month_posters' => $month_posters,
			'daily' => $daily,
		);
		$result['total']['day_posts'] += (int)$day['posts'];
		$ip_union_day[] = sprintf("SELECT `ip` FROM ``posts_%s`` WHERE `time` > %d", $uri, $day_ago);
		$ip_union_month[] = sprintf("SELECT `ip` FROM ``posts_%s`` WHERE `time` >= %d", $uri, $from);
	}

	if ($ip_union_day) {
		$query = query('SELECT COUNT(DISTINCT `ip`) FROM (' . implode(' UNION ALL ', $ip_union_day) . ') AS `u`') or error(db_error());
		$result['total']['day_posters'] = (int)$query->fetchColumn();
		$query = query('SELECT COUNT(DISTINCT `ip`) FROM (' . implode(' UNION ALL ', $ip_union_month) . ') AS `u`') or error(db_error());
		$result['total']['month_posters'] = (int)$query->fetchColumn();
	}
	$result['total']['per_hour'] = round($result['total']['day_posts'] / 24, 2);

	return $result;
}

/*
 * Slow numbers for the front page (refreshed hourly): total posts ever, unique posters among
 * existing posts, size of all stored files. The recent theme used to compute these on every
 * single post and deletion by reading every row of every board.
 */
function nichan_site_totals($exclude, $max_age = 3600) {
	sort($exclude);
	return nichan_stats_cached('totals-' . md5(implode(' ', $exclude)), $max_age, function () use ($exclude) {
		$max_ids = array();
		$ips = array();
		$sizes = array();
		foreach (listBoards() as $b) {
			if (in_array($b['uri'], $exclude))
				continue;
			$max_ids[] = sprintf("SELECT MAX(`id`) AS `top` FROM ``posts_%s``", $b['uri']);
			$ips[] = sprintf("SELECT `ip` FROM ``posts_%s``", $b['uri']);
			$sizes[] = sprintf("SELECT `files` FROM ``posts_%s`` WHERE `num_files` > 0", $b['uri']);
		}
		$totals = array('total_posts' => 0, 'unique_posters' => 0, 'active_content' => 0);
		if (!$max_ids)
			return $totals;

		$query = query('SELECT SUM(`top`) FROM (' . implode(' UNION ALL ', $max_ids) . ') AS `u`') or error(db_error());
		$totals['total_posts'] = (int)$query->fetchColumn();

		$query = query('SELECT COUNT(DISTINCT `ip`) FROM (' . implode(' UNION ALL ', $ips) . ') AS `u`') or error(db_error());
		$totals['unique_posters'] = (int)$query->fetchColumn();

		foreach ($sizes as $sql) {
			$query = query($sql) or error(db_error());
			while (($files = $query->fetchColumn()) !== false) {
				if (preg_match_all('/"size":([0-9]+)/', $files, $m))
					$totals['active_content'] += array_sum($m[1]);
			}
		}
		return $totals;
	});
}

// Rendering a static board page may use an older snapshot. The browser refreshes it from statistics.php.
function nichan_cached_board_stats($uri) {
	$stats = nichan_stats_cached('speed', NICHAN_STATS_MAX_AGE, null, true);
	return $stats['boards'][$uri] ?? null;
}

// Older, hosting-local board configs still append this return value to the footer.
function nichan_board_speed_footer($uri, $lang = 'uk') {
	return '';
}

// Board speed table used on the front page (and served by /counter.php for live refreshes).
function nichan_stats_speed_table($stats = null) {
	if ($stats === null)
		$stats = nichan_stats();

	$rows = $stats['boards'];
	uasort($rows, function ($a, $b) { return $b['day_posts'] - $a['day_posts']; });

	$html = '<table class="nichan-speed"><thead><tr><th>Дошка</th><th>Дописів за 24 год</th><th>Дописів/год</th><th>Дописувачів за 24 год</th></tr></thead><tbody>';
	foreach ($rows as $uri => $b) {
		$html .= sprintf('<tr><td><a href="/%1$s/"><b>/%1$s/ – %2$s</b></a></td><td>%3$d</td><td>%4$s</td><td>%5$d</td></tr>',
			htmlspecialchars($uri), htmlspecialchars($b['title']), $b['day_posts'], str_replace('.', ',', $b['per_hour']), $b['day_posters']);
	}
	// all boards together; the posters are counted once even when they wrote on several boards
	$html .= sprintf('</tbody><tfoot><tr class="nichan-speed-total"><td><b>Усього</b></td><td>%d</td><td>%s</td><td>%d</td></tr></tfoot></table>',
		$stats['total']['day_posts'], str_replace('.', ',', $stats['total']['per_hour']), $stats['total']['day_posters']);
	$html .= sprintf('<p>Усього дописів за добу: %d (%s на годину).<br/>Унікальних дописувачів за добу: %d, за місяць: %d.</p>',
		$stats['total']['day_posts'], str_replace('.', ',', $stats['total']['per_hour']), $stats['total']['day_posters'], $stats['total']['month_posters']);
	return $html;
}

/*
 * "Популярні нитки": threads with at least N replies during the last H hours, most replies first
 * (mod.php?/nichan-front: N, H, how many, and which threads are left out).
 * (Former popular.php, which broke on MariaDB 11: "GROUP BY … ASC" is no longer valid syntax.)
 */
function nichan_popular_board($b, $s) {
	global $config, $board;
	$window = $s['popular_hours'] * 3600;
	$since = time() - $window;
	$query = prepare(sprintf('SELECT op.id, op.slug, op.subject, op.body_nomarkup, op.sticky, op.locked, activity.recent, activity.last_reply, activity.first_reply,
		(SELECT COUNT(*) FROM ``posts_%1$s`` r WHERE r.thread = op.id) AS replies
		FROM (SELECT thread, COUNT(*) AS recent, MAX(time) AS last_reply, MIN(time) AS first_reply FROM ``posts_%1$s``
			WHERE time > :since AND thread IS NOT NULL GROUP BY thread HAVING COUNT(*) >= :min) activity
		JOIN ``posts_%1$s`` op ON op.id = activity.thread AND op.thread IS NULL
		ORDER BY activity.recent DESC, activity.last_reply DESC, op.id DESC', $b['uri']));
	$query->bindValue(':since', $since, PDO::PARAM_INT);
	$query->bindValue(':min', $s['popular_min'], PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$post = $query->fetch(PDO::FETCH_ASSOC);
	if (!$post || !openBoard($b['uri']))
		return array('threads' => array());
	$prefix = $config['root'] . $board['dir'] . $config['dir']['res'];
	$threads = array();
	$expires = PHP_INT_MAX;
	do {
		$bumplimit = $config['reply_limit'] > 0 && $post['replies'] >= $config['reply_limit'];
		if (($s['popular_skip_bumplimit'] && $bumplimit) || ($s['popular_skip_locked'] && $post['locked']) || ($s['popular_skip_sticky'] && $post['sticky'])
				|| nichan_front_blocked($post['subject'] . "\n" . $post['body_nomarkup']))
			continue;
		$expires = min($expires, (int)$post['first_reply'] + $window);
		$threads[] = array(
			'board' => $b['uri'], 'board_title' => $b['title'], 'id' => (int)$post['id'],
			'link' => $prefix . link_for($post), 'subject' => (string)$post['subject'],
			'snippet' => nichan_plain_text(nichan_notify_preview($b['uri'], '', $post['body_nomarkup']) ?? '', 120),
			'recent' => (int)$post['recent'], 'last_reply' => (int)$post['last_reply'], 'bumplimit' => $bumplimit,
		);
	} while (count($threads) < $s['popular_count'] && ($post = $query->fetch(PDO::FETCH_ASSOC)));
	return array('threads' => $threads, 'expires' => $expires);
}

function nichan_popular_threads($max_age = 86400) {
	global $board, $build_pages;
	$s = nichan_front_settings();
	if (!$s['popular_count'])
		return array('threads' => array());
	$exclude = nichan_front_excluded();
	$settings_key = md5(json_encode(array($s['popular_count'], $s['popular_hours'], $s['popular_min'],
		$s['popular_skip_bumplimit'], $s['popular_skip_locked'], $s['popular_skip_sticky'], $s['words'])));
	$original_board = $board;
	$original_pages = $build_pages;
	$threads = array();
	$expires = PHP_INT_MAX;
	try {
		foreach (listBoards() as $b) {
			if (in_array($b['uri'], $exclude, true))
				continue;
			$revision = $settings_key . ':' . nichan_board_activity_revision($b['uri']);
			$ranked = nichan_stats_cached('popular-board-' . $b['uri'], $max_age, function () use ($b, $s) {
				return nichan_popular_board($b, $s);
			}, false, $revision);
			if ($ranked['threads'])
				$expires = min($expires, $ranked['expires']);
			foreach ($ranked['threads'] as $candidate)
				$threads[] = $candidate;
		}
	} finally {
		if (($board['uri'] ?? null) !== ($original_board['uri'] ?? null)) {
			if (isset($original_board['uri'])) {
				openBoard($original_board['uri']);
			} else {
				$board = $original_board;
				loadConfig();
				nichan_apply_site_settings();
			}
		}
		$build_pages = $original_pages;
	}
	usort($threads, function ($a, $b) {
		return $b['recent'] <=> $a['recent'] ?: $b['last_reply'] <=> $a['last_reply']
			?: strcmp($a['board'], $b['board']) ?: $b['id'] <=> $a['id'];
	});
	return array('threads' => array_slice($threads, 0, $s['popular_count']), 'expires' => $threads ? $expires : 0);
}

function nichan_popular_threads_html($data = null) {
	if ($data === null)
		$data = nichan_popular_threads();
	if (empty($data['threads']))
		return '';
	$html = '<div class="box left popular"><h2>Популярні нитки</h2><ul>';
	foreach ($data['threads'] as $t) {
		$title = $t['subject'] !== '' ? $t['subject'] : $t['snippet'];
		$link = sprintf('<a href="/%s/res/%d.html" title="%s">%s</a>', htmlspecialchars($t['board']), $t['id'],
			htmlspecialchars($t['snippet']), htmlspecialchars($title !== '' ? $title : '#' . $t['id']));
		$html .= '<li><strong>' . htmlspecialchars($t['board_title']) . '</strong>: '
			. ($t['bumplimit'] ? '<s>' . $link . '</s> (бампліміт)' : $link)
			. ' <span class="unimportant">+' . $t['recent'] . '</span></li>';
	}
	return $html . '</ul></div>';
}
