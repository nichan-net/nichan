<?php
/*
 * push.php: push notifications about replies (inc/nichan-push.php), for js/nichan.js.
 *   GET  push.php?key                          -> {"key": VAPID public key}
 *   POST action=subscribe&sub=<JSON>&posts=<JSON [[board,id],…]>&lang=uk|en
 *   POST action=own&endpoint=…&board=b&id=123   (a new post of the subscriber)
 *   POST action=unsubscribe&endpoint=…
 */
require_once 'inc/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function push_out($data, $code = 200) {
	http_response_code($code);
	echo json_encode($data);
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
	if (isset($_GET['key'])) {
		list(, $public) = nichan_push_vapid();
		push_out(array('key' => $public));
	}
	push_out(array('error' => 'bad request'), 400);
}

$action = isset($_POST['action']) ? (string)$_POST['action'] : '';
$ip = $_SERVER['REMOTE_ADDR'];
if (nichan_rate_count('push', $ip, 3600) >= 200)
	push_out(array('error' => 'too many requests'), 429);
nichan_rate_hit('push', $ip);

if ($action === 'subscribe') {
	$sub = json_decode(isset($_POST['sub']) ? (string)$_POST['sub'] : '', true);
	$endpoint = isset($sub['endpoint']) ? $sub['endpoint'] : '';
	$p256dh = isset($sub['keys']['p256dh']) ? (string)$sub['keys']['p256dh'] : '';
	$auth = isset($sub['keys']['auth']) ? (string)$sub['keys']['auth'] : '';
	if (!nichan_push_endpoint_ok($endpoint) || strlen(nichan_b64u_decode($p256dh)) !== 65 || strlen(nichan_b64u_decode($auth)) !== 16)
		push_out(array('error' => 'bad subscription'), 400);
	$id = nichan_push_subscribe($endpoint, $p256dh, $auth, isset($_POST['lang']) ? (string)$_POST['lang'] : 'uk');
	$posts = json_decode(isset($_POST['posts']) ? (string)$_POST['posts'] : '[]', true);
	nichan_push_own($id, is_array($posts) ? $posts : array());
	push_out(array('ok' => true));
}

if ($action === 'own' || $action === 'unsubscribe') {
	$endpoint = isset($_POST['endpoint']) ? (string)$_POST['endpoint'] : '';
	if (!nichan_push_endpoint_ok($endpoint))
		push_out(array('error' => 'bad subscription'), 400);
	if ($action === 'unsubscribe') {
		nichan_push_unsubscribe($endpoint);
		push_out(array('ok' => true));
	}
	if (!($id = nichan_push_sub_id($endpoint)))
		push_out(array('error' => 'not subscribed'), 404);
	nichan_push_own($id, array(array(isset($_POST['board']) ? (string)$_POST['board'] : '', isset($_POST['id']) ? (int)$_POST['id'] : 0)));
	push_out(array('ok' => true));
}

push_out(array('error' => 'bad request'), 400);
