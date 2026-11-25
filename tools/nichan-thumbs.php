#!/usr/bin/php
<?php
/*
 *  nichan-thumbs.php - make the old thumbnails lighter: the .gif ones (really PNG or JPEG inside) become
 *  WebP, and MP4 / WebM videos without a thumbnail get a frame from the video (when the server has ffmpeg).
 *  The posts are updated to point at the new files; the pages then need a rebuild. The old .gif files
 *  are left in place until --delete-old (run it after the rebuild, so no page still shows them).
 *
 *    php tools/nichan-thumbs.php --dry-run        # only count
 *    php tools/nichan-thumbs.php                  # convert (then: php tools/rebuild.php -q)
 *    php tools/nichan-thumbs.php --delete-old     # after the rebuild: remove the old .gif thumbnails
 *    php tools/nichan-thumbs.php --board=b        # one board
 *
 *  Safe to run again: what is done already is skipped.
 */

require dirname(__FILE__) . '/inc/cli.php';

$opts = getopt('', array('dry-run', 'delete-old', 'board:'));
$dry = isset($opts['dry-run']);
$delete_old = isset($opts['delete-old']);
// files that could not be read (broken or not pictures): named, so they can be looked at
function nichan_thumbs_failed($uri, $name) {
	global $total;
	if ($total['failed']++ < 20)
		echo "  could not convert /$uri/ $name\n";
}

$total = array('webp' => 0, 'video' => 0, 'saved' => 0, 'deleted' => 0, 'failed' => 0);
if (!$delete_old && !nichan_ffmpeg())
	echo "(no ffmpeg on this server: videos keep their icon)\n";

foreach (listBoards() as $b) {
	if (!empty($opts['board']) && $b['uri'] !== $opts['board'])
		continue;
	$thumb_dir = sprintf($config['board_path'], $b['uri']) . $config['dir']['thumb'];
	$src_dir = sprintf($config['board_path'], $b['uri']) . $config['dir']['img'];

	if ($delete_old) {
		// .gif thumbnails that no post points at any more
		$used = array();
		$query = query(sprintf("SELECT `files` FROM ``posts_%s`` WHERE `files` LIKE '%%.gif%%'", $b['uri'])) or error(db_error());
		while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
			foreach ((array)json_decode((string)$row['files']) as $f)
				if (is_object($f) && isset($f->thumb))
					$used[$f->thumb] = true;
		}
		$n = 0;
		foreach ((array)glob($thumb_dir . '*.gif') as $path) {
			if (isset($used[basename($path)]) || !is_file(preg_replace('/\.gif$/', '.webp', $path)))
				continue; // still used, or never converted
			unlink($path);
			$n++;
		}
		$total['deleted'] += $n;
		echo "/{$b['uri']}/: removed $n old thumbnails\n";
		continue;
	}

	$query = query(sprintf("SELECT `id`, `thread`, `files` FROM ``posts_%s`` WHERE `files` IS NOT NULL AND `files` <> '[]'", $b['uri'])) or error(db_error());
	$update = prepare(sprintf('UPDATE ``posts_%s`` SET `files` = :files WHERE `id` = :id', $b['uri']));
	$webp = $video = 0;
	while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
		$files = json_decode((string)$row['files']);
		if (!is_array($files))
			continue;
		$changed = false;
		foreach ($files as $f) {
			if (!is_object($f) || !isset($f->file, $f->thumb) || $f->file == 'deleted')
				continue;
			$ext = strtolower(pathinfo($f->file, PATHINFO_EXTENSION));
			if (preg_match('/\.gif$/i', $f->thumb) && is_file($thumb_dir . $f->thumb)) {
				// the old thumbnail, re-encoded as WebP
				$new = preg_replace('/\.gif$/i', '.webp', $f->thumb);
				if (!$dry) {
					$im = @imagecreatefromstring(file_get_contents($thumb_dir . $f->thumb));
					if (!$im) {
						nichan_thumbs_failed($b['uri'], $f->thumb);
						continue;
					}
					imagepalettetotruecolor($im);
					imagealphablending($im, false);
					imagesavealpha($im, true);
					if (!imagewebp($im, $thumb_dir . $new, 80)) {
						nichan_thumbs_failed($b['uri'], $f->thumb);
						continue;
					}
					imagedestroy($im);
					$total['saved'] += filesize($thumb_dir . $f->thumb) - filesize($thumb_dir . $new);
				}
				$f->thumb = $new;
				$changed = true;
				$webp++;
			} elseif ($f->thumb == 'file' && in_array($ext, array('mp4', 'webm')) && is_file($src_dir . $f->file) && nichan_ffmpeg()) {
				// an old video: a frame as its thumbnail
				$new = preg_replace('/\.[^.]+$/', '', $f->file) . '.webp';
				$op = !$row['thread'];
				$t = $dry ? array(0, 0, 0, 0) : nichan_video_thumb($src_dir . $f->file, $thumb_dir . $new,
					$op ? $config['thumb_op_width'] : $config['thumb_width'], $op ? $config['thumb_op_height'] : $config['thumb_height']);
				if (!$t) {
					nichan_thumbs_failed($b['uri'], $f->file);
					continue;
				}
				$f->thumb = $new;
				list($f->thumbwidth, $f->thumbheight, $f->width, $f->height) = $t;
				$changed = true;
				$video++;
			}
		}
		if ($changed && !$dry) {
			$update->bindValue(':files', json_encode($files));
			$update->bindValue(':id', (int)$row['id'], PDO::PARAM_INT);
			$update->execute() or error(db_error($update));
		}
	}
	$total['webp'] += $webp;
	$total['video'] += $video;
	echo "/{$b['uri']}/: " . ($dry ? 'to convert' : 'converted') . " $webp thumbnails, videos: $video\n";
}

if ($delete_old)
	echo "Removed {$total['deleted']} old thumbnails.\n";
else
	echo ($dry ? 'Would convert' : 'Converted') . " {$total['webp']} thumbnails to WebP" .
		($dry ? '' : ' (' . round($total['saved'] / 1048576, 1) . ' MB smaller)') . ", video thumbnails: {$total['video']}" .
		($total['failed'] ? ", failed: {$total['failed']}" : '') . ".\n" .
		($dry ? '' : "Now rebuild the pages: php tools/rebuild.php -q\n");
