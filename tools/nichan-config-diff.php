#!/usr/bin/php
<?php
/*
 *  nichan-config-diff.php - compare two instance-config files by what they actually set: each is
 *  loaded on top of the defaults in inc/config.php and the resulting settings are compared. Use it
 *  before replacing inc/instance-config.php with a rewritten copy. Secrets are never printed, only
 *  the names of the settings that differ.
 *
 *  Usage: php tools/nichan-config-diff.php inc/instance-config.php inc/instance-config.new.php
 */

if (php_sapi_name() !== 'cli')
	exit;
if ($argc < 3)
	exit("Usage: php tools/nichan-config-diff.php <old file> <new file>\n");
chdir(dirname(__DIR__));

define('TINYBOARD', null);
function define_groups() {
	global $config;
	foreach ($config['mod']['groups'] as $level => $name)
		if (!defined(strtoupper($name)))
			define(strtoupper($name), $level);
}
function nichan_config_load($file) {
	global $config;
	$config = array();
	include 'inc/config.php';
	include $file;
	// the file cache ('fs') never looks at it
	if ($config['cache']['enabled'] === 'fs')
		unset($config['cache']['timeout']);
	return $config;
}
function nichan_config_flat($a, $prefix = '') {
	$out = array();
	foreach ($a as $k => $v) {
		if (is_array($v) && $v)
			$out += nichan_config_flat($v, $prefix . "[$k]");
		else
			$out[$prefix . "[$k]"] = $v;
	}
	return $out;
}

foreach (array($argv[1], $argv[2]) as $f)
	if (!is_file($f))
		exit("No such file: $f\n");
$a = nichan_config_flat(nichan_config_load($argv[1]));
$b = nichan_config_flat(nichan_config_load($argv[2]));
$diff = array();
foreach ($a + $b as $k => $v)
	if (!array_key_exists($k, $a) || !array_key_exists($k, $b) || $a[$k] != $b[$k])
		$diff[] = $k;
if ($diff)
	exit('DIFFERENT: ' . implode(', ', $diff) . "\n");
echo 'SAME (' . count($a) . " settings)\n";
