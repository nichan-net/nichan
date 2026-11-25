<?php
/*
 * news.php?id=N: one news entry, with the other active news below it. News that was deactivated or has
 * expired is in the archive: only moderators and admins can still open it. See inc/nichan-news.php.
 */
require_once 'inc/functions.php';
require_once 'inc/mod/auth.php';

header('Cache-Control: no-store');

$news = isset($_GET['id']) ? nichan_news_get((int)$_GET['id']) : false;
if ($news)
	$news = nichan_news_prepare($news); // (links in the text, the discussion thread)
$archived = $news && !nichan_news_is_active($news);
if ($archived) {
	check_login(false);
	if (!$mod || !hasPermission($config['mod']['nichan_news_archive']))
		$news = false;
}
if (!$news) {
	http_response_code(404);
	header('X-Robots-Tag: noindex');
	echo Element('nichan_news.html', array(
		'config' => $config,
		'portal_section' => 'news',
		'title' => 'Новину не знайдено',
		'news' => false,
	));
	exit;
}

$others = array();
foreach (nichan_news_active(20) as $n) {
	if ($n['id'] != $news['id'])
		$others[] = $n;
}

echo Element('nichan_news.html', array(
	'config' => $config,
	'portal_section' => 'news',
	'title' => $news['subject'],
	'news' => $news,
	'name' => nichan_news_name($news),
	'archived' => $archived,
	'others' => $others,
));
