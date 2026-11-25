<?php
defined('TINYBOARD') or exit;

function nichan_cf_peer($ip) {
	// Published at https://www.cloudflare.com/ips-v4/ and https://www.cloudflare.com/ips-v6/
	$ranges = array('173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
		'141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
		'197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
		'104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22', '2400:cb00::/32',
		'2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
		'2a06:98c0::/29', '2c0f:f248::/32');
	$packed = @inet_pton(preg_replace('/^::ffff:(?=\d+\.)/i', '', $ip));
	if ($packed === false) return false;
	foreach ($ranges as $cidr) {
		list($first, $last) = Bans::parse_range($cidr);
		if (strlen($packed) === strlen($first) && strcmp($packed, $first) >= 0 && strcmp($packed, $last) <= 0)
			return true;
	}
	return false;
}

function nichan_is_tor_request() {
	$ip = $_SERVER['REMOTE_ADDR'] ?? '';
	$peer = $_SERVER['NICHAN_PEER_ADDR'] ?? $_SERVER['REDIRECT_NICHAN_PEER_ADDR'] ?? $ip;
	if ((isset($_SERVER['HTTP_CF_IPCOUNTRY']) || isset($_SERVER['HTTP_CF_CONNECTING_IP'])) && nichan_cf_peer($peer ?: $ip)) {
		if (($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '') === 'T1') return true;
		$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $ip;
		// Cloudflare's Pseudo IPv4 "Overwrite Headers" puts a Class E address in CF-Connecting-IP.
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && (ord(inet_pton($ip)[0]) & 0xf0) === 0xf0)
			$ip = $_SERVER['HTTP_CF_CONNECTING_IPV6'] ?? $ip;
	}
	$file = dirname(__DIR__) . '/tmp/tor-exits.txt';
	if (!is_file($file)) return false;
	$ip = @inet_pton(preg_replace('/^::ffff:(?=\d+\.)/i', '', $ip));
	return $ip !== false && strpos((string)file_get_contents($file), "\n" . inet_ntop($ip) . "\n") !== false;
}

function nichan_tor_has_external_link($text, $site_host, $depth = 0) {
	if ($depth > 6) return true;
	$text = nichan_normalize_link_text($text);
	$text = preg_replace('~\b(h(?:tt|xx)ps?)\s*(?::|\[:\])\s*/\s*/\s*~u', '$1://', $text);
	$text = str_replace(array('hxxps://', 'hxxp://'), array('https://', 'http://'), $text);
	$site_host = strtolower($site_host);
	$domain = '(?:[\p{L}\p{N}_-]{1,63}\.)+(?:xn--[a-z0-9-]{1,59}|[\p{L}]{2,63})(?![\p{L}\p{N}_-])';
	$start = '(?:[a-z][a-z0-9+.-]{0,31}:)?//|(?<![\w.:/-])/(?!/)|(?<![\p{L}\p{N}_-])(?:' . $domain . '|(?:\d{1,3}\.){3}\d{1,3}\b)';
	$pattern = '~(?:' . $start . ')[^\s<>"\'«»“”‘’…()\[\]{},;|]*~u';
	if (preg_match_all($pattern, $text, $matches) === false) return true;
	foreach ($matches[0] as $candidate) {
		$url = rtrim($candidate, '.!?');
		if (strpos($url, '//') === 0) $url = 'https:' . $url;
		elseif ($url[0] === '/') $url = 'https://' . $site_host . $url;
		elseif (!preg_match('~^[a-z][a-z0-9+.-]*://~', $url)) $url = 'https://' . $url;
		$parts = parse_url($url);
		if (empty($parts['host']) || !in_array($parts['scheme'], array('http', 'https'), true)
			|| isset($parts['user']) || isset($parts['pass']) || strpos($candidate, '\\') !== false) return true;
		$host = rtrim($parts['host'], '.');
		$youtube = preg_match('~(?:^|\.)(?:youtube\.com|youtu\.be|youtube-nocookie\.com)$~D', $host);
		if (!$youtube && $host !== $site_host && $host !== 'www.' . $site_host) return true;
		$path = $parts['path'] ?? '';
		if ($youtube && preg_match('~^/(?:redirect|attribution_link)(?:/|$)~', $path)) return true;
		// A permitted URL must not conceal a second destination in its parameters.
		$parameters = trim(($parts['query'] ?? '') . ' ' . ($parts['fragment'] ?? ''));
		if (strpos($path, '//') !== false || ($parameters !== '' && nichan_tor_has_external_link($parameters, $site_host, $depth + 1))) return true;
	}
	return (bool)preg_match('~\b(?:magnet|mailto|data):~u', $text);
}

function nichan_tor_post_check($fields, $uploads) {
	global $config;
	$blocked = !empty($fields['file_url']);
	foreach ($uploads as $file) {
		if ($file['error'] !== UPLOAD_ERR_NO_FILE) $blocked = true;
	}
	foreach (array('body', 'subject', 'name', 'email', 'embed') as $key) {
		if ($blocked) break;
		if (!empty($fields[$key]))
			$blocked = !is_string($fields[$key]) || nichan_tor_has_external_link($fields[$key], $config['nichan_site']['host']);
	}
	if ($blocked) {
		nichan_api_code('tor_restricted');
		error('З Тора пишеш? Давай тоді без оцих ваший файлів та посилань, а то я вас знаю.');
	}
}
