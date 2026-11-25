<?php

/*
 * Translation of posts for the app (POST /api/v1/translate, API.md) through Microsoft Azure Translator (Text API v3.0).
 * The app names a post (board + number) and a language; the text is taken from the database, so the endpoint can't be
 * used to translate anything else. A translation is kept (nichan_translations) until the post is edited or deleted:
 * asking again costs nothing. What goes to Azure is limited per address and for the whole site per day and month.
 * The key, region and endpoint are set in mod.php -> Board settings -> Integration (stored in the database only).
 * Texts of posts are never written to logs.
 */

defined('TINYBOARD') or exit;

// The languages the app may ask for: Europe's (not Russian), Japanese and Chinese. Code => name in the language itself.
function nichan_translate_languages() {
	return array(
		'uk' => 'Українська', 'en' => 'English', 'pl' => 'Polski', 'de' => 'Deutsch', 'fr' => 'Français',
		'es' => 'Español', 'it' => 'Italiano', 'pt' => 'Português (Brasil)', 'pt-PT' => 'Português (Portugal)',
		'nl' => 'Nederlands', 'cs' => 'Čeština', 'sk' => 'Slovenčina', 'sl' => 'Slovenščina', 'hr' => 'Hrvatski',
		'bs' => 'Bosanski', 'sr-Latn' => 'Srpski (latinica)', 'sr-Cyrl' => 'Српски (ћирилица)', 'mk' => 'Македонски',
		'bg' => 'Български', 'ro' => 'Română', 'hu' => 'Magyar', 'el' => 'Ελληνικά', 'sq' => 'Shqip', 'be' => 'Беларуская',
		'lt' => 'Lietuvių', 'lv' => 'Latviešu', 'et' => 'Eesti', 'fi' => 'Suomi', 'sv' => 'Svenska', 'da' => 'Dansk',
		'nb' => 'Norsk Bokmål', 'is' => 'Íslenska', 'fo' => 'Føroyskt', 'ga' => 'Gaeilge', 'cy' => 'Cymraeg',
		'mt' => 'Malti', 'lb' => 'Lëtzebuergesch', 'ca' => 'Català', 'gl' => 'Galego', 'eu' => 'Euskara',
		'hsb' => 'Hornjoserbšćina', 'dsb' => 'Dolnoserbšćina', 'tr' => 'Türkçe', 'ka' => 'ქართული', 'hy' => 'Հայերեն',
		'az' => 'Azərbaycan', 'ja' => '日本語', 'zh-Hans' => '中文 (简体)', 'zh-Hant' => '繁體中文', 'yue' => '粵語',
	);
}

// The language as Azure spells it ("zh-hans", "ZH" -> "zh-Hans"), or null if it isn't offered.
function nichan_translate_language($code) {
	static $aliases = array('zh' => 'zh-Hans', 'zh-cn' => 'zh-Hans', 'zh-tw' => 'zh-Hant', 'zh-hk' => 'zh-Hant',
		'no' => 'nb', 'pt-br' => 'pt', 'sr' => 'sr-Latn', 'ua' => 'uk');
	$code = strtolower(trim((string)$code));
	if (isset($aliases[$code]))
		return $aliases[$code];
	foreach (array_keys(nichan_translate_languages()) as $lang) {
		if (strtolower($lang) === $code)
			return $lang;
	}
	return null;
}

function nichan_translate_ready() {
	return (string)nichan_setting('azure_translator_key', '') !== '';
}

const NICHAN_TRANSLATE_ENDPOINT = 'https://api.cognitive.microsofttranslator.com';

function nichan_translate_endpoint() {
	$e = rtrim((string)nichan_setting('azure_translator_endpoint', ''), '/');
	return $e !== '' ? $e : NICHAN_TRANSLATE_ENDPOINT;
}

// Only Azure's own addresses may be set in mod.php (the site sends the key there).
function nichan_translate_endpoint_valid($url) {
	return (bool)preg_match('~^https://([a-z0-9-]+\.)*(microsofttranslator\.com|cognitiveservices\.azure\.com|cognitive\.microsoft\.com)/?$~i', $url);
}

// The month's and the day's budgets of characters sent to Azure (mod.php; 2 million a month is Azure's free tier, F0).
function nichan_translate_budgets() {
	return array(
		'month' => (int)nichan_setting('translate_month_chars', 2000000),
		'day' => (int)nichan_setting('translate_day_chars', 200000),
	);
}

// what one address may send to Azure, characters per 10 minutes and per day; requests per 10 minutes (kept ones too)
const NICHAN_TRANSLATE_IP_10MIN = 20000;
const NICHAN_TRANSLATE_IP_DAY = 100000;
const NICHAN_TRANSLATE_REQUESTS_10MIN = 120;
// the longest text sent in one request (subject and text together); posts are shorter on every board anyway
const NICHAN_TRANSLATE_MAX_CHARS = 20000;

// An address for the limits: IPv6 counts by its /64 (one home or phone gets a whole /64 and could change the rest).
function nichan_translate_who($ip) {
	if (strpos($ip, ':') === false || ($bin = @inet_pton($ip)) === false || strlen($bin) !== 16)
		return $ip;
	return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
}

// The post's text as the poster typed it, without the markup, keeping its lines.
function nichan_translate_source($body_nomarkup) {
	$t = remove_modifiers((string)$body_nomarkup);
	$t = nichan_strip_html(preg_replace('~<br\b[^>]*>|</(p|div|li|h[1-6])>~i', "\n", $t));
	$t = preg_replace('~\[/?(b|i|u|s|spoiler|code|mod)\]~i', '', $t);
	$t = preg_replace("/'''|''|\*\*|%%|__|~~|==/", '', $t);
	$t = preg_replace('~>>>/[a-z0-9_]+/\d*|>>\d+~i', '', $t);
	$t = preg_replace('~^[ \t]*>+[ \t]*~mu', '', $t);
	$t = preg_replace('~[ \t]+~u', ' ', str_replace("\r", '', $t));
	$t = preg_replace('~ *\n *~u', "\n", $t);
	return trim(preg_replace("~\n{3,}~", "\n\n", $t));
}

/* ---------------------------------------------------------------- what was sent to Azure */

function nichan_translate_used($who, $seconds) {
	$sql = 'SELECT COALESCE(SUM(`chars`), 0) FROM ``nichan_translate_use`` WHERE `time` > :t' . ($who !== null ? ' AND `who` = :who' : '');
	$query = prepare($sql);
	$query->bindValue(':t', time() - $seconds, PDO::PARAM_INT);
	if ($who !== null)
		$query->bindValue(':who', mb_substr($who, 0, 64));
	$query->execute() or error(db_error($query));
	return (int)$query->fetchColumn();
}

// Seconds until enough of this address's (or, $who null, the site's) characters leave the window for $need more.
function nichan_translate_wait($who, $seconds, $limit, $need) {
	$sql = 'SELECT `time`, `chars` FROM ``nichan_translate_use`` WHERE `time` > :t' . ($who !== null ? ' AND `who` = :who' : '') . ' ORDER BY `time`';
	$query = prepare($sql);
	$query->bindValue(':t', time() - $seconds, PDO::PARAM_INT);
	if ($who !== null)
		$query->bindValue(':who', mb_substr($who, 0, 64));
	$query->execute() or error(db_error($query));
	$rows = $query->fetchAll(PDO::FETCH_ASSOC);
	$used = array_sum(array_column($rows, 'chars'));
	foreach ($rows as $row) {
		$used -= (int)$row['chars'];
		if ($used + $need <= $limit)
			return max(60, (int)$row['time'] + $seconds - time());
	}
	return $seconds;
}

function nichan_translate_month_used() {
	return (int)nichan_setting('translate_used_' . date('Ym'), 0);
}

function nichan_translate_count($who, $chars) {
	$query = prepare('INSERT INTO ``nichan_translate_use`` (`who`, `time`, `chars`) VALUES (:who, :t, :chars)');
	$query->bindValue(':who', mb_substr($who, 0, 64));
	$query->bindValue(':t', time(), PDO::PARAM_INT);
	$query->bindValue(':chars', $chars, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	// the month's sum, in one statement so that two requests at once both count
	$query = prepare('INSERT INTO ``nichan_settings`` (`name`, `value`) VALUES (:name, :chars)
		ON DUPLICATE KEY UPDATE `value` = CAST(`value` AS UNSIGNED) + VALUES(`value`)');
	$query->bindValue(':name', 'translate_used_' . date('Ym'));
	$query->bindValue(':chars', $chars, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	if (mt_rand(1, 100) == 1) {
		query('DELETE FROM ``nichan_translate_use`` WHERE `time` < ' . (time() - 2 * 86400));
		// kept translations nobody asked for in three months
		query('DELETE FROM ``nichan_translations`` WHERE `time` < ' . (time() - 90 * 86400));
	}
}

/* ---------------------------------------------------------------- kept translations */

function nichan_translation_get($uri, $post, $lang, $hash) {
	$query = prepare('SELECT * FROM ``nichan_translations`` WHERE `board` = :b AND `post` = :p AND `lang` = :l');
	$query->bindValue(':b', $uri);
	$query->bindValue(':p', $post, PDO::PARAM_INT);
	$query->bindValue(':l', $lang);
	$query->execute() or error(db_error($query));
	$row = $query->fetch(PDO::FETCH_ASSOC);
	return $row && $row['hash'] === $hash ? $row : null;
}

function nichan_translation_put($uri, $post, $lang, $hash, $subject, $body, $from) {
	$query = prepare('REPLACE INTO ``nichan_translations`` (`board`, `post`, `lang`, `hash`, `subject`, `body`, `from_lang`, `time`)
		VALUES (:b, :p, :l, :h, :s, :body, :f, :t)');
	$query->bindValue(':b', $uri);
	$query->bindValue(':p', $post, PDO::PARAM_INT);
	$query->bindValue(':l', $lang);
	$query->bindValue(':h', $hash);
	$query->bindValue(':s', $subject);
	$query->bindValue(':body', $body);
	$query->bindValue(':f', mb_substr((string)$from, 0, 12));
	$query->bindValue(':t', time(), PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
}

// deletePost(): the translations of deleted posts go with them
function nichan_translations_forget($uri, array $ids) {
	$ids = array_filter(array_map('intval', $ids));
	if (!$ids)
		return;
	$query = prepare('DELETE FROM ``nichan_translations`` WHERE `board` = :b AND `post` IN (' . implode(',', $ids) . ')');
	$query->bindValue(':b', $uri);
	$query->execute() or error(db_error($query));
}

/* ---------------------------------------------------------------- Azure */

/*
 * Translates the texts into $to. Returns array('texts' => [...], 'from' => detected language) or
 * array('error' => HTTP status for the app, 'retry_after' => seconds or null). What went wrong is kept for mod.php
 * (azure_translator_error); the key, the address and Azure's words never reach the app.
 */
function nichan_translate_azure(array $texts, $to) {
	$body = array();
	foreach ($texts as $t)
		$body[] = array('Text' => $t);
	$ch = curl_init(nichan_translate_endpoint() . '/translate?api-version=3.0&to=' . rawurlencode($to));
	$headers = array('Content-Type: application/json; charset=UTF-8', 'Ocp-Apim-Subscription-Key: ' . nichan_setting('azure_translator_key', ''));
	if ((string)nichan_setting('azure_translator_region', '') !== '')
		$headers[] = 'Ocp-Apim-Subscription-Region: ' . nichan_setting('azure_translator_region', '');
	$retry = null;
	curl_setopt_array($ch, array(
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CONNECTTIMEOUT => 5,
		CURLOPT_TIMEOUT => 15,
		CURLOPT_HTTPHEADER => $headers,
		CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$retry) {
			if (preg_match('/^Retry-After:\s*(\d+)/i', $line, $m))
				$retry = (int)$m[1];
			return strlen($line);
		},
	));
	$res = curl_exec($ch);
	$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$curl_error = curl_error($ch);
	curl_close($ch);
	$json = is_string($res) ? json_decode($res, true) : null;

	if ($status === 200 && is_array($json) && count($json) === count($texts)) {
		$out = array();
		$from = null;
		foreach ($json as $item) {
			if (!isset($item['translations'][0]['text']))
				break;
			$out[] = (string)$item['translations'][0]['text'];
			if ($from === null && !empty($item['detectedLanguage']['language']))
				$from = (string)$item['detectedLanguage']['language'];
		}
		if (count($out) === count($texts)) {
			if ((string)nichan_setting('azure_translator_error', '') !== '')
				nichan_set_setting('azure_translator_error', '');
			return array('texts' => $out, 'from' => $from);
		}
	}

	// for mod.php only: the status and Azure's code and message (they never hold the key or the text)
	$why = $status ? 'HTTP ' . $status : ($curl_error !== '' ? $curl_error : 'немає відповіді');
	if (isset($json['error']['code']))
		$why .= ' (' . $json['error']['code'] . (isset($json['error']['message']) ? ': ' . mb_substr((string)$json['error']['message'], 0, 200) : '') . ')';
	nichan_set_setting('azure_translator_error', date('d.m H:i') . ': ' . $why);
	if ($status === 429)
		return array('error' => 429, 'retry_after' => $retry ? min(3600, max(1, $retry)) : 60);
	return array('error' => 503, 'retry_after' => $status >= 500 || !$status ? 60 : null);
}

// mod.php, after the key is saved: a word into English, to see whether the key, region and endpoint work.
function nichan_translate_check() {
	$res = nichan_translate_azure(array('Привіт'), 'en');
	return isset($res['texts']) ? array('ok' => true, 'text' => $res['texts'][0])
		: array('ok' => false, 'error' => (string)nichan_setting('azure_translator_error', ''));
}
