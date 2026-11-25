<?php

/*
 * The app API (api.php, /api/v1/...; API.md): its JSON answers and errors. The site's own code reports why a post
 * was refused with error(); in the API that becomes {"error": "<code>", "message": "<text>"} with an HTTP status.
 */

defined('TINYBOARD') or exit;

// Why the next error() refuses the request, for the app (the site itself only shows the text). Returns true,
// so it can stand in a condition before error(): if (!ok && nichan_api_code('captcha')) error(...).
function nichan_api_code($code, $until = null) {
	$GLOBALS['nichan_api_code'] = array($code, $until);
	return true;
}

// $etag: true — a weak ETag of the body itself; a string — one worked out beforehand (nichan_api_not_modified()).
function nichan_api_send($status, array $data, $cache = 'no-store', $etag = null, $modified = null) {
	$body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
	if ($status == 200 && $etag !== null)
		nichan_api_not_modified($etag === true ? nichan_api_etag($body) : $etag, $modified, $cache);
	if (!headers_sent()) {
		http_response_code($status);
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: ' . $cache);
	}
	echo $body;
	exit;
}

function nichan_api_etag($what) {
	return 'W/"' . substr(md5($what), 0, 20) . '"';
}

/*
 * Conditional GET (RFC 9110, 13.1): sends the validators and, when the app's copy is still current, 304 without a body.
 * If-None-Match (a list, "*", weak comparison) wins over If-Modified-Since. Returns when the answer has to be sent.
 */
function nichan_api_not_modified($etag, $modified = null, $cache = 'private, no-cache') {
	header('ETag: ' . $etag);
	if ($modified)
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');
	header('Cache-Control: ' . $cache);
	$fresh = false;
	if (isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
		$tag = preg_replace('~^W/~', '', $etag);
		if (trim($_SERVER['HTTP_IF_NONE_MATCH']) === '*')
			$fresh = true;
		// (a compressing server adds "-gzip" / "-br" to the tag it sends; the app gives it back that way)
		elseif (preg_match_all('~(?:W/)?"([^"]*?)(?:-(?:gzip|br|deflate))?"~', $_SERVER['HTTP_IF_NONE_MATCH'], $m))
			$fresh = in_array(trim($tag, '"'), $m[1], true);
	} elseif ($modified && isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
		$since = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
		$fresh = $since !== false && $modified <= $since;
	}
	if ($fresh) {
		http_response_code(304);
		exit;
	}
}

function nichan_api_fail($status, $code, $message, array $extra = array()) {
	nichan_api_send($status, array('error' => $code, 'message' => $message) + $extra);
}

// the site's messages are HTML at times
function nichan_api_text($html) {
	return trim(html_entity_decode(strip_tags(str_replace(array('<br>', '<br/>', '<br />'), ' ', (string)$html)), ENT_QUOTES, 'UTF-8'));
}

// error() of the site's code, in API mode (inc/display.php)
function nichan_api_error($message) {
	global $config;
	static $codes = array(
		'noboard' => array(404, 'board_not_found'), 'nonexistant' => array(404, 'thread_not_found'),
		'nopost' => array(404, 'post_not_found'), 'locked' => array(403, 'thread_locked'),
		'reply_hard_limit' => array(403, 'thread_full'), 'image_hard_limit' => array(403, 'thread_full'),
		'invalidpassword' => array(403, 'wrong_password'), 'nodelete' => array(403, 'cannot_delete'),
		'delete_too_soon' => array(403, 'too_soon'), 'noreport' => array(400, 'nothing_to_report'),
		'toomanyreports' => array(429, 'rate_limited'), 'tooshort_body' => array(400, 'too_short'),
		'toolong_body' => array(400, 'too_long'), 'toolong' => array(400, 'too_long'),
		'filesize' => array(413, 'file_too_big'), 'maxsize' => array(413, 'file_too_big'),
		'unknownext' => array(415, 'file_type'), 'invalidimg' => array(415, 'bad_file'),
		'mime_exploit' => array(415, 'bad_file'), 'toomanyimages' => array(400, 'too_many_files'),
		'noimage' => array(400, 'file_required'), 'fileexists' => array(409, 'duplicate_file'),
		'fileexistsinthread' => array(409, 'duplicate_file'), 'muted' => array(403, 'muted'),
		'unoriginal' => array(409, 'unoriginal'), 'spamword' => array(400, 'spam'), 'bot' => array(400, 'bad_request'),
	);
	static $ours = array('captcha' => 400, 'captcha_unavailable' => 503, 'flood' => 429, 'threads_paused' => 429, 'closed' => 403, 'country' => 403, 'tor_restricted' => 403);

	$text = nichan_api_text($message);
	$extra = array();
	if (!empty($GLOBALS['nichan_api_code'])) {
		list($code, $until) = $GLOBALS['nichan_api_code'];
		$status = isset($ours[$code]) ? $ours[$code] : 400;
		if ($until) {
			$extra['retry_after'] = max(1, (int)$until - time());
			header('Retry-After: ' . $extra['retry_after']);
		}
		nichan_api_fail($status, $code, $text, $extra);
	}
	if (stripos($text, 'database error') !== false)
		nichan_api_fail(500, 'server_error', strpos($config['locale'], 'en') === 0 ? 'Something went wrong on the site. Try again later.' : 'Щось пішло не так на сайті. Спробуйте пізніше.');
	// the message of $config['error'][key] (sprintf'ed ones too: their %s / {name} parts match anything)
	foreach ($codes as $key => $how) {
		if (!isset($config['error'][$key]))
			continue;
		$re = '/^' . preg_replace('/%(\d+\$)?[sdu]|\\\\\{[a-z_]+\\\\\}/', '.*', preg_quote(nichan_api_text($config['error'][$key]), '/')) . '$/su';
		if ($text === nichan_api_text($config['error'][$key]) || @preg_match($re, $text))
			nichan_api_fail($how[0], $how[1], $text);
	}
	nichan_api_fail(400, 'rejected', $text);
}

// checkBan() in API mode: what the app shows instead of the ban page (inc/functions.php)
function nichan_api_banned(array $ban) {
	global $config;
	$en = strpos($config['locale'], 'en') === 0;
	nichan_api_fail(403, 'banned', $en ? 'You are banned.' : 'Вас заблоковано.', array('ban' => array(
		'id' => (int)$ban['id'],
		'board' => $ban['board'] ? $ban['board'] : null,
		'reason' => $ban['reason'] !== null && $ban['reason'] !== '' ? nichan_api_text($ban['reason']) : null,
		'created' => (int)$ban['created'],
		'expires' => $ban['expires'] ? (int)$ban['expires'] : null,
		'appeal' => nichan_ban_appeal_state($ban['id']),
		'url' => $config['root'] . 'banned.php',
	)));
}
