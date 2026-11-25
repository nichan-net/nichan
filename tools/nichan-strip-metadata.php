#!/usr/bin/php
<?php
/*
 *  nichan-strip-metadata.php - remove EXIF (GPS position, phone model, date), XMP, IPTC and comments
 *  from the pictures already on the boards (src/ and thumb/), without re-encoding them. New uploads are
 *  cleaned by post.php. JPEG photos keep their orientation. Safe to run again.
 *
 *    php tools/nichan-strip-metadata.php --dry-run   # only count
 *    php tools/nichan-strip-metadata.php             # clean
 *    php tools/nichan-strip-metadata.php --board=b   # one board
 */

require dirname(__FILE__) . '/inc/cli.php';

$opts = getopt('', array('dry-run', 'board:'));
$dry = isset($opts['dry-run']);
$total = array('files' => 0, 'with' => 0, 'bytes' => 0);

foreach (listBoards() as $b) {
	if (!empty($opts['board']) && $b['uri'] !== $opts['board'])
		continue;
	$n = $with = 0;
	foreach (array($config['dir']['img'], $config['dir']['thumb']) as $sub) {
		$dir = sprintf($config['board_path'], $b['uri']) . $sub;
		foreach ((array)glob($dir . '*.{jpg,jpeg,JPG,JPEG,png,PNG,webp,WEBP}', GLOB_BRACE) as $path) {
			$n++;
			$before = filesize($path);
			if (nichan_strip_metadata($path, null, !$dry)) {
				$with++;
				clearstatcache(true, $path);
				if (!$dry)
					$total['bytes'] += $before - filesize($path);
			}
		}
	}
	$total['files'] += $n;
	$total['with'] += $with;
	echo "/{$b['uri']}/: $n pictures, " . ($dry ? 'with metadata' : 'cleaned') . ": $with\n";
}
echo ($dry ? 'Would clean' : 'Cleaned') . " {$total['with']} of {$total['files']} pictures" .
	($dry ? '' : ', ' . round($total['bytes'] / 1048576, 1) . ' MB smaller') . ".\n";
