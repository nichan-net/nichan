<?php
/*
 * settings.php: the visitor's own settings ("Усі налаштування"). Everything is kept in this browser
 * (localStorage); the page itself is built by js/nichan.js, this only gives it the site's header and styles.
 */
require_once 'inc/functions.php';

$en = isset($_GET['lang']) && $_GET['lang'] === 'en';
if ($en)
	$config['locale'] = 'en';
header('X-Robots-Tag: noindex');
echo Element('portal.html', array(
	'portal_section' => 'settings',
	'portal_en' => $en,
	'config' => $config,
	'title' => $en ? 'My settings' : 'Мої налаштування',
	'subtitle' => $en ? 'kept in this browser only' : 'зберігаються лише в цьому браузері',
	'body' => '<div id="nichan-settings" style="max-width:820px;margin:0 auto;padding:0 8px"><noscript>' .
		($en ? 'The settings need JavaScript.' : 'Для налаштувань потрібен JavaScript.') . '</noscript></div>',
));
