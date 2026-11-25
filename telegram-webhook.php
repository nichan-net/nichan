<?php
/*
 * Telegram calls this when a button under a report is pressed in the moderators' chat
 * (inc/nichan-telegram.php, nichan_telegram_handle_update). Connected from mod.php → Board settings →
 * Integration; Telegram sends the secret chosen there in the X-Telegram-Bot-Api-Secret-Token header.
 */

require_once 'inc/functions.php';

$secret = (string)nichan_setting('telegram_webhook_secret', '');
$given = isset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']) ? (string)$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] : '';
if ($secret === '' || !hash_equals($secret, $given) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(403);
	exit;
}

$update = json_decode((string)file_get_contents('php://input'), true);
if (is_array($update))
	nichan_telegram_handle_update($update);

header('Content-Type: application/json');
echo '{}';
