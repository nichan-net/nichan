<?php
/*
 * Settings of one board (/a/). They are read after inc/config.php and inc/instance-config.php, so any
 * $config value set here applies to this board only. Other boards' config.php files live only on the
 * server (they are not in git); this one and /b/'s are kept as examples.
 *
 * Often used here: 'anonymous' (the name of posters who leave it empty), 'locale' (e.g. 'en' for an
 * English board), 'country_flags' (show the poster's country), 'footer' (lines under the board pages).
 * Captcha, visibility, closing posting and flags are set in mod.php -> «Налаштування дошок», not here.
 */

	// The name shown for posters who leave the name empty.
	$config['anonymous'] = 'Ханю';
