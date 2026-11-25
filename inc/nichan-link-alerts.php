<?php
/* Link alerts: a text-only signal for humans, never a verdict or a publication gate. */
defined('TINYBOARD') or exit;

function nichan_normalize_link_text($text) {
	$text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	// Decode common percent escapes once, without ever fetching a URL.
	$text = rawurldecode($text);
	if (!mb_check_encoding($text, 'UTF-8'))
		$text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
	$text = mb_strtolower($text, 'UTF-8');
	$text = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{FEFF}]/u', '', $text);
	$text = strtr($text, array('。' => '.', '．' => '.', '｡' => '.', '：' => ':', '／' => '/'));
	$text = preg_replace('/\h*[\[(\{]\s*(?:dot|крапка|точка|\.)\s*[\])\}]\h*/u', '.', $text);
	$text = preg_replace('/\s+(?:dot|крапка|точка)\s+/u', '.', $text);
	$text = preg_replace('/\s+\.\s*/u', '.', $text);
	return $text;
}

function nichan_link_alert_has_link($text) {
	$text = nichan_normalize_link_text($text);
	if (strpos($text, 'youtu') !== false) {
		// Remove only the YouTube address. Leave the surrounding text and URL parameters
		// for the usual detector, including any other destination in the same post.
		$host = '(?:[a-z0-9-]+\.)*(?:youtube\.com|youtu\.be|youtube-nocookie\.com)';
		$end = '(?=[/?#]|[.!:]*+(?:[\s<>"\'«»“”()\[\]{},;|*…]|$))';
		$direct = '~(?<![\p{L}\p{N}_.@/-])(?:https?://|(?<!:)//)?' . $host . '(?::[0-9]+)?' . $end
			. '(?!/(?:redirect|attribution_link)\b)(?:/(?:@|user/|c/)[\p{L}\p{N}_.-]+)?~u';
		$text = preg_replace($direct, ' ', $text);
	}
	if (preg_match('~\b(?:https?|hxxps?|ftp)\s*:\s*/\s*/|\bwww\.|\b(?:https?|hxxps?)\s*\[:\]\s*//~u', $text))
		return true;
	// Bare domains (including shorteners and IDNs); not decimal numbers, dates or board citations.
	return (bool)preg_match('~(?<![\p{L}\p{N}_-])(?:[\p{L}\p{N}](?:[\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?\.)+(?:[\p{L}]{2,63}|xn--[a-z0-9-]{2,59})(?![\p{L}\p{N}_-])~u', $text);
}

function nichan_link_alert_keyboard($id) {
	return json_encode(array('inline_keyboard' => array(
		array(array('text' => 'Видалити', 'callback_data' => 'l:del:' . (int)$id),
			array('text' => 'Видалити+забанити', 'callback_data' => 'l:ban:' . (int)$id)),
		array(array('text' => 'Відхилити', 'callback_data' => 'l:dis:' . (int)$id))
	)), JSON_UNESCAPED_UNICODE);
}

function nichan_link_alert_message($uri, $id, $thread, $time, $body) {
	$link = nichan_site_url() . '/' . rawurlencode($uri) . '/res/' . (int)$thread . '.html#' . (int)$id;
	$esc = function ($s) { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
	$date = new DateTimeImmutable('@' . (int)$time);
	$date = $date->setTimezone(new DateTimeZone('Europe/Kyiv'));
	$head = '<b>ПІДОЗРІЛИЙ ДОПИС</b>' . "\n\n" . '<a href="' . $esc($link) . '">/' . $esc($uri) . '/' . (int)$id . '</a> (тема ' . (int)$thread . ')'
		. "\nчас: " . $date->format('d.m.Y, H:i') . "\n\nтекст:\n";
	$footer = "\n\n<i>Цей бот реагує на дописи з прямими і прихованими посиланнями. Він не аналізує текст чи файл. Це твоя задача.</i>";
	// Code entities keep user-supplied links inert; escaping prevents injected Telegram HTML/buttons.
	// 3000 UTF-16 units also leaves room for the heading/footer and Telegram's 4096-unit limit.
	$body = trim(remove_modifiers((string)$body));
	if (strlen(mb_convert_encoding($body, 'UTF-16LE', 'UTF-8')) > 6000)
		$body = mb_convert_encoding(mb_strcut(mb_convert_encoding($body, 'UTF-16LE', 'UTF-8'), 0, 5998, 'UTF-16LE'), 'UTF-8', 'UTF-16LE') . '…';
	return $head . '<pre>' . $esc($body) . '</pre>' . $footer;
}

function nichan_link_alert_new($uri, $id, array $post) {
	global $pdo;
	$body = (string)(isset($post['body_nomarkup']) ? $post['body_nomarkup'] : '');
	if (!empty($post['subject']))
		$body = $post['subject'] . "\n\n" . $body;
	if (!nichan_telegram_enabled() || !nichan_link_alert_has_link($body))
		return;
	// Do not let a notification error turn an already accepted post into a failed submission.
	try {
		$q = prepare('INSERT IGNORE INTO ``nichan_link_alerts`` (`board`, `post`, `chat`, `created`) VALUES (:board, :post, :chat, :time)');
		$q->bindValue(':board', $uri);
		$q->bindValue(':post', (int)$id, PDO::PARAM_INT);
		$q->bindValue(':chat', (string)nichan_telegram_config('chat_id'));
		$q->bindValue(':time', time(), PDO::PARAM_INT);
		if (!$q->execute() || !$q->rowCount())
			return;
		$alert = (int)$pdo->lastInsertId();
		$thread = !empty($post['op']) ? $id : (int)$post['thread'];
		$fields = array('text' => nichan_link_alert_message($uri, $id, $thread, isset($post['time']) ? $post['time'] : time(), $body),
			'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true');
		if (nichan_setting('telegram_webhook_secret', '') !== '')
			$fields['reply_markup'] = nichan_link_alert_keyboard($alert);
		nichan_telegram_queue('sendMessage', $fields, null, null, $alert);
	} catch (Throwable $e) {
		error_log('Nichan: could not queue link alert for an accepted post.');
	}
}

function nichan_link_alert_sent($id, $message) {
	$q = prepare('UPDATE ``nichan_link_alerts`` SET `message` = :message WHERE `id` = :id');
	$q->bindValue(':message', $message);
	$q->bindValue(':id', (int)$id, PDO::PARAM_INT);
	$q->execute();
}

function nichan_link_alert_authorized($user_id, $chat) {
	if (!ctype_digit((string)$user_id) || (int)$user_id <= 0)
		return false;
	// Optional explicit moderator IDs for private groups whose moderators aren't Telegram admins.
	$allowed = array_map('strval', (array)nichan_telegram_config('moderator_user_ids', array()));
	if ($allowed)
		return in_array((string)$user_id, $allowed, true);
	// Merely viewing a channel message (or forwarding it) must not grant moderation rights.
	$r = nichan_telegram_api('getChatMember', array('chat_id' => $chat, 'user_id' => $user_id));
	return !empty($r['ok']) && isset($r['result']['status']) && in_array($r['result']['status'], array('creator', 'administrator'), true);
}

// Called only after the webhook secret and configured chat have been checked.
function nichan_link_alert_callback(array $cq, $answer, $chat) {
	global $config, $mod, $board;
	if (!preg_match('/^l:(del|ban|dis):([1-9][0-9]*)$/D', (string)$cq['data'], $m) || empty($cq['message']['message_id'])) {
		$answer('Невідома кнопка.');
		return;
	}
	if (!nichan_link_alert_authorized(isset($cq['from']['id']) ? $cq['from']['id'] : '', $chat)) {
		$answer('Ця дія доступна лише модераторам.');
		return;
	}
	$id = (int)$m[2];
	$lock_name = 'nichan-link-' . md5($config['db']['database'] . $config['db']['prefix']) . '-' . $id;
	$lock = prepare('SELECT GET_LOCK(:name, 0)');
	$lock->bindValue(':name', $lock_name);
	$lock->execute();
	if ((int)$lock->fetchColumn() !== 1) {
		$answer('Цей допис уже опрацьовують.');
		return;
	}
	$old_mod = $mod;
	try {
		$q = prepare('SELECT * FROM ``nichan_link_alerts`` WHERE `id` = :id');
		$q->bindValue(':id', $id, PDO::PARAM_INT);
		$q->execute();
		$row = $q->fetch(PDO::FETCH_ASSOC);
		if (!$row || (string)$row['chat'] !== $chat || (string)$row['message'] !== (string)$cq['message']['message_id']) {
			$answer('Сповіщення не знайдено.');
			return;
		}
		if ($row['status'] !== 'pending') {
			$answer('Це сповіщення вже опрацьовано.');
			return;
		}
		$u = $cq['from'];
		$who = !empty($u['username']) ? '@' . $u['username'] : 'id ' . $u['id'];
		$mod = array('id' => -1, 'username' => 'Telegram ' . utf8tohtml($who), 'type' => ADMIN, 'boards' => array('*'));
		$label = '/' . $row['board'] . '/' . $row['post'];
		$status = $m[1];
		if ($status === 'dis') {
			$text = 'Сповіщення відхилено';
		} elseif (!openBoard($row['board'])) {
			$text = 'Дошки вже немає';
			$status = 'gone';
		} else {
			$q = prepare(sprintf('SELECT `id`, `ip` FROM ``posts_%s`` WHERE `id` = :id', $board['uri']));
			$q->bindValue(':id', (int)$row['post'], PDO::PARAM_INT);
			$q->execute();
			$post = $q->fetch(PDO::FETCH_ASSOC);
			if (!$post) {
				$text = 'Допис уже видалено';
				$status = 'gone';
			} else {
				if ($status === 'ban' && !$row['ban_applied']) {
					require_once 'inc/bans.php';
					Bans::new_ban($post['ip'], 'Спам (рішення модератора)', false, false, -1);
					$q = prepare('UPDATE ``nichan_link_alerts`` SET `ban_applied` = 1 WHERE `id` = :id');
					$q->bindValue(':id', $id, PDO::PARAM_INT);
					$q->execute();
				}
				deletePost($post['id']);
				buildIndex();
				$text = 'Допис видалено' . ($status === 'ban' ? ', автора забанено назавжди' : '');
			}
		}
		$q = prepare('UPDATE ``nichan_link_alerts`` SET `status` = :status WHERE `id` = :id');
		$q->bindValue(':status', $status);
		$q->bindValue(':id', $id, PDO::PARAM_INT);
		$q->execute();
		modLog('Telegram (' . utf8tohtml($who) . '): link alert #' . $id . ' ' . $status . ' ' . utf8tohtml($label), $row['board']);
		$answer($text);
		nichan_telegram_api('editMessageReplyMarkup', array('chat_id' => $chat, 'message_id' => $row['message'], 'reply_markup' => json_encode(array('inline_keyboard' => array()))));
		nichan_telegram_api('sendMessage', array('chat_id' => $chat, 'text' => $text . ' — ' . $who,
			'reply_to_message_id' => $row['message'], 'allow_sending_without_reply' => 'true', 'disable_web_page_preview' => 'true'));
	} finally {
		$mod = $old_mod;
		$unlock = prepare('SELECT RELEASE_LOCK(:name)');
		$unlock->bindValue(':name', $lock_name);
		$unlock->execute();
	}
}
