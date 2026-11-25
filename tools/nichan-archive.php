#!/usr/bin/php
<?php
/*
 *  nichan-archive.php - move the threads past each board's last page to the archive now (normally that
 *  happens when a new thread is posted) and rebuild their pages, the archive pages and the board pages.
 *  The number of pages is set in mod.php -> «Фільтри слів і флуд» -> «Архів».
 *
 *    php tools/nichan-archive.php            # all boards
 *    php tools/nichan-archive.php --board=b  # one board
 */

require dirname(__FILE__) . '/inc/cli.php';

$opts = getopt('', array('board:'));
if (nichan_archive_pages() < 1)
	die("The archive is off (0 pages).\n");
foreach (listBoards() as $b) {
	if (!empty($opts['board']) && $b['uri'] !== $opts['board'])
		continue;
	openBoard($b['uri']);
	$n = nichan_archive_run($b['uri'], null);
	nichan_archive_build_page($b['uri']);
	buildIndex();
	echo "/{$b['uri']}/: archived $n threads\n";
}
rebuildThemes('all');
echo "Done.\n";
