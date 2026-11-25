#!/usr/bin/php
<?php
/*
 *  nichan-backup.php - backups for cron, kept outside the web folder (default /backups/nichan).
 *
 *    php tools/nichan-backup.php            database: db-YYYYmmdd-HHMM.sql.gz, checked after writing
 *    php tools/nichan-backup.php --code     site code with inc/instance-config.php (the secrets are
 *                                           not in git) and board configs, without pictures, generated
 *                                           pages and caches: code-YYYYmmdd-HHMM.tar.gz
 *  Options: --dir=/path  --keep-days=14 (database; code: 8 weeks)
 *
 *  Old copies are deleted after --keep-days, but the newest 3 always stay, so a backup that stopped
 *  working never takes the last good ones with it. The database password is passed to mysqldump in a
 *  temporary file readable only by you, never on the command line. Prints nothing unless something
 *  goes wrong (cron mails the output); the exit code is not 0 then.
 *
 *  cron:  15 4 * * *  cd /path/to/site && php tools/nichan-backup.php
 *         30 4 * * 0  cd /path/to/site && php tools/nichan-backup.php --code
 */

require dirname(__FILE__) . '/inc/cli.php';

$opts = getopt('', array('code', 'dir:', 'keep-days:', 'verbose'));
$home = getenv('HOME') ?: (function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['dir'] : dirname(dirname(__DIR__)));
$dir = rtrim(isset($opts['dir']) ? $opts['dir'] : '/backups/nichan', '/');
$code = isset($opts['code']);
$keep_days = isset($opts['keep-days']) ? max(1, (int)$opts['keep-days']) : ($code ? 56 : 14);
$verbose = isset($opts['verbose']);
$site = dirname(__DIR__);

function fail($msg) {
	fwrite(STDERR, "nichan backup FAILED: $msg\n");
	exit(1);
}
function say($msg) {
	global $verbose;
	if ($verbose)
		echo $msg, "\n";
}

if (!is_dir($dir) && !@mkdir($dir, 0700, true))
	fail("cannot create $dir");
if (strpos(realpath($dir) . '/', realpath($site) . '/') === 0)
	fail("$dir is inside the web folder; choose a folder outside it");
@chmod($dir, 0700);
$stamp = date('Ymd-Hi');

if (!$code) {
	$dump = trim((string)shell_exec('command -v mariadb-dump || command -v mysqldump'));
	if ($dump === '')
		fail('neither mariadb-dump nor mysqldump was found');
	// credentials in a private temporary file, not on the command line
	$cnf = tempnam(sys_get_temp_dir(), 'nichan-bk');
	chmod($cnf, 0600);
	$db = $config['db'];
	$server = $db['server'];
	$lines = array('[client]', 'user="' . addcslashes($db['user'], '"\\') . '"', 'password="' . addcslashes($db['password'], '"\\') . '"');
	if ($server === 'localhost' || $server === '')
		$lines[] = 'host=localhost';
	elseif ($server[0] === ':')
		$lines[] = 'socket="' . substr($server, 1) . '"';
	else {
		$hp = explode(':', $server);
		$lines[] = 'host="' . $hp[0] . '"';
		if (isset($hp[1]))
			$lines[] = 'port=' . (int)$hp[1];
	}
	file_put_contents($cnf, implode("\n", $lines) . "\n");

	$file = "$dir/db-$stamp.sql.gz";
	$tmp = "$file.part";
	// MyISAM tables: the dump locks them for reading for a few seconds (writes wait, reads go on)
	$cmd = escapeshellarg($dump) . ' --defaults-extra-file=' . escapeshellarg($cnf) .
		' --default-character-set=utf8mb4 --quick --add-drop-table --routines ' . escapeshellarg($db['database']) .
		' 2>' . escapeshellarg("$tmp.err") . ' | gzip -6 > ' . escapeshellarg($tmp) . '; echo ${PIPESTATUS[0]}';
	$status = trim((string)shell_exec('bash -c ' . escapeshellarg($cmd)));
	@unlink($cnf);
	$err = trim((string)@file_get_contents("$tmp.err"));
	@unlink("$tmp.err");
	if ($status !== '0') {
		@unlink($tmp);
		fail("$dump exited with $status" . ($err ? ": $err" : ''));
	}
	// the file must be a complete, readable gzip and the dump must have finished
	$tail = (string)shell_exec('gzip -dc ' . escapeshellarg($tmp) . ' 2>/dev/null | tail -c 200');
	if (strpos($tail, 'Dump completed') === false || filesize($tmp) < 1024) {
		@unlink($tmp);
		fail('the dump is incomplete' . ($err ? ": $err" : ''));
	}
	rename($tmp, $file);
	chmod($file, 0600);
	say('database: ' . $file . ' (' . round(filesize($file) / 1048576, 1) . ' MB)');
	$pattern = 'db-*.sql.gz';
} else {
	$file = "$dir/code-$stamp.tar.gz";
	$tmp = "$file.part";
	$boards = array();
	foreach (listBoards() as $b)
		$boards[] = $b['uri'];
	$ex = array('tmp', 'templates/cache', 'all/index.html', 'recent.html', 'recent.xml', 'sitemap.xml',
		// pictures and thread pages of any folder, also of boards no longer in the database
		'*/src', '*/thumb', '*/res');
	foreach ($boards as $uri)
		foreach (array('*.html', '*.json', 'index.rss') as $p)
			$ex[] = "$uri/$p";
	// "*" must not cross "/": js/wPaint/plugins/*/src is code
	$args = ' --no-wildcards-match-slash --exclude=error_log';
	// archives and dumps left in the site folder are not code
	foreach (array('*.zip', '*.sql', '*.sql.gz', '*.tar.gz', '*.tgz') as $p)
		$args .= ' --exclude=' . escapeshellarg('./' . $p);
	foreach ($ex as $p)
		$args .= ' --exclude=' . escapeshellarg('./' . $p);
	exec('cd ' . escapeshellarg($site) . ' && tar czf ' . escapeshellarg($tmp) . $args . ' . 2>&1', $out, $rc);
	if ($rc !== 0 || !is_file($tmp)) {
		@unlink($tmp);
		fail('tar: ' . implode(' ', $out));
	}
	rename($tmp, $file);
	chmod($file, 0600);
	say('code: ' . $file . ' (' . round(filesize($file) / 1048576, 1) . ' MB)');
	$pattern = 'code-*.tar.gz';
}

// rotation: older than --keep-days goes, the newest 3 always stay
$all = glob("$dir/$pattern") ?: array();
rsort($all);
foreach (array_slice($all, 3) as $old) {
	if (filemtime($old) < time() - $keep_days * 86400) {
		unlink($old);
		say('deleted ' . basename($old));
	}
}
