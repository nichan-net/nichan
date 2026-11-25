#!/usr/bin/php
<?php
/*
 *  nichan-cf-watchdog.php - from cron every few minutes: are the front page and the boards' pages really the site's?
 *
 *  The hosting may put its bot check ("One moment, please... Please wait while your request is being verified")
 *  in front of the site. If Cloudflare keeps that page in its cache, everybody gets it instead of the site and
 *  it reloads itself for ever. This opens the pages as a visitor does, through Cloudflare, and:
 *    - a check page from Cloudflare's cache: those addresses are purged and the admins' Telegram chat is told;
 *    - a check page from the server itself: only told (purging won't help; the hosting's support can relax it).
 *  At most one message per problem every 30 minutes.
 *
 *    0-59/5 * * * *  cd /path/to/site && php tools/nichan-cf-watchdog.php -q
 *
 *  Options: -q (print only problems), --dry-run (purge nothing, tell nobody), --base=https://… (another address).
 */

require dirname(__FILE__) . '/inc/cli.php';

$quiet = in_array('-q', $argv);
$dry = in_array('--dry-run', $argv);
$base = rtrim($config['nichan_public_url'], '/') . $config['root'];
foreach ($argv as $a)
	if (strpos($a, '--base=') === 0)
		$base = rtrim(substr($a, 7), '/') . '/';

// what the hosting's check pages say
define('NICHAN_CHECK_PAGE', '~One moment, please|Please wait while your request is being verified|Checking your browser before accessing~i');

// the front page and each public board's first page
$pages = array($base => array($base, $base . 'recent.html'));
foreach (nichan_stats_boards() as $uri => $title)
	$pages[$base . $uri . '/'] = array($base . $uri . '/', $base . $uri . '/' . $config['file_index']);

$cached = array();   // addresses to purge
$origin = array();   // the server itself answers with a check page
$failed = array();
foreach ($pages as $url => $purge) {
	$headers = array();
	$ch = curl_init($url);
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
		CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25,
		// (as a browser, so the watchdog is not what the bot check trips on)
		CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36 nichan-watchdog',
		CURLOPT_HTTPHEADER => array('Accept: text/html,application/xhtml+xml', 'Accept-Language: uk,en;q=0.8'),
		CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
			if (preg_match('/^([\w-]+):\s*(.*)$/', trim($line), $m))
				$headers[strtolower($m[1])] = $m[2];
			return strlen($line);
		},
	));
	$body = curl_exec($ch);
	$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	if ($body === false || $code >= 500) {
		$failed[] = "$url (" . ($body === false ? 'no answer' : "HTTP $code") . ')';
		continue;
	}
	if (!preg_match(NICHAN_CHECK_PAGE, (string)$body)) {
		if (!$quiet)
			echo "ok  $url" . (isset($headers['cf-cache-status']) ? ' (' . $headers['cf-cache-status'] . ')' : '') . "\n";
		continue;
	}
	$status = isset($headers['cf-cache-status']) ? strtoupper($headers['cf-cache-status']) : '';
	if (in_array($status, array('HIT', 'STALE', 'EXPIRED', 'UPDATING', 'REVALIDATED'))) {
		echo "CHECK PAGE FROM CLOUDFLARE'S CACHE  $url ($status)\n";
		$cached = array_merge($cached, $purge);
	} else {
		echo "CHECK PAGE FROM THE SERVER  $url" . ($status !== '' ? " ($status)" : '') . "\n";
		$origin[] = $url;
	}
}

// At most one message per problem every 30 minutes (tmp/cache/nichan-cf-watchdog.json).
$state_file = dirname(__DIR__) . '/tmp/cache/nichan-cf-watchdog.json';
$state = is_file($state_file) ? json_decode((string)@file_get_contents($state_file), true) : array();
$state = is_array($state) ? $state : array();
$tell = function ($key, $text) use (&$state, $dry) {
	echo $text, "\n";
	if ($dry || (isset($state[$key]) && $state[$key] > time() - 1800))
		return;
	$state[$key] = time();
	if (function_exists('nichan_telegram_queue'))
		nichan_telegram_queue('sendMessage', array('text' => $text, 'disable_web_page_preview' => 'true'));
};

if ($cached) {
	$cached = array_values(array_unique($cached));
	$done = false;
	if ($dry)
		echo "(dry run: would purge " . implode(' ', $cached) . ")\n";
	elseif (!nichan_cf_enabled())
		echo "Cloudflare is not set up (mod.php -> Board settings -> Integration): nothing purged\n";
	else {
		$res = nichan_cf_api('POST', 'zones/' . rawurlencode(nichan_setting('cf_zone_id', '')) . '/purge_cache', array('files' => array_slice($cached, 0, 30)));
		$done = $res && !empty($res['success']);
		if (!$done)
			echo 'Purge failed: ' . ($res && !empty($res['errors'][0]['message']) ? $res['errors'][0]['message'] : 'no answer') . "\n";
	}
	$tell('cached', "🛡 Cloudflare віддавав замість сайту сторінку перевірки хостингу («One moment, please…»): " .
		implode(' ', array_keys(array_intersect_key($pages, array_flip($cached)))) . "\n" .
		($done ? 'Кеш цих сторінок очищено, сайт знову відкривається.' : 'Кеш очистити не вдалося: зробіть це в Cloudflare → Caching → Purge Cache.'));
}
if ($origin)
	$tell('origin', "⚠️ Хостинг сам віддає сторінку перевірки на ботів замість сайту: " . implode(' ', $origin) . "\n" .
		'Чищення кешу тут не допоможе. Якщо це триває, напишіть у підтримку хостингу (захист Imunify360 / WebShield для сайту за Cloudflare).');
if ($failed && count($failed) == count($pages))
	$tell('down', "⚠️ Сайт не відповідає: " . implode(', ', array_slice($failed, 0, 3)));

if (!$dry)
	@file_put_contents($state_file, json_encode($state));
exit($cached || $origin ? 1 : 0);
