<?php
// Only reached by the rewrite inside a restricted board. Keep originals and thumbnails at
// their usual URLs so existing links, the image viewer and video seeking continue to work.
header('Cache-Control: private, no-store');
header('Cloudflare-CDN-Cache-Control: no-store');
// mod_expires would otherwise add the site's month-long public image lifetime.
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');
http_response_code(403);

require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/mod/auth.php';

$uri = $_GET['board'] ?? null;
if (!is_string($uri) || !preg_match('/^' . $config['board_regex'] . '$/Du', $uri)
		|| !is_string($_COOKIE[$config['cookies']['mod']] ?? null))
	exit;
check_login(false);
$visibility = nichan_board_visibility($uri);
if (!in_array($visibility, array(NICHAN_VISIBILITY_STAFF, NICHAN_VISIBILITY_ADMINS), true)
		|| !nichan_visibility_allows($visibility, $mod) || !openBoard($uri))
	exit;
if (!in_array($_SERVER['REQUEST_METHOD'], array('GET', 'HEAD'), true)) {
	header('Allow: GET, HEAD');
	http_response_code(405);
	exit;
}

// Resolve only an immediate child of this board's configured upload directories.
// The rewrite supplies the board; the file comes from the original URL, never the query.
http_response_code(404);
$path = rawurldecode(explode('?', $_SERVER['REQUEST_URI'], 2)[0]);
$root = (string)parse_url($config['root'], PHP_URL_PATH);
$name = basename($path);
if ($name === '' || $name[0] === '.' || preg_match('~[\\\\\x00-\x1f\x7f]~', $path))
	exit;
$file = false;
foreach (array('img', 'thumb') as $kind) {
	$directory = $board['dir'] . $config['dir'][$kind];
	if ($path !== rtrim($root, '/') . '/' . $directory . $name)
		continue;
	$parent = realpath($directory);
	$resolved = realpath($directory . $name);
	if ($parent !== false && $resolved !== false && dirname($resolved) === $parent && is_file($resolved))
		$file = $resolved;
	break;
}
if (!$file || !($input = @fopen($file, 'rb')))
	exit;

$size = fstat($input)['size'];
$start = 0;
$end = $size - 1;
http_response_code(200);
header('Accept-Ranges: bytes');
// Serve one range (as requested by audio/video players); other range formats get the whole file.
if (preg_match('/^bytes=(\d*)-(\d*)$/D', $_SERVER['HTTP_RANGE'] ?? '', $range) && ($range[1] !== '' || $range[2] !== '')) {
	if ($range[1] === '') {
		$start = max(0, $size - (int)$range[2]);
	} else {
		$start = (int)$range[1];
		if ($range[2] !== '')
			$end = min($end, (int)$range[2]);
	}
	if ($start > $end || fseek($input, $start) !== 0) {
		http_response_code(416);
		header('Content-Range: bytes */' . $size);
		exit;
	}
	http_response_code(206);
	header("Content-Range: bytes $start-$end/$size");
}
$inline = array('jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
	'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp', 'avif' => 'image/avif',
	'webm' => 'video/webm', 'mp4' => 'video/mp4', 'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg',
	'opus' => 'audio/ogg', 'flac' => 'audio/flac', 'wav' => 'audio/wav', 'txt' => 'text/plain', 'pdf' => 'application/pdf');
$type = $inline[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? null;
header('Content-Type: ' . ($type ?: 'application/octet-stream'));
header("Content-Security-Policy: sandbox");
if (!$type)
	header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($name));
header('Content-Length: ' . ($end - $start + 1));
if ($_SERVER['REQUEST_METHOD'] !== 'HEAD' && $size > 0)
	stream_copy_to_stream($input, fopen('php://output', 'wb'), $end - $start + 1);
