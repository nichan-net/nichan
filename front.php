<?php
// Only active rankings take this route. An empty home is served directly by Apache.
$deadline = __DIR__ . '/tmp/front-popular-until';
$lock = fopen(__DIR__ . '/tmp/front-page.lock', 'c');
if (!$lock || !flock($lock, LOCK_SH))
	throw new RuntimeException('Could not read the home page');
try {
	$until = is_file($deadline) ? (int)file_get_contents($deadline) : null;
	if ($until !== null && $until <= time()) {
		flock($lock, LOCK_UN);
		require_once __DIR__ . '/inc/functions.php';
		rebuildTheme('recent', 'popular-expired');
		if (!flock($lock, LOCK_SH))
			throw new RuntimeException('Could not read the rebuilt home page');
		clearstatcache(true, $deadline);
		$until = is_file($deadline) ? (int)file_get_contents($deadline) : null;
	}
	$ttl = $until !== null ? max(0, min(86400, $until - time())) : 86400;
	header('Content-Type: text/html; charset=utf-8');
	header('Cache-Control: public, max-age=0, s-maxage=' . $ttl . ', must-revalidate');
	$classic = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) === 'recent-classic.html';
	readfile(__DIR__ . ($classic ? '/recent-classic.html' : '/recent.html'));
} finally {
	fclose($lock);
}
