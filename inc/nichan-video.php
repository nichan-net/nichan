<?php

/*
 * Thumbnails for MP4 and WebM videos: a frame from the first second, made with ffmpeg (when the server has it)
 * and saved as WebP with GD. Without ffmpeg videos keep the file icon, as before.
 * Used by post.php for new videos and by tools/nichan-thumbs.php for the old ones.
 */

defined('TINYBOARD') or exit;

// The ffmpeg command, or null when there is none ($config['webm']['ffmpeg_path'], found once per request).
function nichan_ffmpeg() {
	global $config;
	static $found = false;
	if ($found !== false)
		return $found;
	$found = null;
	if (!function_exists('shell_exec'))
		return null;
	$want = isset($config['webm']['ffmpeg_path']) ? $config['webm']['ffmpeg_path'] : 'ffmpeg';
	// Use the same extra search path as shell_exec_error() (GraphicsMagick, md5sum, etc.).
	$search = isset($config['shell_path']) ? 'PATH=' . escapeshellarg($config['shell_path']) . ':"$PATH"; ' : '';
	$path = trim((string)@shell_exec($search . 'command -v ' . escapeshellarg($want) . ' 2>/dev/null'));
	if ($path !== '')
		return $found = $path;
	// The site's PHP has a shorter PATH than the owner's terminal: a static ffmpeg put in ~/bin (the usual way on a
	// shared hosting) is found there too.
	$homes = array(getenv('HOME'), dirname(dirname(__DIR__)), dirname(dirname(dirname(__DIR__))));
	if (function_exists('posix_getpwuid') && function_exists('posix_geteuid') && ($me = @posix_getpwuid(posix_geteuid())))
		array_unshift($homes, $me['dir']);
	foreach (array_filter($homes) as $home) {
		if (@is_file($home . '/bin/ffmpeg') && @is_executable($home . '/bin/ffmpeg'))
			return $found = $home . '/bin/ffmpeg';
	}
	return $found;
}

/*
 * Writes a WebP thumbnail of the video to $thumb_path, at most $max_w x $max_h.
 * Returns array(thumb_width, thumb_height, video_width, video_height), or null.
 */
function nichan_video_thumb($video_path, $thumb_path, $max_w, $max_h) {
	global $config;
	if (!($ffmpeg = nichan_ffmpeg()) || !function_exists('imagewebp'))
		return null;
	$tmp = tempnam(isset($config['tmp']) ? $config['tmp'] : sys_get_temp_dir(), 'vframe');
	if ($tmp === false)
		return null;
	$frame = $tmp . '.png';
	$diagnostic = '';
	foreach (array('1', '0') as $at) { // a second in (skips black first frames), or the very first for short videos
		// Shared hosting limits processes/threads per account. FFmpeg's automatic thread count can exhaust
		// that limit in a web worker (especially the PNG encoder), even though the same video works in CLI.
		$diagnostic = (string)@shell_exec(escapeshellarg($ffmpeg) . ' -nostdin -hide_banner -v error -y -filter_threads 1 -threads 1 -ss ' . $at . ' -i ' . escapeshellarg($video_path) .
			' -frames:v 1 -an -threads 1 -f image2 -c:v png ' . escapeshellarg($frame) . ' 2>&1');
		clearstatcache(true, $frame); // FFmpeg, rather than PHP, wrote the file (including on the retry).
		if (is_file($frame) && filesize($frame) > 0)
			break;
	}
	$img = is_file($frame) ? @imagecreatefrompng($frame) : false;
	@unlink($frame);
	@unlink($tmp);
	if (!$img) {
		error_log('[nichan-video] Could not extract video thumbnail: ' . substr(trim($diagnostic), 0, 2000));
		return null;
	}
	$w = imagesx($img);
	$h = imagesy($img);
	$scale = min(1, $max_w / $w, $max_h / $h);
	$tw = max(1, (int)round($w * $scale));
	$th = max(1, (int)round($h * $scale));
	$thumb = imagecreatetruecolor($tw, $th);
	imagecopyresampled($thumb, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
	$ok = imagewebp($thumb, $thumb_path, 80);
	imagedestroy($img);
	imagedestroy($thumb);
	if (!$ok)
		return null;
	@chmod($thumb_path, 0644);
	return array($tw, $th, $w, $h);
}
