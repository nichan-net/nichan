<?php
/*
 * pending.php?q=<id>:<token>,<id>:<token>: what happened to the author's threads waiting for a moderator
 * (inc/nichan-threads.php). {"<id>": {"status": "pending|approved|rejected|unknown", "url": …}}
 */
require_once 'inc/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$wanted = array();
foreach (explode(',', isset($_GET['q']) ? (string)$_GET['q'] : '') as $pair) {
	if (preg_match('/^(\d+):([0-9a-f]{32})$/', $pair, $m))
		$wanted[(int)$m[1]] = $m[2];
}
echo json_encode((object)nichan_premod_status($wanted));
