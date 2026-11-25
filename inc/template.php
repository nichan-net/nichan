<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

defined('TINYBOARD') or exit;

$twig = false;

function load_twig() {
	global $twig, $config;
	
	require 'lib/Twig/Autoloader.php';
	Twig_Autoloader::register();

	Twig_Autoloader::autoload('Twig_Extensions_Node_Trans');
	Twig_Autoloader::autoload('Twig_Extensions_TokenParser_Trans');
	Twig_Autoloader::autoload('Twig_Extensions_Extension_I18n');
	Twig_Autoloader::autoload('Twig_Extensions_Extension_Tinyboard');
	
	// The versioned cache folder (see below) may not exist yet after an update.
	// (nor templates/cache itself, in a freshly unpacked copy of the site)
	if (!is_dir("{$config['dir']['template']}/cache/n2") && (is_writable("{$config['dir']['template']}/cache")
		|| (!is_dir("{$config['dir']['template']}/cache") && is_writable($config['dir']['template']))))
		@mkdir("{$config['dir']['template']}/cache/n2", 0777, true);

	$loader = new Twig_Loader_Filesystem($config['dir']['template']);
	$loader->setPaths($config['dir']['template']);
	$twig = new Twig_Environment($loader, array(
		'autoescape' => false,
		'cache' => is_writable('templates') || (is_dir('templates/cache') && is_writable('templates/cache')) ?
			// Versioned subfolder: bump it when the Twig library itself changes, so templates compiled
			// by an older Twig on the server are never reused.
			"{$config['dir']['template']}/cache/n2" : false,
		// Recompile a template when its file changes, so uploading new templates never needs a manual cache purge.
		'auto_reload' => true,
		'debug' => $config['debug']
	));
	$twig->addExtension(new Twig_Extensions_Extension_Tinyboard());
	$twig->addExtension(new Twig_Extensions_Extension_I18n());
}

function Element($templateFile, array $options) {
	global $config, $debug, $twig, $build_pages;
	
	if (!$twig)
		load_twig();
	
	if (function_exists('create_pm_header') && ((isset($options['mod']) && $options['mod']) || isset($options['__mod'])) && !preg_match('!^mod/!', $templateFile)) {
		$options['pm'] = create_pm_header();
	}
	
	if (isset($options['body']) && $config['debug']) {
		$_debug = $debug;
		
		if (isset($debug['start'])) {
			$_debug['time']['total'] = '~' . round((microtime(true) - $_debug['start']) * 1000, 2) . 'ms';
			$_debug['time']['init'] = '~' . round(($_debug['start_debug'] - $_debug['start']) * 1000, 2) . 'ms';
			unset($_debug['start']);
			unset($_debug['start_debug']);
		}
		if ($config['try_smarter'] && isset($build_pages) && !empty($build_pages))
			$_debug['build_pages'] = $build_pages;
		$_debug['included'] = get_included_files();
		$_debug['memory'] = round(memory_get_usage(true) / (1024 * 1024), 2) . ' MiB';
		$_debug['time']['db_queries'] = '~' . round($_debug['time']['db_queries'] * 1000, 2) . 'ms';
		$_debug['time']['exec'] = '~' . round($_debug['time']['exec'] * 1000, 2) . 'ms';
		$options['body'] .=
			'<h3>Debug</h3><pre style="white-space: pre-wrap;font-size: 10px;">' .
				str_replace("\n", '<br/>', utf8tohtml(print_r($_debug, true))) .
			'</pre>';
	}
	
	// Twig reads the source when it compiles a template. A cached stat is enough
	// to retain the empty-file check when rendering every reply in a thread.
	if (@filesize("{$config['dir']['template']}/{$templateFile}") > 0) {
		$body = $twig->render($templateFile, $options);
		
		if ($config['minify_html'] && preg_match('/\.html$/', $templateFile)) {
			$body = trim(preg_replace("/[\t\r\n]/", '', $body));
		}
		
		return $body;
	} else {
		throw new Exception("Template file '{$templateFile}' does not exist or is empty in '{$config['dir']['template']}'!");
	}
}

