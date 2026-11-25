<?php

defined('TINYBOARD') or exit;

// Board URIs come from the caller's visibility-filtered list. A timestamp alone
// retains the old exclusive cutoff. New cursors also locate a post within that second.
function nichan_search_page($boards, $words, $before) {
	$cursor = explode(':', is_string($before) ? $before : '');
	$time = max(0, (int)$cursor[0]);
	$tied = count($cursor) === 3 && $cursor[1] !== '' && ctype_digit($cursor[2]);
	$has_cutoff = $time > 0 || $tied;
	$results = array();
	foreach ($boards as $uri) {
		$where = array();
		foreach ($words as $i => $word)
			$where[] = "(`body_nomarkup` LIKE :w$i OR `subject` LIKE :w$i)";
		// Sort by time DESC, board ASC, id DESC, both here and in the merged page.
		$order = $tied ? strcmp((string)$uri, $cursor[1]) : -1;
		if ($has_cutoff) {
			if ($order === 0)
				$where[] = '(`time` < :before OR (`time` = :same_time AND id < :id))';
			else
				$where[] = $order > 0 ? '`time` <= :before' : '`time` < :before';
		}
		$query = prepare(sprintf('SELECT id, thread, time, subject, body_nomarkup, files FROM ``posts_%s``
			WHERE %s ORDER BY time DESC, id DESC LIMIT 51', $uri, implode(' AND ', $where)));
		foreach ($words as $i => $word)
			$query->bindValue(":w$i", '%' . addcslashes($word, '%_\\') . '%');
		if ($has_cutoff) {
			$query->bindValue(':before', $time, PDO::PARAM_INT);
			if ($order === 0) {
				$query->bindValue(':same_time', $time, PDO::PARAM_INT);
				$query->bindValue(':id', (int)$cursor[2], PDO::PARAM_INT);
			}
		}
		$query->execute() or error(db_error($query));
		while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
			$row['board'] = (string)$uri;
			$results[] = $row;
		}
	}
	usort($results, function ($a, $b) {
		return $b['time'] <=> $a['time'] ?: strcmp($a['board'], $b['board']) ?: $b['id'] <=> $a['id'];
	});
	$next = null;
	if (count($results) > 50) {
		$results = array_slice($results, 0, 50);
		$last = end($results);
		$next = $last['time'] . ':' . $last['board'] . ':' . $last['id'];
	}
	return array($results, $next);
}
