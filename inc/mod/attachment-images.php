<?php

defined('TINYBOARD') or exit;

function nichan_mod_edit_revision($post) {
	return hash('sha256', serialize(array_intersect_key($post, array_flip(
		array('files', 'name', 'email', 'subject', 'body', 'body_nomarkup')
	))));
}

function nichan_mod_edit_conflict() {
	global $config;
	http_response_code(409);
	exit(Element('page.html', array('config' => $config, 'mod' => true, 'title' => _('Edit post'),
		'body' => strpos($config['locale'], 'en') === 0
			? 'This post has changed. Reload it before saving your edits.'
			: 'Допис уже змінився. Оновіть сторінку, перш ніж зберігати свої правки.')));
}

function nichan_mod_image_slots($post) {
	$slots = array();
	foreach ((array)json_decode($post['files'] ?? '', true) as $slot => $file) {
		if (empty($file['file']) || !ctype_digit((string)$slot) || basename($file['file']) !== $file['file'])
			continue;
		if (in_array(strtolower(pathinfo($file['file'], PATHINFO_EXTENSION)), array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'), true))
			$slots[$slot] = $file;
	}
	return $slots;
}

function nichan_mod_image_changes($post, &$new_paths) {
	global $board, $config;
	$slots = nichan_mod_image_slots($post);
	$files = (array)json_decode($post['files'] ?? '', true);
	$changes = array();
	$allowed = !$post['thread'] && $config['allowed_ext_op'] ? $config['allowed_ext_op'] : $config['allowed_ext'];
	$types = array('jpg' => IMAGETYPE_JPEG, 'jpeg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG,
		'gif' => IMAGETYPE_GIF, 'bmp' => IMAGETYPE_BMP, 'webp' => IMAGETYPE_WEBP);
	$bytes = array_column(array_filter($files, function ($f) { return $f && $f['file'] !== 'deleted'; }), 'size');
	$total = array_sum($bytes);
	foreach ($_FILES as $field => $upload) {
		if ($upload['error'] === UPLOAD_ERR_NO_FILE)
			continue;
		if (!preg_match('/^replacement_(\d+)$/D', $field, $match) || !isset($slots[$match[1]]) ||
			!is_string($_POST['revision'] ?? null) || !hasPermission($config['mod']['deletefile'], $board['uri']))
			error($config['error']['noaccess']);
		$slot = (int)$match[1];
		if ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name']))
			error($config['error']['nomove']);
		$ext = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
		if (!isset($types[$ext]) || !in_array($ext, $allowed, true))
			error($config['error']['unknownext']);
		$tmp = $upload['tmp_name'];
		$size = filesize($tmp);
		$total += $size - (int)($slots[$slot]['size'] ?? 0);
		if ($size > $config['max_filesize'])
			error(sprintf3($config['error']['filesize'], array('sz' => $size, 'filesz' => $size, 'maxsz' => $config['max_filesize'])));
		$dimensions = @getimagesize($tmp);
		if (!$dimensions || $dimensions[2] !== $types[$ext] || $dimensions[0] < 1 || $dimensions[1] < 1)
			error($config['error']['invalidimg']);
		if ($dimensions[0] > $config['max_width'] || $dimensions[1] > $config['max_height'])
			error($config['error']['maxsize']);
		if ($config['ie_mime_type_detection'] && preg_match($config['ie_mime_type_detection'], file_get_contents($tmp, false, null, 0, 255)))
			error($config['error']['mime_exploit']);
		$name = mb_substr(basename($upload['name']), 0, $config['max_filename_len']);
		$changes[$slot] = array('tmp_name' => $tmp, 'hash' => md5_file($tmp), 'is_an_image' => true,
			'name' => $name, 'filename' => $name,
			'extension' => $ext, 'size' => $size, 'width' => $dimensions[0], 'height' => $dimensions[1]);
	}
	if (!$changes)
		return array();
	if ($config['multiimage_method'] === 'split' && $total > $config['max_filesize'])
		error(sprintf3($config['error']['filesize'], array('sz' => $total, 'filesz' => $total, 'maxsz' => $config['max_filesize'])));
	nichan_check_banned_upload(array('files' => $changes));
	require_once dirname(__DIR__) . '/image.php';
	foreach ($changes as $slot => &$file) {
		// Numeric prefix remains compatible with old clients; a new URL never serves the old bytes.
		$id = time() . '-' . bin2hex(random_bytes(12));
		$file['file_id'] = $id;
		$file['file'] = $id . '.' . $file['extension'];
		$path = $board['dir'] . $config['dir']['img'] . $file['file'];
		$file['file_path'] = $path;
		$new_paths[] = $path;
		$image = new Image($file['tmp_name'], $file['extension']);
		if ($config['redraw_image'])
			$image->to($path);
		elseif (!copy($file['tmp_name'], $path))
			error($config['error']['nomove']);
		if (!is_file($path) || !filesize($path))
			error($config['error']['invalidimg']);
		chmod($path, 0644);
		unset($file['tmp_name']);
		nichan_strip_metadata($path, $file['extension']);
		if (($slots[$slot]['thumb'] ?? null) === 'spoiler') {
			$file['thumb'] = $file['thumb_path'] = 'spoiler';
			list($file['thumbwidth'], $file['thumbheight']) = getimagesize($config['spoiler_image']);
		} else {
			$ext = $config['thumb_ext'] ?: $file['extension'];
			$file['thumb'] = $id . '.' . $ext;
			$thumb_path = $board['dir'] . $config['dir']['thumb'] . $file['thumb'];
			$file['thumb_path'] = $thumb_path;
			$new_paths[] = $thumb_path;
			$thumb = $image->resize($ext, $post['thread'] ? $config['thumb_width'] : $config['thumb_op_width'],
				$post['thread'] ? $config['thumb_height'] : $config['thumb_op_height']);
			$thumb->to($thumb_path);
			$file['thumbwidth'] = $thumb->width;
			$file['thumbheight'] = $thumb->height;
			$thumb->_destroy();
			if (!is_file($thumb_path) || !filesize($thumb_path))
				error($config['error']['invalidimg']);
			nichan_strip_metadata($thumb_path);
		}
		$image->destroy();
		clearstatcache(true, $path);
		$file['size'] = filesize($path);
	}
	return $changes;
}

function nichan_mod_remove_replaced_images($old_files, $changes) {
	global $config, $board;
	$removed = true;
	foreach ($changes as $slot => $unused) {
		foreach (array('file' => 'img', 'thumb' => 'thumb') as $key => $dir) {
			$name = $old_files[$slot][$key] ?? '';
			if (!$name || in_array($name, array('spoiler', 'file', 'deleted'), true) || basename($name) !== $name)
				continue;
			$path = $board['dir'] . $config['dir'][$dir] . $name;
			nichan_cf_queue_media($path);
			if (is_file($path) && !file_unlink($path)) {
				$removed = false;
				nichan_error_report('Moderation', 'Could not remove replaced attachment: ' . $path);
			}
		}
	}
	return $removed;
}
