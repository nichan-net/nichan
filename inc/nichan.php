<?php

/*
 * Nichan site features that sit on top of the vichan engine:
 *  - site settings stored in the database (``nichan_settings``)
 *  - schema migrations (applied automatically, idempotent, safe to re-run on a newer dump)
 *  - board visibility (public / moderators and admins / admins only)
 *  - closing posting on a board or on the whole site
 */

defined('TINYBOARD') or exit;

define('NICHAN_SCHEMA_VERSION', 23);

// Board visibility levels (``boards``.`visibility`).
define('NICHAN_VISIBILITY_PUBLIC', 0);
define('NICHAN_VISIBILITY_STAFF', 1);   // moderators and admins
define('NICHAN_VISIBILITY_ADMINS', 2);  // admins only
define('NICHAN_VISIBILITY_UNLISTED', 3); // hidden: open to anyone with the link, but not listed anywhere

/* ---------------------------------------------------------------- settings */

$nichan_settings = null;

function nichan_setting($name, $default = null) {
	global $nichan_settings;
	if ($nichan_settings === null) {
		$nichan_settings = array();
		$query = query('SELECT `name`, `value` FROM ``nichan_settings``');
		if ($query) {
			foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row)
				$nichan_settings[$row['name']] = $row['value'];
		}
	}
	return array_key_exists($name, $nichan_settings) ? $nichan_settings[$name] : $default;
}

function nichan_set_setting($name, $value) {
	global $nichan_settings;
	$query = prepare('INSERT INTO ``nichan_settings`` (`name`, `value`) VALUES (:name, :value) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
	$query->bindValue(':name', $name);
	$query->bindValue(':value', $value);
	$query->execute() or error(db_error($query));
	if ($nichan_settings !== null)
		$nichan_settings[$name] = $value;
}

function nichan_delete_setting($name) {
	global $nichan_settings;
	$query = prepare('DELETE FROM ``nichan_settings`` WHERE `name` = :name');
	$query->bindValue(':name', $name);
	$query->execute() or error(db_error($query));
	if ($nichan_settings !== null)
		unset($nichan_settings[$name]);
}

/* -------------------------------------------------------------- migrations */

// Cheap per-request check: a marker file says the current schema is in place.
function nichan_schema_check() {
	$marker = dirname(__DIR__) . '/tmp/cache/nichan-schema-' . NICHAN_SCHEMA_VERSION;
	if (is_file($marker))
		return;
	// Right after an update many requests arrive at once: one of them migrates, the others wait for it
	// (else two could add the same column, and one of them would fail).
	$lock = @fopen(dirname(__DIR__) . '/tmp/cache/nichan-migrate.lock', 'c');
	if ($lock)
		flock($lock, LOCK_EX);
	clearstatcache(true, $marker);
	if (!is_file($marker)) {
		nichan_migrate();
		@file_put_contents($marker, date('c'));
	}
	if ($lock) {
		flock($lock, LOCK_UN);
		fclose($lock);
	}
}

function nichan_column_exists($table, $column) {
	global $config;
	$query = prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c');
	$query->bindValue(':t', $config['db']['prefix'] . $table);
	$query->bindValue(':c', $column);
	$query->execute() or error(db_error($query));
	return (bool)$query->fetchColumn();
}

/*
 * Brings any copy of the nichan database (including a fresh dump of the old live site) up to date.
 * Every step checks before it changes anything, so running it again is harmless.
 */
function nichan_migrate($verbose = false) {
	$log = function ($msg) use ($verbose) { if ($verbose) echo $msg, "\n"; };

	query('CREATE TABLE IF NOT EXISTS ``nichan_settings`` (
		`name` VARCHAR(64) NOT NULL,
		`value` MEDIUMTEXT NULL,
		PRIMARY KEY (`name`)
	) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci') or error(db_error());

	if (!nichan_column_exists('boards', 'visibility')) {
		query('ALTER TABLE ``boards`` ADD `visibility` TINYINT(1) NOT NULL DEFAULT 0') or error(db_error());
		$log('boards.visibility added');
	}
	if (!nichan_column_exists('boards', 'posting_closed')) {
		query('ALTER TABLE ``boards`` ADD `posting_closed` TINYINT(1) NOT NULL DEFAULT 0') or error(db_error());
		$log('boards.posting_closed added');
	}

	// v2 ----------------------------------------------------------------------------------------
	global $nichan_settings;
	$nichan_settings = null;
	if (nichan_setting('board_order') === null)
		nichan_set_setting('board_order', 'a b int');
	// reCAPTCHA keys and switch live in the database (Board settings in mod.php), not in files.
	if (nichan_setting('recaptcha_public') === null) {
		nichan_set_setting('recaptcha_public', '');
		nichan_set_setting('recaptcha_private', '');
		nichan_set_setting('recaptcha_enabled', 0);
		// "*" = every board; the switch itself starts off
		nichan_set_setting('recaptcha_boards', '*');
	}
	// Custom tripcodes configured in the moderation panel.
	query('CREATE TABLE IF NOT EXISTS ``nichan_trips`` (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
		`ips` TEXT CHARACTER SET ascii NULL,
		`text` VARCHAR(40) NOT NULL,
		`style` TEXT NULL,
		`note` VARCHAR(200) NULL,
		`created` INT UNSIGNED NOT NULL,
		PRIMARY KEY (`id`)
	) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci') or error(db_error());

	// v3 ----------------------------------------------------------------------------------------
	// Captcha: the reCAPTCHA "switch + list of boards" becomes a captcha type per board
	// ("captcha_<uri>": recaptcha / uk / en) plus one "captcha on all boards" switch.
	if (nichan_setting('step_captcha_types') === null) {
		$rc_on = (int)nichan_setting('recaptcha_enabled', 0);
		$rc_boards = preg_split('/[\s,]+/', (string)nichan_setting('recaptcha_boards', '*'), -1, PREG_SPLIT_NO_EMPTY);
		$all = $rc_on && in_array('*', $rc_boards);
		nichan_set_setting('captcha_all', $all ? 1 : 0);
		$query = query('SELECT `uri` FROM ``boards``') or error(db_error());
		foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $uri) {
			if (nichan_setting('captcha_' . $uri) !== null)
				continue;
			if ($rc_on && !$all && in_array($uri, $rc_boards))
				nichan_set_setting('captcha_' . $uri, 'recaptcha');
		}
		query("DELETE FROM ``nichan_settings`` WHERE `name` IN ('recaptcha_enabled', 'recaptcha_boards')") or error(db_error());
		nichan_set_setting('step_captcha_types', 1);
		$log('captcha settings converted');
	}
	// Telegram bot token and chat move into the database (mod.php -> Board settings -> Integration);
	// the values in inc/instance-config.php are only used for this first copy.
	if (nichan_setting('telegram_bot_token') === null) {
		global $config;
		nichan_set_setting('telegram_bot_token', isset($config['telegram']['bot_token']) ? (string)$config['telegram']['bot_token'] : '');
		nichan_set_setting('telegram_chat_id', isset($config['telegram']['chat_id']) ? (string)$config['telegram']['chat_id'] : '');
	}
	// A custom tripcode now also needs its owner's code ("Name#code"); stored as a password hash.
	if (!nichan_column_exists('nichan_trips', 'secret')) {
		query("ALTER TABLE ``nichan_trips`` ADD `secret` VARCHAR(255) NOT NULL DEFAULT ''") or error(db_error());
		$log('nichan_trips.secret added');
	}
	// Challenges of the site's own picture captcha (two words; see inc/nichan-captcha.php).
	query('CREATE TABLE IF NOT EXISTS ``nichan_captchas`` (
		`id` CHAR(32) CHARACTER SET ascii NOT NULL,
		`answer` VARCHAR(64) NOT NULL,
		`created` INT UNSIGNED NOT NULL,
		PRIMARY KEY (`id`),
		KEY `created` (`created`)
	) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci') or error(db_error());

	// v4 ----------------------------------------------------------------------------------------
	// Banned files: MD5 of exact copies and a picture hash for re-saved ones (inc/nichan-filebans.php).
	query('CREATE TABLE IF NOT EXISTS ``nichan_banned_files`` (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
		`md5` CHAR(32) CHARACTER SET ascii NOT NULL,
		`dhash` CHAR(16) CHARACTER SET ascii NULL,
		`reason` VARCHAR(255) NOT NULL DEFAULT \'\',
		`origin` VARCHAR(64) NOT NULL DEFAULT \'\',
		`created` INT UNSIGNED NOT NULL,
		`mod` VARCHAR(30) NOT NULL DEFAULT \'\',
		`hits` INT UNSIGNED NOT NULL DEFAULT 0,
		`last_hit` INT UNSIGNED NULL,
		PRIMARY KEY (`id`),
		UNIQUE KEY `md5` (`md5`)
	) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci') or error(db_error());

	// v5 ----------------------------------------------------------------------------------------
	// Word filters (block / replace) and flood cooldowns (inc/nichan-antispam.php).
	query('CREATE TABLE IF NOT EXISTS ``nichan_word_filters`` (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
		`action` VARCHAR(10) CHARACTER SET ascii NOT NULL,
		`pattern` VARCHAR(255) NOT NULL,
		`is_regex` TINYINT(1) NOT NULL DEFAULT 0,
		`whole_word` TINYINT(1) NOT NULL DEFAULT 0,
		`replacement` VARCHAR(255) NOT NULL DEFAULT \'\',
		`note` VARCHAR(255) NOT NULL DEFAULT \'\',
		`created` INT UNSIGNED NOT NULL,
		`mod` VARCHAR(30) NOT NULL DEFAULT \'\',
		`hits` INT UNSIGNED NOT NULL DEFAULT 0,
		`last_hit` INT UNSIGNED NULL,
		PRIMARY KEY (`id`)
	) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci') or error(db_error());
	query('CREATE TABLE IF NOT EXISTS ``nichan_post_log`` (
		`ip` VARCHAR(39) CHARACTER SET ascii NOT NULL,
		`time` INT UNSIGNED NOT NULL,
		`thread` TINYINT(1) NOT NULL DEFAULT 0,
		KEY `ip_time` (`ip`, `time`),
		KEY `time` (`time`)
	) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci') or error(db_error());
	query('CREATE TABLE IF NOT EXISTS ``nichan_cooldowns`` (
		`ip` VARCHAR(39) CHARACTER SET ascii NOT NULL,
		`until` INT UNSIGNED NOT NULL,
		`last_until` INT UNSIGNED NULL,
		`strikes` INT UNSIGNED NOT NULL DEFAULT 0,
		`reason` VARCHAR(100) NOT NULL DEFAULT \'\',
		`created` INT UNSIGNED NOT NULL,
		PRIMARY KEY (`ip`)
	) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci') or error(db_error());
	// v6: attempts counted per IP (or per user name) for the rate limits: logins, reports, captchas, deletes
	query('CREATE TABLE IF NOT EXISTS ``nichan_rate`` (
		`kind` VARCHAR(16) CHARACTER SET ascii NOT NULL,
		`who` VARCHAR(64) NOT NULL,
		`time` INT UNSIGNED NOT NULL,
		KEY `kind_who_time` (`kind`, `who`, `time`),
		KEY `time` (`time`)
	) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci') or error(db_error());
	// v7: push notifications about replies (inc/nichan-push.php)
	query('CREATE TABLE IF NOT EXISTS ``nichan_push_subs`` (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
		`endpoint_hash` CHAR(64) CHARACTER SET ascii NOT NULL,
		`endpoint` VARCHAR(1000) CHARACTER SET ascii NOT NULL,
		`p256dh` VARCHAR(100) CHARACTER SET ascii NOT NULL,
		`auth` VARCHAR(40) CHARACTER SET ascii NOT NULL,
		`lang` VARCHAR(2) CHARACTER SET ascii NOT NULL DEFAULT \'uk\',
		`created` INT UNSIGNED NOT NULL,
		`seen` INT UNSIGNED NOT NULL,
		`fails` INT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY (`id`),
		UNIQUE KEY `endpoint_hash` (`endpoint_hash`)
	) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci') or error(db_error());
	query('CREATE TABLE IF NOT EXISTS ``nichan_push_posts`` (
		`sub` INT UNSIGNED NOT NULL,
		`board` VARCHAR(58) NOT NULL,
		`post` INT UNSIGNED NOT NULL,
		`time` INT UNSIGNED NOT NULL,
		PRIMARY KEY (`board`, `post`, `sub`),
		KEY `sub` (`sub`),
		KEY `time` (`time`)
	) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci') or error(db_error());
	// v8: threads — the OP mark, the archive, continuations, threads waiting for a moderator (inc/nichan-threads.php)
	query('CREATE TABLE IF NOT EXISTS ``nichan_opmarks`` (
		`board` VARCHAR(58) NOT NULL,
		`thread` INT UNSIGNED NOT NULL,
		`ip` VARCHAR(39) CHARACTER SET ascii NOT NULL,
		`password` VARCHAR(20) NOT NULL DEFAULT \'\',
		PRIMARY KEY (`board`, `thread`)
	) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci') or error(db_error());
	query('CREATE TABLE IF NOT EXISTS ``nichan_archive`` (
		`board` VARCHAR(58) NOT NULL,
		`thread` INT UNSIGNED NOT NULL,
		`time` INT UNSIGNED NOT NULL,
		PRIMARY KEY (`board`, `thread`),
		KEY `board_time` (`board`, `time`)
	) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci') or error(db_error());
	// v9: a thread taken out of the archive is unlocked only if the archive locked it
	if (!nichan_column_exists('nichan_archive', 'was_locked'))
		query('ALTER TABLE ``nichan_archive`` ADD `was_locked` TINYINT(1) NOT NULL DEFAULT 0') or error(db_error());
	query('CREATE TABLE IF NOT EXISTS ``nichan_continuations`` (
		`board` VARCHAR(58) NOT NULL,
		`old` INT UNSIGNED NOT NULL,
		`new` INT UNSIGNED NOT NULL,
		PRIMARY KEY (`board`, `old`),
		KEY `new` (`board`, `new`)
	) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci') or error(db_error());
	query('CREATE TABLE IF NOT EXISTS ``nichan_pending`` (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
		`board` VARCHAR(58) NOT NULL,
		`ip` VARCHAR(39) CHARACTER SET ascii NOT NULL,
		`time` INT UNSIGNED NOT NULL,
		`data` MEDIUMTEXT NOT NULL,
		PRIMARY KEY (`id`)
	) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci') or error(db_error());
	// v10: a waiting thread keeps its fate for its author's page ("на розгляді" / "схвалено" / "відхилено")
	if (!nichan_column_exists('nichan_pending', 'status')) {
		query('ALTER TABLE ``nichan_pending`` ADD `token` CHAR(32) CHARACTER SET ascii NOT NULL DEFAULT \'\', ADD `status` VARCHAR(10) CHARACTER SET ascii NOT NULL DEFAULT \'pending\',
			ADD `thread` INT UNSIGNED NULL, ADD `decided` INT UNSIGNED NULL, ADD KEY `status` (`status`)') or error(db_error());
	}
	// v11: visitors with a page open right now (online.php): a random id per browser, no IP
	query('CREATE TABLE IF NOT EXISTS ``nichan_online`` (
		`visitor` CHAR(16) CHARACTER SET ascii NOT NULL,
		`board` VARCHAR(58) NOT NULL DEFAULT \'\',
		`thread` INT UNSIGNED NOT NULL DEFAULT 0,
		`seen` INT UNSIGNED NOT NULL,
		PRIMARY KEY (`visitor`, `board`, `thread`),
		KEY `seen` (`seen`)
	) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci') or error(db_error());
	// v12 ---------------------------------------------------------------------------------------
	// News gets a short description, a moment until which it is up, and a switch; the old entries were
	// never shown anywhere, so they go straight to the archive instead of popping up on the front page.
	if (!nichan_column_exists('news', 'active')) {
		query('ALTER TABLE ``news`` ADD `summary` TEXT NULL, ADD `until` INT UNSIGNED NULL DEFAULT NULL, ADD `active` TINYINT(1) NOT NULL DEFAULT 1') or error(db_error());
		query('UPDATE ``news`` SET `active` = 0') or error(db_error());
		$log('news.summary, news.until, news.active added');
	}

	// v13 ---------------------------------------------------------------------------------------
	// A custom tripcode may be forced on an address: every post from it gets the tripcode, code or not.
	if (!nichan_column_exists('nichan_trips', 'always')) {
		query('ALTER TABLE ``nichan_trips`` ADD `always` TINYINT(1) NOT NULL DEFAULT 0') or error(db_error());
		$log('nichan_trips.always added');
	}

	// v14 ---------------------------------------------------------------------------------------
	// static/flags/geo.css (regions of Ukraine, boards' own flag pictures) is linked on every page.
	if (!is_file(dirname(__DIR__) . '/static/flags/geo.css')) {
		$cwd = getcwd();
		chdir(dirname(__DIR__));
		nichan_write_geo_css();
		chdir($cwd);
		$log('static/flags/geo.css written');
	}

	// v15 ---------------------------------------------------------------------------------------
	// «Обговорення новини»: the thread where a news entry is talked over ("board/number")
	if (!nichan_column_exists('news', 'thread')) {
		query('ALTER TABLE ``news`` ADD `thread` VARCHAR(80) NULL') or error(db_error());
		$log('news.thread added');
	}

	// v16 ---------------------------------------------------------------------------------------
	// the text of a news entry as it was typed, to edit it later (older news: worked back from the HTML)
	if (!nichan_column_exists('news', 'body_raw')) {
		query('ALTER TABLE ``news`` ADD `body_raw` TEXT NULL') or error(db_error());
		$log('news.body_raw added');
	}

	// v17 ---------------------------------------------------------------------------------------
	// The app's translations of posts (inc/nichan-translate.php): the kept ones, and how many characters
	// each address sent to Azure (no texts).
	query('CREATE TABLE IF NOT EXISTS ``nichan_translations`` (
		`board` VARCHAR(58) NOT NULL,
		`post` INT UNSIGNED NOT NULL,
		`lang` VARCHAR(12) NOT NULL,
		`hash` CHAR(32) NOT NULL,
		`subject` TEXT NULL,
		`body` MEDIUMTEXT NOT NULL,
		`from_lang` VARCHAR(12) NULL,
		`time` INT UNSIGNED NOT NULL,
		PRIMARY KEY (`board`, `post`, `lang`),
		KEY `time` (`time`)
	) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci') or error(db_error());
	query('CREATE TABLE IF NOT EXISTS ``nichan_translate_use`` (
		`who` VARCHAR(64) NOT NULL,
		`time` INT UNSIGNED NOT NULL,
		`chars` INT UNSIGNED NOT NULL,
		KEY `who_time` (`who`, `time`),
		KEY `time` (`time`)
	) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci') or error(db_error());

	// v18 ---------------------------------------------------------------------------------------
	// Requests per minute of one kind from one address, one row per minute (the app's notification check runs
	// every few seconds: a row per request, as nichan_rate keeps, would be too many).
	query('CREATE TABLE IF NOT EXISTS ``nichan_rate_counter`` (
		`kind` VARCHAR(16) NOT NULL,
		`who` VARCHAR(64) NOT NULL,
		`minute` INT UNSIGNED NOT NULL,
		`n` INT UNSIGNED NOT NULL,
		PRIMARY KEY (`kind`, `who`, `minute`),
		KEY `minute` (`minute`)
	) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci') or error(db_error());

	// v19: link alerts are independent of visitor reports and never hold publication.
	query('CREATE TABLE IF NOT EXISTS ``nichan_link_alerts`` (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
		`board` VARCHAR(58) NOT NULL,
		`post` INT UNSIGNED NOT NULL,
		`chat` VARCHAR(32) NOT NULL,
		`message` BIGINT NULL,
		`status` VARCHAR(16) NOT NULL DEFAULT "pending",
		`ban_applied` TINYINT NOT NULL DEFAULT 0,
		`created` INT UNSIGNED NOT NULL,
		PRIMARY KEY (`id`),
		UNIQUE KEY `post` (`board`, `post`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci') or error(db_error());

	// v20: early installers copied the default FAQ into the editable settings. Match the
	// two shipped versions (0df5433 and 38c0f5b), allowing for Windows line endings.
	// Comparing inside DELETE also preserves a concurrent edit. Missing rows use the current FAQ.
	if (nichan_setting('step_faq_defaults') === null) {
		query("DELETE FROM ``nichan_settings`` WHERE
			(`name` = 'faq_uk' AND SHA2(REPLACE(`value`, CHAR(13,10), CHAR(10)), 256) IN
				('e6a52798853616fcad403e4bda0c91aa0067c7e8748313864789c8eee95a94c9', '2739afb05319f744cd6bd6e3d096c86a8171cdaa0e56e4db5811e14c39b50e47'))
			OR (`name` = 'faq_en' AND SHA2(REPLACE(`value`, CHAR(13,10), CHAR(10)), 256) IN
				('f6bd261d46c79daf4fedc871faa2b370b3d72d1a8a8ef4d4b7e5a64a2af699f3', 'a59a1d64ff94d3e6db1b91da4e6e7e4f7da8658533684a0b6f53a77f5b0dbdcc'))") or error(db_error());
		$nichan_settings = null;
		nichan_set_setting('step_faq_defaults', 1);
	}

	// v21: NULL means unrestricted IP. Existing single addresses remain bound to the same ID.
	if (nichan_column_exists('nichan_trips', 'ip')) {
		query('ALTER TABLE ``nichan_trips`` DROP INDEX `ip`, CHANGE COLUMN `ip` `ips` TEXT CHARACTER SET ascii NULL') or error(db_error());
		$log('nichan_trips.ip converted to an address list');
	}

	// Board rows are cached; drop the cached copies so the new columns show up.
	if (class_exists('Cache'))
		Cache::flush();

	nichan_write_board_access_files();
	nichan_write_posting_status_files();
	nichan_write_flags_css();
	$log('schema version ' . NICHAN_SCHEMA_VERSION);
}

/* -------------------------------------------------------- board visibility */

function nichan_board_visibility($uri) {
	$b = getBoardInfo($uri);
	return $b && isset($b['visibility']) ? (int)$b['visibility'] : null;
}

function nichan_resolve_mod($who) {
	if ($who === true) {
		// templates pass mod = true on moderator pages
		global $mod;
		return $mod;
	}
	return $who;
}

// Can the given moderator (or a visitor, when $who is false) open this board?
function nichan_can_see_board($uri, $who = false) {
	return nichan_visibility_allows(nichan_board_visibility($uri), $who);
}

function nichan_visibility_allows($level, $who = false) {
	$who = nichan_resolve_mod($who);
	if ($level === NICHAN_VISIBILITY_PUBLIC || $level === NICHAN_VISIBILITY_UNLISTED)
		return true;
	if (!$who || !isset($who['type']))
		return false;
	if ($level === NICHAN_VISIBILITY_ADMINS)
		return $who['type'] >= ADMIN;
	return $level === NICHAN_VISIBILITY_STAFF && $who['type'] >= MOD;
}

// Is the board shown in menus, on the front page, in statistics, sitemaps and so on?
function nichan_board_listed($uri) {
	return nichan_board_visibility($uri) === NICHAN_VISIBILITY_PUBLIC;
}

// Board order shared by the menu, the front page and the statistics (Board settings in mod.php).
function nichan_board_order() {
	$order = preg_split('/[\s,]+/', (string)nichan_setting('board_order', 'a b int'), -1, PREG_SPLIT_NO_EMPTY);
	return $order;
}

// After a board is deleted (mod.php -> edit board -> delete): forget its settings, take it out of
// the order and the status files, and rebuild the other boards' pages so their menus drop it.
function nichan_board_deleted($uri) {
	global $config, $nichan_settings;
	$query = prepare('DELETE FROM ``nichan_settings`` WHERE `name` IN (:captcha, :flags)');
	$query->bindValue(':captcha', 'captcha_' . $uri);
	$query->bindValue(':flags', 'user_flags_' . $uri);
	$query->execute() or error(db_error($query));
	$nichan_settings = null;
	$order = array_values(array_diff(nichan_board_order(), array($uri)));
	nichan_set_setting('board_order', implode(' ', $order));
	if ($config['cache']['enabled']) {
		cache::delete('all_boards');
		cache::delete('all_boards_uri');
	}
	nichan_write_board_access_files();
	nichan_write_posting_status_files();
	if (function_exists('nichan_stats_flush'))
		nichan_stats_flush();
	rebuild_batch_begin();
	foreach (listBoards() as $b) {
		if ($b['uri'] == $uri)
			continue;
		openBoard($b['uri']);
		buildIndex();
	}
	// the front page, catalog, sitemap and RSS ignore the engine's own rebuildThemes('boards')
	rebuildThemes('all');
	rebuild_batch_end();
}

function nichan_sort_boards(array $boards) {
	$order = nichan_board_order();
	usort($boards, function ($x, $y) use ($order) {
		$a = array_search($x['uri'], $order);
		$b = array_search($y['uri'], $order);
		$a = $a === false ? 1000 : $a;
		$b = $b === false ? 1000 : $b;
		return $a == $b ? strcmp($x['uri'], $y['uri']) : $a - $b;
	});
	return $boards;
}

/*
 * Boards for menus. A visitor (false) gets the listed (public) boards. A moderator gets those
 * plus, with $hidden = true, the ones hidden from lists that he may open (staff-only, admins-only
 * and "easter egg" boards).
 */
function nichan_boards_for($who = false, $hidden = false) {
	$who = nichan_resolve_mod($who);
	$boards = array();
	foreach (listBoards() as $b) {
		$level = (int)$b['visibility'];
		$listed = $level === NICHAN_VISIBILITY_PUBLIC;
		if ($hidden ? (!$listed && $who && nichan_visibility_allows($level, $who)) : $listed)
			$boards[] = $b;
	}
	return nichan_sort_boards($boards);
}

function nichan_non_public_board_uris() {
	$out = array();
	foreach (listBoards() as $b) {
		if ((int)$b['visibility'] !== NICHAN_VISIBILITY_PUBLIC)
			$out[] = $b['uri'];
	}
	return $out;
}

function nichan_public_board_uris() {
	return array_map(function ($b) { return $b['uri']; }, nichan_boards_for(false));
}

// Keep all visibility writers serialized until their DB changes and access files agree.
// Only administrative saves, migrations and board deletion take this lock.
function nichan_lock_board_access_files() {
	static $lock;
	if (is_resource($lock))
		return;
	$path = dirname(__DIR__) . '/tmp/nichan-board-access.lock';
	$lock = @fopen($path, 'c') ?: @fopen($path, 'r');
	if (!$lock || !flock($lock, LOCK_EX))
		throw new RuntimeException('Cannot lock the board access settings.');
}

// Install the rule before making a board private. A single rename keeps the previous
// protection in force until the new rule is completely written.
function nichan_protect_board_files($uri) {
	global $config;
	$dir = sprintf($config['board_path'], $uri);
	if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir))
		throw new RuntimeException("Cannot create the private board directory: " . $dir);
	$file = $dir . '.htaccess';
	$root = (string)parse_url($config['root'], PHP_URL_PATH);
	$target = rtrim($root, '/') . '/private-media.php?board=' . rawurlencode($uri);
	$rules = "# Written by nichan: restricted board\n" .
		"Options -Indexes -MultiViews\nRewriteEngine On\n" .
		// The controller checks the board role and serves only files inside its media directories.
		"RewriteRule ^.+/[^/]+$ \"$target\" [END]\nRewriteRule ^ - [F,L]\n" .
		"<IfModule mod_headers.c>\nHeader always set Cache-Control \"private, no-store\"\n" .
		"Header always set Cloudflare-CDN-Cache-Control \"no-store\"\n</IfModule>\n";
	if (is_file($file) && file_get_contents($file) === $rules)
		return;
	if (is_file($file) && strpos((string)file_get_contents($file), '# Written by nichan') !== 0)
		throw new RuntimeException("Existing board access rules need review: " . $file);
	$temp = tempnam($dir, '.nichan-access-');
	if (!$temp || dirname($temp) !== realpath($dir) || file_put_contents($temp, $rules) !== strlen($rules)
			|| !chmod($temp, 0644) || !rename($temp, $file)) {
		if ($temp && is_file($temp))
			unlink($temp);
		throw new RuntimeException("Cannot protect the private board directory: " . $dir);
	}
	// The old public URLs, including alternate query strings, may already be held at the edge.
	nichan_cf_queue_purge_all();
}

function nichan_write_board_access_files() {
	global $config;
	nichan_lock_board_access_files();
	foreach (listBoards() as $b) {
		$file = sprintf($config['board_path'], $b['uri']) . '.htaccess';
		if (in_array((int)$b['visibility'], array(NICHAN_VISIBILITY_STAFF, NICHAN_VISIBILITY_ADMINS), true)) {
			nichan_protect_board_files($b['uri']);
		} elseif (is_file($file) && strpos((string)file_get_contents($file), '# Written by nichan') === 0) {
			if (!unlink($file))
				throw new RuntimeException("Cannot reopen the board directory: " . $file);
		}
	}
}

/* ---------------------------------------------------------- posting closed */

function nichan_site_closed() {
	return (bool)nichan_setting('site_closed', 0);
}

function nichan_board_posting_closed($uri) {
	$b = getBoardInfo($uri);
	return $b && !empty($b['posting_closed']);
}

// false, 'site' or 'board'
function nichan_posting_closed($uri) {
	if (nichan_site_closed())
		return 'site';
	if (nichan_board_posting_closed($uri))
		return 'board';
	return false;
}

function nichan_posting_closed_message($why, $english = false) {
	if ($why == 'site')
		return $english ? 'The administrator has temporarily closed posting on the whole site.'
			: 'Адміністратор тимчасово закрив можливість писати на сайті.';
	return $english ? 'The administrator has closed posting on this board.'
		: 'Адміністратор закрив можливість писати у цій дошці.';
}

/*
 * Pages are static HTML, so the post form is hidden by a tiny stylesheet every page links to:
 * /posting-status.css (whole site) and /<board>/posting-status.css. Toggling only rewrites these
 * files; no page has to be rebuilt. Moderator views don't link them.
 */
function nichan_board_status_script(array $uris) {
	$types = array();
	foreach ($uris as $uri) {
		if ($type = nichan_captcha_type($uri))
			$types[$uri] = $type;
	}
	$country = nichan_country_captcha_boards();
	$banners = nichan_banner_map($uris, $links);
	$status = array(
		'captcha' => array('key' => in_array('recaptcha', $types, true) ? (string)nichan_setting('recaptcha_public') : '',
			'boards' => (object)$types, 'country' => in_array('*', $country, true) ? array('*') : array_values(array_intersect($country, $uris))),
		'translate' => array_values(array_filter($uris, 'nichan_board_translate_on')),
		'view' => (string)nichan_setting('board_view_default', 'standard'),
		'banners' => (object)$banners, 'bannerLinks' => (object)$links
	);
	return 'window.nichanStatus = ' . json_encode($status, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ";\n";
}

// Moderator board pages are dynamic. Keep their data out of static files on restricted boards.
function nichan_mod_board_status($uri) {
	global $mod;
	return $mod && nichan_can_see_board($uri, $mod) ? nichan_board_status_script(array($uri)) : '';
}

function nichan_write_status_script($path, array $uris) {
	$script = nichan_board_status_script($uris);
	if (file_put_contents($path, $script, LOCK_EX) !== strlen($script))
		throw new RuntimeException('Cannot write board status: ' . $path);
	nichan_cf_queue($path);
}

function nichan_write_posting_status_files() {
	global $config, $nichan_settings, $nichan_country_policy_cache;
	nichan_lock_board_access_files();
	// Another request may have changed visibility or gallery assignments while we waited.
	$nichan_settings = $nichan_country_policy_cache = null;
	nichan_write_status_script('site-status.js', nichan_public_board_uris());
	nichan_cf_queue('posting-status.css');
	$hide = "form[name=\"post\"], #quick-reply, .createbutton { display: none !important; }\n" .
		".nichan-closed { margin: 1em auto; padding: .6em 1em; max-width: 600px; text-align: center; font-weight: bold; border: 1px solid rgba(200,60,60,.6); }\n";
	@file_put_contents('posting-status.css', nichan_site_closed()
		? "/* posting closed on the whole site */\n" . $hide . ".nichan-closed-site { display: block !important; }\n"
		: "/* posting open */\n");
	foreach (listBoards() as $b) {
		$dir = sprintf($config['board_path'], $b['uri']);
		$status = $dir . 'site-status.js';
		if (nichan_visibility_allows((int)$b['visibility'])) {
			if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir))
				throw new RuntimeException('Cannot create board directory: ' . $dir);
			nichan_write_status_script($status, array($b['uri']));
		} elseif (is_file($status)) {
			if (!unlink($status))
				throw new RuntimeException('Cannot remove old board status: ' . $status);
			nichan_cf_queue($status);
		}
		@file_put_contents($dir . 'posting-status.css', !empty($b['posting_closed'])
			? "/* posting closed on this board */\n" . $hide . ".nichan-closed-board { display: block !important; }\n"
			: "/* posting open */\n");
		nichan_cf_queue($dir . 'posting-status.css');
	}
}

/* ------------------------------------------------ per-board settings from DB */

// vichan calls this right after a board's config has been loaded (see openBoard()).
function after_open_board() {
	global $config, $board;
	// (loadConfig() for the board read config.php again)
	if (function_exists('nichan_apply_site_settings'))
		nichan_apply_site_settings();
	if (!isset($board['uri']))
		return;
	$uri = $board['uri'];

	// With the archive on, a board shows this many pages; older threads are archived (inc/nichan-threads.php).
	if (nichan_archive_pages() > 0)
		$config['max_pages'] = nichan_archive_pages();

	// Captcha type of the board (Board settings in mod.php); reCAPTCHA keys only in the database.
	$config['recaptcha_public'] = (string)nichan_setting('recaptcha_public', '');
	$config['recaptcha_private'] = (string)nichan_setting('recaptcha_private', '');
	$config['nichan_captcha'] = nichan_captcha_type($uri);
	$config['recaptcha'] = $config['nichan_captcha'] === 'recaptcha';

	// «Спитати долю» in the post form
	$config['nichan_fate'] = nichan_board_fate_on($uri);

	// the message under the post form (mod.php?/nichan-messages; until saved there, the one of instance-config.php)
	$message = nichan_board_message($uri);
	if ($message !== null)
		$config['global_message'] = $message;

	// The post form's button: the engine's English words have no Ukrainian translation (/int/ keeps them).
	if (strpos($config['locale'], 'en') !== 0) {
		$config['button_newtopic'] = 'Створити нитку';
		$config['button_reply'] = 'Відповісти';
	}

	// User-selectable flags ("позначки"): switched on per board in mod.php (Board settings); the list edited in
	// mod.php replaces the one from the board's config.php, and a board with neither gets the default ones.
	// The name of posters who leave it empty (Board settings; else the board's config.php or the site's).
	$anon = trim((string)nichan_setting('board_anon_' . $uri, ''));
	if ($anon !== '')
		$config['anonymous'] = $anon;

	// Geolocation (the flag found from the address: the country, or the region for Ukrainians) is per board too;
	// when it isn't compulsory, the form has «Без прапора».
	$config['nichan_geo_mode'] = nichan_board_geo_mode($uri);
	$config['country_flags'] = $config['nichan_geo_mode'] > 0;
	$config['allow_no_country'] = $config['country_flags'] && !nichan_board_geo_required($uri);
	$config['nichan_geo_text'] = nichan_board_geo_text($uri);
	// The country database's licence (CC BY 4.0) asks for a credit where the flags are shown.
	if ($config['country_flags'])
		$config['footer'][] = (strpos($config['locale'], 'en') === 0 ? 'IP geolocation by ' : 'IP-геолокація: ') .
			'<a href="https://db-ip.com" target="_blank" rel="noopener">DB-IP</a>';
	$config['user_flag'] = nichan_board_flags_on($uri);
	if ($config['country_flags'] || $config['user_flag'])
		$config['display_flags'] = true; // (the flag is shown next to the name)
	if ($config['user_flag']) {
		$own = !empty($config['user_flags']) ? $config['user_flags'] : array();
		// (the board's own config.php list, for the flags page in mod.php)
		$config['nichan_config_user_flags'] = $own;
		$config['user_flags'] = array();
		foreach (nichan_user_flags($uri, $own) as $f)
			$config['user_flags'][$f['key']] = $f['label'];
	}
}

// Checks a reCAPTCHA v2 answer with Google (cURL, or plain HTTP when cURL is missing).
function nichan_recaptcha_verify($response, $secret) {
	if ($response === '' || $secret === '')
		return false;
	$fields = array('secret' => $secret, 'response' => $response, 'remoteip' => $_SERVER['REMOTE_ADDR']);
	$url = 'https://www.google.com/recaptcha/api/siteverify';
	if (function_exists('curl_init')) {
		$ch = curl_init($url);
		curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields),
			CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10));
		$raw = curl_exec($ch);
		curl_close($ch);
	} else {
		$raw = @file_get_contents($url, false, stream_context_create(array('http' => array(
			'method' => 'POST', 'header' => 'Content-Type: application/x-www-form-urlencoded',
			'content' => http_build_query($fields), 'timeout' => 10))));
	}
	$data = $raw ? json_decode($raw, true) : null;
	return !empty($data['success']);
}

/* ------------------------------------------------------------- tripcodes */

// "## Admin" / "## Mod": the capcode a moderator signs with when ticking the checkbox in the form.
// Plain text of a post for <title>, meta descriptions and link previews: no markup, no post links,
// one line, cut at a word boundary with "…" (Twig filter nichan_plain).
// A post written in raw HTML by the staff keeps its tags in body_nomarkup: only real tags go («<3», «a < b» stay),
// line-like ones leave a space, entities become their letters.
function nichan_strip_html($t) {
	if (strpos($t, '<') === false && strpos($t, '&') === false)
		return $t;
	$t = preg_replace('~<(br|/p|/div|/li|/h[1-6]|hr)\b[^>]*>~i', ' ', $t);
	$t = preg_replace('~<(script|style)\b[^>]*>.*?</\1\s*>~is', '', $t);
	$t = preg_replace('~</?[a-z][a-z0-9]*(\s[^<>]*)?/?>~i', '', $t);
	return html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function nichan_plain_text($text, $max = 200) {
	$t = nichan_strip_html(remove_modifiers((string)$text));
	$t = preg_replace('~\[/?(b|i|u|s|spoiler|code|mod)\]~i', '', $t);
	$t = preg_replace("/'''|''|\*\*|%%|__|~~|==/", '', $t);
	$t = preg_replace('~>>>/[a-z0-9_]+/\d*|>>\d+~i', '', $t);
	$t = preg_replace('~^\s*>+~mu', '', $t);
	$t = trim(preg_replace('~\s+~u', ' ', $t));
	if (mb_strlen($t) > $max) {
		$t = mb_substr($t, 0, $max);
		$cut = mb_strrpos($t, ' ');
		if ($cut !== false && $cut > $max * 0.6)
			$t = mb_substr($t, 0, $cut);
		// (not rtrim(): it takes "–—" byte by byte and would cut the end off a letter such as «р», D1 80,
		// leaving text that is no longer UTF-8, which json_encode() then refuses)
		$t = preg_replace('/[\s,.;:\-–—]+$/u', '', $t) . '…';
	}
	return $t;
}

// Keep the original URL directory so relative references inside stylesheets still work.
function nichan_asset($path) {
	global $config;
	static $versions = array();
	if (isset($versions[$path]))
		return $versions[$path];
	if (!preg_match('~\.(js|css)$~D', $path, $match) || preg_match('~^(?:[a-z]+:)?//|(?:^|/)\.\.(?:/|$)~i', $path))
		return $path;
	$root = $config['root'] ?? '/';
	$relative = ltrim($root !== '' && strpos($path, $root) === 0 ? substr($path, strlen($root)) : $path, '/');
	$file = dirname(__DIR__) . '/' . $relative;
	if (!is_file($file))
		return $path;
	if (!preg_match('~^(?:js|stylesheets)/~', $relative) || in_array(basename($relative), array('custom.css', 'geo.css', 'posting-status.css', 'site-status.js'), true))
		return $path . '?v=' . filemtime($file);

	// An old generated board page must still receive the exact bytes named by its URL.
	$bytes = file_get_contents($file);
	if ($bytes === false)
		return $path;
	$hash = hash('sha256', $bytes);
	$dir = dirname(__DIR__) . '/tmp/nichan-assets';
	$snapshot = $dir . '/' . $hash . '.' . $match[1];
	if (!is_file($snapshot)) {
		if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir))
			return $path;
		$tmp = tempnam($dir, 'build-');
		if ($tmp === false)
			return $path;
		$written = file_put_contents($tmp, $bytes) === strlen($bytes) && chmod($tmp, 0644) && @rename($tmp, $snapshot);
		if (!$written) {
			@unlink($tmp);
			if (!is_file($snapshot))
				return $path;
		}
	}
	return $versions[$path] = $path . '?v=' . $hash;
}

function nichan_default_capcode($who = null) {
	if ($who === null) {
		global $mod;
		$who = $mod;
	}
	if (!$who || !isset($who['type']))
		return '';
	return $who['type'] >= ADMIN ? 'Admin' : 'Mod';
}

$nichan_trips = null;

// NULL signals invalid input. An empty array is a bound list with no matching address.
function nichan_trip_ips($input) {
	$ips = array();
	foreach (preg_split('/[\s,;]+/', trim($input), -1, PREG_SPLIT_NO_EMPTY) as $ip) {
		if (filter_var($ip, FILTER_VALIDATE_IP) === false)
			return null;
		$ips[inet_ntop(inet_pton($ip))] = true;
	}
	return array_keys($ips);
}

function nichan_trips() {
	global $nichan_trips;
	if ($nichan_trips === null) {
		$nichan_trips = array();
		$query = query('SELECT * FROM ``nichan_trips`` ORDER BY `id`');
		if ($query) {
			foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $t) {
				$t['style'] = json_decode((string)$t['style'], true) ?: array();
				if ($t['ips'] !== null)
					$t['ips'] = nichan_trip_ips($t['ips']) ?? array();
				$nichan_trips[$t['id']] = $t;
			}
		}
	}
	return $nichan_trips;
}

// For overlapping rules, prefer IP + code, then code alone, then automatic IP.
function nichan_trip_for($ip, $code) {
	$ip = filter_var($ip, FILTER_VALIDATE_IP) !== false ? inet_ntop(inet_pton($ip)) : null;
	$automatic = null;
	$unbound = array();
	$hint = $code !== '' ? nichan_trip_code_hint($code) : '';
	foreach (nichan_trips() as $trip) {
		if ($trip['ips'] === null) {
			if (!$trip['always'] && $code !== '' && $trip['secret'] !== '')
				$unbound[] = $trip;
			continue;
		}
		if (!in_array($ip, $trip['ips'], true))
			continue;
		if ($trip['always']) {
			$automatic ??= $trip;
		} elseif ($code !== '' && nichan_trip_verify_code($code, $trip['secret'], $hint)) {
			return $trip;
		}
	}
	foreach ($unbound as $trip) {
		if (nichan_trip_verify_code($code, $trip['secret'], $hint))
			return $trip;
	}
	return $automatic;
}

// A keyed hint skips expensive password checks for unrelated codes. Its key ID lets old
// records fall back to their password hash if the installation rotates its trip salt.
function nichan_trip_code_hint($code) {
	global $config;
	$key = $config['secure_trip_salt'];
	return substr(hash_hmac('sha256', 'nichan-trip-key-id', $key), 0, 16) . ':' .
		hash_hmac('sha256', 'nichan-trip-code:' . $code, $key);
}

function nichan_trip_verify_code($code, $stored, $hint) {
	if ($stored === '' || str_contains($code, "\0"))
		return false;
	if (!str_starts_with($stored, 'v2:'))
		return password_verify($code, $stored);
	[, $key_id, $digest, $hash] = explode(':', $stored, 4);
	if (str_starts_with($hint, $key_id . ':') && !hash_equals($key_id . ':' . $digest, $hint))
		return false;
	return password_verify(base64_encode(hash('sha384', $code, true)), $hash);
}

function nichan_trip_hash_code($code) {
	// bcrypt reads up to 72 bytes. Hash the full UTF-8 code before passing it to password_hash().
	return 'v2:' . nichan_trip_code_hint($code) . ':' . password_hash(base64_encode(hash('sha384', $code, true)), PASSWORD_DEFAULT);
}

// HTML of a custom tripcode: every letter may have its own colour, weight and slant; the whole
// thing its own font and an optional blink. Used by templates/post/name.html for trips "!!ct<id>".
function nichan_trip_html($trip) {
	if (!preg_match('/^!!ct(\d+)$/', (string)$trip, $m))
		return '';
	$all = nichan_trips();
	if (!isset($all[$m[1]]))
		return '';
	$t = $all[$m[1]];
	$st = $t['style'];
	$fonts = array('serif' => 'Georgia, "Times New Roman", serif', 'sans' => 'Arial, sans-serif', 'mono' => '"Courier New", monospace',
		'cursive' => '"Comic Sans MS", cursive', 'impact' => 'NichanImpact, Impact, "Arial Black", sans-serif');
	$css = '';
	if (!empty($st['font']) && isset($fonts[$st['font']]))
		$css .= 'font-family:' . $fonts[$st['font']] . ';';
	$chars = preg_split('//u', $t['text'], -1, PREG_SPLIT_NO_EMPTY);
	$html = '';
	foreach ($chars as $i => $ch) {
		$l = isset($st['letters'][$i]) ? $st['letters'][$i] : array();
		$s = '';
		if (!empty($l['c']) && preg_match('/^#[0-9a-f]{6}$/i', $l['c']))
			$s .= 'color:' . $l['c'] . ';';
		if (!empty($l['b']))
			$s .= 'font-weight:bold;';
		if (!empty($l['i']))
			$s .= 'font-style:italic;';
		$html .= $s ? '<span style="' . $s . '">' . htmlspecialchars($ch) . '</span>' : htmlspecialchars($ch);
	}
	return '<span class="trip nichan-trip' . (!empty($st['blink']) ? ' nichan-blink' : '') . '"' . ($css ? ' style="' . htmlspecialchars($css) . '"' : '') . '>' . $html . '</span>';
}

/* ---------------------------------------------------- user flags (/pol/) */

// A board with flags switched on but no list of its own gets these (pictures in static/flags/default/).
function nichan_default_flags() {
	return array(
		array('key' => 'asuka', 'label' => 'Asuka', 'file' => 'default/asuka.png'),
		array('key' => 'rei', 'label' => 'Rei', 'file' => 'default/rei.png'),
	);
}

// Flags on the board (mod.php -> Board settings; until set there, whatever the board's config.php says).
function nichan_board_flags_on($uri) {
	global $config;
	$v = nichan_setting('board_flags_' . $uri);
	if ($v !== null)
		return (bool)$v;
	$file = sprintf($config['board_path'], $uri) . 'config.php';
	$src = is_file($file) ? (string)@file_get_contents($file) : '';
	return (bool)preg_match('/^\s*\$config\[\'user_flag\'\]\s*=\s*true/m', $src);
}

// Flags found from the address (mod.php?/nichan-board/<uri>; until set there, whatever config.php says):
// 0 off, 1 the country, 2 detailed (the region for Ukrainians, inc/nichan-geoip.php).
function nichan_board_geo_mode($uri) {
	global $config;
	$v = nichan_setting('board_geo_' . $uri);
	if ($v !== null)
		return max(0, min(2, (int)$v));
	$file = sprintf($config['board_path'], $uri) . 'config.php';
	$src = is_file($file) ? (string)@file_get_contents($file) : '';
	return preg_match('/^\s*\$config\[\'country_flags\'\]\s*=\s*true/m', $src) ? 1 : 0;
}

function nichan_board_geo_on($uri) {
	return nichan_board_geo_mode($uri) > 0;
}

// A ban's appeal (one per ban, $config['ban_appeals_max']): 'pending', 'denied' or null (not appealed).
function nichan_ban_appeal_state($ban_id) {
	$query = query('SELECT MAX(`denied`), COUNT(*) FROM ``ban_appeals`` WHERE `ban_id` = ' . (int)$ban_id);
	$row = $query ? $query->fetch(PDO::FETCH_NUM) : null;
	if (!$row || !$row[1])
		return null;
	return $row[0] ? 'denied' : 'pending';
}

// What a banned poster whose ban was appealed gets instead of the ban page again (post.php, checkBan()).
function nichan_ban_appeal_message($state) {
	global $config;
	$en = strpos($config['locale'], 'en') === 0;
	if ($state === 'pending')
		return $en ? 'Your ban appeal is still under review.' : 'Ваше оскарження бану досі на розгляді.';
	return $en ? 'Your ban appeal was rejected.' : 'Ваше оскарження бану було відхилене.';
}

// «Спитати долю» in the post form (mod.php?/nichan-board/<uri>; off until switched on).
function nichan_board_fate_on($uri) {
	return (bool)nichan_setting('board_fate_' . $uri, '0');
}

// A post sent with «Спитати долю»: now and then fate has a word to say (templates/post/fate.html), else nothing.
function nichan_fate_roll() {
	$n = random_int(1, 1000);
	if ($n <= 50)
		return 'mighty';    // 5%
	if ($n <= 90)
		return 'victory';   // 4%
	if ($n <= 130)
		return 'betrayal';  // 4%
	if ($n <= 135)
		return 'epicfail';  // 0.5%
	if ($n <= 140)
		return 'epicwin';   // 0.5%
	return null;            // 86%
}

// Is the flag compulsory? Otherwise the form has «Без прапора».
function nichan_board_geo_required($uri) {
	return (bool)nichan_setting('board_geo_required_' . $uri, '1');
}

// "Політик з": the text of the flag becomes "Політик з PL"; '' = the country's name.
function nichan_board_geo_text($uri) {
	return (string)nichan_setting('board_geo_text_' . $uri, '');
}

// Pictures of the board's own for flags found from the address (regions, countries): array(code => file under
// static/flags/). Shown through static/flags/geo.css on this board's posts only, old posts included.
function nichan_board_geo_flags($uri) {
	$list = json_decode((string)nichan_setting('geo_flags_' . $uri, ''), true);
	return is_array($list) ? $list : array();
}

/* ------------------------------------------------ the message under the form */

// Messages under the post form (mod.php?/nichan-messages): array of array('id', 'text' (HTML), 'boards' => array(uri)),
// or null while never saved (then $config['global_message'] of inc/instance-config.php is shown everywhere).
function nichan_board_messages() {
	$v = nichan_setting('board_messages');
	if ($v === null)
		return null;
	$list = json_decode((string)$v, true);
	return is_array($list) ? $list : array();
}

// The board's message: the texts of the messages chosen for it, one under another (false: none).
function nichan_board_message($uri) {
	$list = nichan_board_messages();
	if ($list === null)
		return null;
	$texts = array();
	foreach ($list as $m)
		if (in_array($uri, $m['boards'], true) && trim($m['text']) !== '')
			$texts[] = $m['text'];
	return $texts ? implode('<br>', $texts) : false;
}

/* ---------------------------------------------------------------- banners */

// Galleries of banners (mod.php?/nichan-banners): array of array('id' => int, 'name', 'boards' => array(uri),
// 'files' => array(file name), optional 'links' => array(file name => destination)).
function nichan_banner_galleries() {
	$list = json_decode((string)nichan_setting('banner_galleries', ''), true);
	return is_array($list) ? $list : array();
}

function nichan_banner_galleries_save(array $list, &$pending_files = null) {
	nichan_set_setting('banner_galleries', json_encode(array_values($list), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
	$pending_files = array(); // Files committed to the gallery survive a later status-file failure.
	nichan_write_posting_status_files(); // site-status.js carries the lists; no page has to be rebuilt
}

// For site-status.js: array(uri => array(url, ...)). A board in two galleries gets the banners of both; a board in
// none gets no banner. The page shows one of them at random (templates/header.html: nichanBanner).
function nichan_banner_map(array $uris, &$links) {
	global $config;
	$map = array();
	$links = array();
	foreach (nichan_banner_galleries() as $g) {
		$boards = array_intersect($g['boards'], $uris);
		if (!$boards) continue;
		foreach ($g['files'] as $file) {
			$src = $config['root'] . 'static/banners/' . (int)$g['id'] . '/' . $file;
			foreach ($boards as $uri)
				$map[$uri][] = $src;
			$link = nichan_banner_link($g['links'][$file] ?? '');
			if ($link !== null && $link !== '') $links[$src] = $link;
		}
	}
	return $map;
}

// An empty field clears the link. Accept site paths and absolute web URLs, including Ukrainian text.
function nichan_banner_link($value) {
	if (!is_string($value)) return null;
	$url = trim($value);
	if ($url === '') return '';
	if (!mb_check_encoding($url, 'UTF-8') || mb_strlen($url, 'UTF-8') > 2048 || preg_match('~[\x00-\x20\x7f\\\\]~', $url)) return null;
	if ($url[0] === '/') return substr($url, 0, 2) === '//' ? null : $url;
	return preg_match('~^https?://~i', $url) && parse_url($url, PHP_URL_HOST) ? $url : null;
}

// Is any board showing regions (tools/update-geoip.php then downloads the city database)?
function nichan_geo_detailed_anywhere() {
	foreach (listBoards() as $b)
		if (nichan_board_geo_mode($b['uri']) == 2)
			return true;
	return false;
}

/*
 * static/flags/geo.css (linked on every page): a region without a picture shows the flag of Ukraine; the
 * pictures of each board's own (mod.php?/nichan-geo-flags) for its posts. templates/post/flag.html gives each
 * flag the class flag-b-<board>, so they apply in quotes shown on hover and on /all/ too.
 */
function nichan_write_geo_css() {
	$css = "/* written by nichan (mod.php -> board settings -> flags of geolocation) */\n";
	$sprite = @file_get_contents('static/flags/flags.css');
	if ($sprite && preg_match('/\.flag\.flag-ua\s*\{([^}]*)\}/', $sprite, $m)) {
		$sel = array();
		foreach (array_keys(nichan_ua_regions()) as $code)
			$sel[] = '.flag.flag-ua-' . $code;
		$css .= implode(', ', $sel) . ' {' . $m[1] . "}\n";
	}
	foreach (listBoards() as $b) {
		$list = nichan_board_geo_flags($b['uri']);
		// a region without a picture of its own: the board's picture for Ukraine, when there is one
		if (isset($list['ua']))
			foreach (array_keys(nichan_ua_regions()) as $code)
				if (!isset($list['ua-' . $code]))
					$list['ua-' . $code] = $list['ua'];
		foreach ($list as $code => $file) {
			if (!preg_match('/^[a-z0-9-]+$/', $code) || !preg_match('/^[a-z0-9_\/.?=-]+$/i', $file))
				continue;
			$css .= '.flag.flag-b-' . $b['uri'] . '.flag-' . $code . ' { background: url(' . $file . ') no-repeat center / contain !important; }' . "\n";
		}
	}
	@file_put_contents('static/flags/geo.css', $css);
	nichan_cf_queue('static/flags/geo.css');
}

// The poster name a board has without the setting in mod.php: its config.php's, else the site's.
function nichan_board_default_anonymous($uri, $site_default) {
	global $config;
	$file = sprintf($config['board_path'], $uri) . 'config.php';
	$src = is_file($file) ? (string)@file_get_contents($file) : '';
	return preg_match_all('/^\s*\$config\[\'anonymous\'\]\s*=\s*\'((?:[^\'\\\\]|\\\\.)*)\'/m', $src, $m) ? stripslashes(end($m[1])) : $site_default;
}

// The [translate] button on the board's posts (mod.php -> Board settings; until set there, on English boards).
function nichan_board_translate_on($uri) {
	$v = nichan_setting('board_translate_' . $uri);
	return $v !== null ? (bool)$v : nichan_board_is_english($uri);
}

// The board's selectable flags: [{key, label, file}], file relative to static/flags/.
function nichan_user_flags($uri, $config_flags = array()) {
	$stored = nichan_setting('user_flags_' . $uri);
	if ($stored !== null) {
		$list = json_decode($stored, true);
		return is_array($list) ? $list : array();
	}
	// Not edited yet: the board's config.php list with each picture from flags.css, or the default flags.
	if (!$config_flags)
		return nichan_default_flags();
	$css = @file_get_contents('static/flags/flags.css');
	$list = array();
	foreach ($config_flags as $key => $label) {
		$file = '';
		if ($css && preg_match('/\.flag-' . preg_quote($key, '/') . '\s*\{[^}]*url\(([^)]+)\)/', $css, $m))
			$file = trim($m[1], '\'" ');
		$list[] = array('key' => $key, 'label' => $label, 'file' => $file);
	}
	return $list;
}

// static/flags/custom.css: pictures for every edited flag list (linked after flags.css).
function nichan_write_flags_css() {
	$query = query("SELECT `name`, `value` FROM ``nichan_settings`` WHERE `name` LIKE 'user\_flags\_%'") or error(db_error());
	$css = "/* written by nichan (mod.php -> Flags) */\n";
	// the default flags, for boards without a list of their own
	foreach (nichan_default_flags() as $f)
		$css .= '.flag.flag-' . $f['key'] . ' { background: url(' . $f['file'] . ') no-repeat center / contain !important; }' . "\n";
	foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
		foreach ((array)json_decode($row['value'], true) as $f) {
			if (empty($f['key']) || empty($f['file']) || !preg_match('/^[a-z0-9_-]+$/i', $f['key']))
				continue;
			$css .= '.flag.flag-' . $f['key'] . ' { background: url(' . str_replace(array('(', ')', '"', "'"), '', $f['file']) . ') no-repeat center / contain !important; }' . "\n";
		}
	}
	@file_put_contents('static/flags/custom.css', $css);
	nichan_cf_queue('static/flags/custom.css');
}

/* ------------------------------------------------------------ post passwords */

// The deletion password of a post is kept as a keyed hash ("#" + 19 characters, fits the old
// VARCHAR(20) column), not as the text the poster typed. Old posts may still hold the text itself
// until tools/nichan-hash-passwords.php converts them; both are accepted.
function nichan_post_password_hash($password) {
	global $config;
	$password = (string)$password;
	if ($password === '')
		return '';
	return '#' . substr(strtr(base64_encode(hash_hmac('sha256', $password, 'post-password:' . $config['cookies']['salt'], true)), '+/', '-_'), 0, 19);
}

function nichan_post_password_ok($stored, $password) {
	$stored = (string)$stored;
	$password = (string)$password;
	if ($stored === '' || $password === '')
		return false;
	return hash_equals($stored, nichan_post_password_hash($password)) || hash_equals($stored, $password);
}

/* ------------------------------------------------------------------ IP notes */

// The moderators' notes about an IP (mod.php?/IP/...), newest first: shown on reports and in Telegram.
function nichan_ip_notes($ip, $limit = 3) {
	$query = prepare('SELECT `body`, `time` FROM ``ip_notes`` WHERE `ip` = :ip ORDER BY `time` DESC LIMIT ' . (int)$limit);
	$query->bindValue(':ip', (string)$ip);
	$query->execute() or error(db_error($query));
	return $query->fetchAll(PDO::FETCH_ASSOC);
}

function nichan_ip_notes_count($ip) {
	$query = prepare('SELECT COUNT(*) FROM ``ip_notes`` WHERE `ip` = :ip');
	$query->bindValue(':ip', (string)$ip);
	$query->execute() or error(db_error($query));
	return (int)$query->fetchColumn();
}
