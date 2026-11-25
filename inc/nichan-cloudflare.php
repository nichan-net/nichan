<?php

/*
 * Cloudflare cache of the static pages: every page the site writes (a board page, a thread, the catalog,
 * their JSON) or deletes is purged from Cloudflare's cache at the end of the request, so Cloudflare may
 * keep them (a "Cache Everything" rule) and visitors still see new posts at once.
 * Needs a Zone ID and an API token that may only purge the cache (mod.php -> Board settings -> Integration).
 * Many pages at once (a full rebuild) purge everything instead.
 */

defined('TINYBOARD') or exit;

function nichan_cf_enabled() {
	return nichan_setting('cf_zone_id', '') !== '' && nichan_setting('cf_api_token', '') !== '';
}

// The address of a written or deleted file, if it is a page Cloudflare could keep.
function nichan_cf_url($path) {
	global $config;
	$path = str_replace('\\', '/', (string)$path);
	$root = str_replace('\\', '/', dirname(__DIR__)) . '/';
	if (strpos($path, $root) === 0)
		$path = substr($path, strlen($root));
	$path = preg_replace('~^(\./)+~', '', $path);
	if ($path === '' || $path[0] === '/' || strpos($path, '..') !== false)
		return null;
	if (!preg_match('~\.(html|json|xml|rss|css|js|webmanifest)$~', $path) || preg_match('~^(tmp|inc|templates|tools)/~', $path))
		return null;
	return rtrim($config['nichan_public_url'], '/') . $config['root'] . $path;
}

function nichan_cf_queue($path) {
	global $nichan_cf_urls, $config;
	if (!nichan_cf_enabled() || !($url = nichan_cf_url($path)))
		return;
	if ($nichan_cf_urls === null) {
		$nichan_cf_urls = array();
		register_shutdown_function('nichan_cf_flush');
	}
	$nichan_cf_urls[$url] = true;
	// the front page (recent.html) is the root address "/" too (DirectoryIndex in .htaccess)
	$root = rtrim($config['nichan_public_url'], '/') . $config['root'];
	if ($url === $root . 'recent.html')
		$nichan_cf_urls[$root] = true;
	// an index page is also reached by its folder: /b/ as well as /b/index.html
	if (basename($url) == $config['file_index'])
		$nichan_cf_urls[substr($url, 0, -strlen($config['file_index']))] = true;
}

// Cloudflare's API; returns its answer (array) or false.
function nichan_cf_api($method, $endpoint, $body = null) {
	$ch = curl_init('https://api.cloudflare.com/client/v4/' . $endpoint);
	curl_setopt_array($ch, array(
		CURLOPT_CUSTOMREQUEST => $method,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 15,
		CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . nichan_setting('cf_api_token', ''), 'Content-Type: application/json'),
	));
	if ($body !== null)
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
	$res = curl_exec($ch);
	curl_close($ch);
	$json = is_string($res) ? json_decode($res, true) : null;
	return is_array($json) ? $json : false;
}

function nichan_cf_flush() {
	global $nichan_cf_urls;
	if (!$nichan_cf_urls)
		return;
	$urls = array_keys($nichan_cf_urls);
	$nichan_cf_urls = array();
	if (function_exists('fastcgi_finish_request'))
		@fastcgi_finish_request();
	elseif (function_exists('litespeed_finish_request'))
		@litespeed_finish_request();
	@ignore_user_abort(true);
	$zone = rawurlencode(nichan_setting('cf_zone_id', ''));
	$batches = count($urls) > 300 ? array(null) : array_chunk($urls, 30); // (30 addresses per call)
	foreach ($batches as $batch) {
		$res = nichan_cf_api('POST', "zones/$zone/purge_cache", $batch === null ? array('purge_everything' => true) : array('files' => $batch));
		if (!$res || empty($res['success'])) {
			$err = $res && !empty($res['errors'][0]['message']) ? $res['errors'][0]['message'] : 'no answer';
			nichan_set_setting('cf_last_error', date('d.m H:i') . ': ' . $err);
			if (function_exists('nichan_error_report'))
				nichan_error_report('Cloudflare', 'Cache purge failed: ' . $err);
			return;
		}
	}
}

// For the Integration section: does the token work for this zone?
function nichan_cf_check() {
	if (!nichan_cf_enabled())
		return null;
	$res = nichan_cf_api('POST', 'zones/' . rawurlencode(nichan_setting('cf_zone_id', '')) . '/purge_cache', array('files' => array(rtrim($GLOBALS['config']['nichan_public_url'], '/') . '/robots.txt')));
	if ($res && !empty($res['success']))
		return array('ok' => true);
	return array('ok' => false, 'error' => $res && !empty($res['errors'][0]['message']) ? $res['errors'][0]['message'] : 'Cloudflare не відповів');
}
