<?php
/*
 * The API for the mobile app: /api/v1/<route> (.htaccess), described in API.md.
 * Reading boards and threads is mostly the static JSON the site writes anyway (/<board>/catalog.json,
 * /<board>/res/<id>.json); this adds what those files can't give. Posting, deleting and reporting go through
 * post.php with every check of the site's own form (bans, captcha, cooldowns, closed boards), only the answers
 * are the API's: {"ok": true, ...} or {"error": "<code>", "message": "<text>"} with an HTTP status (inc/nichan-api.php).
 */

define('NICHAN_API', 1);
require_once 'inc/functions.php';
require_once 'inc/bans.php';

$route = isset($_GET['route']) ? trim((string)$_GET['route'], '/') : '';
$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$en = strpos($config['locale'], 'en') === 0;
$reads = array('boards', 'thread/new', 'captcha', 'search', 'translate/languages', 'archive');
$writes = array('post', 'delete', 'report', 'translate', 'notifications/check');

if (!in_array($route, $reads) && !in_array($route, $writes))
	nichan_api_fail(404, 'not_found', $en ? 'No such API method.' : 'Такого методу API немає.');
if (in_array($route, $reads)) {
	header('Access-Control-Allow-Origin: *');
	if ($method !== 'GET' && $method !== 'HEAD')
		nichan_api_fail(405, 'method_not_allowed', $en ? 'Use GET.' : 'Цей метод приймає лише GET.');
} elseif ($method !== 'POST') {
	nichan_api_fail(405, 'method_not_allowed', $en ? 'Use POST.' : 'Цей метод приймає лише POST.');
}

// ?board= / board=: a board a visitor may open, opened
function api_open_board($uri) {
	global $config, $en;
	$uri = (string)$uri;
	if (!preg_match('/^' . $config['board_regex'] . '$/u', $uri) || !getBoardInfo($uri) || !nichan_can_see_board($uri, false) || !openBoard($uri))
		nichan_api_fail(404, 'board_not_found', $en ? 'No such board.' : 'Такої дошки немає.');
}

function api_param($name, $default = null) {
	$v = isset($_POST[$name]) ? $_POST[$name] : (isset($_GET[$name]) ? $_GET[$name] : $default);
	return is_string($v) ? $v : $default;
}

// retry_after of an answer, also sent as the Retry-After header
function api_retry($seconds) {
	$seconds = max(1, (int)$seconds);
	header('Retry-After: ' . $seconds);
	return $seconds;
}

// post ids from "post=123" or "posts=123,124"
function api_ids() {
	$ids = array();
	foreach (preg_split('/[\s,]+/', (string)api_param('posts', api_param('post', ''))) as $id)
		if (ctype_digit($id) && (int)$id > 0)
			$ids[(int)$id] = true;
	return array_slice(array_keys($ids), 0, 20);
}

// a JSON body ({"board": "b", ...}) is read as the form's fields
if ($method === 'POST' && $route !== 'notifications/check' && stripos(isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '', 'application/json') === 0) {
	$json = json_decode((string)file_get_contents('php://input', false, null, 0, 65536), true);
	if (!is_array($json))
		nichan_api_fail(400, 'bad_request', $en ? 'The body is not JSON.' : 'Тіло запиту — не JSON.');
	foreach ($json as $k => $v)
		if (is_string($v) || is_int($v))
			$_POST[$k] = (string)$v;
}

switch ($route) {

case 'archive':
	// a page of the board's archive (inc/nichan-notify.php): the archived threads' first posts, as in catalog.json
	if (($wait = nichan_rate_per_minute('archive', nichan_translate_who($_SERVER['REMOTE_ADDR']), NICHAN_ARCHIVE_PER_MINUTE)))
		nichan_api_fail(429, 'rate_limited', $en ? 'Too many requests. Try again in a minute.' : 'Забагато запитів. Спробуйте за хвилину.',
			array('retry_after' => api_retry($wait)));
	api_open_board(api_param('board', ''));
	$limit = api_param('limit', '50');
	if (!ctype_digit($limit) || (int)$limit < 1 || (int)$limit > 100)
		nichan_api_fail(400, 'bad_request', $en ? 'limit must be 1-100.' : 'limit має бути від 1 до 100.');
	$after = null;
	if (($cursor = api_param('cursor')) !== null && ($after = nichan_archive_cursor_key($board['uri'], $cursor)) === false)
		nichan_api_fail(400, 'cursor_invalid', $en ? 'The cursor is not valid. Start the list again.' : 'Недійсний курсор. Почніть список спочатку.');
	if (nichan_archive_pages() <= 0)
		nichan_api_send(200, array('board' => $board['uri'], 'available' => false, 'threads' => array(), 'nextCursor' => null), 'no-cache', true);
	$page = nichan_archive_page($board['uri'], (int)$limit, $after);
	nichan_api_send(200, array('board' => $board['uri'], 'available' => true, 'threads' => $page['threads'], 'nextCursor' => $page['next']), 'no-cache', true);

case 'notifications/check':
	// what is new on the boards and in the threads the app follows (inc/nichan-notify.php); it only reads
	header('Cache-Control: private, no-store');
	if (($wait = nichan_notify_rate(nichan_translate_who($_SERVER['REMOTE_ADDR']))))
		nichan_api_fail(429, 'rate_limited', $en ? 'Too many checks. Try again in a minute.' : 'Забагато перевірок. Спробуйте за хвилину.',
			array('retry_after' => api_retry($wait)));
	if (isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > NICHAN_NOTIFY_BODY)
		nichan_api_fail(413, 'too_large', $en ? 'The request is too large.' : 'Запит завеликий.');
	$raw = (string)file_get_contents('php://input', false, null, 0, NICHAN_NOTIFY_BODY + 1);
	$req = json_decode($raw, true);
	if (strlen($raw) > NICHAN_NOTIFY_BODY)
		nichan_api_fail(413, 'too_large', $en ? 'The request is too large.' : 'Запит завеликий.');
	$bad = function ($what) use ($en) {
		nichan_api_fail(400, 'bad_request', ($en ? 'Bad request: ' : 'Некоректний запит: ') . $what);
	};
	if (!is_array($req) || ($req && array_keys($req) === range(0, count($req) - 1)))
		$bad($en ? 'the body must be a JSON object' : 'тіло має бути JSON-об’єктом');
	$lists = array();
	foreach (array('boards' => NICHAN_NOTIFY_BOARDS, 'threads' => NICHAN_NOTIFY_THREADS, 'ownPosts' => NICHAN_NOTIFY_OWN) as $key => $max) {
		$list = isset($req[$key]) ? $req[$key] : array();
		if (!is_array($list) || ($list && array_keys($list) !== range(0, count($list) - 1)))
			$bad("$key");
		if (count($list) > $max)
			nichan_api_fail(400, 'too_many_items', ($en ? "At most $max items in " : "Щонайбільше $max елементів у ") . $key . '.');
		foreach ($list as $i => $it) {
			$ok = is_array($it) && isset($it['board']) && is_string($it['board']) && $it['board'] !== '' && strlen($it['board']) <= 58;
			if ($ok && $key !== 'boards')
				$ok = isset($it[$key === 'threads' ? 'thread' : 'post']) && is_int($it[$key === 'threads' ? 'thread' : 'post'])
					&& $it[$key === 'threads' ? 'thread' : 'post'] > 0;
			if ($ok && $key !== 'ownPosts')
				$ok = !isset($it['cursor']) || (is_string($it['cursor']) && strlen($it['cursor']) <= 100);
			if (!$ok)
				$bad("{$key}[$i]");
		}
		$lists[$key] = $list;
	}
	$own = array();
	foreach ($lists['ownPosts'] as $it)
		$own[$it['board'] . ':' . $it['post']] = true;
	// (the same board or thread twice is answered once)
	$out = array('boards' => array(), 'threads' => array());
	$seen = array();
	foreach ($lists['boards'] as $it) {
		if (isset($seen['b:' . $it['board']]))
			continue;
		$seen['b:' . $it['board']] = true;
		$out['boards'][] = nichan_notify_board($it['board'], isset($it['cursor']) ? $it['cursor'] : null, $en);
	}
	foreach ($lists['threads'] as $it) {
		if (isset($seen['t:' . $it['board'] . ':' . $it['thread']]))
			continue;
		$seen['t:' . $it['board'] . ':' . $it['thread']] = true;
		$out['threads'][] = nichan_notify_thread($it['board'], $it['thread'], isset($it['cursor']) ? $it['cursor'] : null, $own, $en);
	}
	nichan_api_send(200, $out, 'private, no-store');

case 'translate/languages':
	$languages = array();
	foreach (nichan_translate_languages() as $code => $name)
		$languages[] = array('code' => $code, 'name' => $name);
	nichan_api_send(200, array('available' => nichan_translate_ready(), 'languages' => $languages), 'public, max-age=3600');

case 'translate':
	// a post of the site into one of nichan_translate_languages() (inc/nichan-translate.php); never any other text
	if (!nichan_translate_ready())
		nichan_api_fail(503, 'not_configured', $en ? 'Translation is not available.' : 'Переклад недоступний.');
	$to = nichan_translate_language(api_param('to', ''));
	if ($to === null)
		nichan_api_fail(400, 'unsupported_language', $en ? 'This language is not offered.' : 'Переклад цією мовою недоступний.');
	api_open_board(api_param('board', ''));
	$id = api_param('post', '');
	if (!ctype_digit($id) || (int)$id < 1)
		nichan_api_fail(400, 'bad_request', $en ? 'Which post?' : 'Не вказано допис.');
	$who = nichan_translate_who($_SERVER['REMOTE_ADDR']);
	if (nichan_rate_count('translate', $who, 600) >= NICHAN_TRANSLATE_REQUESTS_10MIN)
		nichan_api_fail(429, 'rate_limited', $en ? 'Too many translations. Try again in a few minutes.' : 'Забагато перекладів. Спробуйте за кілька хвилин.',
			array('retry_after' => api_retry(nichan_rate_wait('translate', $who, 600))));
	nichan_rate_hit('translate', $who);

	$query = prepare(sprintf('SELECT `subject`, `body_nomarkup` FROM ``posts_%s`` WHERE `id` = :id', $board['uri']));
	$query->bindValue(':id', (int)$id, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	if (!($row = $query->fetch(PDO::FETCH_ASSOC)))
		nichan_api_fail(404, 'post_not_found', $en ? 'No such post.' : 'Такого допису немає.');
	$subject = trim((string)$row['subject']);
	$text = nichan_translate_source($row['body_nomarkup']);
	if ($subject === '' && $text === '')
		nichan_api_fail(400, 'nothing_to_translate', $en ? 'The post has no text.' : 'У дописі немає тексту.');
	$chars = mb_strlen($subject) + mb_strlen($text);
	if ($chars > NICHAN_TRANSLATE_MAX_CHARS)
		nichan_api_fail(413, 'too_long', $en ? 'The post is too long to translate.' : 'Допис задовгий для перекладу.');

	$hash = md5($to . "\0" . $subject . "\0" . $text);
	if ($kept = nichan_translation_get($board['uri'], (int)$id, $to, $hash))
		nichan_api_send(200, array('translatedText' => (string)$kept['body'], 'translatedSubject' => $subject !== '' ? (string)$kept['subject'] : null,
			'from' => $kept['from_lang'] !== '' ? $kept['from_lang'] : null, 'to' => $to, 'cached' => true));

	// what this address and the whole site may still send to Azure
	if (nichan_translate_used($who, 600) + $chars > NICHAN_TRANSLATE_IP_10MIN)
		$wait = nichan_translate_wait($who, 600, NICHAN_TRANSLATE_IP_10MIN, $chars);
	elseif (nichan_translate_used($who, 86400) + $chars > NICHAN_TRANSLATE_IP_DAY)
		$wait = nichan_translate_wait($who, 86400, NICHAN_TRANSLATE_IP_DAY, $chars);
	if (isset($wait))
		nichan_api_fail(429, 'rate_limited', $en ? 'Too many translations. Try again later.' : 'Забагато перекладів. Спробуйте пізніше.',
			array('retry_after' => api_retry($wait)));
	$budget = nichan_translate_budgets();
	if (nichan_translate_month_used() + $chars > $budget['month'])
		$wait = mktime(0, 0, 0, (int)date('n') + 1, 1) - time();
	elseif (nichan_translate_used(null, 86400) + $chars > $budget['day'])
		$wait = nichan_translate_wait(null, 86400, $budget['day'], $chars);
	if (isset($wait))
		nichan_api_fail(503, 'translate_unavailable', $en ? 'Translation is unavailable for now. Try again later.' : 'Переклад тимчасово недоступний. Спробуйте пізніше.',
			array('retry_after' => api_retry($wait)));

	$texts = $subject !== '' ? array($subject, $text) : array($text);
	if ($text === '')
		$texts = array($subject);
	$res = nichan_translate_azure($texts, $to);
	if (isset($res['error'])) {
		$extra = $res['retry_after'] ? array('retry_after' => api_retry($res['retry_after'])) : array();
		nichan_api_fail(503, $res['error'] === 429 ? 'translate_busy' : 'translate_unavailable',
			$en ? 'Translation is unavailable for now. Try again later.' : 'Переклад тимчасово недоступний. Спробуйте пізніше.', $extra);
	}
	nichan_translate_count($who, $chars);
	$out_subject = $subject !== '' ? $res['texts'][0] : null;
	$out_text = $text === '' ? '' : end($res['texts']);
	nichan_translation_put($board['uri'], (int)$id, $to, $hash, $out_subject, $out_text, $res['from']);
	nichan_api_send(200, array('translatedText' => $out_text, 'translatedSubject' => $out_subject, 'from' => $res['from'], 'to' => $to, 'cached' => false));

case 'search':
	require_once 'inc/nichan-search.php';
	$q = isset($_GET['q']) && is_string($_GET['q']) ? trim(preg_replace('/\s+/u', ' ', $_GET['q'])) : '';
	$q = mb_substr($q, 0, 100);
	$words = array_slice(array_values(array_unique(array_filter(
		explode(' ', mb_strtolower($q)),
		function ($word) { return mb_strlen($word) >= 2; }
	))), 0, 6);
	if ($q !== '' && (!$words || mb_strlen(implode('', $words)) < 3))
		nichan_api_fail(400, 'bad_request', $en ? 'Type at least 3 letters.' : 'Введіть щонайменше 3 літери.');

	$search_boards = array_column(nichan_boards_for(false), 'title', 'uri');
	$board_uri = isset($_GET['board']) && is_string($_GET['board']) ? $_GET['board'] : '';
	if ($board_uri !== '' && !isset($search_boards[$board_uri]))
		nichan_api_fail(404, 'board_not_found', $en ? 'No such board.' : 'Такої дошки немає.');

	$results = array();
	$next_cursor = null;
	if ($q !== '') {
		$ip = $_SERVER['REMOTE_ADDR'];
		list($per_ip, $minutes) = $config['search']['queries_per_minutes'];
		list($overall, $minutes_all) = $config['search']['queries_per_minutes_all'];
		$rate_query = prepare('SELECT SUM(`ip` = :ip AND `time` > :t1) AS `mine`, SUM(`time` > :t2) AS `overall` FROM ``search_queries`` WHERE `time` > :t3');
		$rate_query->bindValue(':ip', $ip);
		$rate_query->bindValue(':t1', time() - 60 * $minutes, PDO::PARAM_INT);
		$rate_query->bindValue(':t2', time() - 60 * $minutes_all, PDO::PARAM_INT);
		$rate_query->bindValue(':t3', time() - 60 * max($minutes, $minutes_all), PDO::PARAM_INT);
		$rate_query->execute() or error(db_error($rate_query));
		$rate = $rate_query->fetch(PDO::FETCH_ASSOC);
		if ((int)$rate['mine'] >= $per_ip || (int)$rate['overall'] >= $overall) {
			header('Retry-After: 120');
			nichan_api_fail(429, 'rate_limited',
				$en ? 'Too many searches. Try again in a minute or two.' : 'Забагато пошуків. Спробуйте ще раз за хвилину-дві.',
				array('retry_after' => 120));
		}
		$rate_query = prepare("INSERT INTO ``search_queries`` (`ip`, `time`, `query`) VALUES (:ip, :time, '')");
		$rate_query->bindValue(':ip', $ip);
		$rate_query->bindValue(':time', time(), PDO::PARAM_INT);
		$rate_query->execute() or error(db_error($rate_query));
		if (mt_rand(1, 20) == 1)
			query('DELETE FROM ``search_queries`` WHERE `time` < ' . (time() - 3600));

		list($results, $next_cursor) = nichan_search_page($board_uri !== '' ? array($board_uri) : array_keys($search_boards), $words, $_GET['before'] ?? '');
	}

	$items = array();
	foreach ($results as $row) {
		$body = nichan_plain_text($row['body_nomarkup'], 100000);
		$lower = mb_strtolower($body);
		$position = false;
		foreach ($words as $word) {
			$found = mb_strpos($lower, $word);
			if ($found !== false && ($position === false || $found < $position))
				$position = $found;
		}
		$start = $position === false || $position < 80 ? 0 : $position - 80;
		$snippet = ($start ? '…' : '') . mb_substr($body, $start, 300);
		if (mb_strlen($body) > $start + 300)
			$snippet .= '…';
		$thumbnail = null;
		foreach ((array)json_decode((string)$row['files']) as $file) {
			if (is_object($file) && !empty($file->thumb)
				&& !in_array($file->thumb, array('spoiler', 'file', 'deleted'))
				&& isset($file->file) && $file->file !== 'deleted') {
				$thumbnail = $config['root'] . sprintf($config['board_path'], $row['board'])
					. $config['dir']['thumb'] . $file->thumb;
				break;
			}
		}
		$items[] = array(
			'board' => $row['board'],
			'board_title' => $search_boards[$row['board']],
			'id' => (int)$row['id'],
			'thread' => (int)$row['thread'],
			'time' => (int)$row['time'],
			'subject' => (string)$row['subject'],
			'snippet' => $snippet,
			'thumb_path' => $thumbnail
		);
	}
	nichan_api_send(200, array(
		'results' => $items,
		'has_more' => $next_cursor !== null,
		'nextCursor' => $next_cursor,
		'before' => $items ? end($items)['time'] : null
	));

case 'boards':
	$tor = nichan_is_tor_request();
	// the boards in the menu's order, with what the app's post form needs to know about each
	$ip = $_SERVER['REMOTE_ADDR'];
	$out = array();
	foreach (nichan_boards_for(false) as $b) {
		if (!openBoard($b['uri']))
			continue;
		$uri = $b['uri'];
		$closed = nichan_posting_closed($uri);
		$geo = nichan_board_geo_mode($uri);
		$board_en = nichan_board_is_english($uri);
		// flags the author may pick, and the board's own pictures for flags (the site draws them with CSS:
		// static/flags/custom.css, geo.css); a code not listed is static/flags/<code>.png, a region of Ukraine is ua's
		$root = $config['root'] . 'static/flags/';
		$flags = $images = array();
		if (!empty($config['user_flag']) && $config['user_flags']) {
			$files = array();
			foreach (nichan_user_flags($uri, $config['user_flags']) as $f)
				$files[$f['key']] = $f['file'];
			foreach ($config['user_flags'] as $code => $name) {
				$image = !empty($files[$code]) ? $root . $files[$code] : null;
				$flags[] = array('code' => (string)$code, 'name' => $name, 'image' => $image);
				if ($image)
					$images[$code] = $image;
			}
		}
		foreach (nichan_board_geo_flags($uri) as $code => $file)
			if (preg_match('/^[a-z0-9-]+$/', $code) && preg_match('/^[a-z0-9_\/.?=-]+$/i', $file))
				$images[$code] = $root . $file;
		$out[] = array(
			'uri' => $uri,
			'title' => $b['title'],
			'subtitle' => isset($b['subtitle']) && $b['subtitle'] !== '' ? $b['subtitle'] : null,
			'lang' => $board_en ? 'en' : 'uk',
			'closed' => $closed ? nichan_posting_closed_message($closed, $board_en) : null,
			'captcha' => nichan_captcha_type_for($uri, $ip) ?: null,
			'anonymous' => (string)$config['anonymous'],
			'name_field' => empty($config['field_disable_name']),
			'max_files' => $tor ? 0 : (int)$config['max_images'],
			'max_filesize' => (int)$config['max_filesize'],
			'filesize_per' => $config['multiimage_method'] === 'each' ? 'file' : 'post',
			'file_types' => array_values(array_unique(array_merge((array)$config['allowed_ext'], (array)$config['allowed_ext_files']))),
			'max_body' => (int)$config['max_body'],
			'op_file_required' => !$tor && $config['force_image_op'],
			'op_body_required' => (bool)($config['force_body_op'] || $config['force_body']),
			'bump_limit' => (int)$config['reply_limit'],
			'threads_per_page' => (int)$config['threads_per_page'],
			'pages' => (int)$config['max_pages'],
			'flags' => $flags ? $flags : null,
			'flag_images' => (object)$images,
			'geo' => $geo ? array('mode' => $geo == 2 ? 'detailed' : 'country', 'optional' => !nichan_board_geo_required($uri)) : null,
			'fate' => nichan_board_fate_on($uri),
			'archiveAvailable' => nichan_archive_pages() > 0,
		);
	}
	// the captcha may depend on the visitor's country: not for shared caches
	nichan_api_send(200, array('api' => 1, 'site' => array('name' => $en ? $config['nichan_site']['name_en'] : $config['nichan_site']['name'],
		'closed' => nichan_site_closed() ? nichan_posting_closed_message('site', $en) : null), 'boards' => $out), 'private, no-cache', true);

case 'thread/new':
	// the thread's replies after a given one (for refreshing a thread the app already shows), in the format of res/<id>.json
	api_open_board(api_param('board', ''));
	$thread = (int)api_param('thread', '0');
	$after = max(0, (int)api_param('after', '0'));
	$query = prepare(sprintf('SELECT `id`, `sticky`, `locked`, `sage`, `cycle` FROM ``posts_%s`` WHERE `id` = :id AND `thread` IS NULL', $board['uri']));
	$query->bindValue(':id', $thread, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	if (!($op = $query->fetch(PDO::FETCH_ASSOC)))
		nichan_api_fail(404, 'thread_not_found', $en ? 'The thread is gone (deleted or archived).' : 'Нитки вже немає (видалена або в архіві).');
	// The site rewrites res/<id>.json on every change of the thread (a post, an edit, a deletion, a lock), so the
	// answer's validator comes from that file and the query: an unchanged thread is answered 304 without reading it.
	$etag = $modified = null;
	$json_file = $board['dir'] . $config['dir']['res'] . $thread . '.json';
	if (is_file($json_file) && ($json_md5 = md5_file($json_file)) !== false) {
		$modified = filemtime($json_file);
		$etag = nichan_api_etag(implode('|', array('thread/new', $board['uri'], $thread, $after, $json_md5, implode(',', $op))));
		nichan_api_not_modified($etag, $modified);
	}
	$limit = 250;
	$query = prepare(sprintf('SELECT * FROM ``posts_%s`` WHERE `thread` = :thread AND `id` > :after ORDER BY `id` LIMIT ' . ($limit + 1), $board['uri']));
	$query->bindValue(':thread', $thread, PDO::PARAM_INT);
	$query->bindValue(':after', $after, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$rows = $query->fetchAll(PDO::FETCH_ASSOC);
	$more = count($rows) > $limit;
	$api = new Api();
	$posts = array();
	foreach (array_slice($rows, 0, $limit) as $row)
		$posts[] = $api->translatePost(new Post($row));
	$query = prepare(sprintf('SELECT COUNT(*) FROM ``posts_%s`` WHERE `thread` = :thread', $board['uri']));
	$query->bindValue(':thread', $thread, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	nichan_api_send(200, array(
		'thread' => array('no' => (int)$op['id'], 'sticky' => (int)$op['sticky'], 'locked' => (int)$op['locked'],
			'bumplocked' => (int)$op['sage'], 'cyclical' => (int)$op['cycle'], 'replies' => (int)$query->fetchColumn()),
		'posts' => $posts,
		'more' => $more,
	), 'private, no-cache', $etag !== null ? $etag : true, $modified);

case 'captcha':
	// the captcha this visitor gets on the board: none, reCAPTCHA (its site key) or a new picture of ours
	api_open_board(api_param('board', ''));
	$type = nichan_captcha_type_for($board['uri'], $_SERVER['REMOTE_ADDR']);
	if ($type === 'unavailable')
		nichan_api_fail(503, 'captcha_unavailable', $en ? 'Captcha is temporarily unavailable.' : 'Капча тимчасово недоступна.');
	if ($type === 'recaptcha')
		nichan_api_send(200, array('type' => 'recaptcha', 'key' => (string)nichan_setting('recaptcha_public', '')));
	if ($type !== 'uk' && $type !== 'en')
		nichan_api_send(200, array('type' => null));
	// every picture costs drawing and a database row: at most 30 in 10 minutes from one IP (shared with captcha.php)
	if (nichan_rate_count('captcha', $_SERVER['REMOTE_ADDR'], 600) >= 30)
		nichan_api_fail(429, 'rate_limited', $type === 'en' ? 'Too many pictures. Try again in a few minutes.' : 'Забагато картинок. Спробуйте за кілька хвилин.',
			array('retry_after' => max(60, nichan_rate_wait('captcha', $_SERVER['REMOTE_ADDR'], 600))));
	nichan_rate_hit('captcha', $_SERVER['REMOTE_ADDR']);
	list($id, $png) = nichan_captcha_new($type);
	nichan_api_send(200, array('type' => $type, 'id' => $id, 'image' => 'data:image/png;base64,' . base64_encode($png)));

case 'post':
	// the post form's fields, nothing else (no staff fields: the app posts as a visitor)
	$in = $_POST;
	$_POST = array('post' => '1', 'json_response' => '1');
	foreach (array('board', 'thread', 'name', 'email', 'subject', 'body', 'password', 'spoiler', 'no_country', 'user_flag',
		'nichan_fate', 'nichan_opmark') as $k)
		if (isset($in[$k]) && is_string($in[$k]) && ($k !== 'thread' || $in[$k] !== ''))
			$_POST[$k] = $in[$k];
	foreach (array('captcha_id' => 'nichan_captcha_id', 'captcha' => 'nichan_captcha', 'recaptcha' => 'g-recaptcha-response') as $from => $to)
		if (isset($in[$from]) && is_string($in[$from]))
			$_POST[$to] = $in[$from];
	if (!isset($_POST['body']))
		$_POST['body'] = '';
	if (isset($_POST['thread']) && !ctype_digit($_POST['thread']))
		nichan_api_fail(400, 'bad_request', 'thread');
	api_open_board(isset($_POST['board']) ? $_POST['board'] : '');
	// files: file, file2, file3, file4 (one file each)
	$files = array();
	foreach (array('file', 'file2', 'file3', 'file4') as $k) {
		if (!isset($_FILES[$k]))
			continue;
		if (is_array($_FILES[$k]['name']))
			nichan_api_fail(400, 'bad_request', $en ? "One file per field: $k" : "Один файл на поле: $k");
		if ($_FILES[$k]['error'] === UPLOAD_ERR_NO_FILE)
			continue;
		$files[$k] = $_FILES[$k];
	}
	$_FILES = $files;
	require 'post.php';
	exit;

case 'delete':
	// the author's own posts (or only their files), by the password sent with them
	$ids = api_ids();
	$_POST = array('delete' => '1', 'json_response' => '1', 'board' => (string)api_param('board', ''), 'password' => (string)api_param('password', ''));
	foreach ($ids as $id)
		$_POST['delete_' . $id] = '1';
	if (api_param('file_only') === '1')
		$_POST['file'] = '1';
	api_open_board($_POST['board']);
	require 'post.php';
	exit;

case 'report':
	$ids = api_ids();
	$_POST = array('report' => '1', 'json_response' => '1', 'board' => (string)api_param('board', ''), 'reason' => (string)api_param('reason', ''));
	foreach ($ids as $id)
		$_POST['delete_' . $id] = '1';
	api_open_board($_POST['board']);
	require 'post.php';
	exit;
}
