<?php
/*
 * online.php: "зараз на сайті / на дошці / у цій нитці" and "відвідали за останні 24 години". Every open
 * (visible) page of the site sends v=<random id of the browser>&b=<board>&t=<thread> once a minute
 * (js/nichan.js) and gets the numbers back: browsers seen in the last 3 minutes and in the last 24 hours.
 * No IP addresses are kept. The numbers are recounted at most every 20 seconds (the day's: every 5 minutes),
 * so a request is one small write and a file read.
 *   all=1   also "boards": the people on each public board now (the front page's list of boards)
 *   here=1  also "here": the threads of the board with people in them now, and those on the board's own pages
 *           («Хто тут?» on a board page); "mine" marks where this browser is
 */
require_once 'inc/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$visitor = isset($_POST['v']) && is_string($_POST['v']) ? $_POST['v'] : '';
if (!preg_match('/^[0-9a-f]{16}$/', $visitor)) {
	http_response_code(400);
	exit('{}');
}
$requestedBoard = isset($_POST['b']) && is_string($_POST['b']) ? $_POST['b'] : '';
$boardInfo = preg_match('/^' . $config['board_regex'] . '$/u', $requestedBoard) ? getBoardInfo($requestedBoard) : false;
$boardUri = $boardInfo && nichan_visibility_allows((int)$boardInfo['visibility']) ? $boardInfo['uri'] : '';
$thread = $boardUri !== '' && isset($_POST['t']) ? max(0, (int)$_POST['t']) : 0;

$query = prepare('REPLACE INTO ``nichan_online`` (`visitor`, `board`, `thread`, `seen`) VALUES (:v, :b, :t, :s)');
$query->bindValue(':v', $visitor);
$query->bindValue(':b', $boardUri);
$query->bindValue(':t', $thread, PDO::PARAM_INT);
$query->bindValue(':s', time(), PDO::PARAM_INT);
$query->execute() or error(db_error($query));

// Browsers seen after $since: on the site, per board, per thread.
$count = function ($since) {
	$since = (int)$since;
	$site = (int)query("SELECT COUNT(DISTINCT `visitor`) FROM ``nichan_online`` WHERE `seen` > $since")->fetchColumn();
	$boards = array();
	foreach (query("SELECT `board`, COUNT(DISTINCT `visitor`) AS `n` FROM ``nichan_online`` WHERE `seen` > $since AND `board` <> '' GROUP BY `board`")->fetchAll(PDO::FETCH_ASSOC) as $r)
		$boards[$r['board']] = (int)$r['n'];
	$threads = array();
	foreach (query("SELECT `board`, `thread`, COUNT(*) AS `n` FROM ``nichan_online`` WHERE `seen` > $since AND `thread` > 0 GROUP BY `board`, `thread`")->fetchAll(PDO::FETCH_ASSOC) as $r)
		$threads[$r['board'] . ':' . $r['thread']] = (int)$r['n'];
	return array('site' => $site, 'boards' => $boards, 'threads' => $threads);
};
$now = nichan_stats_cached('online', 20, function () use ($count) {
	// (rows are kept for a day, for the day's numbers)
	query('DELETE FROM ``nichan_online`` WHERE `seen` < ' . (time() - 86400));
	return $count(time() - 180);
});
$day = nichan_stats_cached('online-24h', 300, function () use ($count) {
	return $count(time() - 86400);
});

// (the counts may be a few seconds or minutes old: this visitor is always among them)
$pick = function ($counts) use ($boardUri, $thread) {
	return array(
		'site' => max(1, $counts['site']),
		'board' => $boardUri !== '' ? max(1, isset($counts['boards'][$boardUri]) ? $counts['boards'][$boardUri] : 0) : null,
		'thread' => $thread ? max(1, isset($counts['threads'][$boardUri . ':' . $thread]) ? $counts['threads'][$boardUri . ':' . $thread] : 0) : null,
	);
};
$res = $pick($now);
$last24 = $pick($day);
// the people on this board are counted right now («Хто тут?» appears as soon as someone else comes)
if ($boardUri !== '') {
	$q = prepare('SELECT COUNT(DISTINCT `visitor`) FROM ``nichan_online`` WHERE `board` = :b AND `seen` > :s');
	$q->bindValue(':b', $boardUri);
	$q->bindValue(':s', time() - 180, PDO::PARAM_INT);
	$q->execute() or error(db_error($q));
	$res['board'] = max(1, (int)$q->fetchColumn());
}
// the day's number is never below the one of right now (the two are counted at different moments)
$res['site24'] = max($res['site'], $last24['site']);
$res['board24'] = $res['board'] === null ? null : max($res['board'], $last24['board']);
$res['thread24'] = $res['thread'] === null ? null : max($res['thread'], $last24['thread']);

if (!empty($_POST['all'])) {
	$res['boards'] = array();
	foreach (nichan_stats_boards() as $uri => $title)
		$res['boards'][$uri] = isset($now['boards'][$uri]) ? $now['boards'][$uri] : 0;
	$res['boards'] = (object)$res['boards'];
}

if (!empty($_POST['here']) && $boardUri !== '' && (int)$boardInfo['visibility'] === NICHAN_VISIBILITY_PUBLIC) {
	// where this browser is right now (it may have several tabs open)
	$q = prepare('SELECT `thread` FROM ``nichan_online`` WHERE `visitor` = :v AND `board` = :b AND `seen` > :s');
	$q->bindValue(':v', $visitor);
	$q->bindValue(':b', $boardUri);
	$q->bindValue(':s', time() - 180, PDO::PARAM_INT);
	$q->execute() or error(db_error($q));
	$mine = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));

	// counted right now, not from the cache: the list is asked for by hand and the table is small (one day of rows)
	$q = prepare('SELECT `thread`, COUNT(*) AS `n` FROM ``nichan_online`` WHERE `board` = :b AND `seen` > :s GROUP BY `thread`');
	$q->bindValue(':b', $boardUri);
	$q->bindValue(':s', time() - 180, PDO::PARAM_INT);
	$q->execute() or error(db_error($q));
	$counts = array();
	$pages = 0;
	foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
		if ($r['thread'])
			$counts[(int)$r['thread']] = (int)$r['n'];
		else
			$pages = (int)$r['n'];
	}
	$list = array();
	if ($counts) {
		$names = query(sprintf('SELECT `id`, `subject`, `body_nomarkup` FROM ``posts_%s`` WHERE `thread` IS NULL AND `id` IN (%s)',
			$boardUri, implode(',', array_keys($counts))))->fetchAll(PDO::FETCH_ASSOC);
		foreach ($names as $p) {
			$title = trim((string)$p['subject']) !== '' ? trim($p['subject']) : nichan_plain_text($p['body_nomarkup'], 70);
			$list[] = array('id' => (int)$p['id'], 'title' => $title, 'n' => $counts[$p['id']], 'mine' => in_array((int)$p['id'], $mine),
				'url' => $config['root'] . $boardUri . '/' . $config['dir']['res'] . sprintf($config['file_page'], $p['id']));
		}
		usort($list, function ($a, $b) { return $b['n'] - $a['n'] ?: $b['id'] - $a['id']; });
	}
	$res['here'] = array('threads' => $list, 'pages' => $pages, 'pagesMine' => in_array(0, $mine, true));
}

echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
