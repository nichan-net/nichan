#!/usr/bin/php
<?php
/*
 *  nichan-stats.php - refresh the cached site statistics (board speed, charts, popular threads).
 *  Optional: the site refreshes them on its own every few minutes; cron can call this to keep
 *  page views from ever doing the work.
 *
 *  Usage: php tools/nichan-stats.php
 */

require dirname(__FILE__) . '/inc/cli.php';

$stats = nichan_stats(0);
nichan_popular_threads(0);
// The front page embeds these numbers; rebuild it so it shows the fresh ones.
rebuildTheme('recent', 'all');
printf("Posts in the last 24h: %d, posters: %d\n", $stats['total']['day_posts'], $stats['total']['day_posters']);
