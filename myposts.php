<?php
/*
 * myposts.php: the visitor's own posts ("Мої дописи" in js/nichan.js). POST q=[["b",123],…] — the ids the
 * browser remembers (show-own-posts.js) — gives back for each: its thread, time, the start of its text and
 * how many posts answer it. Only what is public anyway; posts on hidden boards and deleted ones are skipped.
 */
require_once 'inc/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$wanted = json_decode(isset($_POST['q']) ? (string)$_POST['q'] : '[]', true);
$byBoard = array();
foreach (array_slice(is_array($wanted) ? $wanted : array(), 0, 300) as $p) {
	if (is_array($p) && count($p) == 2 && is_string($p[0]) && preg_match('/^' . $config['board_regex'] . '$/u', $p[0]) && (int)$p[1] > 0)
		$byBoard[$p[0]][] = (int)$p[1];
}
$out = array();
foreach ($byBoard as $uri => $ids) {
	if (!getBoardInfo($uri) || !nichan_can_see_board($uri))
		continue;
	$in = implode(',', array_unique($ids));
	$query = query(sprintf('SELECT `id`, `thread`, `time`, `subject`, `body_nomarkup` FROM ``posts_%s`` WHERE `id` IN (%s)', $uri, $in)) or error(db_error());
	$posts = $query->fetchAll(PDO::FETCH_ASSOC);
	$replies = array();
	$query = prepare(sprintf('SELECT `target`, COUNT(DISTINCT `board`, `post`) AS `n` FROM ``cites`` WHERE `target_board` = :b AND `target` IN (%s) GROUP BY `target`', $in));
	$query->bindValue(':b', $uri);
	$query->execute() or error(db_error($query));
	foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $r)
		$replies[(int)$r['target']] = (int)$r['n'];
	foreach ($posts as $p) {
		$thread = $p['thread'] ? (int)$p['thread'] : (int)$p['id'];
		$out[] = array(
			'board' => $uri,
			'id' => (int)$p['id'],
			'op' => !$p['thread'],
			'url' => $config['root'] . $uri . '/' . $config['dir']['res'] . $thread . '.html' . ($p['thread'] ? '#' . $p['id'] : ''),
			'time' => (int)$p['time'],
			'subject' => (string)$p['subject'],
			'text' => nichan_plain_text($p['body_nomarkup'], 140),
			'replies' => isset($replies[(int)$p['id']]) ? $replies[(int)$p['id']] : 0,
		);
	}
}
usort($out, function ($a, $b) { return $b['time'] - $a['time']; });
// (a text that is not valid UTF-8 would make json_encode() give nothing, and the list would not open at all)
echo json_encode($out, JSON_INVALID_UTF8_SUBSTITUTE);
