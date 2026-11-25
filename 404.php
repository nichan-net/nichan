<?php
// "Page not found". .htaccess sends every missing file here.
http_response_code(404);

// Missing images, scripts etc. get a short answer: no point in rendering a whole page for them.
$path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
if (preg_match('/\.(jpe?g|png|gif|webp|webm|mp4|mp3|ogg|svg|ico|css|js|json|map|woff2?|ttf|txt|xml)$/i', $path)) {
	header('Content-Type: text/plain; charset=utf-8');
	echo "404 Not Found\n";
	exit;
}

require_once 'inc/functions.php';

$body = '<div class="nichan-404">'
	. '<img src="' . $config['root'] . 'images/greetings-notext.png" alt="' . htmlspecialchars($config['nichan_site']['name']) . '">'
	. '<p>Такої сторінки не існує, сталкере!</p>'
	. '<p class="unimportant">Можливо, тред видалили або він пішов в архів. Подивіться свіжі нитки на дошках нижче.</p>'
	. '<ul class="nichan-404-boards">';
foreach (nichan_boards_for(false) as $b) {
	$body .= '<li><a href="' . $config['root'] . htmlspecialchars($b['uri']) . '/">/' . htmlspecialchars($b['uri']) . '/ – '
		. htmlspecialchars($b['title']) . '</a></li>';
}
$body .= '</ul><p><a href="' . $config['root'] . '">На головну</a></p></div>'
	. '<style>
		.nichan-404 { text-align: center; max-width: 700px; margin: 0 auto; padding: 0 10px; }
		.nichan-404 img { max-width: 100%; height: auto; }
		.nichan-404 > p:first-of-type { font-size: 1.4em; font-weight: bold; }
		.nichan-404-boards { list-style: none; padding: 0; line-height: 1.8; }
	</style>';

echo Element('page.html', array(
	'config' => $config,
	'title' => 'Сторінку не знайдено',
	'subtitle' => false,
	'body' => $body,
	'boardlist' => createBoardlist(false),
	'nojavascript' => true,
));
