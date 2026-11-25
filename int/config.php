<?php
/*
 * Settings of the international board (/int/). They are read after inc/config.php and inc/instance-config.php,
 * so any $config value set here applies to this board only (see a/config.php for the usual ones).
 * Geolocation (the poster's country flag), flags and the [translate] button are switched per board in
 * mod.php -> «Налаштування дошок»; the lines below are only their starting values.
 */

	// An English interface on this board.
	$config['locale'] = 'en';
	$config['int_board'] = true;

	// The name shown for posters who leave the name empty.
	$config['anonymous'] = 'Anonymous';

	// The poster's country flag next to the name (until changed in «Налаштування дошок»).
	$config['country_flags'] = true;
	$config['display_flags'] = true;

	// Lines under the board's pages.
	$config['footer'][] = 'All trademarks, copyrights, comments, and images on this page are owned by and are the responsibility of their respective parties.';
