<?php
	$theme = Array();
	
	// Theme name
	$theme['name'] = 'RecentPosts';
	// Description (you can use Tinyboard markup here)
	$theme['description'] = 'Show recent posts and recently bumped threads.';
	$theme['version'] = 'v1.0';
	
	// Theme configuration	
	$theme['config'] = Array();
	
	$theme['config'][] = Array(
		'title' => 'Title',
		'name' => 'title',
		'type' => 'text',
		'default' => 'Найновіші Повідомлення'
	);
	
	$theme['config'][] = Array(
		'title' => 'Excluded boards',
		'name' => 'exclude',
		'type' => 'text',
		'comment' => '(space seperated)'
	);
	
	$theme['config'][] = Array(
		'title' => '# of recent posts',
		'name' => 'limit_posts',
		'type' => 'text',
		'default' => '15',
		'comment' => '(maximum posts to display)'
	);
	
	$theme['config'][] = Array(
		'title' => 'HTML file',
		'name' => 'html',
		'type' => 'text',
		'default' => 'recent.html',
		'comment' => '(eg. "recent.html")'
	);
	
	$theme['config'][] = Array(
		'title' => 'CSS file',
		'name' => 'css',
		'type' => 'text',
		'default' => 'recent.css',
		'comment' => '(eg. "recent.css")'
	);

	$theme['config'][] = Array(
		'title' => 'CSS stylesheet name',
		'name' => 'basecss',
		'type' => 'text',
		'default' => 'recent.css',
		'comment' => '(eg. "recent.css" - see templates/themes/recent for details)'
	);
	
	// Unique function name for building everything
	$theme['build_function'] = 'recentposts_build';
	$theme['install_callback'] = 'recentposts_install';

	if (!function_exists('recentposts_install')) {
		function recentposts_install($settings) {
			if (!is_numeric($settings['limit_posts']) || $settings['limit_posts'] < 0)
				return Array(false, '<strong>' . utf8tohtml($settings['limit_posts']) . '</strong> is not a non-negative integer.');
		}
	}
	
