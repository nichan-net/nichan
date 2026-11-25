<?php

/*
 * Banned files: moderators ban a picture once, and every later upload of it is refused.
 *  - exact copies: the MD5 the engine already computes for every upload
 *  - re-saved / resized copies of pictures: a 64-bit difference hash (dHash) made with GD,
 *    compared with a Hamming distance of at most NICHAN_DHASH_DISTANCE bits
 * Only hashes are kept (``nichan_banned_files``), never the pictures themselves: for illegal content
 * that matters. mod.php: the ⛔ link next to a file (mod_nichan_ban_file) and the list
 * "Заборонені файли" (mod_nichan_banned_files).
 */

defined('TINYBOARD') or exit;

define('NICHAN_DHASH_DISTANCE', 6);
define('NICHAN_DHASH_MAX_PIXELS', 16000000); // bigger pictures would need too much memory for GD

// 16 hex digits, or null when the file isn't a picture GD can read (or is too big, or nearly blank)
function nichan_dhash($path) {
	if (!function_exists('imagecreatefromstring') || !is_file($path))
		return null;
	$size = @getimagesize($path);
	if (!$size || $size[0] * $size[1] > NICHAN_DHASH_MAX_PIXELS)
		return null;
	$im = @imagecreatefromstring(file_get_contents($path));
	if (!$im)
		return null;
	$small = imagecreatetruecolor(9, 8);
	imagecopyresampled($small, $im, 0, 0, 0, 0, 9, 8, imagesx($im), imagesy($im));
	$im = null;
	$bits = '';
	for ($y = 0; $y < 8; $y++) {
		$prev = null;
		for ($x = 0; $x < 9; $x++) {
			$c = imagecolorat($small, $x, $y);
			$gray = (($c >> 16) & 255) * 299 + (($c >> 8) & 255) * 587 + ($c & 255) * 114;
			if ($prev !== null)
				$bits .= $gray > $prev ? '1' : '0';
			$prev = $gray;
		}
	}
	$ones = substr_count($bits, '1');
	if ($ones < 6 || $ones > 58)
		return null; // an almost uniform picture would "match" every other plain picture
	$hex = '';
	foreach (str_split($bits, 4) as $nibble)
		$hex .= dechex(bindec($nibble));
	return $hex;
}

function nichan_hamming($a, $b) {
	$d = 0;
	for ($i = 0; $i < 16; $i += 4)
		$d += substr_count(decbin(hexdec(substr($a, $i, 4)) ^ hexdec(substr($b, $i, 4))), '1');
	return $d;
}

// The ban that matches a file (exact MD5 first, then a similar picture), or null.
function nichan_banned_file_match($md5, $dhash) {
	$query = prepare('SELECT * FROM ``nichan_banned_files`` WHERE `md5` = :md5');
	$query->bindValue(':md5', $md5);
	$query->execute() or error(db_error($query));
	if ($row = $query->fetch(PDO::FETCH_ASSOC))
		return $row;
	if ($dhash === null)
		return null;
	$query = query('SELECT * FROM ``nichan_banned_files`` WHERE `dhash` IS NOT NULL') or error(db_error());
	foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
		if (nichan_hamming($row['dhash'], $dhash) <= NICHAN_DHASH_DISTANCE)
			return $row;
	}
	return null;
}

// post.php, right after the files were hashed: refuse banned files.
function nichan_check_banned_upload(array $post) {
	global $config;
	if (empty($post['files']))
		return;
	foreach ($post['files'] as $file) {
		$dhash = !empty($file['is_an_image']) ? nichan_dhash($file['tmp_name']) : null;
		$ban = nichan_banned_file_match($file['hash'], $dhash);
		if (!$ban)
			continue;
		$query = prepare('UPDATE ``nichan_banned_files`` SET `hits` = `hits` + 1, `last_hit` = :t WHERE `id` = :id');
		$query->bindValue(':t', time(), PDO::PARAM_INT);
		$query->bindValue(':id', $ban['id'], PDO::PARAM_INT);
		$query->execute();
		error(strpos($config['locale'], 'en') === 0 ? 'This file is banned on this site.' : 'Цей файл заборонено на сайті.');
	}
}

// Every post on the site that has a file with this MD5: array of array(board, post id, file index, thread id or 0).
function nichan_find_file_copies($md5) {
	$found = array();
	foreach (listBoards() as $b) {
		$query = prepare(sprintf("SELECT `id`, `thread`, `files` FROM ``posts_%s`` WHERE `files` LIKE :like", $b["uri"]));
		$query->bindValue(':like', '%' . $md5 . '%');
		$query->execute() or error(db_error($query));
		while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
			foreach ((array)json_decode($row['files'], true) as $i => $f) {
				if (is_array($f) && isset($f['hash']) && $f['hash'] === $md5 && $f['file'] !== 'deleted')
					$found[] = array($b["uri"], (int)$row["id"], $i, (int)$row["thread"]);
			}
		}
	}
	return $found;
}

function nichan_ban_file_add($md5, $dhash, $reason, $origin, $who) {
	$query = prepare('INSERT INTO ``nichan_banned_files`` (`md5`, `dhash`, `reason`, `origin`, `created`, `mod`)
		VALUES (:md5, :dhash, :reason, :origin, :created, :mod)
		ON DUPLICATE KEY UPDATE `dhash` = COALESCE(`dhash`, VALUES(`dhash`))');
	$query->bindValue(':md5', $md5);
	$query->bindValue(':dhash', $dhash);
	$query->bindValue(':reason', mb_substr($reason, 0, 255));
	$query->bindValue(':origin', mb_substr($origin, 0, 64));
	$query->bindValue(':created', time(), PDO::PARAM_INT);
	$query->bindValue(':mod', mb_substr($who, 0, 30));
	$query->execute() or error(db_error($query));
}
