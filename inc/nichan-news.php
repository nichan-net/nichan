<?php
/*
 * News (mod.php?/edit_news): a name shown to visitors ("Admin" when left empty), a subject, a short
 * description, the text and the moment until which the news stays up. Active news is listed on the front
 * page and each one has its own page, news.php?id=N, with the other active news below it. Deactivated or
 * expired news goes to the archive, which only the staff sees.
 */

defined('TINYBOARD') or exit;

// Active news, newest first.
function nichan_news_active($limit = 20) {
	$query = prepare('SELECT * FROM ``news`` WHERE `active` = 1 AND (`until` IS NULL OR `until` > :now) ORDER BY `time` DESC, `id` DESC LIMIT :limit');
	$query->bindValue(':now', time(), PDO::PARAM_INT);
	$query->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	return $query->fetchAll(PDO::FETCH_ASSOC);
}

// Deactivated or expired news, newest first.
function nichan_news_archive($offset, $limit) {
	$query = prepare('SELECT * FROM ``news`` WHERE `active` = 0 OR (`until` IS NOT NULL AND `until` <= :now) ORDER BY `time` DESC, `id` DESC LIMIT :offset, :limit');
	$query->bindValue(':now', time(), PDO::PARAM_INT);
	$query->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
	$query->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	return $query->fetchAll(PDO::FETCH_ASSOC);
}

function nichan_news_archive_count() {
	$query = prepare('SELECT COUNT(*) FROM ``news`` WHERE `active` = 0 OR (`until` IS NOT NULL AND `until` <= :now)');
	$query->bindValue(':now', time(), PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	return (int)$query->fetchColumn();
}

function nichan_news_get($id) {
	$query = prepare('SELECT * FROM ``news`` WHERE `id` = :id');
	$query->bindValue(':id', (int)$id, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	return $query->fetch(PDO::FETCH_ASSOC);
}

function nichan_news_is_active($n) {
	return $n && $n['active'] && ($n['until'] === null || (int)$n['until'] > time());
}

function nichan_news_name($n) {
	return trim((string)$n['name']) !== '' ? $n['name'] : 'Admin';
}

// Links to posts of this site (its address: mod.php?/nichan-site) become >>>/board/ links: posts (post.php) and news.
function nichan_site_links_to_cites($text) {
	global $config;
	if (empty($config['nichan_site']['host'])) return $text;
	$host = preg_quote($config['nichan_site']['host'], '/');
	$text = preg_replace('/https?:\/\/' . $host . '\/(.*)\/res\/(.*)#q/', '>>>/$1/', $text);
	$text = preg_replace('/https?:\/\/' . $host . '\/(.*)\/res\/(.*)#/', '>>>/$1/', $text);
	return preg_replace('/https?:\/\/' . $host . '\/(.*)\/res\/(.*).html/', '>>>/$1/$2', $text);
}

// The address of a post (a thread's own page, or the reply's place in its thread), or null when there is none.
function nichan_news_post_url($uri, $id) {
	global $config;
	if (!preg_match('/^[a-z0-9_]+$/i', $uri) || !getBoardInfo($uri))
		return null;
	$query = prepare(sprintf('SELECT `thread` FROM ``posts_%s`` WHERE `id` = :id', $uri));
	$query->bindValue(':id', (int)$id, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$row = $query->fetch(PDO::FETCH_ASSOC);
	if (!$row)
		return null;
	$thread = $row['thread'] ? (int)$row['thread'] : (int)$id;
	return $config['root'] . $uri . '/' . $config['dir']['res'] . sprintf($config['file_page'], $thread) . '#' . (int)$id;
}

// The text of a news entry is marked up when it is saved, and >>>/n/123 becomes a link only if that post exists
// then (a discussion thread is often started after the news): plain ones become links when shown.
function nichan_news_links($html) {
	$parts = preg_split('~(<a\b.*?</a>|<[^>]+>)~is', (string)$html, -1, PREG_SPLIT_DELIM_CAPTURE);
	foreach ($parts as $i => &$part) {
		if ($i % 2)
			continue; // (tags and links stay as they are)
		$part = preg_replace_callback('~&gt;&gt;&gt;/([a-z0-9_]+)/(\d+)~i', function ($m) {
			$url = nichan_news_post_url($m[1], $m[2]);
			return $url ? '<a href="' . $url . '">' . $m[0] . '</a>' : $m[0];
		}, $part);
	}
	unset($part);
	return implode('', $parts);
}

// «Обговорення новини»: "b/123", "/b/res/123.html", "https://site/b/res/123.html#5" or ">>>/b/123" -> "b/123";
// '' for an empty field. The thread has to exist.
function nichan_news_thread_parse($value) {
	$value = trim((string)$value);
	if ($value === '' || $value === '-')
		return '';
	if (!preg_match('~(?:^|/)([a-z0-9_]+)/(?:res/)?(\d+)(?:[^/\d][^/]*)?$~i', $value, $m) || !getBoardInfo($m[1]))
		error('Нитку обговорення вкажіть як «дошка/номер» (напр. n/123) або адресою нитки.');
	$query = prepare(sprintf('SELECT `thread` FROM ``posts_%s`` WHERE `id` = :id', $m[1]));
	$query->bindValue(':id', (int)$m[2], PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$row = $query->fetch(PDO::FETCH_ASSOC);
	if (!$row)
		error("На /{$m[1]}/ немає допису №{$m[2]}.");
	// a reply stands for its thread
	return $m[1] . '/' . ($row['thread'] ? (int)$row['thread'] : (int)$m[2]);
}

// What the pages show of a news entry: the text with its links, and the discussion thread (array(board, id, url)).
function nichan_news_prepare(array $n) {
	global $config;
	$n['body'] = nichan_news_links($n['body']);
	$n['discussion'] = null;
	if (!empty($n['thread']) && preg_match('~^([a-z0-9_]+)/(\d+)$~i', $n['thread'], $m) && getBoardInfo($m[1]))
		$n['discussion'] = array('board' => $m[1], 'id' => (int)$m[2],
			'url' => $config['root'] . $m[1] . '/' . $config['dir']['res'] . sprintf($config['file_page'], $m[2]));
	return $n;
}

// The text of a news entry as typed -> the HTML kept in ``news``.`body` (the markup of posts).
function nichan_news_markup($raw) {
	$body = nichan_site_links_to_cites((string)$raw);
	$body = escape_markup_modifiers($body);
	markup($body);
	return $body;
}

// The text to edit: as typed (``news``.`body_raw`), or for news written before that was kept, worked back from the
// HTML: bold, italic, spoilers, underline, strike, headings, code and line breaks return to their markup, links to
// their text (a >>>/n/1 link is made again from its text).
function nichan_news_source(array $n) {
	if (isset($n['body_raw']) && $n['body_raw'] !== null && $n['body_raw'] !== '')
		return $n['body_raw'];
	$h = (string)$n['body'];
	$h = preg_replace('~<br\s*/?>~i', "\n", $h);
	$h = preg_replace('~<code><pre[^>]*>(.*?)</pre></code>~is', '[code]$1[/code]', $h);
	$h = preg_replace('~<span class="heading">(.*?)</span>~is', '==$1==', $h);
	$h = preg_replace('~<span class="spoiler">(.*?)</span>~is', '%%$1%%', $h);
	$h = preg_replace('~<(strong|b)>(.*?)</\1>~is', '**$2**', $h);
	$h = preg_replace('~<(em|i)>(.*?)</\1>~is', '*$2*', $h);
	$h = preg_replace('~<u>(.*?)</u>~is', '__$1__', $h);
	$h = preg_replace('~<(strike|s)>(.*?)</\1>~is', '~~$2~~', $h);
	$h = strip_tags($h);
	return html_entity_decode($h, ENT_QUOTES, 'UTF-8');
}

// The front page lists the active news, so it is built again (news.php itself is never cached).
function nichan_news_changed() {
	rebuildThemes('news');
}

// "2026-10-01T18:30" from <input type="datetime-local">, in the site's time zone; null when empty or wrong.
function nichan_news_parse_until($value) {
	$value = trim((string)$value);
	if ($value === '')
		return null;
	$d = DateTime::createFromFormat('!Y-m-d\TH:i', substr($value, 0, 16));
	return $d ? $d->getTimestamp() : null;
}
