<?php

/*
 * Push notifications about replies (Web Push, RFC 8030 / 8291 / 8292), without outside libraries.
 *
 *  - A visitor turns them on in a thread ("notify about replies to you…"); the browser gives a
 *    subscription (an address at Google / Mozilla / Apple / Microsoft and two keys), kept in
 *    ``nichan_push_subs`` together with the posts of that visitor (``nichan_push_posts``).
 *  - When a new post answers one of those posts (>>123), a notification goes to that subscription, at
 *    the end of the request. Nothing is sent to the author about their own reply to themselves.
 *  - The message is encrypted for the browser (aes128gcm) and signed with the site's VAPID key,
 *    made once and kept in the settings.
 */

defined('TINYBOARD') or exit;

function nichan_b64u_encode($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function nichan_b64u_decode($s) { return base64_decode(strtr((string)$s, '-_', '+/') . str_repeat('=', (4 - strlen((string)$s) % 4) % 4)); }

// Push services a subscription may point at (the site sends requests there, so nowhere else).
function nichan_push_endpoint_ok($url) {
	return is_string($url) && strlen($url) < 1000 && preg_match('~^https://(fcm\.googleapis\.com|(updates\.)?push\.services\.mozilla\.com|web\.push\.apple\.com|[a-z0-9.-]+\.push\.apple\.com|[a-z0-9.-]+\.notify\.windows\.com)/~', $url);
}

/* ---------------------------------------------------------------------- keys */

function nichan_ec_new_key() {
	return openssl_pkey_new(array('curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC));
}

// the uncompressed public point (65 bytes) of an EC key
function nichan_ec_public_raw($key) {
	$d = openssl_pkey_get_details($key);
	return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
}

// a public key from its 65-byte point
function nichan_ec_public_from_raw($raw) {
	if (strlen($raw) !== 65 || $raw[0] !== "\x04")
		return false;
	$der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
	return openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n");
}

// The site's VAPID key pair: array(private PEM, public point base64url), made on first use.
function nichan_push_vapid() {
	$pem = (string)nichan_setting('push_vapid_private', '');
	if ($pem === '' || !($key = openssl_pkey_get_private($pem))) {
		$key = nichan_ec_new_key();
		openssl_pkey_export($key, $pem);
		nichan_set_setting('push_vapid_private', $pem);
	}
	return array($pem, nichan_b64u_encode(nichan_ec_public_raw($key)));
}

// VAPID "Authorization" header for a push service (ES256 JWT)
function nichan_push_vapid_header($endpoint) {
	global $config;
	list($pem, $public) = nichan_push_vapid();
	$p = parse_url($endpoint);
	$input = nichan_b64u_encode(json_encode(array('typ' => 'JWT', 'alg' => 'ES256'))) . '.' .
		nichan_b64u_encode(json_encode(array('aud' => $p['scheme'] . '://' . $p['host'], 'exp' => time() + 12 * 3600,
			'sub' => rtrim($config['nichan_public_url'], '/'))));
	openssl_sign($input, $der, $pem, OPENSSL_ALGO_SHA256);
	// DER (SEQUENCE of two INTEGERs) -> r || s, 32 bytes each
	$pos = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7f : 0);
	$sig = '';
	for ($i = 0; $i < 2; $i++) {
		$len = ord($der[$pos + 1]);
		$int = ltrim(substr($der, $pos + 2, $len), "\0");
		$sig .= str_pad($int, 32, "\0", STR_PAD_LEFT);
		$pos += 2 + $len;
	}
	return 'vapid t=' . $input . '.' . nichan_b64u_encode($sig) . ', k=' . $public;
}

/* ----------------------------------------------------------------- encryption */

// The body of a push message for a browser (RFC 8291, aes128gcm). $p256dh, $auth: raw bytes.
function nichan_push_encrypt($payload, $p256dh, $auth) {
	$ua_public = nichan_ec_public_from_raw($p256dh);
	if (!$ua_public || strlen($auth) !== 16)
		return false;
	$as_key = nichan_ec_new_key();
	$as_public = nichan_ec_public_raw($as_key);
	$shared = openssl_pkey_derive($ua_public, $as_key, 32);
	if ($shared === false)
		return false;
	$prk_key = hash_hmac('sha256', $shared, $auth, true);
	$ikm = hash_hmac('sha256', "WebPush: info\0" . $p256dh . $as_public . "\x01", $prk_key, true);
	$salt = random_bytes(16);
	$prk = hash_hmac('sha256', $ikm, $salt, true);
	$cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
	$nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);
	$tag = '';
	$ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
	if ($ct === false)
		return false;
	return $salt . pack('N', 4096) . chr(65) . $as_public . $ct . $tag;
}

/* -------------------------------------------------------------- subscriptions */

function nichan_push_sub_id($endpoint) {
	$query = prepare('SELECT `id` FROM ``nichan_push_subs`` WHERE `endpoint_hash` = :h');
	$query->bindValue(':h', hash('sha256', $endpoint));
	$query->execute() or error(db_error($query));
	return (int)$query->fetchColumn();
}

function nichan_push_subscribe($endpoint, $p256dh, $auth, $lang) {
	$query = prepare('INSERT INTO ``nichan_push_subs`` (`endpoint_hash`, `endpoint`, `p256dh`, `auth`, `lang`, `created`, `seen`)
		VALUES (:h, :e, :p, :a, :l, :t, :t2)
		ON DUPLICATE KEY UPDATE `endpoint` = VALUES(`endpoint`), `p256dh` = VALUES(`p256dh`), `auth` = VALUES(`auth`), `lang` = VALUES(`lang`), `seen` = VALUES(`seen`), `fails` = 0');
	$query->bindValue(':h', hash('sha256', $endpoint));
	$query->bindValue(':e', $endpoint);
	$query->bindValue(':p', $p256dh);
	$query->bindValue(':a', $auth);
	$query->bindValue(':l', $lang === 'en' ? 'en' : 'uk');
	$query->bindValue(':t', time(), PDO::PARAM_INT);
	$query->bindValue(':t2', time(), PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	return nichan_push_sub_id($endpoint);
}

function nichan_push_unsubscribe($endpoint) {
	if (!($id = nichan_push_sub_id($endpoint)))
		return;
	query('DELETE FROM ``nichan_push_posts`` WHERE `sub` = ' . (int)$id);
	query('DELETE FROM ``nichan_push_subs`` WHERE `id` = ' . (int)$id);
}

// Remembers that these posts ([board, id], …) are the subscriber's (only posts that exist).
function nichan_push_own($sub, array $posts) {
	$by_board = array();
	foreach (array_slice($posts, 0, 300) as $p) {
		if (!is_array($p) || !isset($p[0], $p[1]) || !is_string($p[0]) ||
			!(is_int($p[1]) || (is_string($p[1]) && ctype_digit($p[1]))) || (int)$p[1] < 1)
			continue;
		$by_board[$p[0]][(int)$p[1]] = true;
	}
	foreach (listBoards() as $b) {
		$uri = $b['uri'];
		if (empty($by_board[$uri]) || !nichan_visibility_allows((int)$b['visibility']))
			continue;
		$ids = implode(',', array_keys($by_board[$uri]));
		$insert = prepare(sprintf('INSERT IGNORE INTO ``nichan_push_posts`` (`sub`, `board`, `post`, `time`)
			SELECT :s, :b, `id`, :t FROM ``posts_%s`` WHERE `id` IN (%s)', $uri, $ids));
		$insert->bindValue(':s', (int)$sub, PDO::PARAM_INT);
		$insert->bindValue(':b', $uri);
		$insert->bindValue(':t', time(), PDO::PARAM_INT);
		$insert->execute() or error(db_error($insert));
	}
	if (mt_rand(1, 50) == 1) // posts older than two months no longer notify
		query('DELETE FROM ``nichan_push_posts`` WHERE `time` < ' . (time() - 60 * 86400));
}

/* -------------------------------------------------------------------- sending */

// post.php, after a post was saved: the owners of the posts it answers get a notification.
function nichan_push_replies($board_uri, array $post) {
	global $config, $nichan_push_queue;
	if (!preg_match_all('/(?<![>\w])>>(\d+)/', (string)$post['body_nomarkup'], $m))
		return;
	$ids = array_values(array_unique(array_map('intval', $m[1])));
	$ids = array_slice(array_diff($ids, array((int)$post['id'])), 0, 20);
	// Subscriptions outlive visibility changes. Check access again before queuing any text.
	if (!$ids || !nichan_can_see_board($board_uri))
		return;
	$in = implode(',', $ids);
	// the answered posts, with their authors' IPs (no notice about a reply to oneself)
	$query = query(sprintf('SELECT `id`, `ip` FROM ``posts_%s`` WHERE `id` IN (%s)', $board_uri, $in)) or error(db_error());
	$ips = array();
	foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $r)
		$ips[(int)$r['id']] = $r['ip'];
	$query = prepare(sprintf('SELECT DISTINCT `s`.*, `p`.`post` AS `answered` FROM ``nichan_push_posts`` AS `p`
		JOIN ``nichan_push_subs`` AS `s` ON `s`.`id` = `p`.`sub` WHERE `p`.`board` = :b AND `p`.`post` IN (%s)', $in));
	$query->bindValue(':b', $board_uri);
	$query->execute() or error(db_error($query));
	$thread = $post['thread'] ? $post['thread'] : $post['id'];
	$url = rtrim($config['nichan_public_url'], '/') . $config['root'] . $board_uri . '/' . $config['dir']['res'] . $thread . '.html#' . $post['id'];
	$text = nichan_plain_text($post['body_nomarkup'], 140);
	$seen = array();
	foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $sub) {
		$answered = (int)$sub['answered'];
		if (isset($seen[$sub['id']]) || (isset($ips[$answered]) && $ips[$answered] === $_SERVER['REMOTE_ADDR']))
			continue;
		$seen[$sub['id']] = true;
		$en = $sub['lang'] === 'en';
		if ($nichan_push_queue === null) {
			$nichan_push_queue = array();
			register_shutdown_function('nichan_push_flush');
		}
		$nichan_push_queue[] = array($sub, array(
			'title' => $en ? "Reply to your post /$board_uri/ No.$answered" : "Відповідь на ваш допис /$board_uri/ №$answered",
			'body' => $text !== '' ? $text : ($en ? '(picture)' : '(картинка)'),
			'url' => $url,
			'tag' => "nichan-$board_uri-$thread",
		));
	}
}

function nichan_push_flush() {
	global $nichan_push_queue;
	if (!$nichan_push_queue)
		return;
	$queue = $nichan_push_queue;
	$nichan_push_queue = array();
	if (function_exists('fastcgi_finish_request'))
		@fastcgi_finish_request();
	elseif (function_exists('litespeed_finish_request'))
		@litespeed_finish_request();
	@ignore_user_abort(true);
	$multi = curl_multi_init();
	$handles = array();
	foreach ($queue as $item) {
		list($sub, $message) = $item;
		$body = nichan_push_encrypt(json_encode($message, JSON_UNESCAPED_UNICODE), nichan_b64u_decode($sub['p256dh']), nichan_b64u_decode($sub['auth']));
		if ($body === false || !nichan_push_endpoint_ok($sub['endpoint']))
			continue;
		$ch = curl_init($sub['endpoint']);
		curl_setopt_array($ch, array(
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 10,
			CURLOPT_HTTPHEADER => array(
				'Authorization: ' . nichan_push_vapid_header($sub['endpoint']),
				'Content-Encoding: aes128gcm',
				'Content-Type: application/octet-stream',
				'TTL: 86400',
				'Urgency: normal',
			),
		));
		curl_multi_add_handle($multi, $ch);
		$handles[] = array($ch, (int)$sub['id']);
	}
	do {
		$status = curl_multi_exec($multi, $running);
		if ($running)
			curl_multi_select($multi, 1);
	} while ($running && $status == CURLM_OK);
	foreach ($handles as $h) {
		list($ch, $id) = $h;
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($code == 404 || $code == 410) {
			// the browser dropped the subscription
			query('DELETE FROM ``nichan_push_posts`` WHERE `sub` = ' . $id);
			query('DELETE FROM ``nichan_push_subs`` WHERE `id` = ' . $id);
		} elseif ($code < 200 || $code >= 300) {
			query('UPDATE ``nichan_push_subs`` SET `fails` = `fails` + 1 WHERE `id` = ' . $id);
			query('DELETE FROM ``nichan_push_subs`` WHERE `id` = ' . $id . ' AND `fails` > 20');
		}
		curl_multi_remove_handle($multi, $ch);
		curl_close($ch);
	}
	curl_multi_close($multi);
}
