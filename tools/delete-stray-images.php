#!/usr/bin/php
<?php
/*
 *  delete-stray-images.php - find files in <board>/src and <board>/thumb that no post refers to.
 *
 *  Usage (from the site folder):
 *    php tools/delete-stray-images.php                   # report only, nothing is touched
 *    php tools/delete-stray-images.php --move-to=DIR     # move stray files to DIR/<board>/<src|thumb>/
 *    php tools/delete-stray-images.php --delete          # delete stray files
 *
 *  Files modified during the last hour are skipped (an upload may still be in progress).
 */

require dirname(__FILE__) . '/inc/cli.php';

$opts = getopt('', array('delete', 'move-to:'));
$delete = isset($opts['delete']);
$move_to = isset($opts['move-to']) ? rtrim($opts['move-to'], '/') : false;
if ($delete && $move_to)
	die("Use either --delete or --move-to, not both.\n");

$total = array('files' => 0, 'size' => 0);

foreach (listBoards() as $_board) {
	openBoard($_board['uri']);
	echo "/{$board['uri']}/... ";

	$valid = array('img' => array(), 'thumb' => array());
	$query = query(sprintf("SELECT `files` FROM ``posts_%s`` WHERE `files` IS NOT NULL", $board['uri'])) or error(db_error());
	while (($files = $query->fetchColumn()) !== false) {
		foreach ((array)json_decode($files, true) as $file) {
			if (!empty($file['file']))
				$valid['img'][$file['file']] = true;
			if (!empty($file['thumb']))
				$valid['thumb'][$file['thumb']] = true;
		}
	}

	$stats = array('files' => 0, 'size' => 0);
	foreach (array('img', 'thumb') as $kind) {
		$dir = $board['dir'] . $config['dir'][$kind];
		foreach (glob($dir . '*') ?: array() as $path) {
			$name = basename($path);
			// index.html / dot files are directory guards, not uploads.
			if (!is_file($path) || isset($valid[$kind][$name]) || $name[0] == '.' || strtolower($name) == 'index.html' || filemtime($path) > time() - 3600)
				continue;

			$stats['files']++;
			$stats['size'] += filesize($path);

			if ($move_to) {
				$dest = $move_to . '/' . $board['uri'] . '/' . rtrim($config['dir'][$kind], '/');
				if (!is_dir($dest) && !mkdir($dest, 0755, true))
					die("error: cannot create $dest\n");
				if (!rename($path, $dest . '/' . $name))
					die("error: cannot move $path\n");
			} elseif ($delete) {
				if (!@unlink($path)) {
					$er = error_get_last();
					die("error: " . $er['message'] . "\n");
				}
			}
		}
	}

	$total['files'] += $stats['files'];
	$total['size'] += $stats['size'];
	printf("%d stray files (%s)\n", $stats['files'], format_bytes($stats['size']));
}

printf("Total: %d files (%s)%s\n", $total['files'], format_bytes($total['size']),
	$delete ? ' deleted' : ($move_to ? " moved to $move_to" : ' - nothing changed; use --move-to=DIR or --delete'));
