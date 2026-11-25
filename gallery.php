<?php
// All files of one thread, in post order. The normal board access rules still apply.
require_once 'inc/functions.php';

$uri = isset($_GET['board']) && is_string($_GET['board']) ? $_GET['board'] : '';
$thread_id = isset($_GET['thread']) && is_string($_GET['thread']) && ctype_digit($_GET['thread']) ? (int)$_GET['thread'] : 0;
$en = $uri === 'int';
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

$not_found = function () use ($en) {
	global $config;
	http_response_code(404);
	echo Element('page.html', array(
		'config' => $config,
		'title' => $en ? 'Gallery not found' : 'Галерею не знайдено',
		'body' => '<p style="text-align:center">' . ($en ? 'No such board or thread.' : 'Такої дошки або нитки немає.') . '</p>',
		'boardlist' => createBoardlist(),
		'nojavascript' => true,
	));
	exit;
};
if (!$thread_id || !preg_match('/^' . $config['board_regex'] . '$/u', $uri) || !getBoardInfo($uri) || !nichan_can_see_board($uri) || !openBoard($uri))
	$not_found();

$en = strpos($config['locale'], 'en') === 0;
$query = prepare(sprintf('SELECT `id`, `subject`, `body_nomarkup` FROM ``posts_%s`` WHERE `id` = :id AND `thread` IS NULL', $board['uri']));
$query->bindValue(':id', $thread_id, PDO::PARAM_INT);
$query->execute() or error(db_error($query));
$thread = $query->fetch(PDO::FETCH_ASSOC);
if (!$thread)
	$not_found();

$L = $en ? array(
	'title' => 'Gallery', 'back' => 'Back to /', 'thread' => 'To thread', 'empty' => 'This thread has no files.', 'files' => 'Files',
	'previous' => 'Previous file', 'next' => 'Next file', 'post' => 'To post', 'open' => 'Open file', 'download' => 'Download',
	'spoiler' => 'Spoiler', 'reveal' => 'Show file', 'missing' => 'The file could not be loaded.', 'select' => 'Choose a file to view.',
) : array(
	'title' => 'Галерея', 'back' => 'Назад до /', 'thread' => 'До треду', 'empty' => 'У цій нитці немає файлів.', 'files' => 'Файлів',
	'previous' => 'Попередній файл', 'next' => 'Наступний файл', 'post' => 'До допису', 'open' => 'Відкрити файл', 'download' => 'Завантажити',
	'spoiler' => 'Спойлер', 'reveal' => 'Показати файл', 'missing' => 'Не вдалося завантажити файл.', 'select' => 'Виберіть файл для перегляду.',
);
$thread_url = $config['root'] . $board['dir'] . $config['dir']['res'] . link_for(array('id' => $thread_id, 'thread' => null));
$files = array();
$query = prepare(sprintf('SELECT `id`, `body_nomarkup`, `files` FROM ``posts_%s`` WHERE `id` = :op OR `thread` = :thread ORDER BY `id`', $board['uri']));
$query->bindValue(':op', $thread_id, PDO::PARAM_INT);
$query->bindValue(':thread', $thread_id, PDO::PARAM_INT);
$query->execute() or error(db_error($query));
foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $post) {
	$attachments = json_decode((string)$post['files'], true);
	foreach (is_array($attachments) ? $attachments : array() as $index => $file) {
		if (!is_array($file) || empty($file['file']) || $file['file'] === 'deleted' || !preg_match('/^[a-z0-9_.-]+$/i', $file['file']))
			continue;
		$ext = strtolower(pathinfo($file['file'], PATHINFO_EXTENSION));
		$kind = in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif')) ? 'image'
			: (in_array($ext, array('webm', 'mp4', 'm4v', 'ogv', 'mov')) ? 'video'
			: (in_array($ext, array('mp3', 'ogg', 'oga', 'flac', 'wav', 'm4a')) ? 'audio' : 'file'));
		$icon = $kind === 'image' ? 'picture-o' : ($kind === 'video' ? 'film' : ($kind === 'audio' ? 'music'
			: ($ext === 'pdf' ? 'file-pdf-o' : ($ext === 'zip' ? 'file-archive-o' : ($ext === 'txt' ? 'file-text-o' : ($ext === 'apk' ? 'android' : 'file-o'))))));
		$thumb = isset($file['thumb']) ? (string)$file['thumb'] : '';
		$spoiler = $thumb === 'spoiler';
		$thumb_url = '';
		if ($spoiler)
			$thumb_url = $config['root'] . $config['spoiler_image'];
		elseif ($thumb !== '' && !in_array($thumb, array('file', 'deleted')) && preg_match('/^[a-z0-9_.-]+$/i', $thumb) && preg_match('/\.(jpe?g|png|gif|webp|avif)$/i', $thumb))
			$thumb_url = $config['uri_thumb'] . $thumb;
		$files[] = array(
			'key' => 'file-' . $post['id'] . '-' . $index,
			'url' => $config['uri_img'] . $file['file'],
			'thumb' => $thumb_url,
			'name' => !empty($file['filename']) ? $file['filename'] : $file['file'],
			'kind' => $kind, 'ext' => strtoupper($ext), 'icon' => $icon, 'spoiler' => $spoiler,
			'size' => isset($file['size']) ? (int)$file['size'] : 0,
			'width' => isset($file['width']) ? (int)$file['width'] : 0,
			'height' => isset($file['height']) ? (int)$file['height'] : 0,
			'post' => (int)$post['id'], 'post_url' => $thread_url . '#' . $post['id'],
			'text' => nichan_plain_text($post['body_nomarkup'], 220),
		);
	}
}
$json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;
echo Element('page.html', array(
	'config' => $config, 'board' => $board,
	'title' => $L['title'] . ' ' . $board['url'] . ' · №' . $thread_id,
	'subtitle' => htmlspecialchars(nichan_plain_text($thread['subject'] ?: $thread['body_nomarkup'], 100), ENT_QUOTES, 'UTF-8'),
	'body' => Element('gallery.html', array(
		'config' => $config, 'board' => $board, 'files' => $files, 'labels' => $L, 'thread_url' => $thread_url,
		'gallery_json' => json_encode(array('files' => $files, 'labels' => $L), $json_flags),
	)),
	'boardlist' => createBoardlist(), 'nojavascript' => true,
));
