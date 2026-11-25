<?php

/*
 * Country (and for Ukraine the region) of an IP address: the flags of geolocation, rules by country.
 * Databases: DB-IP "IP to Country Lite" (inc/lib/geoip/dbip-country-lite.mmdb, in git) and, when a board shows
 * regions, "IP to City Lite" (dbip-city-lite.mmdb, ~130 MB, not in git); both CC BY 4.0, https://db-ip.com,
 * refreshed with tools/update-geoip.php. Replaces the GeoIP Legacy files from 2013/2020.
 */

defined('TINYBOARD') or exit;

function nichan_geoip_file() {
	return dirname(__DIR__) . '/inc/lib/geoip/dbip-country-lite.mmdb';
}

// DB-IP "IP to City Lite" (~130 MB, not in git): the country and, for Ukraine, the region. Downloaded by
// tools/update-geoip.php when a board shows regions; when it's there, it answers every lookup.
function nichan_geoip_city_file() {
	return dirname(__DIR__) . '/inc/lib/geoip/dbip-city-lite.mmdb';
}

// The database's record of the address (array), or null.
function nichan_geoip_record($ip) {
	static $reader = null;
	if ($reader === null) {
		$file = is_file(nichan_geoip_city_file()) ? nichan_geoip_city_file() : nichan_geoip_file();
		if (!is_file($file))
			return null;
		require_once dirname(__DIR__) . '/inc/lib/MaxMind/Db/Reader/InvalidDatabaseException.php';
		require_once dirname(__DIR__) . '/inc/lib/MaxMind/Db/Reader/Util.php';
		require_once dirname(__DIR__) . '/inc/lib/MaxMind/Db/Reader/Decoder.php';
		require_once dirname(__DIR__) . '/inc/lib/MaxMind/Db/Reader/Metadata.php';
		require_once dirname(__DIR__) . '/inc/lib/MaxMind/Db/Reader.php';
		try {
			$reader = new MaxMind\Db\Reader($file);
		} catch (Exception $e) {
			$reader = false;
		}
	}
	if (!$reader)
		return null;
	try {
		$rec = $reader->get($ip);
	} catch (Exception $e) {
		return null;
	}
	return is_array($rec) ? $rec : null;
}

// array('ua', 'Україна') or null. $lang: 'uk' or 'en' for the country name.
function nichan_geoip_country($ip, $lang = 'uk') {
	$rec = nichan_geoip_record($ip);
	if (!$rec || empty($rec['country']['iso_code']))
		return null;
	$code = strtolower($rec['country']['iso_code']);
	$name = isset($rec['country']['names']['en']) ? $rec['country']['names']['en'] : strtoupper($code);
	// Crimea and Sevastopol are Ukraine, whatever the database says (the city database knows the region)
	if ($code !== 'ua' && isset($rec['subdivisions'][0]['names']['en'])
		&& in_array(nichan_ua_region_code($rec['subdivisions'][0]['names']['en']), array('crimea', 'sevastopol'), true)) {
		$code = 'ua';
		$name = 'Ukraine';
	}
	// Ukrainian country names from the intl extension when it's there.
	if ($lang == 'uk' && class_exists('Locale')) {
		$uk = Locale::getDisplayRegion('-' . strtoupper($code), 'uk');
		if ($uk && strcasecmp($uk, $code) != 0)
			$name = $uk;
	}
	return array($code, $name);
}

/* ------------------------------------------------------ regions of Ukraine */

// code => array(Ukrainian name, English name). The flag of a region is "ua-<code>" (mod.php?/nichan-geo-flags).
function nichan_ua_regions() {
	return array(
		'vinnytsia' => array('Вінницька область', 'Vinnytsia Oblast'),
		'volyn' => array('Волинська область', 'Volyn Oblast'),
		'dnipropetrovsk' => array('Дніпропетровська область', 'Dnipropetrovsk Oblast'),
		'donetsk' => array('Донецька область', 'Donetsk Oblast'),
		'zhytomyr' => array('Житомирська область', 'Zhytomyr Oblast'),
		'zakarpattia' => array('Закарпатська область', 'Zakarpattia Oblast'),
		'zaporizhzhia' => array('Запорізька область', 'Zaporizhzhia Oblast'),
		'ivano-frankivsk' => array('Івано-Франківська область', 'Ivano-Frankivsk Oblast'),
		'kyiv-oblast' => array('Київська область', 'Kyiv Oblast'),
		'kirovohrad' => array('Кіровоградська область', 'Kirovohrad Oblast'),
		'luhansk' => array('Луганська область', 'Luhansk Oblast'),
		'lviv' => array('Львівська область', 'Lviv Oblast'),
		'mykolaiv' => array('Миколаївська область', 'Mykolaiv Oblast'),
		'odesa' => array('Одеська область', 'Odesa Oblast'),
		'poltava' => array('Полтавська область', 'Poltava Oblast'),
		'rivne' => array('Рівненська область', 'Rivne Oblast'),
		'sumy' => array('Сумська область', 'Sumy Oblast'),
		'ternopil' => array('Тернопільська область', 'Ternopil Oblast'),
		'kharkiv' => array('Харківська область', 'Kharkiv Oblast'),
		'kherson' => array('Херсонська область', 'Kherson Oblast'),
		'khmelnytskyi' => array('Хмельницька область', 'Khmelnytskyi Oblast'),
		'cherkasy' => array('Черкаська область', 'Cherkasy Oblast'),
		'chernivtsi' => array('Чернівецька область', 'Chernivtsi Oblast'),
		'chernihiv' => array('Чернігівська область', 'Chernihiv Oblast'),
		'kyiv' => array('Київ', 'Kyiv'),
		'crimea' => array('Автономна Республіка Крим', 'Autonomous Republic of Crimea'),
		'sevastopol' => array('Севастополь', 'Sevastopol'),
	);
}

// The database names regions in English, in several spellings ("Odesa", "Odessa", "Poltava Oblast"): the code
// of the region, or null.
function nichan_ua_region_code($name) {
	static $aliases = array(
		'vinnitsa' => 'vinnytsia', 'vinnytsya' => 'vinnytsia', 'volhynia' => 'volyn',
		'dnepropetrovsk' => 'dnipropetrovsk', 'dnipro' => 'dnipropetrovsk', 'zhitomir' => 'zhytomyr',
		'zakarpattya' => 'zakarpattia', 'transcarpathia' => 'zakarpattia', 'zaporizhia' => 'zaporizhzhia',
		'zaporozhye' => 'zaporizhzhia', 'zaporizhzhya' => 'zaporizhzhia', 'ivano-frankovsk' => 'ivano-frankivsk',
		'kirovograd' => 'kirovohrad', 'kropyvnytskyi' => 'kirovohrad', 'lugansk' => 'luhansk', 'lvov' => 'lviv',
		'nikolaev' => 'mykolaiv', 'mykolayiv' => 'mykolaiv', 'odessa' => 'odesa', 'rovno' => 'rivne',
		'kharkov' => 'kharkiv', 'khmelnytskyy' => 'khmelnytskyi', 'khmelnitskiy' => 'khmelnytskyi',
		'khmelnytskiy' => 'khmelnytskyi', 'khmelnitsky' => 'khmelnytskyi', 'cherkassy' => 'cherkasy',
		'chernovtsy' => 'chernivtsi', 'chernigov' => 'chernihiv', 'krym' => 'crimea', 'kiev' => 'kyiv',
		// stems of the Ukrainian forms ("Vinnyts'ka")
		'vinnyts' => 'vinnytsia', 'zaporiz' => 'zaporizhzhia', 'rivnen' => 'rivne', 'khmelnyts' => 'khmelnytskyi',
		'chernivets' => 'chernivtsi', 'zakarpat' => 'zakarpattia',
	);
	$n = strtolower(trim((string)$name));
	$oblast = (bool)preg_match('/\b(oblast|region|province)\b/', $n);
	$n = preg_replace('/\b(autonomous republic of|republic of|oblast|region|province|city|of)\b|[\'’`]/', '', $n);
	$n = trim(preg_replace('/[\s_]+/', '-', trim($n)), '-');
	if (isset($aliases[$n]))
		$n = $aliases[$n];
	$regions = nichan_ua_regions();
	// Ukrainian forms: "Kharkivs’ka Oblast’" -> "kharkivska" -> "kharkiv"; "Poltavs'ka" -> "poltava"
	if (!isset($regions[$n]) && preg_match('/^(.+?)s?ka$/', $n, $m)) {
		foreach (array($m[1], $m[1] . 'a', $m[1] . 'y', $m[1] . 'sk', $m[1] . 's', $m[1] . 'sa', $m[1] . 'sy') as $try)
			if (isset($regions[$try]) || isset($aliases[$try])) {
				$n = isset($aliases[$try]) ? $aliases[$try] : $try;
				break;
			}
	}
	// "Kyiv City" / "Kyiv" is the city, "Kyiv Oblast" the region around it
	if ($n === 'kyiv' && $oblast)
		$n = 'kyiv-oblast';
	return isset($regions[$n]) ? $n : null;
}

/*
 * The flag a post gets on a board with geolocation: array(code, text) or null.
 *   $detailed  Ukrainians get the flag of their region ("ua-lviv", "Львівська область"); the country's flag when
 *              the region is unknown
 *   $text      "Політик з": the text is then "Політик з PL" (in detailed mode: for the other countries only);
 *              '' = the country's name
 * Crimea and Sevastopol are Ukraine, whatever the database says.
 */
function nichan_geo_flag($ip, $detailed, $text, $lang = 'uk') {
	$rec = nichan_geoip_record($ip);
	if (!$rec || empty($rec['country']['iso_code']))
		return null;
	$region = isset($rec['subdivisions'][0]['names']['en']) ? nichan_ua_region_code($rec['subdivisions'][0]['names']['en']) : null;
	$cc = strtolower($rec['country']['iso_code']);
	if ($cc !== 'ua' && !in_array($region, array('crimea', 'sevastopol'), true))
		$region = null;
	elseif ($region)
		$cc = 'ua';
	if ($detailed && $cc === 'ua' && $region) {
		$names = nichan_ua_regions();
		return array('ua-' . $region, $names[$region][$lang === 'en' ? 1 : 0]);
	}
	$text = trim((string)$text);
	$country = nichan_geoip_country($ip, $lang);
	$country_name = $country ? $country[1] : strtoupper($cc);
	// detailed: the other countries get the place too, from the same city database: "Політик з Zeeland (Нідерланди)"
	// (the database names places in English; no place known: "Політик з NL (Нідерланди)")
	if ($text !== '' && $detailed && $cc !== 'ua') {
		$place = isset($rec['subdivisions'][0]['names']['en']) ? trim($rec['subdivisions'][0]['names']['en']) : '';
		if ($place === '' && isset($rec['city']['names']['en']))
			$place = trim($rec['city']['names']['en']);
		return array($cc, $text . ' ' . ($place !== '' ? $place : strtoupper($cc)) . ' (' . $country_name . ')');
	}
	if ($text !== '' && !($detailed && $cc === 'ua'))
		return array($cc, $text . ' ' . strtoupper($cc));
	return array($cc, $country_name);
}

/* ---------------------------------------------------- rules by country */

// One row per action in each scope. A global scope replaces the board-specific scopes.
const NICHAN_COUNTRY_RULES = ['uk' => 'Українська капча', 'en' => 'Англійська капча', 'recaptcha' => 'reCAPTCHA', 'block' => 'Блок'];
define('NICHAN_COUNTRY_MESSAGE', 'Дописи з вашої країни заборонені адміністрацією');

function nichan_country_row($row) {
	return array(
		'mode' => ($row['mode'] ?? '') === 'except' ? 'except' : 'only',
		'countries' => array_values(array_unique(array_filter((array)($row['countries'] ?? array()), function ($c) {
			return is_string($c) && preg_match('/^[a-z]{2}$/D', $c);
		}))),
	);
}

function nichan_country_scope_clean($scope) {
	$out = array('enabled' => !empty($scope['enabled']));
	// Preserve the former single row and its board-first behaviour until this scope is edited.
	if (is_array($scope['captcha'] ?? null)) {
		$type = $scope['captcha']['type'] ?? '';
		$scope[in_array($type, ['uk', 'en'], true) ? $type : 'recaptcha'] = $scope['captcha'];
		$out['board_captcha_first'] = true;
	} elseif (!empty($scope['board_captcha_first'])) {
		$out['board_captcha_first'] = true;
	}
	foreach (NICHAN_COUNTRY_RULES as $type => $label)
		$out[$type] = nichan_country_row($scope[$type] ?? null);
	return $out;
}

function nichan_country_policy() {
	$cache = &$GLOBALS['nichan_country_policy_cache'];
	if ($cache !== null)
		return $cache;
	$p = json_decode((string)nichan_setting('country_policy', ''), true);
	$p = is_array($p) ? $p : array();
	$cache = array('global' => nichan_country_scope_clean(isset($p['global']) ? $p['global'] : null), 'boards' => array(), 'messages' => array());
	foreach (isset($p['boards']) && is_array($p['boards']) ? $p['boards'] : array() as $uri => $scope)
		$cache['boards'][$uri] = nichan_country_scope_clean($scope);
	foreach (isset($p['messages']) && is_array($p['messages']) ? $p['messages'] : array() as $k => $text)
		if (($k === 'default' || preg_match('/^[a-z]{2}$/', $k)) && trim((string)$text) !== '')
			$cache['messages'][$k] = (string)$text;
	return $cache;
}

function nichan_country_policy_save(array $p) {
	nichan_set_setting('country_policy', json_encode($p, JSON_UNESCAPED_UNICODE));
	$GLOBALS['nichan_country_policy_cache'] = null;
	nichan_write_posting_status_files();
}

// Is the visitor's country ($code, null when unknown) covered by the row?
function nichan_country_row_applies(array $row, $code) {
	$listed = $code !== null && in_array($code, $row['countries'], true);
	return $row['mode'] === 'except' ? !$listed : $listed;
}

// Can the row cover anybody at all?
function nichan_country_row_active(array $row) {
	return $row['mode'] === 'except' || count($row['countries']) > 0;
}

// The rules in force on a board: the global ones when they are on, else the board's own when on.
function nichan_country_scope($uri) {
	$p = nichan_country_policy();
	if ($p['global']['enabled'])
		return $p['global'];
	return isset($p['boards'][$uri]) && $p['boards'][$uri]['enabled'] ? $p['boards'][$uri] : null;
}

function nichan_country_has_captcha(array $scope) {
	return nichan_country_row_active($scope['uk']) || nichan_country_row_active($scope['en']) || nichan_country_row_active($scope['recaptcha']);
}

// A block always wins. An explicit inclusion wins over an "except" rule.
function nichan_country_action_for($ip, $uri) {
	$scope = nichan_country_scope($uri);
	if (!$scope || (!nichan_country_row_active($scope['block']) && !nichan_country_has_captcha($scope)))
		return null;
	static $countries = array();
	if (!array_key_exists($ip, $countries))
		$countries[$ip] = nichan_geoip_country($ip, 'en')[0] ?? null;
	$code = $countries[$ip];
	if (nichan_country_row_applies($scope['block'], $code))
		return array('block', $code);
	foreach (['only', 'except'] as $mode)
		foreach (['recaptcha', 'uk', 'en'] as $type)
			if ($scope[$type]['mode'] === $mode && nichan_country_row_applies($scope[$type], $code))
				return array('captcha', $type);
	return null;
}

function nichan_captcha_type_for($uri, $ip) {
	$board_type = nichan_captcha_type($uri);
	$action = nichan_country_action_for($ip, $uri);
	if (!$action || $action[0] !== 'captcha' || ($board_type !== '' && !empty(nichan_country_scope($uri)['board_captcha_first'])))
		return $board_type;
	$type = $action[1];
	if ($type === 'recaptcha' ? nichan_recaptcha_ready() : nichan_text_captcha_ready())
		return $type;
	// Keep a challenge in place if the configured provider becomes unavailable.
	if ($board_type !== '')
		return $board_type;
	if (nichan_recaptcha_ready())
		return 'recaptcha';
	if (nichan_text_captcha_ready())
		return nichan_board_is_english($uri) ? 'en' : 'uk';
	return 'unavailable';
}

// Boards whose pages ask captcha.php?need=1 ('*' = every board), for js/nichan.js.
function nichan_country_captcha_boards() {
	$p = nichan_country_policy();
	if ($p['global']['enabled'])
		return nichan_country_has_captcha($p['global']) ? array('*') : array();
	$boards = array();
	foreach ($p['boards'] as $uri => $scope)
		if ($scope['enabled'] && nichan_country_has_captcha($scope))
			$boards[] = $uri;
	return $boards;
}

// post.php, when the post is sent: refused with the country's message. Nothing is hidden beforehand.
function nichan_country_block_check($ip, $uri, $is_staff) {
	if ($is_staff || !($action = nichan_country_action_for($ip, $uri)) || $action[0] !== 'block')
		return;
	$m = nichan_country_policy()['messages'];
	$text = $action[1] !== null && isset($m[$action[1]]) ? $m[$action[1]] : (isset($m['default']) ? $m['default'] : NICHAN_COUNTRY_MESSAGE);
	nichan_api_code('country');
	error(utf8tohtml($text));
}
