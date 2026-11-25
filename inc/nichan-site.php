<?php

/*
 * The site's own name, address and links (mod.php?/nichan-site), kept in the database. An empty field means
 * the default below, so a site that never opened the page looks as before. Templates read them as
 * config.nichan_site.* (nichan_apply_site_settings() runs after every loadConfig()).
 */

defined('TINYBOARD') or exit;

const NICHAN_ENGINE_TAGLINE = 'багатофункціональний іміджбордовий рушій';

function nichan_site_defaults() {
	global $config;
	// Keep the configured URL apart from the database override applied later in this request.
	static $public_url;
	if ($public_url === null)
		$public_url = rtrim($config['nichan_public_url'] ?? 'https://example.org', '/');
	return array(
		'name' => 'Нічан',
		'name_en' => 'Nichan',
		'home_subtitle' => 'Спілкування без реєстрації та обмежень',
		'tagline' => 'Український анонімний іміджборд',
		'tagline_en' => 'the Ukrainian anonymous imageboard',
		'description' => 'Місце для спілкування без реєстрації і обмежень!',
		'public_url' => $public_url,

		'menu' => '',
		'twitter' => '',
		'image' => '/stylesheets/img/nichankit.png',
		'footer' => "Нічан © 2010-Heat Death of the Universe\nНічан - " . NICHAN_ENGINE_TAGLINE,
	);
}

// Used by Twig and by the standalone video player (which does not boot the board engine).
function nichan_footer($config = array(), $en = null) {
	if ($en === null)
		$en = strpos($config['locale'] ?? '', 'en') === 0;
	$site = isset($config['nichan_site']) ? $config['nichan_site'] : nichan_site_defaults();
	$lines = isset($site['footer_lines']) ? $site['footer_lines'] : explode("\n", $site['footer']);
	$root = isset($config['root']) ? $config['root'] : '/';
	$disclaimer = 'Адміністрація сайту не несе відповідальності за те, що тут понаписували.';
	// PHP board configs can include trusted markup (e.g. links and live counters).
	$notes = $config['footer'] ?? array($disclaimer);
	$escape = function ($v) { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
	ob_start();
	?>
<footer class="portal-foot"><div class="nichan-footer-inner">
	<div class="nichan-footer-copy">
		<div>
			<?php foreach ($lines as $line): ?>
				<?php if ($line === 'Нічан - ' . NICHAN_ENGINE_TAGLINE): ?>
					<a href="https://github.com/nichan-net/nichan"><?= $en ? 'Nichan' : 'Нічан' ?></a><?= $en ? ' is a full-featured imageboard engine.' : ' - ' . NICHAN_ENGINE_TAGLINE ?><br>
				<?php else: ?>
					<?= $escape($line) ?><br>
				<?php endif; ?>
			<?php endforeach; ?>
			<?= $en ? 'Built in Ukraine with' : 'Вітчизняна розробка на базі' ?> <a href="https://github.com/Stewart-Companies/Tinyboard">Tinyboard</a> <?= $en ? 'and' : 'і' ?> <a href="https://github.com/vichan-devel/vichan">Vichan</a>
		</div>
		<?php foreach ($notes as $note): if ($note === '') continue; ?>
		<p><?= $en && $note === $disclaimer ? 'The site administration is not responsible for what users post here.' : $note ?></p>
		<?php endforeach; ?>
		<div class="nichan-footer-apps"><a href="<?= $escape($root) ?>"><?= $escape($en ? $site['name_en'] : $site['name']) ?> <?= $en ? 'web app' : 'веб-апп' ?></a>
			<?php if ($config['nichan_link_android_app'] ?? is_file(dirname(__DIR__) . '/app/nichan_base.apk')): ?><a href="<?= $escape(rtrim($site['public_url'], '/')) ?>/app/nichan_base.apk"><?= $en ? 'Android app' : 'Андроїд-застосунок' ?></a><?php endif; ?></div>
	</div>
	<nav class="nichan-footer-nav" aria-label="<?= $en ? 'Site sections' : 'Розділи сайту' ?>">
		<a href="<?= $escape($root) ?>statistics.php"><?= $en ? 'Statistics' : 'Статистика' ?></a>
		<a href="<?= $escape($root . ($en ? 'intrules.php' : 'rules.php')) ?>"><?= $en ? 'About us' : 'Про нас' ?></a>
		<a href="<?= $escape($root) ?>extras.php"><?= $en ? 'Extras' : 'Цікавинки' ?></a>
	</nav>
</div></footer>
	<?php
	return trim(ob_get_clean());
}

// One value: the stored one, or the default when it was never set (or left empty); "-" means none.
function nichan_site($key) {
	$defaults = nichan_site_defaults();
	$v = nichan_setting('site_' . $key);
	if ($v !== null && trim((string)$v) === '-')
		return '';
	return $v !== null && trim((string)$v) !== '' ? (string)$v : (isset($defaults[$key]) ? $defaults[$key] : '');
}

// The links on the right of the top menu: "caption | address | hint" per line; a caption "fa-..." is an icon.
function nichan_site_menu() {
	$links = array();
	foreach (preg_split('/\r?\n/', nichan_site('menu')) as $line) {
		$parts = array_map('trim', explode('|', $line));
		if (count($parts) < 2 || $parts[0] === '' || !preg_match('~^(https?://|/)~i', $parts[1]))
			continue;
		$links[] = array(
			'label' => $parts[0],
			'icon' => preg_match('/^fa-[a-z0-9-]+$/', $parts[0]) ? $parts[0] : '',
			'url' => $parts[1],
			'title' => isset($parts[2]) ? $parts[2] : '',
			'external' => (bool)preg_match('~^https?://~i', $parts[1]),
		);
	}
	return $links;
}

// $fresh: read the settings again (right after they were changed in mod.php)
function nichan_apply_site_settings($fresh = false) {
	global $config;
	static $site = null;
	if ($site === null || $fresh) {
		$site = array();
		foreach (array_keys(nichan_site_defaults()) as $k)
			$site[$k] = nichan_site($k);
		$site['header_image'] = nichan_setting('site_header_image');
		$site['public_url'] = rtrim($site['public_url'], '/');
		$site['menu_links'] = nichan_site_menu();
		$site['portal_links'] = array();
		$site['has_android_app'] = $config['nichan_link_android_app'] ?? is_file(dirname(__DIR__) . '/app/nichan_base.apk');
		foreach ($site['menu_links'] as $link) {
			$host = strtolower((string)parse_url($link['url'], PHP_URL_HOST));
			if (in_array($host, array('t.me', 'telegram.me'), true) && !isset($site['portal_links']['telegram']))
				$site['portal_links']['telegram'] = $link['url'];
			if ($link['label'] === 'ФМ' || $host === 'ukrmemoria.com' || $host === 'www.ukrmemoria.com')
				$site['portal_links']['fm'] = $link['url'];
		}
		$site['footer_lines'] = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $site['footer']))));
		$site['host'] = (string)parse_url($site['public_url'], PHP_URL_HOST);
	}
	$config['nichan_site'] = $site;
	$config['nichan_public_url'] = $site['public_url'];
}

// Publish both assets from the current database settings. The player reads its footer without booting PHP's board engine.
function nichan_write_site_assets() {
	global $config, $nichan_settings;
	$root = dirname(__DIR__);
	$lock = fopen($root . '/tmp/site-assets.lock', 'c');
	if (!$lock || !flock($lock, LOCK_EX))
		throw new RuntimeException('Could not lock site assets');
	try {
		$nichan_settings = null;
		nichan_apply_site_settings(true);
		$site = $config['nichan_site'];
		$manifest = json_decode(file_get_contents($root . '/static/app/manifest.webmanifest'), true, 512, JSON_THROW_ON_ERROR);
		$manifest['name'] = $manifest['short_name'] = $site['name'];
		$manifest['description'] = $site['description'];
		$files = array(
			'manifest.webmanifest' => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
			'tmp/player-footer.html' => nichan_footer($config),
		);
		foreach ($files as $path => $contents) {
			$file = $root . '/' . $path;
			if (is_file($file) && file_get_contents($file) === $contents)
				continue;
			$tmp = $file . '.' . getmypid() . '.tmp';
			if (file_put_contents($tmp, $contents) !== strlen($contents) || !rename($tmp, $file)) {
				@unlink($tmp);
				throw new RuntimeException('Could not publish ' . $path);
			}
			if ($path === 'manifest.webmanifest')
				nichan_cf_queue($path);
		}
	} finally {
		fclose($lock);
	}
}
