<?php

/*
 * Removes metadata from uploaded pictures without re-encoding them: EXIF (GPS position, phone model,
 * date), XMP, IPTC and comments. JPEG keeps only the orientation, so photos from phones stay upright;
 * colour profiles are kept. PNG loses its text and EXIF chunks, WEBP its EXIF and XMP chunks.
 * Used by post.php for new files and by tools/nichan-strip-metadata.php for the old ones.
 */

defined('TINYBOARD') or exit;

// Strips the file in place. Returns true when something was removed, false when there was nothing to
// remove (or the file isn't a picture of a known kind). $write = false: only tell.
function nichan_strip_metadata($path, $ext = null, $write = true) {
	$ext = strtolower($ext ? $ext : pathinfo($path, PATHINFO_EXTENSION));
	if (!in_array($ext, array('jpg', 'jpeg', 'png', 'webp')) || !is_file($path))
		return false;
	$data = file_get_contents($path);
	if ($data === false)
		return false;
	if ($ext === 'png')
		$new = nichan_strip_png($data);
	elseif ($ext === 'webp')
		$new = nichan_strip_webp($data);
	else
		$new = nichan_strip_jpeg($data);
	if ($new === null || $new === $data)
		return false;
	if (!$write)
		return true;
	$tmp = $path . '.strip';
	if (file_put_contents($tmp, $new) !== strlen($new) || !rename($tmp, $path)) {
		@unlink($tmp);
		return false;
	}
	@chmod($path, 0644);
	return true;
}

// The Orientation tag (1..8) of an EXIF block ("Exif\0\0" + TIFF), or 1.
function nichan_exif_orientation($exif) {
	if (strlen($exif) < 14 || substr($exif, 0, 6) !== "Exif\0\0")
		return 1;
	$tiff = substr($exif, 6);
	$le = substr($tiff, 0, 2) === 'II';
	if (!$le && substr($tiff, 0, 2) !== 'MM')
		return 1;
	$u16 = function ($o) use ($tiff, $le) { $v = unpack($le ? 'v' : 'n', substr($tiff, $o, 2)); return $v[1]; };
	$u32 = function ($o) use ($tiff, $le) { $v = unpack($le ? 'V' : 'N', substr($tiff, $o, 4)); return $v[1]; };
	$ifd = $u32(4);
	if ($ifd + 2 > strlen($tiff))
		return 1;
	$n = $u16($ifd);
	for ($i = 0; $i < $n; $i++) {
		$e = $ifd + 2 + 12 * $i;
		if ($e + 12 > strlen($tiff))
			break;
		if ($u16($e) === 0x0112) {
			$v = $u16($e + 8);
			return $v >= 1 && $v <= 8 ? $v : 1;
		}
	}
	return 1;
}

function nichan_strip_jpeg($data) {
	if (substr($data, 0, 2) !== "\xFF\xD8")
		return null;
	$out = "\xFF\xD8";
	$pos = 2;
	$len = strlen($data);
	$orientation = 1;
	$exif_seen = false;
	$removed = false;
	while ($pos + 4 <= $len) {
		if ($data[$pos] !== "\xFF")
			return null; // not what we expected: leave the file alone
		$marker = ord($data[$pos + 1]);
		if ($marker === 0xFF) { // fill byte
			$pos++;
			continue;
		}
		if ($marker === 0xDA) { // start of scan: the picture itself, copied as it is
			$out .= substr($data, $pos);
			break;
		}
		if ($marker === 0xD8 || ($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
			$out .= substr($data, $pos, 2);
			$pos += 2;
			continue;
		}
		$seg_len = unpack('n', substr($data, $pos + 2, 2))[1];
		if ($seg_len < 2 || $pos + 2 + $seg_len > $len)
			return null;
		$segment = substr($data, $pos, 2 + $seg_len);
		$payload = substr($segment, 4);
		// APP1 (EXIF, XMP), APP12 (Ducky), APP13 (Photoshop IPTC), COM (comments) go
		if ($marker === 0xE1 || $marker === 0xEC || $marker === 0xED || $marker === 0xFE) {
			// (the first EXIF block counts, as in picture viewers)
			if ($marker === 0xE1 && substr($payload, 0, 6) === "Exif\0\0" && !$exif_seen) {
				$orientation = nichan_exif_orientation($payload);
				$exif_seen = true;
			}
			$removed = true;
		} else {
			$out .= $segment;
		}
		$pos += 2 + $seg_len;
	}
	if (!$removed)
		return $data;
	if ($orientation !== 1) {
		// a minimal EXIF block with only the orientation, straight after SOI / JFIF
		$tiff = 'MM' . pack('nN', 42, 8) . pack('n', 1) . pack('nnNnn', 0x0112, 3, 1, $orientation, 0) . pack('N', 0);
		$app1 = "\xFF\xE1" . pack('n', 2 + 6 + strlen($tiff)) . "Exif\0\0" . $tiff;
		$at = 2;
		if (substr($out, 2, 2) === "\xFF\xE0") // keep JFIF first
			$at = 4 + unpack('n', substr($out, 4, 2))[1];
		$out = substr($out, 0, $at) . $app1 . substr($out, $at);
	}
	return $out;
}

function nichan_strip_png($data) {
	if (substr($data, 0, 8) !== "\x89PNG\r\n\x1a\n")
		return null;
	$out = substr($data, 0, 8);
	$pos = 8;
	$len = strlen($data);
	while ($pos + 12 <= $len) {
		$chunk_len = unpack('N', substr($data, $pos, 4))[1];
		$type = substr($data, $pos + 4, 4);
		if ($pos + 12 + $chunk_len > $len)
			return null;
		if (!in_array($type, array('eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME')))
			$out .= substr($data, $pos, 12 + $chunk_len);
		$pos += 12 + $chunk_len;
		if ($type === 'IEND')
			break;
	}
	return $out;
}

function nichan_strip_webp($data) {
	if (substr($data, 0, 4) !== 'RIFF' || substr($data, 8, 4) !== 'WEBP')
		return null;
	$body = '';
	$pos = 12;
	$len = strlen($data);
	$removed = false;
	while ($pos + 8 <= $len) {
		$type = substr($data, $pos, 4);
		$chunk_len = unpack('V', substr($data, $pos + 4, 4))[1];
		$full = 8 + $chunk_len + ($chunk_len & 1);
		if ($pos + 8 + $chunk_len > $len)
			return null;
		if ($type === 'EXIF' || $type === 'XMP ') {
			$removed = true;
		} else {
			$chunk = substr($data, $pos, $full);
			if ($type === 'VP8X' && strlen($chunk) > 8) // flags: no more EXIF (0x08) and XMP (0x04)
				$chunk[8] = chr(ord($chunk[8]) & ~0x0C);
			$body .= $chunk;
		}
		$pos += $full;
	}
	if (!$removed)
		return $data;
	return 'RIFF' . pack('V', 4 + strlen($body)) . 'WEBP' . $body;
}
