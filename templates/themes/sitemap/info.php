<?php
	$theme = Array();

	// Theme name
	$theme['name'] = 'Sitemap Generator';
	// Description (you can use Tinyboard markup here)
	$theme['description'] = 'sitemap.xml for search engines: every public board and thread (with the time of its latest post), the front page, statistics and FAQ. Addresses come from $config[\'nichan_public_url\'].';
	$theme['version'] = 'v2.0-nichan';

	// Theme configuration
	$theme['config'] = Array();

	$theme['config'][] = Array(
		'title' => 'Sitemap Path',
		'name' => 'path',
		'type' => 'text',
		'default' => 'sitemap.xml',
		'size' => '20'
	);

	$theme['config'][] = Array(
		'title' => 'Minimum time between regenerating on new threads',
		'name' => 'regen_time',
		'type' => 'text',
		'comment' => '(in seconds; replies regenerate it at most every 15 minutes)',
		'default' => '0',
		'size' => '8'
	);

	// Unique function name for building everything
	$theme['build_function'] = 'sitemap_build';
