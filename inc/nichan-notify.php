<?php

/*
 * The app's batched notification check (POST /api/v1/notifications/check, API.md): for the boards and threads the app
 * follows, what is new since its cursors — new threads, new replies, replies quoting the posts the app thinks are its
 * own — counted from the posts' numbers. A post gets its number when it is published (a pre-moderated one when it is
 * approved), and posts are written one at a time, so "a number above the cursor" never loses one. Nothing is stored:
 * the cursors live in the app, the list of its own posts is only used for counting.
 */

defined('TINYBOARD') or exit;

// limits of one request
const NICHAN_NOTIFY_BOARDS = 32;
const NICHAN_NOTIFY_THREADS = 50;
const NICHAN_NOTIFY_OWN = 1024;
const NICHAN_NOTIFY_BODY = 131072;
// requests per minute from one address (the app checks every 5 seconds while open: a few phones behind one address)
const NICHAN_NOTIFY_PER_MINUTE = 120;
// at most this many new replies of one thread are read for the quotes (the rest are only counted)
const NICHAN_NOTIFY_READ = 500;

/*
 * A cursor: "1.<number>.<signature>", the number of the last post checked, signed for its board (and thread), so a
 * cursor of another board or thread, or a made-up one, is refused (cursor_invalid) instead of being taken silently.
 * It never expires and the same position always gives the same cursor.
 */
function nichan_notify_cursor($uri, $thread, $id) {
	global $config;
	$sig = substr(hash_hmac('sha256', "notify|$uri|" . (int)$thread . '|' . (int)$id, $config['secure_trip_salt'] . '|' . $config['cookies']['salt']), 0, 12);
	return '1.' . (int)$id . '.' . $sig;
}

// the number in a cursor, or false when it isn't one of this board's (thread's)
function nichan_notify_cursor_id($uri, $thread, $cursor) {
	if (!is_string($cursor) || !preg_match('/^1\.(\d{1,10})\.[0-9a-f]{12}$/', $cursor, $m))
		return false;
	return hash_equals(nichan_notify_cursor($uri, $thread, (int)$m[1]), $cursor) ? (int)$m[1] : false;
}

function nichan_notify_rate($who) {
	return nichan_rate_per_minute('notify', $who, NICHAN_NOTIFY_PER_MINUTE);
}

/*
 * Requests of one kind per minute from one address, for the API's frequent requests: one row per address and minute
 * (not a row per request, as nichan_rate keeps). Returns the seconds to wait when over $limit, else 0.
 */
function nichan_rate_per_minute($kind, $who, $limit) {
	$minute = (int)floor(time() / 60);
	$query = prepare('INSERT INTO ``nichan_rate_counter`` (`kind`, `who`, `minute`, `n`) VALUES (:k, :who, :m, 1)
		ON DUPLICATE KEY UPDATE `n` = `n` + 1');
	$query->bindValue(':k', $kind);
	$query->bindValue(':who', mb_substr($who, 0, 64));
	$query->bindValue(':m', $minute, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$query = prepare('SELECT `n` FROM ``nichan_rate_counter`` WHERE `kind` = :k AND `who` = :who AND `minute` = :m');
	$query->bindValue(':k', $kind);
	$query->bindValue(':who', mb_substr($who, 0, 64));
	$query->bindValue(':m', $minute, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	if (mt_rand(1, 200) == 1)
		query('DELETE FROM ``nichan_rate_counter`` WHERE `minute` < ' . ($minute - 60));
	return (int)$query->fetchColumn() > $limit ? 60 - time() % 60 : 0;
}

/* ---------------------------------------------------------------- the archive (GET /api/v1/archive) */

const NICHAN_ARCHIVE_PER_MINUTE = 60;

// "a1.<archived at>.<thread>.<signature>": the last thread of a page, where the next one starts; signed for its board
function nichan_archive_cursor($uri, $time, $thread) {
	global $config;
	return 'a1.' . (int)$time . '.' . (int)$thread . '.' . substr(hash_hmac('sha256', "archive|$uri|" . (int)$time . '|' . (int)$thread,
		$config['secure_trip_salt'] . '|' . $config['cookies']['salt']), 0, 12);
}

// array(archived at, thread) of a cursor of this board, or false
function nichan_archive_cursor_key($uri, $cursor) {
	if (!is_string($cursor) || !preg_match('/^a1\.(\d{1,10})\.(\d{1,10})\.[0-9a-f]{12}$/', $cursor, $m))
		return false;
	return hash_equals(nichan_archive_cursor($uri, (int)$m[1], (int)$m[2]), $cursor) ? array((int)$m[1], (int)$m[2]) : false;
}

/*
 * One page of a board's archive, newest archived first (by the time of archiving, then the thread's number, both
 * descending), each thread's first post as in catalog.json plus archived, archivedAt and lastReplyAt.
 * Two queries whatever the page's size. The cursor is a key, not an offset: threads archived meanwhile don't shift it.
 */
function nichan_archive_page($uri, $limit, $after) {
	global $config;
	$where = $after ? ' AND (`a`.`time` < :t OR (`a`.`time` = :t2 AND `a`.`thread` < :id))' : '';
	$query = prepare(sprintf('SELECT `p`.*, `a`.`time` AS `nichan_archived_at` FROM ``nichan_archive`` AS `a`
		JOIN ``posts_%s`` AS `p` ON `p`.`id` = `a`.`thread` AND `p`.`thread` IS NULL
		WHERE `a`.`board` = :b' . $where . ' ORDER BY `a`.`time` DESC, `a`.`thread` DESC LIMIT ' . ((int)$limit + 1), $uri));
	$query->bindValue(':b', $uri);
	if ($after) {
		$query->bindValue(':t', $after[0], PDO::PARAM_INT);
		$query->bindValue(':t2', $after[0], PDO::PARAM_INT);
		$query->bindValue(':id', $after[1], PDO::PARAM_INT);
	}
	$query->execute() or error(db_error($query));
	$rows = $query->fetchAll(PDO::FETCH_ASSOC);
	$more = count($rows) > $limit;
	$rows = array_slice($rows, 0, $limit);
	// the replies of all the page's threads at once: how many, their files (as catalog.json counts them), the last one's time
	$counts = array();
	if ($rows) {
		$ids = implode(',', array_map(function ($r) { return (int)$r['id']; }, $rows));
		$query = query(sprintf('SELECT `thread`, COUNT(*) AS `replies`, COALESCE(SUM(`num_files`), 0) AS `images`, MAX(`time`) AS `last`
			FROM ``posts_%s`` WHERE `thread` IN (%s) GROUP BY `thread`', $uri, $ids)) or error(db_error());
		foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $c)
			$counts[(int)$c['thread']] = $c;
	}
	$api = new Api();
	$threads = array();
	foreach ($rows as $row) {
		$archived_at = (int)$row['nichan_archived_at'];
		unset($row['nichan_archived_at']);
		$c = isset($counts[(int)$row['id']]) ? $counts[(int)$row['id']] : null;
		$thread = new Thread($row, $config['root'], false, false);
		$thread->replies = $c ? (int)$c['replies'] : 0;
		$thread->images = $c ? (int)$c['images'] : 0;
		$thread->omitted = 0;
		$thread->omitted_images = 0;
		$item = $api->translatePost($thread);
		$item['resto'] = 0;
		$item['archived'] = true;
		$item['archivedAt'] = $archived_at;
		$item['lastReplyAt'] = $c ? (int)$c['last'] : null;
		$threads[] = $item;
	}
	$last = end($rows);
	return array('threads' => $threads, 'next' => $more && $last ? nichan_archive_cursor($uri, (int)$last['nichan_archived_at'], (int)$last['id']) : null);
}

// A notification's text: the subject or the text, plain, up to 200 characters, the spoilers hidden.
function nichan_notify_preview($uri, $subject, $body_nomarkup) {
	$hidden = nichan_board_is_english($uri) ? '[spoiler]' : '[спойлер]';
	$body = preg_replace(array('/%%.+?%%/s', '~\[spoiler\].+?\[/spoiler\]~is'), $hidden, (string)$body_nomarkup);
	$text = nichan_plain_text($body, 200);
	$subject = nichan_plain_text((string)$subject, 200);
	if ($subject !== '' && $text !== '')
		$text = nichan_plain_text($subject . ': ' . $text, 200);
	elseif ($subject !== '')
		$text = $subject;
	return $text !== '' ? $text : null;
}

// The posts a text quotes, as "board:number", by the site's own rules (inc/functions.php, markup()):
// >>123 on the post's board, >>>/board/123 on another one; the numbers alone are not quotes.
function nichan_notify_quotes($uri, $body_nomarkup) {
	global $config;
	$out = array();
	$body = remove_modifiers((string)$body_nomarkup);
	if (preg_match_all('/(^|\s)>>(\d+?)([\s,.)?]|$)/m', $body, $m))
		foreach ($m[2] as $id)
			$out["$uri:" . (int)$id] = true;
	if (preg_match_all('/(^|\s)>>>\/(' . $config['board_regex'] . ')\/(\d+)([\s,.)?]|$)/um', $body, $m, PREG_SET_ORDER))
		foreach ($m as $c)
			$out[$c[2] . ':' . (int)$c[3]] = true;
	return $out;
}

function nichan_notify_fail_item(array $item, $code, $message) {
	return $item + array('error' => $code, 'message' => $message);
}

// the highest post number of the board now: everything up to it is written, so it is what this check sees
function nichan_notify_snapshot($uri) {
	$query = query(sprintf('SELECT MAX(`id`) FROM ``posts_%s``', $uri)) or error(db_error());
	return (int)$query->fetchColumn();
}

function nichan_notify_board($uri, $cursor, $en) {
	$item = array('board' => $uri);
	if (!nichan_notify_board_ok($uri))
		return nichan_notify_fail_item($item, 'board_not_found', $en ? 'No such board.' : 'Такої дошки немає.');
	$top = nichan_notify_snapshot($uri);
	$empty = array('newThreads' => 0, 'firstNewThread' => null, 'lastNewThread' => null, 'preview' => null);
	if ($cursor === null)
		return $item + array('cursor' => nichan_notify_cursor($uri, 0, $top)) + $empty;
	if (($after = nichan_notify_cursor_id($uri, 0, $cursor)) === false)
		return nichan_notify_fail_item($item, 'cursor_invalid', $en ? 'The cursor is not valid. Start again with "cursor": null.' : 'Недійсний курсор. Почніть заново з "cursor": null.');
	$query = prepare(sprintf('SELECT COUNT(*) AS `n`, MIN(`id`) AS `first`, MAX(`id`) AS `last` FROM ``posts_%s``
		WHERE `thread` IS NULL AND `id` > :after AND `id` <= :top', $uri));
	$query->bindValue(':after', $after, PDO::PARAM_INT);
	$query->bindValue(':top', $top, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$row = $query->fetch(PDO::FETCH_ASSOC);
	// (a cursor above the highest number: the newest posts were deleted since; it stays where it was)
	$item['cursor'] = nichan_notify_cursor($uri, 0, max($after, $top));
	if (!(int)$row['n'])
		return $item + $empty;
	$query = prepare(sprintf('SELECT `subject`, `body_nomarkup` FROM ``posts_%s`` WHERE `id` = :id', $uri));
	$query->bindValue(':id', (int)$row['first'], PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$first = $query->fetch(PDO::FETCH_ASSOC);
	return $item + array('newThreads' => (int)$row['n'], 'firstNewThread' => (int)$row['first'], 'lastNewThread' => (int)$row['last'],
		'preview' => $first ? nichan_notify_preview($uri, $first['subject'], $first['body_nomarkup']) : null);
}

function nichan_notify_thread($uri, $thread, $cursor, array $own, $en) {
	$item = array('board' => $uri, 'thread' => $thread);
	if (!nichan_notify_board_ok($uri))
		return nichan_notify_fail_item($item, 'board_not_found', $en ? 'No such board.' : 'Такої дошки немає.');
	$empty = array('newReplies' => 0, 'firstNewPost' => null, 'lastNewPost' => null, 'repliesToMe' => 0, 'firstReplyToMe' => null,
		'preview' => null, 'replyToMePreview' => null);
	$after = null;
	if ($cursor !== null && ($after = nichan_notify_cursor_id($uri, $thread, $cursor)) === false)
		return nichan_notify_fail_item($item, 'cursor_invalid', $en ? 'The cursor is not valid. Start again with "cursor": null.' : 'Недійсний курсор. Почніть заново з "cursor": null.');
	$top = nichan_notify_snapshot($uri);
	$query = prepare(sprintf('SELECT `id`, `locked` FROM ``posts_%s`` WHERE `id` = :id AND `thread` IS NULL', $uri));
	$query->bindValue(':id', $thread, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	if (!($op = $query->fetch(PDO::FETCH_ASSOC)))
		// deleted, or never was: the site doesn't tell these apart (the cursor stays as it was)
		return $item + array('cursor' => $cursor, 'state' => 'unavailable', 'locked' => null) + $empty;
	$item += array('cursor' => null, 'state' => nichan_archived($uri, $thread) ? 'archived' : 'active', 'locked' => (bool)$op['locked']);

	if ($after === null) {
		// the starting point: the thread's last post now
		$query = prepare(sprintf('SELECT MAX(`id`) FROM ``posts_%s`` WHERE `thread` = :t AND `id` <= :top', $uri));
		$query->bindValue(':t', $thread, PDO::PARAM_INT);
		$query->bindValue(':top', $top, PDO::PARAM_INT);
		$query->execute() or error(db_error($query));
		$item['cursor'] = nichan_notify_cursor($uri, $thread, max($thread, (int)$query->fetchColumn()));
		return $item + $empty;
	}

	$query = prepare(sprintf('SELECT COUNT(*) AS `n`, MAX(`id`) AS `last` FROM ``posts_%s`` WHERE `thread` = :t AND `id` > :after AND `id` <= :top', $uri));
	$query->bindValue(':t', $thread, PDO::PARAM_INT);
	$query->bindValue(':after', $after, PDO::PARAM_INT);
	$query->bindValue(':top', $top, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$all = $query->fetch(PDO::FETCH_ASSOC);
	// the cursor moves to the thread's last post seen, so an unchanged thread keeps its cursor
	$item['cursor'] = nichan_notify_cursor($uri, $thread, max($after, (int)$all['last']));
	if (!(int)$all['n'])
		return $item + $empty;

	// only the new replies are read (and of them at most NICHAN_NOTIFY_READ, oldest first; more are counted)
	$query = prepare(sprintf('SELECT `id`, `subject`, `body_nomarkup` FROM ``posts_%s`` WHERE `thread` = :t AND `id` > :after AND `id` <= :top
		ORDER BY `id` LIMIT ' . NICHAN_NOTIFY_READ, $uri));
	$query->bindValue(':t', $thread, PDO::PARAM_INT);
	$query->bindValue(':after', $after, PDO::PARAM_INT);
	$query->bindValue(':top', $top, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$rows = $query->fetchAll(PDO::FETCH_ASSOC);
	$new = (int)$all['n'];
	$first = $last = $first_me = null;
	$to_me = 0;
	foreach ($rows as $row) {
		if (isset($own["$uri:" . (int)$row['id']])) {
			$new--; // the app's own posts are not news to it
			continue;
		}
		if ($first === null)
			$first = $row;
		$last = $row;
		if (array_intersect_key(nichan_notify_quotes($uri, $row['body_nomarkup']), $own)) {
			$to_me++;
			if ($first_me === null)
				$first_me = $row;
		}
	}
	if (count($rows) < (int)$all['n'])
		$last = array('id' => (int)$all['last']); // (past the replies read: the newest one is still the last)
	if ($new <= 0 || $first === null)
		return $item + $empty;
	return $item + array('newReplies' => $new, 'firstNewPost' => (int)$first['id'], 'lastNewPost' => (int)$last['id'],
		'repliesToMe' => $to_me, 'firstReplyToMe' => $first_me ? (int)$first_me['id'] : null,
		'preview' => nichan_notify_preview($uri, $first['subject'], $first['body_nomarkup']),
		'replyToMePreview' => $first_me ? nichan_notify_preview($uri, $first_me['subject'], $first_me['body_nomarkup']) : null);
}

// a board this visitor may read (hidden boards are not found, as everywhere in the API)
function nichan_notify_board_ok($uri) {
	global $config;
	static $ok = array();
	if (!isset($ok[$uri]))
		$ok[$uri] = preg_match('/^' . $config['board_regex'] . '$/u', $uri) && getBoardInfo($uri) && nichan_can_see_board($uri, false);
	return $ok[$uri];
}
