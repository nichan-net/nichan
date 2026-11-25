<?php

/*
 * Report notifications in Telegram (formerly the cron script telegram-reports.sh).
 *
 *  - a new report     -> "Нова скарга!" with the post link, file types, reason, IPs and the post's text
 *                        (the picture of its first file when it has one), and buttons: Видалити,
 *                        Видалити і забанити, Відхилити (telegram-webhook.php), Список скарг (link)
 *  - pressed in Telegram -> the bot answers under the report; closed from the admin panel ->
 *                        "Скаргу N опрацьовано з адмінки (who)" under the report, its buttons go away
 *
 * The bot token and chat are edited in mod.php (Board settings -> Integration, stored in the database);
 * inc/instance-config.php gives their first values and the optional extras:
 *   $config['telegram']['bot_token'] = '123456:ABC...';   // from @BotFather
 *   $config['telegram']['chat_id']   = '-100...';         // the moderators' chat
 *   $config['telegram']['known_reporters'] = array('1.2.3.4' => 'name');  // optional labels for IPs
 *   $config['telegram']['site_url']  = 'https://example.org';            // optional
 * Messages are sent after the page has been delivered, so nobody waits for Telegram.
 */

defined('TINYBOARD') or exit;

$nichan_telegram_queue = array();

function nichan_telegram_config($key, $default = null) {
	global $config;
	// The bot token and the chat are edited in mod.php (Board settings -> Integration) and kept in
	// the database; inc/instance-config.php only supplies their first values.
	if ($key === 'bot_token' || $key === 'chat_id') {
		$value = nichan_setting('telegram_' . $key);
		if ($value !== null)
			return $value !== '' ? $value : $default;
	}
	return isset($config['telegram'][$key]) && $config['telegram'][$key] !== '' ? $config['telegram'][$key] : $default;
}

function nichan_telegram_enabled() {
	return nichan_telegram_config('bot_token') && nichan_telegram_config('chat_id');
}

// Queue an API call; everything queued is sent at the end of the request. With $report_id, the id of
// the sent message is remembered, so that later answers about this report go right under it.
function nichan_telegram_queue($method, array $fields, $file = null, $report_id = null, $alert_id = null) {
	global $nichan_telegram_queue;
	if (!nichan_telegram_enabled())
		return;
	if (!$nichan_telegram_queue)
		register_shutdown_function('nichan_telegram_flush');
	$nichan_telegram_queue[] = array($method, $fields, $file, $report_id, $alert_id);
}

function nichan_telegram_flush() {
	global $nichan_telegram_queue;
	if (!$nichan_telegram_queue)
		return;
	if (function_exists('fastcgi_finish_request'))
		@fastcgi_finish_request();
	@ignore_user_abort(true);
	foreach ($nichan_telegram_queue as $call) {
		$res = nichan_telegram_call($call[0], $call[1], $call[2]);
		if (!empty($call[3]) && is_array($res) && isset($res['result']['message_id']))
			nichan_set_setting('tg_msg_' . (int)$call[3], (string)$res['result']['message_id']);
		if (!empty($call[4]) && is_array($res) && !empty($res['ok']) && isset($res['result']['message_id']))
			nichan_link_alert_sent((int)$call[4], (string)$res['result']['message_id']);
	}
	$nichan_telegram_queue = array();
}

// POST to the Telegram API with cURL: array(body or false, HTTP code, error text). When the connection itself
// fails (no DNS answer, no route, timeout) it tries once more over IPv4: hostings with a broken IPv6 route
// otherwise hang until the timeout on every call. Failures go to tmp/telegram-errors.log (not to the errors
// chat, which is Telegram itself). The bot token never gets into the log.
function nichan_telegram_curl($method, array $fields) {
	$url = 'https://api.telegram.org/bot' . nichan_telegram_config('bot_token') . '/' . $method;
	$try = function ($ipv4) use ($url, $fields) {
		$ch = curl_init($url);
		$opts = array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15);
		if ($ipv4)
			$opts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
		curl_setopt_array($ch, $opts);
		$body = curl_exec($ch);
		$res = array($body, (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), $body === false ? 'cURL ' . curl_errno($ch) . ': ' . curl_error($ch) : '', curl_errno($ch));
		curl_close($ch);
		return $res;
	};
	$res = $try(false);
	// 6 no DNS answer, 7 could not connect, 28 timeout
	if ($res[0] === false && in_array($res[3], array(6, 7, 28))) {
		$v4 = $try(true);
		if ($v4[0] !== false)
			$v4[2] = '';
		else
			$v4[2] = $res[2] . '; IPv4: ' . $v4[2];
		$res = $v4;
	}
	if ($res[0] === false || $res[1] != 200) {
		$data = $res[0] !== false ? json_decode($res[0], true) : null;
		$why = $res[0] === false ? $res[2] : 'HTTP ' . $res[1] . (isset($data['description']) ? ': ' . $data['description'] : '');
		$why = str_replace((string)nichan_telegram_config('bot_token'), '<token>', $why);
		@file_put_contents(dirname(__DIR__) . '/tmp/telegram-errors.log', date('Y-m-d H:i:s') . " $method: $why\n", FILE_APPEND | LOCK_EX);
		$res[2] = $why;
	}
	return array($res[0], $res[1], $res[2]);
}

// Returns Telegram's answer (array) when the call worked, false otherwise.
function nichan_telegram_call($method, array $fields, $file = null) {
	$fields['chat_id'] = nichan_telegram_config('chat_id');

	// Simulation / testing: write what would be sent instead of sending it.
	if (nichan_telegram_config('dry_run')) {
		@file_put_contents(dirname(__DIR__) . '/tmp/telegram-dry-run.log',
			date('c') . " $method" . ($file ? " [photo: $file]" : '') . "\n" . print_r($fields, true) . "\n", FILE_APPEND);
		return array('ok' => true, 'result' => array('message_id' => mt_rand(1000, 999999)));
	}

	$url = 'https://api.telegram.org/bot' . nichan_telegram_config('bot_token') . '/' . $method;

	if (function_exists('curl_init')) {
		if ($file && class_exists('CURLFile'))
			$fields['photo'] = new CURLFile($file);
		list($response, $code, $error) = nichan_telegram_curl($method, $fields);
		$ok = $response !== false && $code == 200;
		// Telegram refuses some pictures (too big, odd format): send the text alone then.
		// (Not when Telegram could not be reached at all: the text would not get through either.)
		if (!$ok && $file && $response !== false)
			return nichan_telegram_call('sendMessage', array('text' => $fields['caption']) +
				(isset($fields['reply_markup']) ? array('reply_markup' => $fields['reply_markup']) : array()));
		$data = $ok ? json_decode($response, true) : null;
		return is_array($data) ? $data : ($ok ? array('ok' => true) : false);
	}

	// No cURL: plain HTTP POST without the picture.
	if ($file) {
		$method = 'sendMessage';
		$url = 'https://api.telegram.org/bot' . nichan_telegram_config('bot_token') . '/sendMessage';
		$fields = array('chat_id' => $fields['chat_id'], 'text' => $fields['caption']);
	}
	$ctx = stream_context_create(array('http' => array(
		'method' => 'POST',
		'header' => 'Content-Type: application/x-www-form-urlencoded',
		'content' => http_build_query($fields),
		'timeout' => 15,
		'ignore_errors' => true,
	)));
	$raw = @file_get_contents($url, false, $ctx);
	$data = $raw !== false ? json_decode($raw, true) : null;
	return is_array($data) && !empty($data['ok']) ? $data : false;
}

// "Notes: ..." (what the moderators noted about the IP in mod.php?/IP/...), or ''
function nichan_telegram_notes_line($ip) {
	$notes = nichan_ip_notes($ip, 2);
	if (!$notes)
		return '';
	$texts = array_map(function ($n) { return mb_substr(trim(html_entity_decode(strip_tags($n['body']), ENT_QUOTES, 'UTF-8')), 0, 100); }, $notes);
	$more = nichan_ip_notes_count($ip) - count($notes);
	return "\nNotes: " . implode(' · ', $texts) . ($more > 0 ? " (+$more)" : '');
}

function nichan_telegram_ip_label($ip) {
	$known = nichan_telegram_config('known_reporters', array());
	return isset($known[$ip]) ? "$ip ({$known[$ip]})" : $ip;
}

// Called right after a report row was inserted.
function nichan_telegram_report_new($report_id, $board_uri, $post_id, $reason, $reporter_ip) {
	global $config;
	if (!nichan_telegram_enabled())
		return;

	$query = prepare(sprintf('SELECT `thread`, `body_nomarkup`, `files`, `embed`, `ip` FROM ``posts_%s`` WHERE `id` = :id', $board_uri));
	$query->bindValue(':id', $post_id, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	if (!$post = $query->fetch(PDO::FETCH_ASSOC))
		return;

	$site = nichan_site_url();
	$link = $post['thread'] ? "$site/$board_uri/res/{$post['thread']}.html#$post_id" : "$site/$board_uri/res/$post_id.html";

	$photo = null;
	$types = array();
	foreach ((array)json_decode($post['files'], true) as $f) {
		if (!is_array($f) || empty($f['file']) || $f['file'] == 'deleted')
			continue;
		$ext = strtolower(pathinfo($f['file'], PATHINFO_EXTENSION));
		$types[] = $ext;
		$path = sprintf($config['board_path'], $board_uri) . $config['dir']['img'] . $f['file'];
		if (!$photo && in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp')) && is_file($path))
			$photo = realpath($path);
	}

	$body = nichan_telegram_body_preview((string)$post['body_nomarkup']);
	$reason = trim(html_entity_decode(strip_tags($reason), ENT_QUOTES, 'UTF-8'));
	$head = "Нова скарга!\nReport ID: $report_id\nPost: $link\n" .
		($types ? 'Filetypes: ' . implode(' ', $types) . "\n" : '') .
		(mb_strlen((string)$post['embed']) > 1 ? 'Embed: ' . $post['embed'] . "\n" : '') .
		"Reason: $reason\nPoster IP: " . nichan_telegram_ip_label($post['ip']) . nichan_telegram_notes_line($post['ip']) . "\nReporter IP: " . nichan_telegram_ip_label($reporter_ip) .
		"\n-------------------------------\n";

	$keyboard = array('reply_markup' => nichan_telegram_report_keyboard($report_id));
	if ($photo) {
		// a caption holds at most 1024 characters: the post text gets what is left
		$room = 1024 - mb_strlen($head);
		$caption = $head . (mb_strlen($body) > $room ? rtrim(mb_substr($body, 0, max(0, $room - 1))) . '…' : $body);
		nichan_telegram_queue('sendPhoto', array('caption' => $caption) + $keyboard, $photo, $report_id);
	} else {
		nichan_telegram_queue('sendMessage', array('text' => mb_substr($head . $body, 0, 4096)) + $keyboard, null, $report_id);
	}
}

// A banned poster appealed (post.php): the ban, the appeal, buttons «Зняти бан» / «Відхилити» and the list of appeals.
function nichan_telegram_appeal_new($appeal_id, array $ban, $text) {
	if (!nichan_telegram_enabled())
		return;
	$site = nichan_site_url();
	$mask = Bans::range_to_string(array($ban['ipstart'], $ban['ipend']));
	$msg = "⚖️ Оскарження бану (№$appeal_id)\n" .
		'IP: ' . nichan_telegram_ip_label($mask) . nichan_telegram_notes_line($mask) . "\n" .
		'Дошка: ' . ($ban['board'] ? '/' . $ban['board'] . '/' : 'усі') . "\n" .
		'Причина бану: ' . (trim((string)$ban['reason']) !== '' ? trim(html_entity_decode(strip_tags($ban['reason']), ENT_QUOTES, 'UTF-8')) : '—') . "\n" .
		'Видано: ' . date('d.m.Y H:i', $ban['created']) . (!empty($ban['username']) ? ' (' . $ban['username'] . ')' : '') .
		' · до: ' . ($ban['expires'] ? date('d.m.Y H:i', $ban['expires']) : 'назавжди') . "\n" .
		"-------------------------------\n" . $text;
	$buttons = nichan_setting('telegram_webhook_secret', '') !== ''
		? array(array(array('text' => 'Зняти бан', 'callback_data' => 'a:ok:' . (int)$appeal_id), array('text' => 'Відхилити', 'callback_data' => 'a:no:' . (int)$appeal_id)))
		: array();
	$buttons[] = array(array('text' => 'Апеляції', 'url' => $site . '/mod.php?/ban-appeals'));
	nichan_telegram_queue('sendMessage', array('text' => mb_substr($msg, 0, 4000), 'disable_web_page_preview' => 'true',
		'reply_markup' => json_encode(array('inline_keyboard' => $buttons), JSON_UNESCAPED_UNICODE)));
}

// The buttons under an appeal (telegram-webhook.php): lift the ban, or reject the appeal (the ban then stays for good).
function nichan_telegram_appeal_decide($appeal_id, $lift, $who_html) {
	$query = query('SELECT ``ban_appeals``.`id`, `ban_id`, `denied`, `ipstart`, `ipend` FROM ``ban_appeals``
		LEFT JOIN ``bans`` ON ``bans``.`id` = `ban_id` WHERE ``ban_appeals``.`id` = ' . (int)$appeal_id) or error(db_error());
	$row = $query->fetch(PDO::FETCH_ASSOC);
	if (!$row || $row['ipstart'] === null)
		return 'Цього оскарження вже немає (бан зняли чи строк минув).';
	if ($row['denied'])
		return 'Це оскарження вже відхилили.';
	$mask = Bans::range_to_string(array($row['ipstart'], $row['ipend']));
	if ($lift) {
		Bans::delete($row['ban_id'], false);
		query('DELETE FROM ``ban_appeals`` WHERE `id` = ' . (int)$row['id']) or error(db_error());
		modLog("Telegram ($who_html): Accepted ban appeal #{$row['id']} for $mask");
		return "Бан знято ($mask)";
	}
	query('UPDATE ``ban_appeals`` SET `denied` = 1 WHERE `id` = ' . (int)$row['id']) or error(db_error());
	modLog("Telegram ($who_html): Denied ban appeal #{$row['id']} for $mask");
	return "Оскарження відхилено ($mask)";
}

// The post's text as the board page shows it: long posts are cut the same way (lines, then characters).
function nichan_telegram_body_preview($text) {
	global $config;
	$body = trim(remove_modifiers($text));
	$cut = false;
	$lines = preg_split('/\r?\n/', $body);
	if (!empty($config['body_truncate']) && count($lines) > $config['body_truncate']) {
		$body = implode("\n", array_slice($lines, 0, $config['body_truncate']));
		$cut = true;
	}
	if (!empty($config['body_truncate_char']) && mb_strlen($body) > $config['body_truncate_char']) {
		$body = mb_substr($body, 0, $config['body_truncate_char']);
		$cut = true;
	}
	return $cut ? rtrim($body) . '…' : $body;
}

/* ---------------------------------------------------- buttons under a report */

function nichan_telegram_reports_url() {
	global $config;
	return rtrim($config['nichan_public_url'], '/') . $config['root'] . $config['file_mod'] . '?/reports';
}

// "Список скарг" always; the moderation buttons once the webhook is connected (Board settings -> Integration).
function nichan_telegram_report_keyboard($report_id, $with_actions = null) {
	if ($with_actions === null)
		$with_actions = nichan_setting('telegram_webhook_secret', '') !== '';
	$list = array('text' => 'Список скарг', 'url' => nichan_telegram_reports_url());
	$rows = $with_actions ? array(
		array(array('text' => 'Видалити', 'callback_data' => 'r:del:' . (int)$report_id),
			array('text' => 'Видалити і забанити', 'callback_data' => 'r:ban:' . (int)$report_id)),
		array(array('text' => 'Видалити все з цього IP', 'callback_data' => 'r:ipask:' . (int)$report_id),
			array('text' => 'Закрити нитку', 'callback_data' => 'r:lock:' . (int)$report_id)),
		array(array('text' => 'Інші дописи автора', 'callback_data' => 'r:posts:' . (int)$report_id)),
		array(array('text' => 'Відхилити', 'callback_data' => 'r:dis:' . (int)$report_id), $list),
	) : array(array($list));
	return json_encode(array('inline_keyboard' => $rows), JSON_UNESCAPED_UNICODE);
}

// Telegram API call that returns Telegram's answer (array), for the webhook and its setup.
function nichan_telegram_api($method, array $fields) {
	if (nichan_telegram_config('dry_run')) {
		@file_put_contents(dirname(__DIR__) . '/tmp/telegram-dry-run.log', date('c') . " $method\n" . print_r($fields, true) . "\n", FILE_APPEND);
		if ($method == 'getWebhookInfo')
			return array('ok' => true, 'result' => array('url' => nichan_setting('telegram_webhook_url', ''), 'pending_update_count' => 0));
		return array('ok' => true, 'result' => true);
	}
	$token = nichan_telegram_config('bot_token');
	if (!$token)
		return array('ok' => false, 'description' => 'no bot token');
	list($raw, $code, $error) = nichan_telegram_curl($method, $fields);
	$data = $raw ? json_decode($raw, true) : null;
	return is_array($data) ? $data : array('ok' => false, 'description' => 'no answer from Telegram' . ($error !== '' ? " ($error)" : ''));
}

function nichan_telegram_webhook_url() {
	global $config;
	return rtrim($config['nichan_public_url'], '/') . $config['root'] . 'telegram-webhook.php';
}

// Connect the buttons: Telegram will POST button presses to telegram-webhook.php with a secret header.
function nichan_telegram_connect_webhook() {
	$secret = nichan_setting('telegram_webhook_secret', '');
	if ($secret === '')
		$secret = bin2hex(random_bytes(24));
	$url = nichan_telegram_webhook_url();
	$res = nichan_telegram_api('setWebhook', array('url' => $url, 'secret_token' => $secret,
		'allowed_updates' => json_encode(array('callback_query')), 'drop_pending_updates' => 'true'));
	if (!empty($res['ok'])) {
		nichan_set_setting('telegram_webhook_secret', $secret);
		nichan_set_setting('telegram_webhook_url', $url);
	}
	return $res;
}

function nichan_telegram_webhook_info() {
	if (!nichan_telegram_enabled())
		return null;
	$res = nichan_telegram_api('getWebhookInfo', array());
	if (empty($res['ok']))
		return array('connected' => false, 'error' => isset($res['description']) ? $res['description'] : '?');
	$r = $res['result'];
	return array(
		'connected' => !empty($r['url']) && $r['url'] === nichan_telegram_webhook_url() && nichan_setting('telegram_webhook_secret', '') !== '',
		'url' => isset($r['url']) ? $r['url'] : '',
		'error' => isset($r['last_error_message']) ? $r['last_error_message'] . (isset($r['last_error_date']) ? ' (' . date('d.m H:i', $r['last_error_date']) . ')' : '') : '',
		'pending' => isset($r['pending_update_count']) ? (int)$r['pending_update_count'] : 0,
	);
}

// telegram-webhook.php: a button under a report was pressed in the moderators' chat.
function nichan_telegram_handle_update(array $update) {
	global $config, $mod, $board;
	if (empty($update['callback_query']))
		return;
	$cq = $update['callback_query'];
	$answer = function ($text) use ($cq) {
		nichan_telegram_api('answerCallbackQuery', array('callback_query_id' => $cq['id'], 'text' => $text));
	};
	$chat = isset($cq['message']['chat']['id']) ? (string)$cq['message']['chat']['id'] : '';
	// anyone in the (closed) moderators' chat may press the buttons, nobody elsewhere
	if ($chat === '' || $chat !== (string)nichan_telegram_config('chat_id')) {
		$answer('Ці кнопки працюють лише в чаті модераторів.');
		return;
	}
	if (strpos(isset($cq['data']) ? $cq['data'] : '', 'l:') === 0) {
		nichan_link_alert_callback($cq, $answer, $chat);
		return;
	}
	// a new thread waiting for a moderator (premoderation): approve or reject
	if (preg_match('/^p:(ok|no):(\d+)$/', isset($cq['data']) ? $cq['data'] : '', $pm)) {
		$u = isset($cq['from']) ? $cq['from'] : array();
		$who = !empty($u['username']) ? '@' . $u['username'] : trim((isset($u['first_name']) ? $u['first_name'] : '') . ' ' . (isset($u['last_name']) ? $u['last_name'] : ''));
		$who = $who !== '' ? $who : 'id ' . (isset($u['id']) ? $u['id'] : '?');
		$who_html = utf8tohtml($who);
		$mod = array('id' => -1, 'username' => 'Telegram ' . $who_html, 'type' => ADMIN, 'boards' => array('*'));
		$row = nichan_premod_get((int)$pm[2]);
		if (!$row)
			$text = 'Цю нитку вже перевірили.';
		elseif ($pm[1] === 'ok' && ($pid = nichan_premod_approve((int)$pm[2]))) {
			modLog("Telegram ($who_html): Approved a waiting thread (#$pid)", $row['board']);
			$text = "Нитку схвалено: " . nichan_site_url() . "/{$row['board']}/res/$pid.html — $who";
		} elseif ($pm[1] === 'no' && nichan_premod_reject((int)$pm[2])) {
			modLog("Telegram ($who_html): Rejected a waiting thread (queue #{$pm[2]})", $row['board']);
			$text = "Нитку відхилено — $who";
		} else
			$text = 'Не вдалося.';
		$answer($text);
		if (isset($cq['message']['message_id']))
			nichan_telegram_api('editMessageReplyMarkup', array('chat_id' => $chat, 'message_id' => $cq['message']['message_id'], 'reply_markup' => json_encode(array('inline_keyboard' => array()))));
		nichan_telegram_api('sendMessage', array('chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => 'true',
			'reply_to_message_id' => isset($cq['message']['message_id']) ? $cq['message']['message_id'] : null, 'allow_sending_without_reply' => 'true'));
		return;
	}
	// a ban appeal: lift the ban or reject the appeal
	if (preg_match('/^a:(ok|no):(\d+)$/', isset($cq['data']) ? $cq['data'] : '', $am)) {
		$u = isset($cq['from']) ? $cq['from'] : array();
		$who = !empty($u['username']) ? '@' . $u['username'] : trim((isset($u['first_name']) ? $u['first_name'] : '') . ' ' . (isset($u['last_name']) ? $u['last_name'] : ''));
		$who = $who !== '' ? $who : 'id ' . (isset($u['id']) ? $u['id'] : '?');
		require_once 'inc/bans.php';
		$text = nichan_telegram_appeal_decide((int)$am[2], $am[1] === 'ok', utf8tohtml($who)) . ' — ' . $who;
		$answer($text);
		if (isset($cq['message']['message_id']))
			nichan_telegram_api('editMessageReplyMarkup', array('chat_id' => $chat, 'message_id' => $cq['message']['message_id'], 'reply_markup' => json_encode(array('inline_keyboard' => array()))));
		nichan_telegram_api('sendMessage', array('chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => 'true',
			'reply_to_message_id' => isset($cq['message']['message_id']) ? $cq['message']['message_id'] : null, 'allow_sending_without_reply' => 'true'));
		return;
	}
	if (!preg_match('/^r:(del|ban|dis|ipask|ipdel|lock|posts|no):(\d+)$/', isset($cq['data']) ? $cq['data'] : '', $m)) {
		$answer('Невідома кнопка.');
		return;
	}
	list(, $action, $report_id) = $m;
	$u = isset($cq['from']) ? $cq['from'] : array();
	$who = !empty($u['username']) ? '@' . $u['username'] : trim((isset($u['first_name']) ? $u['first_name'] : '') . ' ' . (isset($u['last_name']) ? $u['last_name'] : ''));
	$who = $who !== '' ? $who : 'id ' . (isset($u['id']) ? $u['id'] : '?');
	// the moderation log shows its text as HTML: a Telegram name is anybody's text
	$who_html = utf8tohtml($who);
	$message_id = isset($cq['message']['message_id']) ? $cq['message']['message_id'] : null;
	$done = function ($text) use ($answer, $message_id, $chat, $report_id) {
		$answer($text);
		if ($message_id) {
			// the moderation buttons go away ("Список скарг" stays), the result is written under the report
			nichan_telegram_api('editMessageReplyMarkup', array('chat_id' => $chat, 'message_id' => $message_id,
				'reply_markup' => nichan_telegram_report_keyboard($report_id, false)));
			nichan_telegram_api('sendMessage', array('chat_id' => $chat, 'text' => $text, 'reply_to_message_id' => $message_id,
				'allow_sending_without_reply' => 'true'));
		}
	};
	// the reports closed below are answered here, not by nichan_telegram_reports_processed()
	global $nichan_telegram_source;
	$nichan_telegram_source = 'telegram';

	$query = prepare('SELECT * FROM ``reports`` WHERE `id` = :id');
	$query->bindValue(':id', (int)$report_id, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	if (!$report = $query->fetch(PDO::FETCH_ASSOC)) {
		$done("Скаргу $report_id уже оброблено.");
		return;
	}

	// acts as an admin named after the Telegram user (so the moderation log says who did it)
	$mod = array('id' => -1, 'username' => 'Telegram ' . $who_html, 'type' => ADMIN, 'boards' => array('*'));
	if ($action == 'dis') {
		nichan_reports_delete('`id` = :id', array(':id' => (int)$report_id));
		modLog("Telegram ($who_html): dismissed report #$report_id");
		$done("Скаргу $report_id відхилено — $who");
		return;
	}

	if (!openBoard($report['board'])) {
		nichan_reports_delete('`id` = :id', array(':id' => (int)$report_id));
		$done("Дошки /{$report['board']}/ вже немає, скаргу $report_id закрито.");
		return;
	}
	$query = prepare(sprintf('SELECT `id`, `thread`, `ip` FROM ``posts_%s`` WHERE `id` = :id', $board['uri']));
	$query->bindValue(':id', (int)$report['post'], PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	if (!$post = $query->fetch(PDO::FETCH_ASSOC)) {
		nichan_reports_delete('`id` = :id', array(':id' => (int)$report_id));
		$done("Пост /{$board['uri']}/{$report['post']} уже видалено, скаргу $report_id закрито.");
		return;
	}

	$thread_id = $post['thread'] ? (int)$post['thread'] : (int)$post['id'];
	$reply = function ($text, $markup = null) use ($chat, $message_id) {
		$fields = array('chat_id' => $chat, 'text' => $text, 'allow_sending_without_reply' => 'true', 'disable_web_page_preview' => 'true');
		if ($message_id)
			$fields['reply_to_message_id'] = $message_id;
		if ($markup)
			$fields['reply_markup'] = $markup;
		nichan_telegram_api('sendMessage', $fields);
	};
	if ($action == 'posts') {
		// the author's latest posts on all boards (the report stays open)
		$list = nichan_telegram_posts_by_ip($post['ip'], 10);
		$answer('Надсилаю список');
		$reply($list ? "Останні дописи автора ($who):\n\n" . implode("\n\n", $list) : 'Інших дописів цього автора немає.');
		return;
	}
	if ($action == 'lock') {
		$query = prepare(sprintf('UPDATE ``posts_%s`` SET `locked` = 1 WHERE `id` = :id AND `thread` IS NULL', $board['uri']));
		$query->bindValue(':id', $thread_id, PDO::PARAM_INT);
		$query->execute() or error(db_error($query));
		buildThread($thread_id);
		buildIndex();
		modLog("Telegram ($who_html): locked thread #$thread_id", $board['uri']);
		$answer('Нитку закрито');
		$reply("Нитку /{$board['uri']}/$thread_id закрито — $who");
		return;
	}
	if ($action == 'ipask' || $action == 'no') {
		if ($action == 'no') {
			$answer('Скасовано');
			if ($message_id)
				nichan_telegram_api('deleteMessage', array('chat_id' => $chat, 'message_id' => $message_id));
			return;
		}
		// deleting everything is asked once more, with the number of posts
		$count = array_sum(array_map('count', nichan_telegram_post_ids_by_ip($post['ip'])));
		$answer('Потрібне підтвердження');
		$reply("Видалити всі дописи цього автора на всіх дошках: $count шт.?", json_encode(array('inline_keyboard' => array(array(
			array('text' => "Так, видалити $count", 'callback_data' => 'r:ipdel:' . (int)$report_id),
			array('text' => 'Ні', 'callback_data' => 'r:no:' . (int)$report_id)))), JSON_UNESCAPED_UNICODE));
		return;
	}
	if ($action == 'ipdel') {
		$deleted = 0;
		rebuild_batch_begin();
		foreach (nichan_telegram_post_ids_by_ip($post['ip']) as $uri => $ids) {
			if (!openBoard($uri))
				continue;
			foreach ($ids as $id) {
				deletePost($id, false);
				$deleted++;
			}
			buildIndex();
		}
		rebuild_batch_end();
		modLog("Telegram ($who_html): deleted all $deleted posts of the author of /{$report['board']}/{$report['post']}");
		$answer("Видалено $deleted");
		if ($message_id)
			nichan_telegram_api('editMessageText', array('chat_id' => $chat, 'message_id' => $message_id,
				'text' => "Видалено всі дописи автора: $deleted шт. — $who"));
		return;
	}

	$text = '';
	if ($action == 'ban') {
		require_once 'inc/bans.php';
		$length = (int)nichan_setting('telegram_ban_length', 604800);
		$reason = trim(html_entity_decode(strip_tags((string)$report['reason']), ENT_QUOTES, 'UTF-8'));
		Bans::new_ban($post['ip'], $reason, $length > 0 ? $length : false, false, -1);
		$text = ' і забанено ' . nichan_telegram_ban_length_label($length);
		modLog("Telegram ($who_html): banned the author of /{$board['uri']}/{$post['id']} " . ($length > 0 ? "for {$length}s" : 'forever'), $board['uri']);
	}
	// deleting the post also closes its reports (and announces them as processed)
	deletePost($post['id']);
	modLog("Telegram ($who_html): deleted post #{$post['id']} (report #$report_id)", $board['uri']);
	$done("Пост /{$board['uri']}/{$post['id']} видалено$text — $who");
}

// ids of the posts from an IP, per board (all boards)
function nichan_telegram_post_ids_by_ip($ip) {
	$ids = array();
	foreach (listBoards() as $b) {
		$query = prepare(sprintf('SELECT `id` FROM ``posts_%s`` WHERE `ip` = :ip ORDER BY `thread` IS NULL, `id` DESC', $b['uri']));
		$query->bindValue(':ip', $ip);
		$query->execute() or error(db_error($query));
		if ($found = $query->fetchAll(PDO::FETCH_COLUMN))
			$ids[$b['uri']] = $found;
	}
	return $ids;
}

// the latest posts from an IP as lines "link\ntext" for a Telegram message
function nichan_telegram_posts_by_ip($ip, $limit) {
	$site = nichan_site_url();
	$rows = array();
	foreach (listBoards() as $b) {
		$query = prepare(sprintf('SELECT `id`, `thread`, `time`, `body_nomarkup` FROM ``posts_%s`` WHERE `ip` = :ip ORDER BY `time` DESC LIMIT %d', $b['uri'], $limit));
		$query->bindValue(':ip', $ip);
		$query->execute() or error(db_error($query));
		foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $r) {
			$r['board'] = $b['uri'];
			$rows[] = $r;
		}
	}
	usort($rows, function ($a, $b) { return $b['time'] - $a['time']; });
	return array_map(function ($r) use ($site) {
		$link = "$site/{$r['board']}/res/" . ($r['thread'] ?: $r['id']) . '.html#' . $r['id'];
		return date('d.m H:i', $r['time']) . " $link\n" . nichan_plain_text($r['body_nomarkup'], 150);
	}, array_slice($rows, 0, $limit));
}

function nichan_telegram_ban_length_label($seconds) {
	$labels = array(86400 => 'на добу', 604800 => 'на тиждень', 2592000 => 'на 30 днів', 0 => 'назавжди');
	return isset($labels[$seconds]) ? $labels[$seconds] : 'на ' . round($seconds / 86400) . ' дн.';
}

/*
 * Reports that were closed: from Telegram the bot has already answered under the report, so nothing
 * more is sent; from the admin panel (or when the post went some other way) a short note goes right
 * under the report, and its moderation buttons are taken away.
 */
function nichan_telegram_reports_processed(array $ids) {
	global $nichan_telegram_source, $mod;
	foreach ($ids as $id) {
		$msg = nichan_setting('tg_msg_' . (int)$id);
		if ($msg !== null)
			nichan_delete_setting('tg_msg_' . (int)$id);
		if ($nichan_telegram_source === 'telegram')
			continue;
		$text = !empty($mod['username']) && $mod['id'] != -1
			? "Скаргу $id опрацьовано з адмінки ({$mod['username']})"
			: "Скаргу $id закрито: пост видалено автором або при очищенні старих ниток";
		$fields = array('text' => $text);
		if ($msg !== null) {
			$fields['reply_to_message_id'] = $msg;
			$fields['allow_sending_without_reply'] = 'true';
			nichan_telegram_queue('editMessageReplyMarkup', array('message_id' => $msg,
				'reply_markup' => nichan_telegram_report_keyboard($id, false)));
		}
		nichan_telegram_queue('sendMessage', $fields);
	}
}

/*
 * Delete reports matching $where (SQL with named placeholders from $binds) and announce each one
 * as processed. Every place that removes reports goes through here.
 */
function nichan_reports_delete($where, array $binds) {
	$select = prepare('SELECT `id` FROM ``reports`` WHERE ' . $where);
	$delete = prepare('DELETE FROM ``reports`` WHERE ' . $where);
	foreach ($binds as $name => $value) {
		$type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
		$select->bindValue($name, $value, $type);
		$delete->bindValue($name, $value, $type);
	}
	$select->execute() or error(db_error($select));
	$ids = $select->fetchAll(PDO::FETCH_COLUMN);
	if (!$ids)
		return 0;
	$delete->execute() or error(db_error($delete));
	nichan_telegram_reports_processed($ids);
	return count($ids);
}
