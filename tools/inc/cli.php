<?php

/*
 *  This script will look for Tinyboard in the following places (in order):
 *    - $TINYBOARD_PATH environment varaible
 *    - ./
 *    - ./Tinyboard/
 *    - ../
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(0);
$shell_path = getcwd();

if (php_sapi_name() != 'cli') {
	die("This script is executable only from Command Line Interface.");
}

if(getenv('TINYBOARD_PATH') !== false)
	$dir = getenv('TINYBOARD_PATH');
elseif(file_exists('inc/functions.php'))
	$dir = false;
elseif(file_exists('Tinyboard') && is_dir('Tinyboard') && file_exists('Tinyboard/inc/functions.php'))
	$dir = 'Tinyboard';
elseif(file_exists('../inc/functions.php'))
	$dir = '..';
else
	die("Could not locate Tinyboard directory!\n");

if($dir && !chdir($dir))
	die("Could not change directory to {$dir}\n");

if(!getenv('TINYBOARD_PATH')) {
	// follow symlink
	chdir(realpath('inc') . '/..');
}

putenv('TINYBOARD_PATH=' . getcwd());

// nichan: on shared hosting the "php" of the terminal and cron is often another build than the one
// the site runs on. Show which extensions are missing and suggest an available PHP binary.
$nichan_need = array('mbstring', 'pdo_mysql', 'gd', 'json');
$nichan_missing = array_values(array_filter($nichan_need, function ($e) { return !extension_loaded($e); }));
if ($nichan_missing) {
	fwrite(STDERR, 'This PHP (' . PHP_BINARY . ', ' . PHP_VERSION . ') lacks: ' . implode(', ', $nichan_missing) . ".\n");
	$good = array();
	foreach (array_merge(glob('/opt/alt/php*/usr/bin/php') ?: array(), glob('/opt/cpanel/ea-php*/root/usr/bin/php') ?: array(), array('/usr/local/bin/php', '/usr/bin/php')) as $bin) {
		if (!is_executable($bin) || realpath($bin) === realpath(PHP_BINARY))
			continue;
		$check = 'foreach (' . var_export($nichan_need, true) . ' as $e) if (!extension_loaded($e)) exit(1); echo PHP_VERSION;';
		$version = trim((string)@shell_exec(escapeshellarg($bin) . ' -r ' . escapeshellarg($check) . ' 2>/dev/null'));
		// a real CLI answers with its version (php-cgi prints its usage instead); the engine needs 7.4+
		if (preg_match('/^\d+\.\d+\.\d+$/', $version) && version_compare($version, '7.4', '>='))
			$good[$bin] = $version;
	}
	// the closest to this PHP first (the site usually runs the same version as the terminal's php)
	uksort($good, function ($a, $b) use ($good) {
		$da = abs(version_compare($good[$a], PHP_VERSION) === 0 ? 0 : (float)$good[$a] - (float)PHP_VERSION);
		$db = abs(version_compare($good[$b], PHP_VERSION) === 0 ? 0 : (float)$good[$b] - (float)PHP_VERSION);
		return $da == $db ? version_compare($good[$b], $good[$a]) : ($da < $db ? -1 : 1);
	});
	if ($good) {
		fwrite(STDERR, "PHP builds on this server that have everything:\n");
		foreach ($good as $bin => $version)
			fwrite(STDERR, "  $bin  ($version)\n");
		reset($good);
		fwrite(STDERR, 'Run it as:  ' . key($good) . ' ' . implode(' ', $_SERVER['argv']) . "\n");
	} else {
		fwrite(STDERR, "Enable the missing extensions for PHP CLI or run this script with a PHP binary that provides them.\n");
	}
	exit(1);
}

require 'inc/functions.php';

$mod = Array(
	'id' => -1,
	'type' => ADMIN,
	'username' => '?',
	'boards' => Array('*')
);


