<?php
require_once __DIR__ . '/inc/functions.php';
echo Element('portal.html', array('config' => $config, 'portal_section' => 'extras', 'title' => 'Цікавинки', 'body' => nichan_builtin_html('extras')));
