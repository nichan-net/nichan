<?php

/*
 * Tells the admins' Telegram chat about site errors: PHP errors and warnings, database errors, failed
 * picture processing and crashes. Visitors' mistakes (wrong captcha, flood, banned words) are not
 * errors and are not reported. The same error is reported at most once an hour (with how many times it
 * happened meanwhile), and at most 20 messages go out per hour. Everything also goes to tmp/nichan-errors.log.
 * No IP addresses or post texts are sent.
 */

defined('TINYBOARD') or exit;

function nichan_errors_file($name) {
	return dirname(__DIR__) . '/tmp/' . $name;
}

// $kind: 'PHP', 'DB', 'Crash', 'Upload'…; $where: file:line when known.
function nichan_error_report($kind, $message, $where = '') {
	global $nichan_error_reported;
	// one report per request (and never from inside the reporting itself)
	if (!empty($nichan_error_reported))
		return;
	$nichan_error_reported = true;

	$message = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string)$message, ENT_QUOTES, 'UTF-8'))));
	$root = dirname(__DIR__) . '/';
	$where = str_replace($root, '', (string)$where);
	$request = php_sapi_name() === 'cli' ? 'CLI ' . implode(' ', array_map('basename', array_slice(isset($GLOBALS['argv']) ? $GLOBALS['argv'] : array(), 0, 1)))
		: (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') . ' ' . strtok(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', '?');

	$log = nichan_errors_file('nichan-errors.log');
	if (@filesize($log) > 5 * 1048576)
		@rename($log, $log . '.1');
	@file_put_contents($log, date('Y-m-d H:i:s') . " [$kind] $message" . ($where ? " @ $where" : '') . " ($request)\n", FILE_APPEND | LOCK_EX);

	// throttling, shared by all requests
	$state_file = nichan_errors_file('cache/nichan-errors.json');
	$fp = @fopen($state_file, 'c+');
	if (!$fp)
		return;
	flock($fp, LOCK_EX);
	$state = json_decode(stream_get_contents($fp), true);
	if (!is_array($state))
		$state = array('sent' => array(), 'seen' => array());
	$now = time();
	$state['sent'] = array_values(array_filter($state['sent'], function ($t) use ($now) { return $t > $now - 3600; }));
	foreach ($state['seen'] as $k => $s) {
		if ($s['last'] < $now - 86400)
			unset($state['seen'][$k]);
	}
	$sig = md5($kind . '|' . preg_replace('/\d+/', '#', $message) . '|' . $where);
	$seen = isset($state['seen'][$sig]) ? $state['seen'][$sig] : array('last' => 0, 'skipped' => 0);
	$send = $seen['last'] < $now - 3600 && count($state['sent']) < 20;
	if ($send) {
		$skipped = $seen['skipped'];
		$state['seen'][$sig] = array('last' => $now, 'skipped' => 0);
		$state['sent'][] = $now;
	} else {
		$seen['skipped']++;
		$state['seen'][$sig] = $seen;
	}
	ftruncate($fp, 0);
	rewind($fp);
	fwrite($fp, json_encode($state));
	flock($fp, LOCK_UN);
	fclose($fp);

	if (!$send || !function_exists('nichan_telegram_queue'))
		return;
	$text = "⚠️ Помилка на сайті ($kind)\n" . mb_substr($message, 0, 800) .
		($where ? "\nДе: $where" : '') . "\nЗапит: $request" .
		($skipped ? "\nЩе $skipped раз(и) за останню годину, поки не повідомлялося." : '');
	nichan_telegram_queue('sendMessage', array('text' => $text));
}

// Called by error() (inc/display.php): reports only the site's own failures, not visitors' mistakes.
function nichan_error_from_error($message, $debug_stuff) {
	global $db_error;
	if (is_array($debug_stuff) && isset($debug_stuff['errno'])) {
		nichan_error_report('PHP', $debug_stuff['error'], isset($debug_stuff['file']) ? $debug_stuff['file'] : '');
	} elseif (isset($db_error[2]) && $db_error[2] !== null && $message === $db_error[2]) {
		nichan_error_report('DB', $message, nichan_error_caller());
	} elseif ($debug_stuff) {
		// failures that come with details: resizing a picture, moving an upload…
		nichan_error_report('Upload', $message . ': ' . (is_array($debug_stuff) ? json_encode($debug_stuff, JSON_UNESCAPED_UNICODE) : $debug_stuff), nichan_error_caller());
	}
}

// file:line of the code that called error()
function nichan_error_caller() {
	foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $f) {
		if (isset($f['function'], $f['file']) && $f['function'] === 'error')
			return $f['file'] . ':' . $f['line'];
	}
	return '';
}

// crashes: fatal errors and uncaught exceptions
register_shutdown_function(function () {
	$e = error_get_last();
	if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR)))
		nichan_error_report('Crash', $e['message'], $e['file'] . ':' . $e['line']);
});
set_exception_handler(function ($ex) {
	nichan_error_report('Crash', get_class($ex) . ': ' . $ex->getMessage(), $ex->getFile() . ':' . $ex->getLine());
	if (php_sapi_name() === 'cli') {
		fwrite(STDERR, (string)$ex . "\n");
		exit(1);
	}
	if (!headers_sent())
		header((isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1') . ' 500 Internal Server Error');
	echo 'Внутрішня помилка сайту. Адмінів уже повідомлено.';
});

// Warnings and notices when verbose_errors is off (the live site): the page goes on, the admins hear of it.
// (With verbose_errors on, verbose_error_handler() turns them into error(), which reports them.)
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
	if (!(error_reporting() & $errno)) // silenced with @
		return false;
	if ($errno == E_DEPRECATED || $errno == E_USER_DEPRECATED)
		return false;
	nichan_error_report('PHP', $errstr, $errfile . ':' . $errline);
	return false; // and PHP's own handling (its error log)
});
