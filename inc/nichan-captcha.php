<?php

/*
 * Captcha of a board (mod.php -> Board settings): none, reCAPTCHA, or the site's own picture
 * captcha with two distorted words, Ukrainian ("uk") or English ("en").
 *
 *   captcha_<uri>  setting: '' | 'recaptcha' | 'uk' | 'en'
 *   captcha_all    setting: 1 = every board asks for a captcha; boards without their own type get
 *                  reCAPTCHA when its keys are set, otherwise the picture captcha in the board's language.
 *
 * A picture challenge is a row in ``nichan_captchas`` (random id -> answer). It is used once: checking
 * deletes it whether the answer was right or not, so guessing needs a new picture every time.
 */

defined('TINYBOARD') or exit;

define('NICHAN_CAPTCHA_TTL', 3600);

function nichan_captcha_font() {
	return __DIR__ . '/lib/captcha/DejaVuSans.ttf';
}

// The picture captcha needs GD with FreeType (imagettftext) and the font file.
function nichan_text_captcha_ready() {
	return function_exists('imagettftext') && function_exists('imagepng') && is_file(nichan_captcha_font());
}

function nichan_recaptcha_ready() {
	return (string)nichan_setting('recaptcha_public', '') !== '' && (string)nichan_setting('recaptcha_private', '') !== '';
}

// Is the board's interface English? (/int/.) Read from its config.php without opening the board.
function nichan_board_is_english($uri) {
	static $cache = array();
	global $config;
	if (!isset($cache[$uri])) {
		$file = sprintf($config['board_path'], $uri) . 'config.php';
		$src = is_file($file) ? (string)@file_get_contents($file) : '';
		$cache[$uri] = (bool)preg_match('/^\s*\$config\[\'locale\'\]\s*=\s*\'en/m', $src);
	}
	return $cache[$uri];
}

// The captcha type the board's visitors actually get ('' = none).
function nichan_captcha_type($uri) {
	$type = (string)nichan_setting('captcha_' . $uri, '');
	$lang = nichan_board_is_english($uri) ? 'en' : 'uk';
	if ($type === '' && nichan_setting('captcha_all'))
		$type = nichan_recaptcha_ready() ? 'recaptcha' : $lang;
	// never let a board go without a captcha because its keys or GD are missing
	if ($type === 'recaptcha' && !nichan_recaptcha_ready())
		$type = $lang;
	if (($type === 'uk' || $type === 'en') && !nichan_text_captcha_ready())
		$type = nichan_recaptcha_ready() ? 'recaptcha' : '';
	return in_array($type, array('recaptcha', 'uk', 'en'), true) ? $type : '';
}

function nichan_captcha_words($lang) {
	if ($lang === 'en') {
		return array('apple', 'river', 'stone', 'cloud', 'green', 'house', 'water', 'bread', 'light', 'night',
			'table', 'chair', 'forest', 'garden', 'window', 'paper', 'music', 'dream', 'ocean', 'bridge',
			'candle', 'castle', 'dragon', 'eagle', 'flower', 'ghost', 'honey', 'island', 'jungle', 'kettle',
			'lemon', 'magnet', 'orange', 'pencil', 'planet', 'rabbit', 'silver', 'tiger', 'violin', 'wizard',
			'yellow', 'zebra', 'anchor', 'basket', 'butter', 'cactus', 'coffee', 'desert', 'engine', 'falcon',
			'guitar', 'hammer', 'jacket', 'kitten', 'ladder', 'meadow', 'needle', 'parrot', 'pepper', 'pillow',
			'rocket', 'saddle', 'sailor', 'shadow', 'spider', 'summer', 'tomato', 'turtle', 'velvet', 'walnut',
			'winter', 'bottle', 'bucket', 'cherry', 'circle', 'copper', 'dinner', 'feather', 'finger', 'frozen',
			'goblin', 'harbor', 'helmet', 'insect', 'knight', 'lizard', 'marble', 'mirror', 'monkey', 'napkin',
			'oyster', 'pirate', 'puzzle', 'ribbon', 'school', 'spoon', 'stream', 'sugar', 'sunset', 'tunnel',
			'valley', 'wagon', 'whale', 'wolf', 'cable', 'radio', 'robot', 'pizza', 'train', 'truck');
	}
	return array('вода', 'хліб', 'мова', 'небо', 'земля', 'сонце', 'річка', 'поле', 'гора', 'місто',
		'село', 'кава', 'книга', 'пісня', 'школа', 'хата', 'вікно', 'двері', 'ложка', 'миска',
		'сокира', 'молоко', 'масло', 'сало', 'борщ', 'каша', 'груша', 'слива', 'вишня', 'яблуко',
		'ягода', 'калина', 'верба', 'тополя', 'береза', 'сосна', 'ялина', 'трава', 'квітка', 'зерно',
		'жито', 'овес', 'гарбуз', 'цибуля', 'морква', 'буряк', 'капуста', 'огірок', 'зима', 'весна',
		'літо', 'осінь', 'ранок', 'вечір', 'тиждень', 'година', 'свято', 'дорога', 'міст', 'потяг',
		'човен', 'вітер', 'хмара', 'сніг', 'мороз', 'туман', 'грім', 'зірка', 'місяць', 'птах',
		'лелека', 'ворона', 'сова', 'качка', 'гуска', 'коза', 'корова', 'кінь', 'вовк', 'лисиця',
		'заєць', 'ведмідь', 'їжак', 'білка', 'риба', 'щука', 'карась', 'бджола', 'метелик', 'рука',
		'нога', 'голова', 'серце', 'вухо', 'палець', 'сорочка', 'шапка', 'чоботи', 'кожух', 'пояс',
		'хустка', 'скриня', 'глечик', 'свічка', 'лампа', 'олівець', 'зошит', 'газета', 'марка', 'гроші',
		'базар', 'друг', 'родина', 'мати', 'батько', 'сестра', 'брат', 'бабуся', 'онук', 'сусід',
		'козак', 'гетьман', 'булава', 'шабля', 'бандура', 'кобза', 'сопілка', 'танок', 'писанка', 'рушник',
		'вареник', 'пампух', 'узвар', 'пиріг', 'чайник', 'подушка', 'ковдра', 'стеля', 'підлога', 'комин');
}

// Letters people type instead of the right ones (other keyboard layout, Latin look-alikes).
function nichan_captcha_normalize($text, $lang) {
	$text = mb_strtolower(preg_replace('/\s+/u', '', (string)$text), 'UTF-8');
	if ($lang === 'en')
		return strtr($text, array('а' => 'a', 'с' => 'c', 'е' => 'e', 'і' => 'i', 'о' => 'o', 'р' => 'p', 'х' => 'x', 'у' => 'y', 'к' => 'k'));
	return strtr($text, array('a' => 'а', 'c' => 'с', 'e' => 'е', 'i' => 'і', 'o' => 'о', 'p' => 'р', 'x' => 'х', 'y' => 'у', 'k' => 'к',
		'ы' => 'і', 'ъ' => 'ї', 'э' => 'є'));
}

// A new challenge: returns array(id, PNG data).
function nichan_captcha_new($lang) {
	$lang = $lang === 'en' ? 'en' : 'uk';
	$words = nichan_captcha_words($lang);
	$a = $words[mt_rand(0, count($words) - 1)];
	do {
		$b = $words[mt_rand(0, count($words) - 1)];
	} while ($b === $a);
	$text = $a . ' ' . $b;

	$id = bin2hex(function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16));
	query('DELETE FROM ``nichan_captchas`` WHERE `created` < ' . (time() - NICHAN_CAPTCHA_TTL));
	$query = prepare('INSERT INTO ``nichan_captchas`` (`id`, `answer`, `created`) VALUES (:id, :answer, :created)');
	$query->bindValue(':id', $id);
	$query->bindValue(':answer', $lang . ':' . $text);
	$query->bindValue(':created', time(), PDO::PARAM_INT);
	$query->execute() or error(db_error($query));

	return array($id, nichan_captcha_png($text));
}

// Checks (and uses up) a challenge.
function nichan_captcha_check($id, $answer, $required_lang) {
	if (!preg_match('/^[0-9a-f]{32}$/', (string)$id))
		return false;
	$query = prepare('SELECT `answer`, `created` FROM ``nichan_captchas`` WHERE `id` = :id');
	$query->bindValue(':id', $id);
	$query->execute() or error(db_error($query));
	$row = $query->fetch(PDO::FETCH_ASSOC);
	$query = prepare('DELETE FROM ``nichan_captchas`` WHERE `id` = :id');
	$query->bindValue(':id', $id);
	$query->execute() or error(db_error($query));
	if ($query->rowCount() !== 1 || !$row || $row['created'] < time() - NICHAN_CAPTCHA_TTL)
		return false;
	list($lang, $text) = explode(':', $row['answer'], 2);
	return $lang === $required_lang && nichan_captcha_normalize($answer, $lang) === nichan_captcha_normalize($text, $lang);
}

// Two words drawn letter by letter (own size, angle and height), bent by a wave, crossed by
// a curve in the text colour and sprinkled with noise.
function nichan_captcha_png($text) {
	$w = 300;
	$h = 80;
	$font = nichan_captcha_font();
	$im = imagecreatetruecolor($w, $h);
	$bg = imagecolorallocate($im, mt_rand(228, 246), mt_rand(228, 246), mt_rand(228, 246));
	imagefilledrectangle($im, 0, 0, $w, $h, $bg);

	// faint background arcs
	for ($i = 0; $i < 10; $i++) {
		$c = imagecolorallocate($im, mt_rand(160, 215), mt_rand(160, 215), mt_rand(160, 215));
		imagearc($im, mt_rand(0, $w), mt_rand(0, $h), mt_rand(40, 220), mt_rand(20, 120), mt_rand(0, 360), mt_rand(0, 360), $c);
	}

	$hue = mt_rand(0, 2);
	$rgb = array(mt_rand(10, 70), mt_rand(10, 70), mt_rand(10, 70));
	$rgb[$hue] = mt_rand(60, 120);
	$fg = imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);

	// measure, then centre
	$chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
	$glyphs = array();
	$total = 0;
	foreach ($chars as $ch) {
		$size = $ch === ' ' ? 20 : mt_rand(22, 29);
		$box = imagettfbbox($size, 0, $font, $ch);
		$adv = $ch === ' ' ? mt_rand(12, 18) : ($box[2] - $box[0]) + mt_rand(-2, 1);
		$glyphs[] = array($ch, $size, $adv);
		$total += $adv;
	}
	$scale = $total > $w - 24 ? ($w - 24) / $total : 1;
	$x = max(8, ($w - $total * $scale) / 2);
	foreach ($glyphs as $g) {
		list($ch, $size, $adv) = $g;
		if ($ch !== ' ')
			imagettftext($im, $size * $scale, mt_rand(-22, 22), (int)$x, (int)($h / 2 + $size * $scale / 2.6 + mt_rand(-7, 7)), $fg, $font, $ch);
		$x += $adv * $scale;
	}

	// wave: shift columns up/down, then rows left/right
	$out = imagecreatetruecolor($w, $h);
	imagefilledrectangle($out, 0, 0, $w, $h, $bg);
	$amp = mt_rand(3, 6);
	$period = mt_rand(70, 130);
	$phase = mt_rand(0, 628) / 100;
	for ($i = 0; $i < $w; $i++)
		imagecopy($out, $im, $i, (int)round($amp * sin($i * 2 * M_PI / $period + $phase)), $i, 0, 1, $h);
	$im = null; // freed with the last reference (imagedestroy() is deprecated in PHP 8.5)
	$im = imagecreatetruecolor($w, $h);
	imagefilledrectangle($im, 0, 0, $w, $h, $bg);
	$amp = mt_rand(2, 4);
	$period = mt_rand(40, 70);
	$phase = mt_rand(0, 628) / 100;
	for ($j = 0; $j < $h; $j++)
		imagecopy($im, $out, (int)round($amp * sin($j * 2 * M_PI / $period + $phase)), $j, 0, $j, $w, 1);
	$out = null;

	// a curve through the words
	imagesetthickness($im, 2);
	$y0 = mt_rand((int)($h * .35), (int)($h * .65));
	$amp = mt_rand(6, 14);
	$period = mt_rand(120, 260);
	$phase = mt_rand(0, 628) / 100;
	$px = 0;
	$py = $y0;
	for ($i = 4; $i <= $w; $i += 4) {
		$ny = (int)round($y0 + $amp * sin($i * 2 * M_PI / $period + $phase));
		imageline($im, $px, $py, $i, $ny, $fg);
		$px = $i;
		$py = $ny;
	}
	imagesetthickness($im, 1);

	// speckles
	for ($i = 0; $i < 350; $i++) {
		$c = mt_rand(0, 3) ? imagecolorallocate($im, mt_rand(100, 200), mt_rand(100, 200), mt_rand(100, 200)) : $fg;
		imagesetpixel($im, mt_rand(0, $w - 1), mt_rand(0, $h - 1), $c);
	}

	ob_start();
	imagepng($im);
	$im = null; // freed with the last reference (imagedestroy() is deprecated in PHP 8.5)
	return ob_get_clean();
}
