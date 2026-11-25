<?php

/*
 * Front page (recent.html, the "recent" theme): its settings, edited in mod.php?/nichan-front.
 * How many recent posts, which thumbnails, what counts as a popular thread and what is left
 * out of it, boards left out, words that keep a post off the front page.
 */

defined('TINYBOARD') or exit;

function nichan_front_settings() {
	global $nichan_front_settings;
	if ($nichan_front_settings !== null)
		return $nichan_front_settings;
	// the theme's old settings are the defaults
	$theme = array();
	$query = query("SELECT `name`, `value` FROM ``theme_settings`` WHERE `theme` = 'recent' AND `name` IS NOT NULL");
	if ($query)
		foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $r)
			$theme[$r['name']] = $r['value'];
	$split = function ($s) { return array_values(array_filter(preg_split('/[\s,]+/u', (string)$s), 'strlen')); };
	$lines = function ($s) { return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)$s)), 'strlen')); };
	// Older theme installations requested a longer list for the former expand button.
	$default_posts = min(15, (int)($theme['limit_posts'] ?? 15));
	return $nichan_front_settings = array(
		'posts' => max(0, min(200, (int)nichan_setting('front_posts', $default_posts))),
		'images' => max(0, min(60, (int)nichan_setting('front_images', $theme['limit_images'] ?? 4))),
		'images_op' => (int)nichan_setting('front_images_op', 0),
		'images_spoilers' => (int)nichan_setting('front_images_spoilers', 0), // show spoilered pictures (as the spoiler image)
		'exclude' => $split(nichan_setting('front_exclude', isset($theme['exclude']) ? $theme['exclude'] : '')),
		'words' => $lines(nichan_setting('front_words', '')),
		'popular_count' => max(0, min(50, (int)nichan_setting('front_popular_count', 15))),
		'popular_hours' => max(1, min(168, (int)nichan_setting('front_popular_hours', 12))),
		'popular_min' => max(1, (int)nichan_setting('front_popular_min', 5)),
		'popular_skip_bumplimit' => (int)nichan_setting('front_popular_skip_bumplimit', 0),
		'popular_skip_locked' => (int)nichan_setting('front_popular_skip_locked', 1),
		'popular_skip_sticky' => (int)nichan_setting('front_popular_skip_sticky', 0),
	);
}

// boards left out of the front page: the chosen ones and every non-public board
function nichan_front_excluded() {
	$s = nichan_front_settings();
	return array_values(array_unique(array_merge($s['exclude'], nichan_non_public_board_uris())));
}

// does a post's text contain one of the front page's stop words?
function nichan_front_blocked($text) {
	static $words = null;
	if ($words === null)
		$words = array_map('mb_strtolower', nichan_front_settings()['words']);
	if (!$words)
		return false;
	$text = mb_strtolower((string)$text);
	foreach ($words as $w)
		if ($w !== '' && mb_strpos($text, $w) !== false)
			return true;
	return false;
}
