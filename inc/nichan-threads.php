<?php

/*
 * Threads:
 *  - OP mark: a thread started with "ОП-мітка" marks its author's replies "## OP" (same password or IP).
 *  - Continuation: a thread at the reply limit offers "create a continuation"; the two link to each other.
 *  - Archive: threads past the board's last page are not deleted but closed and listed in
 *    <board>/archive.html (they keep their address and stay searchable).
 *  - Premoderation: during a raid (or when switched on), new threads wait for a moderator.
 *  - Moderator notes: a text under a post in red italics (e.g. a warning).
 *  - Moderation statistics from the moderation log.
 */

defined('TINYBOARD') or exit;

/* ---------------------------------------------------------------- OP mark */

function nichan_opmark_save($uri, $thread, $ip, $password) {
	$query = prepare('REPLACE INTO ``nichan_opmarks`` (`board`, `thread`, `ip`, `password`) VALUES (:b, :t, :ip, :pw)');
	$query->bindValue(':b', $uri);
	$query->bindValue(':t', (int)$thread, PDO::PARAM_INT);
	$query->bindValue(':ip', $ip);
	$query->bindValue(':pw', nichan_post_password_hash($password));
	$query->execute() or error(db_error($query));
}

// Is the author of a reply the one who started this OP-marked thread?
function nichan_opmark_is_op($uri, $thread, $ip, $password) {
	$query = prepare('SELECT `ip`, `password` FROM ``nichan_opmarks`` WHERE `board` = :b AND `thread` = :t');
	$query->bindValue(':b', $uri);
	$query->bindValue(':t', (int)$thread, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	if (!$row = $query->fetch(PDO::FETCH_ASSOC))
		return false;
	return $row['ip'] === $ip || ($row['password'] !== '' && (string)$password !== '' && hash_equals($row['password'], nichan_post_password_hash($password)));
}

/* ----------------------------------------------------------- continuation */

function nichan_bump_remaining($limit, $replies, $en) {
	if ((int)$limit === 0)
		return $en ? 'No bump limit' : 'Без бампліміту';

	$n = max(0, (int)$limit - (int)$replies);
	if ($en)
		return $n . ($n === 1 ? ' post' : ' posts') . ' until the bump limit';

	$word = 'дописів';
	if ($n % 10 === 1 && $n % 100 !== 11)
		$word = 'допис';
	elseif ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 12 || $n % 100 > 14))
		$word = 'дописи';
	return 'До бампліміту ' . $n . ' ' . $word;
}

// the thread continuing $old, or 0
function nichan_continuation($uri, $old) {
	global $nichan_continuation_cache;
	$cache = &$nichan_continuation_cache;
	$key = $uri . ':' . (int)$old;
	if (!isset($cache[$key])) {
		$query = prepare('SELECT `new` FROM ``nichan_continuations`` WHERE `board` = :b AND `old` = :o');
		$query->bindValue(':b', $uri);
		$query->bindValue(':o', (int)$old, PDO::PARAM_INT);
		$query->execute() or error(db_error($query));
		$cache[$key] = (int)$query->fetchColumn();
	}
	return $cache[$key];
}

// the thread that $new continues, or 0
function nichan_continuation_of($uri, $new) {
	global $nichan_continuation_of_cache;
	$cache = &$nichan_continuation_of_cache;
	$key = $uri . ':' . (int)$new;
	if (!isset($cache[$key])) {
		$query = prepare('SELECT `old` FROM ``nichan_continuations`` WHERE `board` = :b AND `new` = :n');
		$query->bindValue(':b', $uri);
		$query->bindValue(':n', (int)$new, PDO::PARAM_INT);
		$query->execute() or error(db_error($query));
		$cache[$key] = (int)$query->fetchColumn();
	}
	return $cache[$key];
}

// Deleted posts (deletePost): a deleted continuation gives its old thread the button back; a deleted old thread
// takes its link along.
function nichan_continuation_forget($uri, array $ids) {
	if (!$ids)
		return;
	$in = implode(',', array_map('intval', $ids));
	$b = $GLOBALS['pdo']->quote($uri);
	$olds = query("SELECT `old` FROM ``nichan_continuations`` WHERE `board` = $b AND `new` IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
	query("DELETE FROM ``nichan_continuations`` WHERE `board` = $b AND (`new` IN ($in) OR `old` IN ($in))") or error(db_error());
	$GLOBALS['nichan_continuation_cache'] = array();
	$GLOBALS['nichan_continuation_of_cache'] = array();
	foreach ($olds as $old)
		if (!in_array((int)$old, $ids, true))
			buildThread((int)$old);
}

// A new thread continues $old (a thread at the reply limit that has no continuation yet).
function nichan_continuation_save($uri, $old, $new) {
	global $config;
	$old = (int)$old;
	$query = prepare(sprintf('SELECT COUNT(*) FROM ``posts_%s`` WHERE `thread` = :t', $uri));
	$query->bindValue(':t', $old, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$replies = (int)$query->fetchColumn();
	if ($replies < max(1, (int)$config['reply_limit']) || nichan_continuation($uri, $old))
		return false;
	$query = prepare('INSERT IGNORE INTO ``nichan_continuations`` (`board`, `old`, `new`) VALUES (:b, :o, :n)');
	$query->bindValue(':b', $uri);
	$query->bindValue(':o', $old, PDO::PARAM_INT);
	$query->bindValue(':n', (int)$new, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	unset($GLOBALS['nichan_continuation_cache'][$uri . ':' . $old]);
	unset($GLOBALS['nichan_continuation_of_cache'][$uri . ':' . (int)$new]);
	buildThread($old); // the old thread now links to the new one
	buildThread((int)$new); // and the new one says what it continues (it was built before the link was saved)
	return true;
}

/* ---------------------------------------------------------------- archive */

// How many pages a board shows; the threads after them go to the archive. 0: no archive (vichan deletes them).
function nichan_archive_pages() {
	return max(0, (int)nichan_setting('archive_pages', 10));
}

function nichan_archived($uri, $thread) {
	global $nichan_archived_cache;
	$cache = &$nichan_archived_cache;
	if (!isset($cache[$uri])) {
		$query = prepare('SELECT `thread` FROM ``nichan_archive`` WHERE `board` = :b');
		$query->bindValue(':b', $uri);
		$query->execute() or error(db_error($query));
		$cache[$uri] = array_flip($query->fetchAll(PDO::FETCH_COLUMN));
	}
	return isset($cache[$uri][(int)$thread]);
}

/*
 * clean(): the threads past the last page are closed and archived instead of deleted. Threads that came
 * back onto the pages (a moderator bumped them) leave the archive. At most $rebuild thread pages are
 * rebuilt here (to show the "archived" notice); tools/nichan-archive.php does a whole board.
 */
function nichan_archive_run($uri, $rebuild = 5) {
	global $config;
	$offset = nichan_archive_pages() * $config['threads_per_page'];
	$query = prepare(sprintf('SELECT `id` FROM ``posts_%s`` WHERE `thread` IS NULL ORDER BY `sticky` DESC, `bump` DESC', $uri));
	$query->execute() or error(db_error($query));
	$ids = array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
	$on_pages = array_slice($ids, 0, $offset);
	$past = array_slice($ids, $offset);
	$new = array_values(array_filter($past, function ($id) use ($uri) { return !nichan_archived($uri, $id); }));
	if ($on_pages)
		query(sprintf('DELETE FROM ``nichan_archive`` WHERE `board` = %s AND `thread` IN (%s)', $GLOBALS['pdo']->quote($uri), implode(',', $on_pages))) or error(db_error());
	if (!$new)
		return 0;
	$query = query(sprintf('SELECT `id` FROM ``posts_%s`` WHERE `locked` = 1 AND `id` IN (%s)', $uri, implode(',', $new))) or error(db_error());
	$was_locked = array_flip(array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN)));
	$insert = prepare('INSERT IGNORE INTO ``nichan_archive`` (`board`, `thread`, `time`, `was_locked`) VALUES (:b, :t, :time, :wl)');
	foreach ($new as $id) {
		$insert->bindValue(':b', $uri);
		$insert->bindValue(':t', $id, PDO::PARAM_INT);
		$insert->bindValue(':time', time(), PDO::PARAM_INT);
		$insert->bindValue(':wl', isset($was_locked[$id]) ? 1 : 0, PDO::PARAM_INT);
		$insert->execute() or error(db_error($insert));
	}
	query(sprintf('UPDATE ``posts_%s`` SET `locked` = 1 WHERE `id` IN (%s)', $uri, implode(',', $new))) or error(db_error());
	unset($GLOBALS['nichan_archived_cache'][$uri]); // (the thread pages below ask again)
	foreach (array_slice($new, 0, $rebuild === null ? count($new) : $rebuild) as $id)
		buildThread($id);
	nichan_archive_build_page($uri);
	return count($new);
}

// An admin takes a thread out of the archive: it is opened again (unless it was locked before) and goes to
// the top of the board; when the pages are full, the lowest thread takes its place in the archive.
function nichan_unarchive($uri, $thread) {
	global $board;
	$query = prepare('SELECT `was_locked` FROM ``nichan_archive`` WHERE `board` = :b AND `thread` = :t');
	$query->bindValue(':b', $uri);
	$query->bindValue(':t', (int)$thread, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$was_locked = $query->fetchColumn();
	if ($was_locked === false)
		return false;
	$query = prepare('DELETE FROM ``nichan_archive`` WHERE `board` = :b AND `thread` = :t');
	$query->bindValue(':b', $uri);
	$query->bindValue(':t', (int)$thread, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	unset($GLOBALS['nichan_archived_cache'][$uri]);
	$query = prepare(sprintf('UPDATE ``posts_%s`` SET `bump` = :now%s WHERE `id` = :id AND `thread` IS NULL', $uri, $was_locked ? '' : ', `locked` = 0'));
	$query->bindValue(':now', time(), PDO::PARAM_INT);
	$query->bindValue(':id', (int)$thread, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	buildThread($thread);
	nichan_archive_run($uri); // the lowest thread goes to the archive now, if the pages are full
	nichan_archive_build_page($uri);
	buildIndex();
	rebuildThemes('post-thread', $uri);
	return true;
}

// <board>/archive.html: the archived threads, newest first
function nichan_archive_build_page($uri) {
	global $config, $board;
	$en = strpos($config['locale'], 'en') === 0;
	$query = prepare(sprintf('SELECT `p`.`id`, `p`.`subject`, `p`.`body_nomarkup`, `p`.`time`, `a`.`time` AS `archived`,
		(SELECT COUNT(*) FROM ``posts_%s`` WHERE `thread` = `p`.`id`) AS `replies`
		FROM ``nichan_archive`` AS `a` JOIN ``posts_%s`` AS `p` ON `p`.`id` = `a`.`thread` AND `p`.`thread` IS NULL
		WHERE `a`.`board` = :b ORDER BY `a`.`time` DESC, `p`.`bump` DESC', $uri, $uri));
	$query->bindValue(':b', $uri);
	$query->execute() or error(db_error($query));
	$rows = $query->fetchAll(PDO::FETCH_ASSOC);
	$html = '<div class="nichan-archive" style="max-width:900px;margin:0 auto;padding:0 8px">' .
		'<p class="unimportant" style="text-align:center">' . ($en
			? 'Threads that dropped off the last page. They are closed but stay here and in the search.'
			: 'Нитки, що випали за останню сторінку дошки. Відповідати в них не можна, але вони лишаються тут і в пошуку.') .
		' <a href="' . $config['root'] . $uri . '/">/' . $uri . '/</a> · <a href="' . $config['root'] . 'search.php?board=' . $uri . '">' . ($en ? 'search' : 'пошук') . '</a></p>';
	if (!$rows)
		$html .= '<p style="text-align:center"><em>' . ($en ? 'The archive is empty.' : 'Архів поки порожній.') . '</em></p>';
	else {
		$html .= '<table class="modlog" style="width:100%"><tr><th>№</th><th>' . ($en ? 'Thread' : 'Нитка') . '</th><th>' . ($en ? 'Replies' : 'Відповідей') .
			'</th><th>' . ($en ? 'Started' : 'Створено') . '</th></tr>';
		foreach ($rows as $r) {
			$title = trim((string)$r['subject']) !== '' ? $r['subject'] : nichan_plain_text($r['body_nomarkup'], 90);
			$html .= '<tr><td><a href="' . $config['root'] . $uri . '/' . $config['dir']['res'] . $r['id'] . '.html">' . $r['id'] . '</a></td>' .
				'<td>' . htmlspecialchars($title !== '' ? $title : '—', ENT_QUOTES, 'UTF-8') . '</td>' .
				'<td style="text-align:center">' . (int)$r['replies'] . '</td><td style="white-space:nowrap">' . date('d.m.Y', $r['time']) . '</td></tr>';
		}
		$html .= '</table>';
	}
	$html .= '</div>';
	file_write($config['dir']['home'] . $uri . '/archive.html', Element('page.html', array(
		'config' => $config,
		'search_board' => $uri,
		'title' => ($en ? 'Archive' : 'Архів') . ' /' . $uri . '/',
		'subtitle' => isset($board['title']) && $board['uri'] == $uri ? utf8tohtml($board['title']) : '',
		'body' => $html,
		'boardlist' => createBoardlist(),
	)));
}

/* ----------------------------------------------------------- premoderation */

// until when new threads on the board wait for a moderator (0: they don't)
function nichan_premod_until($uri) {
	$until = (int)nichan_setting('premod_until_' . $uri, 0);
	return $until > time() ? $until : 0;
}

function nichan_premod_set($uri, $until) {
	nichan_set_setting('premod_until_' . $uri, (string)(int)$until);
}

// post.php: a new thread goes to the queue instead of the board
function nichan_premod_add(array $post) {
	global $board;
	$token = bin2hex(random_bytes(16));
	if (mt_rand(1, 20) == 1)
		query("DELETE FROM ``nichan_pending`` WHERE `status` <> 'pending' AND `decided` < " . (time() - 14 * 86400));
	$query = prepare('INSERT INTO ``nichan_pending`` (`board`, `ip`, `time`, `data`, `token`) VALUES (:b, :ip, :t, :d, :token)');
	$query->bindValue(':token', $token);
	$query->bindValue(':b', $board['uri']);
	$query->bindValue(':ip', $_SERVER['REMOTE_ADDR']);
	$query->bindValue(':t', time(), PDO::PARAM_INT);
	$query->bindValue(':d', json_encode($post));
	$query->execute() or error(db_error($query));
	$id = (int)$GLOBALS['pdo']->lastInsertId();
	if (function_exists('nichan_telegram_queue') && nichan_telegram_enabled()) {
		$site = nichan_site_url();
		$text = "🕓 Нова нитка на перевірці /{$board['uri']}/ (№$id)\n" .
			(trim((string)$post['subject']) !== '' ? 'Тема: ' . $post['subject'] . "\n" : '') .
			nichan_telegram_body_preview((string)$post['body_nomarkup']) . "\nIP: " . nichan_telegram_ip_label($_SERVER['REMOTE_ADDR']);
		$buttons = nichan_setting('telegram_webhook_secret', '') !== ''
			? array(array(array('text' => 'Схвалити', 'callback_data' => 'p:ok:' . $id), array('text' => 'Відхилити', 'callback_data' => 'p:no:' . $id)))
			: array();
		$buttons[] = array(array('text' => 'Черга перевірки', 'url' => $site . '/mod.php?/nichan-pending'));
		nichan_telegram_queue('sendMessage', array('text' => mb_substr($text, 0, 3500), 'reply_markup' => json_encode(array('inline_keyboard' => $buttons), JSON_UNESCAPED_UNICODE)));
	}
	return array($id, $token);
}

function nichan_premod_get($id) {
	$query = prepare("SELECT * FROM ``nichan_pending`` WHERE `id` = :id AND `status` = 'pending'");
	$query->bindValue(':id', (int)$id, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	return $query->fetch(PDO::FETCH_ASSOC);
}

function nichan_premod_list() {
	$query = query("SELECT * FROM ``nichan_pending`` WHERE `status` = 'pending' ORDER BY `id`") or error(db_error());
	$list = array();
	foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$post = json_decode($row['data'], true);
		$row['subject'] = isset($post['subject']) ? (string)$post['subject'] : '';
		$nomarkup = isset($post['body_nomarkup']) ? (string)$post['body_nomarkup'] : '';
		$row['text'] = nichan_plain_text($nomarkup, 400);
		// the whole text as the thread will show it (post.php saved it after markup), when the start is not all of it
		$row['body'] = isset($post['body']) ? (string)$post['body'] : '';
		$row['long'] = $row['body'] !== '' && (mb_strlen(trim($nomarkup)) > 400 || $row['body'] !== utf8tohtml($nomarkup) || strpos($nomarkup, "\n") !== false);
		// every file: the picture itself, and its thumbnail when there is one (not for a spoiler or a plain file)
		$row['thumb'] = null;
		$row['files'] = array();
		$dir = $GLOBALS['config']['root'] . $row['board'] . '/';
		foreach (isset($post['files']) ? (array)$post['files'] : array() as $f) {
			if (empty($f['file']) || basename($f['file']) !== $f['file'])
				continue;
			$thumb = !empty($f['thumb']) && !in_array($f['thumb'], array('file', 'spoiler')) && basename($f['thumb']) === $f['thumb']
				? $dir . $GLOBALS['config']['dir']['thumb'] . $f['thumb'] : null;
			$row['files'][] = array(
				'src' => $dir . $GLOBALS['config']['dir']['img'] . $f['file'],
				'thumb' => $thumb,
				'name' => isset($f['filename']) ? (string)$f['filename'] : $f['file'],
				'size' => isset($f['size']) ? (int)$f['size'] : 0,
				'spoiler' => isset($f['thumb']) && $f['thumb'] === 'spoiler',
			);
			if (!$row['thumb'])
				$row['thumb'] = $thumb;
		}
		$list[] = $row;
	}
	return $list;
}

// post.php, a thread just queued: to its author's browser (js/nichan.js keeps it and shows it "на розгляді")
function nichan_premod_respond($id, $token, array $post) {
	global $config, $board;
	$thumb = null;
	foreach (isset($post['files']) ? (array)$post['files'] : array() as $f) {
		if (!empty($f['thumb']) && !in_array($f['thumb'], array('file', 'spoiler')) && basename($f['thumb']) === $f['thumb']) {
			$thumb = $config['root'] . $board['dir'] . $config['dir']['thumb'] . $f['thumb'];
			break;
		}
	}
	$pending = array('id' => (int)$id, 'token' => $token, 'board' => $board['uri'], 'subject' => (string)$post['subject'],
		'text' => nichan_plain_text($post['body_nomarkup'], 300), 'thumb' => $thumb, 'time' => time());
	$redirect = $config['root'] . $board['dir'] . $config['file_index'];
	if (defined('NICHAN_API')) {
		// the app asks pending.php?q=<id>:<token> what became of it
		http_response_code(202);
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('ok' => true, 'pending' => array('id' => (int)$id, 'token' => $token,
			'status_url' => $config['root'] . 'pending.php?q=' . (int)$id . ':' . $token)));
		exit;
	}
	if (isset($_POST['json_response'])) {
		header('Content-Type: text/json; charset=utf-8');
		echo json_encode(array('nichan_pending' => $pending, 'redirect' => $redirect));
		exit;
	}
	// without scripts in the form: a page that remembers it and goes back to the board
	echo Element('page.html', array('config' => $config, 'title' => 'Нитку надіслано на розгляд', 'boardlist' => createBoardlist(),
		'body' => '<p style="text-align:center">Нитку надіслано модераторам. Поки її не схвалять, її бачите лише ви (з позначкою «на розгляді»).</p>' .
			'<p style="text-align:center"><a href="' . $redirect . '">Повернутися на дошку</a></p>' .
			'<script>try { var l = JSON.parse(localStorage.nichan_pending_threads || "[]"); l.push(' . json_encode($pending) . '); localStorage.nichan_pending_threads = JSON.stringify(l); } catch (e) {}</script>'));
	exit;
}

// the decision stays for the author's page; the post itself (and its password) is dropped
function nichan_premod_decide($id, $status) {
	$query = prepare("UPDATE ``nichan_pending`` SET `status` = :s, `decided` = :t, `data` = '' WHERE `id` = :id");
	$query->bindValue(':s', $status);
	$query->bindValue(':t', time(), PDO::PARAM_INT);
	$query->bindValue(':id', (int)$id, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
}

// What happened to waiting threads, for their authors: array(id => array(status, url)); needs each one's token.
function nichan_premod_status(array $wanted) {
	global $config;
	$out = array();
	foreach (array_slice($wanted, 0, 20, true) as $id => $token) {
		$query = prepare('SELECT `board`, `status`, `thread`, `token` FROM ``nichan_pending`` WHERE `id` = :id');
		$query->bindValue(':id', (int)$id, PDO::PARAM_INT);
		$query->execute() or error(db_error($query));
		$row = $query->fetch(PDO::FETCH_ASSOC);
		if (!$row || $row['token'] === '' || !hash_equals($row['token'], (string)$token)) {
			$out[(int)$id] = array('status' => 'unknown');
			continue;
		}
		$out[(int)$id] = array('status' => $row['status'],
			'url' => $row['status'] === 'approved' && $row['thread'] ? $config['root'] . $row['board'] . '/' . $config['dir']['res'] . (int)$row['thread'] . '.html' : null);
	}
	return $out;
}

// publishes a waiting thread; returns its id or false
function nichan_premod_approve($id) {
	global $board, $config;
	if (!($row = nichan_premod_get($id)) || !openBoard($row['board']))
		return false;
	nichan_premod_decide($id, 'approved');
	$post = json_decode($row['data'], true);
	$post['ip'] = $row['ip'];
	$post['mod'] = false;
	unset($post['time']);
	$post['id'] = $pid = post($post);
	if (!empty($post['nichan_opmark']))
		nichan_opmark_save($board['uri'], $pid, $row['ip'], isset($post['password']) ? $post['password'] : '');
	query('UPDATE ``nichan_pending`` SET `thread` = ' . (int)$pid . ' WHERE `id` = ' . (int)$id) or error(db_error());
	buildThread($pid);
	clean($pid);
	buildIndex();
	rebuildThemes('post-thread', $board['uri']);
	return $pid;
}

// drops a waiting thread and its files
function nichan_premod_reject($id) {
	global $config;
	if (!($row = nichan_premod_get($id)))
		return false;
	nichan_premod_decide($id, 'rejected');
	$post = json_decode($row['data'], true);
	$dir = sprintf($config['board_path'], $row['board']);
	foreach (isset($post['files']) ? (array)$post['files'] : array() as $f) {
		if (!empty($f['file']) && basename($f['file']) === $f['file'])
			@unlink($dir . $config['dir']['img'] . $f['file']);
		if (!empty($f['thumb']) && basename($f['thumb']) === $f['thumb'] && !in_array($f['thumb'], array('file', 'spoiler')))
			@unlink($dir . $config['dir']['thumb'] . $f['thumb']);
	}
	return true;
}

/* ---------------------------------------------------------- moderator notes */

// A moderator's note under a post, in red italics ('' removes it). Kept in the post's text as a modifier.
function nichan_modnote_set($post_id, $text) {
	global $board;
	$text = trim(preg_replace('/[\r\n]+/', ' ', (string)$text));
	$query = prepare(sprintf('SELECT `body_nomarkup`, `thread` FROM ``posts_%s`` WHERE `id` = :id', $board['uri']));
	$query->bindValue(':id', (int)$post_id, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	if (!$row = $query->fetch(PDO::FETCH_ASSOC))
		return false;
	$body = preg_replace('@\n?<tinyboard nichan note>.*?</tinyboard>@us', '', (string)$row['body_nomarkup']);
	if ($text !== '')
		$body .= "\n<tinyboard nichan note>" . utf8tohtml(mb_substr($text, 0, 500)) . '</tinyboard>';
	$query = prepare(sprintf('UPDATE ``posts_%s`` SET `body_nomarkup` = :b WHERE `id` = :id', $board['uri']));
	$query->bindValue(':b', $body);
	$query->bindValue(':id', (int)$post_id, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	rebuildPost($post_id);
	buildThread($row['thread'] ? $row['thread'] : $post_id);
	buildIndex();
	return true;
}

function nichan_modnote_get($post_id) {
	global $board;
	$query = prepare(sprintf('SELECT `body_nomarkup` FROM ``posts_%s`` WHERE `id` = :id', $board['uri']));
	$query->bindValue(':id', (int)$post_id, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$m = extract_modifiers((string)$query->fetchColumn());
	return isset($m['nichan note']) ? $m['nichan note'] : '';
}

/* ------------------------------------------------------ moderation statistics */

// What each moderator did in the last $days days, from the moderation log: array(totals, per day).
function nichan_modstats($days) {
	$kinds = array(
		'delete' => '/^(Deleted post|Deleted \d+ posts|Deleted all posts|Telegram \(.*?\): deleted)/i',
		'ban' => '/(^Created a new .*ban|banned the author|^Banned )/i',
		'report' => '/(^Dismissed a report|dismissed report|^Dismissed all reports)/i',
		'lock' => '/(^Locked thread|locked thread)/i',
		'note' => '/^Moderator note/i',
		'premod' => '/(^|: )(Approved|Rejected) a waiting thread/i',
	);
	$from = strtotime('today') - ($days - 1) * 86400;
	$query = prepare('SELECT ``modlogs``.`mod`, ``modlogs``.`time`, ``modlogs``.`text`, ``mods``.`username` FROM ``modlogs``
		LEFT JOIN ``mods`` ON ``mods``.`id` = ``modlogs``.`mod` WHERE ``modlogs``.`time` >= :from ORDER BY ``modlogs``.`time`');
	$query->bindValue(':from', $from, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$totals = array();
	$daily = array();
	foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$kind = null;
		foreach ($kinds as $k => $re) {
			if (preg_match($re, strip_tags(html_entity_decode($row['text'], ENT_QUOTES, 'UTF-8')))) {
				$kind = $k;
				break;
			}
		}
		if (!$kind)
			continue;
		// buttons pressed in Telegram are logged as "Telegram (@name): ..."
		$who = $row['username'] ?: (preg_match('/^Telegram \((.*?)\)/', html_entity_decode($row['text'], ENT_QUOTES, 'UTF-8'), $m) ? 'Telegram ' . $m[1] : '?');
		if (!isset($totals[$who]))
			$totals[$who] = array_fill_keys(array_keys($kinds), 0);
		$totals[$who][$kind]++;
		$day = date('Y-m-d', $row['time']);
		$daily[$day][$kind] = (isset($daily[$day][$kind]) ? $daily[$day][$kind] : 0) + 1;
	}
	foreach ($totals as &$t)
		$t['_sum'] = array_sum($t);
	unset($t);
	uasort($totals, function ($a, $b) { return $b['_sum'] - $a['_sum']; });
	$days_list = array();
	for ($t = $from; $t <= time(); $t += 86400)
		$days_list[date('d.m.Y', $t)] = isset($daily[date('Y-m-d', $t)]) ? $daily[date('Y-m-d', $t)] : array();
	return array('kinds' => array_keys($kinds), 'totals' => $totals, 'daily' => array_reverse($days_list, true));
}
