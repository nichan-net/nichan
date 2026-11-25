<?php

/*
 * Word filters and flood cooldowns (mod.php?/nichan-filters).
 *
 * Word filters (``nichan_word_filters``):
 *  - block:   a post containing the word is refused ("Пост містить слово з чорного списку")
 *  - replace: the word is replaced (typos, censoring; the replacement "*" masks it with asterisks)
 *  Plain words match case-insensitively, optionally only as a whole word; regexes are PCRE with /iu.
 *
 * Flood (``nichan_post_log`` + ``nichan_cooldowns``): too many posts from one IP in a short time
 * give a cooldown (10 minutes); doing it again within an hour after the cooldown ended gives a longer
 * one (30 minutes). The limits are settings (flood_*). Staff are never limited.
 */

defined('TINYBOARD') or exit;

function nichan_flood_settings() {
	return array(
		'enabled' => (int)nichan_setting('flood_enabled', 1),
		'replies_1m' => (int)nichan_setting('flood_replies_1m', 6),
		'replies_10m' => (int)nichan_setting('flood_replies_10m', 20),
		'threads_30m' => (int)nichan_setting('flood_threads_30m', 3),
		'ban_first' => (int)nichan_setting('flood_ban_first', 600),
		'ban_repeat' => (int)nichan_setting('flood_ban_repeat', 1800),
		'repeat_window' => (int)nichan_setting('flood_repeat_window', 3600),
	);
}

/* ------------------------------------------------------------ word filters */

$nichan_word_filters = null;

function nichan_word_filters() {
	global $nichan_word_filters;
	if ($nichan_word_filters === null) {
		$query = query('SELECT * FROM ``nichan_word_filters`` ORDER BY `id`');
		$nichan_word_filters = $query ? $query->fetchAll(PDO::FETCH_ASSOC) : array();
	}
	return $nichan_word_filters;
}

// The PCRE pattern of a filter, or null if its regex doesn't compile.
function nichan_word_filter_regex(array $f) {
	if (!empty($f['is_regex'])) {
		$p = $f['pattern'];
		if (preg_match('~^/(.*)/[a-z]*$~s', $p, $m))
			$p = $m[1]; // written as /…/
		$re = '~' . str_replace('~', '\~', $p) . '~iu';
	} elseif (!empty($f['whole_word'])) {
		$re = '~(?<![\p{L}\p{N}_])' . preg_quote($f['pattern'], '~') . '(?![\p{L}\p{N}_])~iu';
	} else {
		$re = '~' . preg_quote($f['pattern'], '~') . '~iu';
	}
	return @preg_match($re, '') === false ? null : $re;
}

function nichan_word_filter_hit($id) {
	$query = prepare('UPDATE ``nichan_word_filters`` SET `hits` = `hits` + 1, `last_hit` = :t WHERE `id` = :id');
	$query->bindValue(':t', time(), PDO::PARAM_INT);
	$query->bindValue(':id', (int)$id, PDO::PARAM_INT);
	$query->execute();
}

/*
 * Applies the filters to texts (array of strings, by reference). Returns the blocking filter that
 * matched (array), or null; replacements are made in place. $count_hits: false for the admin's test box.
 */
function nichan_word_filters_apply(array &$texts, $allow_block = true, $count_hits = true) {
	foreach (nichan_word_filters() as $f) {
		$re = nichan_word_filter_regex($f);
		if ($re === null)
			continue;
		if ($f['action'] === 'block') {
			if (!$allow_block)
				continue;
			foreach ($texts as $t) {
				if (preg_match($re, $t)) {
					if ($count_hits)
						nichan_word_filter_hit($f['id']);
					return $f;
				}
			}
			continue;
		}
		$hit = false;
		foreach ($texts as &$t) {
			$new = preg_replace_callback($re, function ($m) use ($f) {
				if ($f['replacement'] === '*')
					return str_repeat('*', mb_strlen($m[0]));
				if (!empty($f['is_regex'])) {
					// $1, $2… of the regex
					return preg_replace_callback('~\$(\d)~', function ($g) use ($m) { return isset($m[$g[1]]) ? $m[$g[1]] : ''; }, $f['replacement']);
				}
				return $f['replacement'];
			}, $t);
			if ($new !== null && $new !== $t) {
				$t = $new;
				$hit = true;
			}
		}
		unset($t);
		if ($hit && $count_hits)
			nichan_word_filter_hit($f['id']);
	}
	return null;
}

// post.php: filters on the subject and the text of a new post (staff may use blocked words).
function nichan_word_filters_post(array &$post, $is_staff) {
	global $config;
	$texts = array('subject' => (string)$post['subject'], 'body' => (string)$post['body']);
	$block = nichan_word_filters_apply($texts, !$is_staff);
	if ($block)
		error(strpos($config['locale'], 'en') === 0 ? 'Your post contains a blacklisted word.' : 'Пост містить слово з чорного списку.');
	$post['subject'] = $texts['subject'];
	$post['body'] = $texts['body'];
}

/* ------------------------------------------------------------------- flood */

function nichan_minutes_uk($n) {
	$n = (int)$n;
	$f = ($n % 10 == 1 && $n % 100 != 11) ? 'хвилину' : (($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20)) ? 'хвилини' : 'хвилин');
	return "$n $f";
}

function nichan_flood_error($until, $thread) {
	global $config;
	$left = max(1, (int)ceil(($until - time()) / 60));
	nichan_api_code('flood', $until);
	if (strpos($config['locale'], 'en') === 0)
		error("You are posting too often. Take a break for $left more minute" . ($left == 1 ? '' : 's') . '.');
	error(($thread ? 'Ти надто часто створюєш нитки.' : 'Ти надто часто відповідаєш.') . ' Перепочинь ще ' . nichan_minutes_uk($left) . '.');
}

// post.php, before the post is saved: refuse it during a cooldown, or start one when the limits are exceeded.
function nichan_flood_check($ip, $is_thread, $is_staff) {
	$s = nichan_flood_settings();
	if ($is_staff || !$s['enabled'])
		return;
	$now = time();
	$query = prepare('SELECT * FROM ``nichan_cooldowns`` WHERE `ip` = :ip');
	$query->bindValue(':ip', $ip);
	$query->execute() or error(db_error($query));
	$cd = $query->fetch(PDO::FETCH_ASSOC);
	if ($cd && $cd['until'] > $now)
		nichan_flood_error($cd['until'], $is_thread);

	$query = prepare('SELECT
		SUM(`time` > :t1) AS `m1`, SUM(`time` > :t10) AS `m10`, SUM(`thread` = 1 AND `time` > :t30) AS `th30`
		FROM ``nichan_post_log`` WHERE `ip` = :ip AND `time` > :t30b');
	$query->bindValue(':t1', $now - 60, PDO::PARAM_INT);
	$query->bindValue(':t10', $now - 600, PDO::PARAM_INT);
	$query->bindValue(':t30', $now - 1800, PDO::PARAM_INT);
	$query->bindValue(':t30b', $now - 1800, PDO::PARAM_INT);
	$query->bindValue(':ip', $ip);
	$query->execute() or error(db_error($query));
	$c = $query->fetch(PDO::FETCH_ASSOC);
	// this attempt counts too
	$reason = null;
	if ($s['replies_1m'] > 0 && (int)$c['m1'] + 1 > $s['replies_1m'])
		$reason = ((int)$c['m1'] + 1) . ' posts in a minute';
	elseif ($s['replies_10m'] > 0 && (int)$c['m10'] + 1 > $s['replies_10m'])
		$reason = ((int)$c['m10'] + 1) . ' posts in 10 minutes';
	elseif ($is_thread && $s['threads_30m'] > 0 && (int)$c['th30'] + 1 > $s['threads_30m'])
		$reason = ((int)$c['th30'] + 1) . ' threads in 30 minutes';
	if ($reason === null)
		return;

	$repeat = $cd && $cd['last_until'] && $now - $cd['last_until'] < $s['repeat_window'];
	$until = $now + ($repeat ? $s['ban_repeat'] : $s['ban_first']);
	$query = prepare('INSERT INTO ``nichan_cooldowns`` (`ip`, `until`, `last_until`, `strikes`, `reason`, `created`)
		VALUES (:ip, :until, :until2, 1, :reason, :now)
		ON DUPLICATE KEY UPDATE `until` = VALUES(`until`), `last_until` = VALUES(`last_until`), `strikes` = `strikes` + 1,
			`reason` = VALUES(`reason`), `created` = VALUES(`created`)');
	$query->bindValue(':ip', $ip);
	$query->bindValue(':until', $until, PDO::PARAM_INT);
	$query->bindValue(':until2', $until, PDO::PARAM_INT);
	$query->bindValue(':reason', $reason . ($repeat ? ' (repeated)' : ''));
	$query->bindValue(':now', $now, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	nichan_flood_error($until, $is_thread);
}

// post.php, after the post was saved.
function nichan_flood_log($ip, $is_thread) {
	$query = prepare('INSERT INTO ``nichan_post_log`` (`ip`, `time`, `thread`) VALUES (:ip, :t, :th)');
	$query->bindValue(':ip', $ip);
	$query->bindValue(':t', time(), PDO::PARAM_INT);
	$query->bindValue(':th', $is_thread ? 1 : 0, PDO::PARAM_INT);
	$query->execute();
	if (mt_rand(1, 50) == 1) // now and then forget what is older than a day
		query('DELETE FROM ``nichan_post_log`` WHERE `time` < ' . (time() - 86400));
}

/* ------------------------------------------------- too many threads on a board */

// Too many new threads on one board in a short time (a raid): creating threads there is switched off
// for a while. Replies still work. Admins change the limits and can switch it back on early.
function nichan_board_lock_settings() {
	return array(
		'enabled' => (int)nichan_setting('board_lock_enabled', 1),
		'threads' => (int)nichan_setting('board_lock_threads', 10),
		'window' => (int)nichan_setting('board_lock_window', 600),
		'length' => (int)nichan_setting('board_lock_length', 1800),
		// what a raid does: 'lock' (no new threads) or 'premod' (new threads wait for a moderator)
		'action' => nichan_setting('board_lock_action', 'lock') === 'premod' ? 'premod' : 'lock',
	);
}

// until when new threads are switched off on the board (0: they aren't)
function nichan_board_locked_until($uri) {
	$until = (int)nichan_setting('board_lock_until_' . $uri, 0);
	return $until > time() ? $until : 0;
}

// a message to the moderators' Telegram chat (if the bot is set up)
function nichan_moderators_notice($text) {
	if (function_exists('nichan_telegram_queue'))
		nichan_telegram_queue('sendMessage', array('text' => $text));
}

function nichan_site_url() {
	// the site's address (mod.php?/nichan-site); instance-config's telegram site_url can still override it
	$site = function_exists('nichan_site') ? nichan_site('public_url') : 'https://example.org';
	return rtrim(function_exists('nichan_telegram_config') ? nichan_telegram_config('site_url', $site) : $site, '/');
}

function nichan_board_lock_lift($uri) {
	nichan_set_setting('board_lock_until_' . $uri, '0');
	// the threads that caused it don't count again
	nichan_set_setting('board_lock_from_' . $uri, (string)time());
}

function nichan_board_lock_error($until) {
	global $config;
	$left = max(1, (int)ceil(($until - time()) / 60));
	nichan_api_code('threads_paused', $until);
	if (strpos($config['locale'], 'en') === 0)
		error("Too many new threads on this board. Creating threads is switched off for $left more minute" . ($left == 1 ? '' : 's') . '; replies still work.');
	error('На дошці забагато нових ниток. Створювати нитки можна буде за ' . nichan_minutes_uk($left) . '; відповідати можна й зараз.');
}

// post.php, before a new thread is saved.
function nichan_board_lock_check($uri, $is_staff) {
	if ($is_staff || nichan_premod_until($uri))
		return; // (under premoderation new threads go to the queue)
	if ($until = nichan_board_locked_until($uri))
		nichan_board_lock_error($until);
	$s = nichan_board_lock_settings();
	if (!$s['enabled'] || $s['threads'] < 1)
		return;
	$from = max(time() - $s['window'], (int)nichan_setting('board_lock_from_' . $uri, 0));
	$query = prepare(sprintf('SELECT COUNT(*) FROM ``posts_%s`` WHERE `thread` IS NULL AND `time` > :from', $uri));
	$query->bindValue(':from', $from, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$count = (int)$query->fetchColumn() + 1;
	if ($count <= $s['threads'])
		return;
	$until = time() + $s['length'];
	if ($s['action'] === 'premod') {
		nichan_premod_set($uri, $until);
		nichan_moderators_notice("🕓 Флуд на /$uri/: нових ниток за " . nichan_minutes_uk(round($s['window'] / 60)) . " — $count.\n" .
			'Нові нитки тепер чекають перевірки ' . nichan_minutes_uk(round($s['length'] / 60)) . ' (до ' . date('H:i', $until) . ").\nЧерга: " . nichan_site_url() . '/mod.php?/nichan-pending');
		return; // this thread goes to the queue too
	}
	nichan_set_setting('board_lock_until_' . $uri, (string)$until);
	nichan_moderators_notice("🚫 Флуд на /$uri/: нових ниток за " . nichan_minutes_uk(round($s['window'] / 60)) . " — $count.\n" .
		'Створення ниток вимкнене на ' . nichan_minutes_uk(round($s['length'] / 60)) . ' (до ' . date('H:i', $until) . '), відповідати можна.' .
		"\nУвімкнути раніше: " . nichan_site_url() . '/mod.php?/nichan-filters');
	nichan_board_lock_error($until);
}

/* ------------------------------------------------- many posts on a board */

// A burst of posts on one board (more than N in M minutes): the moderators hear of it in Telegram.
// Nothing is switched off. At most one message per board per M minutes.
function nichan_board_alert_settings() {
	return array(
		'enabled' => (int)nichan_setting('board_alert_enabled', 1),
		'posts' => (int)nichan_setting('board_alert_posts', 100),
		'window' => (int)nichan_setting('board_alert_window', 600),
	);
}

// post.php, after a post was saved.
function nichan_board_alert_check($uri) {
	$s = nichan_board_alert_settings();
	if (!$s['enabled'] || $s['posts'] < 1)
		return;
	$last = (int)nichan_setting('board_alert_last_' . $uri, 0);
	if ($last > time() - $s['window'])
		return;
	$query = prepare(sprintf('SELECT COUNT(*) FROM ``posts_%s`` WHERE `time` > :from', $uri));
	$query->bindValue(':from', time() - $s['window'], PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$count = (int)$query->fetchColumn();
	if ($count <= $s['posts'])
		return;
	nichan_set_setting('board_alert_last_' . $uri, (string)time());
	nichan_moderators_notice("⚠️ Багато дописів на /$uri/: за " . nichan_minutes_uk(round($s['window'] / 60)) . " — $count.\n" .
		'Можливо, флуд. Дошка: ' . nichan_site_url() . "/$uri/\nНалаштування: " . nichan_site_url() . '/mod.php?/nichan-filters');
}

/* ---------------------------------------------------------------- rate limits */

// Attempts of one kind ('login', 'report', 'captcha', 'delete') by one IP (or user name).
function nichan_rate_count($kind, $who, $seconds) {
	$query = prepare('SELECT COUNT(*) FROM ``nichan_rate`` WHERE `kind` = :kind AND `who` = :who AND `time` > :t');
	$query->bindValue(':kind', $kind);
	$query->bindValue(':who', mb_substr((string)$who, 0, 64));
	$query->bindValue(':t', time() - $seconds, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	return (int)$query->fetchColumn();
}

function nichan_rate_hit($kind, $who) {
	$query = prepare('INSERT INTO ``nichan_rate`` (`kind`, `who`, `time`) VALUES (:kind, :who, :t)');
	$query->bindValue(':kind', $kind);
	$query->bindValue(':who', mb_substr((string)$who, 0, 64));
	$query->bindValue(':t', time(), PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	if (mt_rand(1, 100) == 1) // now and then forget what is older than a day
		query('DELETE FROM ``nichan_rate`` WHERE `time` < ' . (time() - 86400));
}

// Seconds until the oldest counted attempt leaves the window (for "try again in N minutes").
function nichan_rate_wait($kind, $who, $seconds) {
	$query = prepare('SELECT MIN(`time`) FROM ``nichan_rate`` WHERE `kind` = :kind AND `who` = :who AND `time` > :t');
	$query->bindValue(':kind', $kind);
	$query->bindValue(':who', mb_substr((string)$who, 0, 64));
	$query->bindValue(':t', time() - $seconds, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$oldest = (int)$query->fetchColumn();
	return $oldest ? max(60, $oldest + $seconds - time()) : 60;
}

// mod.php login: after 5 wrong passwords from an IP in 15 minutes, further tries wait. (Not per user name:
// then anybody could lock the admins out.) Returns the seconds to wait, or 0.
function nichan_login_blocked($username) {
	$ip = $_SERVER['REMOTE_ADDR'];
	return nichan_rate_count('login', $ip, 900) >= 5 ? nichan_rate_wait('login', $ip, 900) : 0;
}

function nichan_login_failed($username) {
	nichan_rate_hit('login', $_SERVER['REMOTE_ADDR']);
}
