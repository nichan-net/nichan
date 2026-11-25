<?php
/*
 * captcha.php?board=<uri>: a new picture captcha for the board's post form (js/nichan.js).
 * Answers JSON {id, img} with the picture as a data: URI; the id goes back with the post.
 * captcha.php?board=<uri>&need=1: {type, key}, the captcha this visitor gets there (by country too).
 */

require_once 'inc/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$uri = isset($_GET['board']) ? (string)$_GET['board'] : '';
// the board's own captcha, or one required by the visitor's country (mod.php?/nichan-countries)
$type = preg_match('/^' . $config['board_regex'] . '$/u', $uri) && getBoardInfo($uri) ? nichan_captcha_type_for($uri, $_SERVER['REMOTE_ADDR']) : '';

// ?need=1: which captcha this visitor gets on the board ('' = none), for boards with rules by country
if (isset($_GET['need'])) {
	echo json_encode(array('type' => $type, 'key' => $type === 'recaptcha' ? (string)nichan_setting('recaptcha_public', '') : ''));
	exit;
}
if ($type !== 'uk' && $type !== 'en') {
	http_response_code(404);
	echo json_encode(array('error' => 'no picture captcha on this board'));
	exit;
}

// every picture costs drawing and a database row: at most 30 in 10 minutes from one IP
if (nichan_rate_count('captcha', $_SERVER['REMOTE_ADDR'], 600) >= 30) {
	http_response_code(429);
	echo json_encode(array('error' => $type === 'en' ? 'Too many pictures. Try again in a few minutes.' : 'Забагато картинок. Спробуйте за кілька хвилин.'));
	exit;
}
nichan_rate_hit('captcha', $_SERVER['REMOTE_ADDR']);

list($id, $png) = nichan_captcha_new($type);
echo json_encode(array('id' => $id, 'img' => 'data:image/png;base64,' . base64_encode($png)));
