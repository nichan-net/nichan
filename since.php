<?php
/*
 * since.php?t=<unix time>: what was posted on the public boards after that moment, for "Поки вас не було"
 * on the front page and the count next to the home icon (js/nichan.js remembers the visitor's last visit).
 * Each public board has its own snapshot. Posts and deletions advance its revision.
 */
require_once 'inc/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

define('NICHAN_SINCE_DAYS', 14);

$now = time();
$since = max(isset($_GET['t']) ? (int)$_GET['t'] : 0, $now - NICHAN_SINCE_DAYS * 86400);

function nichan_since_board_snapshot($uri) {
	$from = time() - NICHAN_SINCE_DAYS * 86400;
	$posts = array();
	$titles = array();
	$reply_counts = array();
	// [time, thread (the post itself for a new thread), new thread?]
	$rows = query(sprintf('SELECT p.id, p.thread, p.time,
		CASE WHEN p.thread IS NULL THEN (SELECT COUNT(*) FROM ``posts_%s`` r WHERE r.thread = p.id) END AS replies
		FROM ``posts_%s`` p WHERE p.time > %d', $uri, $uri, $from));
	$threads = array();
	while ($r = $rows->fetch(PDO::FETCH_ASSOC)) {
		$thread = $r['thread'] ? (int)$r['thread'] : (int)$r['id'];
		$posts[] = array((int)$r['time'], $thread, $r['thread'] ? 0 : 1);
		$threads[$thread] = true;
		if ($r['thread'] === null)
			$reply_counts[$thread] = (int)$r['replies'];
	}
	if (!$threads)
		return array('posts' => array(), 'titles' => array(), 'replies' => array());
	// what the threads are called: the subject, else the start of the first post
	$q = query(sprintf('SELECT `id`, `subject`, `body_nomarkup` FROM ``posts_%s`` WHERE `thread` IS NULL AND `id` IN (%s)',
		$uri, implode(',', array_map('intval', array_keys($threads)))));
	while ($t = $q->fetch(PDO::FETCH_ASSOC)) {
		$name = trim((string)$t['subject']);
		// else the start of the text without the quoted lines, the markup and what the site adds to a post
		// (<tinyboard …>: the verdict of fate, the flag)
		if ($name === '')
			$name = nichan_plain_text(preg_replace('/^>.*$/mu', '', (string)$t['body_nomarkup']), 70);
		$titles[$t['id']] = mb_strlen($name) > 70 ? mb_substr($name, 0, 69) . '…' : $name;
	}
	return array('posts' => $posts, 'titles' => $titles, 'replies' => $reply_counts);
}

$boards = array();
$active = array();
$summary_only = ($_GET['summary'] ?? '') === '1';
foreach (nichan_stats_boards() as $uri => $title) {
	$uri = (string)$uri;
	$recent = nichan_stats_cached('since-' . $uri, 86400, function () use ($uri) {
		return nichan_since_board_snapshot($uri);
	}, false, nichan_board_activity_revision($uri));
	foreach ($recent['posts'] as $p) {
		if ($p[0] <= $since)
			continue;
		if (!isset($boards[$uri]))
			$boards[$uri] = array('posts' => 0, 'threads' => 0);
		$boards[$uri]['posts']++;
		$boards[$uri]['threads'] += $p[2];
		if ($summary_only)
			continue;
		$key = $uri . ':' . $p[1];
		if (!isset($recent['titles'][$p[1]]))
			continue; // (the thread is gone)
		if (!isset($active[$key]))
			$active[$key] = array('board' => $uri, 'id' => $p[1], 'title' => $recent['titles'][$p[1]], 'posts' => 0, 'new' => false, 'last' => 0);
		$active[$key]['posts']++;
		if ($p[2]) {
			$active[$key]['new'] = true;
			$active[$key]['replies'] = $recent['replies'][$p[1]];
		}
		$active[$key]['last'] = max($active[$key]['last'], $p[0]);
	}
}
$response = array('since' => $since, 'boards' => (object)$boards);
if (!$summary_only) {
	// Keep quiet new threads too, even when many older threads received more replies.
	usort($active, function ($a, $b) { return $b['posts'] - $a['posts'] ?: $b['last'] - $a['last']; });
	foreach ($active as &$t) {
		$t['url'] = $config['root'] . $t['board'] . '/' . $config['dir']['res'] . sprintf($config['file_page'], $t['id']);
		unset($t['last']);
	}
	unset($t);
	$response['threads'] = $active;
}
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
