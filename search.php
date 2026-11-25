<?php
// Search: posts containing all the words (text or subject), newest first, on the public boards or one board.
// before accepts a search cursor or a legacy timestamp. Rate limits are shared with API search.
require_once 'inc/functions.php';
require_once 'inc/nichan-search.php';

// boards a visitor may search: the listed ones, or any board they can open when chosen explicitly
$all = array_column(nichan_boards_for(false), 'title', 'uri');
$board_uri = isset($_GET['board']) && is_string($_GET['board']) ? $_GET['board'] : '';
if ($board_uri !== '' && !isset($all[$board_uri])) {
	$info = preg_match('/^\w+$/', $board_uri) ? getBoardInfo($board_uri) : false;
	if ($info && nichan_can_see_board($board_uri))
		$all[$board_uri] = $info['title'];
	else
		$board_uri = '';
}

$en = $board_uri === 'int' || ($_GET['lang'] ?? '') === 'en';
if ($en)
	$config['locale'] = 'en';
$L = $en ? array(
	'title' => 'Search', 'all' => 'All boards', 'go' => 'Search', 'short' => 'Type at least 3 letters.',
	'none' => 'Nothing found.', 'older' => 'Older results', 'limit' => 'Too many searches. Try again in a minute or two.',
	'hint' => 'Posts that contain all the words, newest first. Case does not matter.', 'ph' => 'words to search for',
	'op' => 'thread', 'shown' => 'Showing %d results', 'more' => '(there are older ones below)',
) : array(
	'title' => 'Пошук', 'all' => 'Усі дошки', 'go' => 'Шукати', 'short' => 'Введіть щонайменше 3 літери.',
	'none' => 'Нічого не знайдено.', 'older' => 'Старіші результати', 'limit' => 'Забагато пошуків. Спробуйте ще раз за хвилину-дві.',
	'hint' => 'Дописи, де є всі слова, спершу новіші. Регістр не важливий.', 'ph' => 'слова для пошуку',
	'op' => 'нитка', 'shown' => 'Знайдено %d', 'more' => '(нижче є старіші)',
);

$q = isset($_GET['q']) && is_string($_GET['q']) ? trim(preg_replace('/\s+/u', ' ', $_GET['q'])) : '';
$q = mb_substr($q, 0, 100);
$words = array_slice(array_values(array_unique(array_filter(explode(' ', mb_strtolower($q)), function ($w) { return mb_strlen($w) >= 2; }))), 0, 6);

function nichan_search_allowed() {
	global $config;
	$ip = $_SERVER['REMOTE_ADDR'];
	list($per_ip, $minutes) = $config['search']['queries_per_minutes'];
	list($overall, $minutes_all) = $config['search']['queries_per_minutes_all'];
	$query = prepare('SELECT SUM(`ip` = :ip AND `time` > :t1) AS `mine`, SUM(`time` > :t2) AS `overall` FROM ``search_queries`` WHERE `time` > :t3');
	$query->bindValue(':ip', $ip);
	$query->bindValue(':t1', time() - 60 * $minutes, PDO::PARAM_INT);
	$query->bindValue(':t2', time() - 60 * $minutes_all, PDO::PARAM_INT);
	$query->bindValue(':t3', time() - 60 * max($minutes, $minutes_all), PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$c = $query->fetch(PDO::FETCH_ASSOC);
	if ((int)$c['mine'] >= $per_ip || (int)$c['overall'] >= $overall)
		return false;
	// the text of the query is not kept
	$query = prepare("INSERT INTO ``search_queries`` (`ip`, `time`, `query`) VALUES (:ip, :t, '')");
	$query->bindValue(':ip', $ip);
	$query->bindValue(':t', time(), PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	if (mt_rand(1, 20) == 1)
		query('DELETE FROM ``search_queries`` WHERE `time` < ' . (time() - 3600));
	return true;
}

// a piece of the post around the first word found, with the words marked
function nichan_search_snippet($text, $words) {
	$plain = nichan_plain_text($text, 100000);
	$lower = mb_strtolower($plain);
	$pos = false;
	foreach ($words as $w) {
		$p = mb_strpos($lower, $w);
		if ($p !== false && ($pos === false || $p < $pos))
			$pos = $p;
	}
	$start = $pos === false || $pos < 80 ? 0 : $pos - 80;
	$piece = mb_substr($plain, $start, 300);
	$html = htmlspecialchars(($start ? '…' : '') . $piece . (mb_strlen($plain) > $start + 300 ? '…' : ''), ENT_QUOTES, 'UTF-8');
	$re = implode('|', array_map(function ($w) { return preg_quote(htmlspecialchars($w, ENT_QUOTES, 'UTF-8'), '/'); }, $words));
	return $re === '' ? $html : preg_replace('/(' . $re . ')/iu', '<mark>$1</mark>', $html);
}

$error = null;
$results = array();
$next_cursor = null;
if ($q !== '') {
	if (!$words || mb_strlen(implode('', $words)) < 3)
		$error = $L['short'];
	elseif (!nichan_search_allowed())
		$error = $L['limit'];
	else {
		list($results, $next_cursor) = nichan_search_page($board_uri !== '' ? array($board_uri) : array_keys($all), $words, $_GET['before'] ?? '');
	}
}

ob_start();
?>
<style>
	.nichan-search { max-width: 900px; margin: 0 auto; padding: 0 8px; }
	.nichan-search form { text-align: center; margin: 10px 0 16px; }
	.nichan-search input[type=search] { width: min(420px, 60vw); }
	.nichan-search .r { display: flex; gap: 10px; margin: 0 0 12px; padding: 6px 8px; border-left: 3px solid rgba(128, 128, 128, .4); }
	.nichan-search .r img { width: 64px; height: 64px; object-fit: cover; flex: none; }
	.nichan-search .r .t { min-width: 0; overflow-wrap: anywhere; }
	.nichan-search .r .h { font-size: 12px; margin-bottom: 3px; }
	.nichan-search mark { background: rgba(255, 200, 0, .45); color: inherit; padding: 0 1px; }
</style>
<div class="nichan-search">
	<form method="get" action="<?= $config['root'] ?>search.php">
		<?php if ($en): ?><input type="hidden" name="lang" value="en"><?php endif; ?>
		<input type="search" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= $L['ph'] ?>" maxlength="100" autofocus>
		<select name="board">
			<option value=""><?= $L['all'] ?></option>
			<?php foreach ($all as $uri => $title): ?>
			<option value="<?= $uri ?>"<?= (string)$uri === $board_uri ? ' selected' : '' ?>>/<?= $uri ?>/ – <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></option>
			<?php endforeach; ?>
		</select>
		<input type="submit" value="<?= $L['go'] ?>">
		<div class="unimportant" style="margin-top:4px"><?= $L['hint'] ?></div>
	</form>
<?php if ($error): ?>
	<p style="text-align:center"><strong><?= $error ?></strong></p>
<?php elseif ($q !== '' && !$results): ?>
	<p style="text-align:center"><em><?= $L['none'] ?></em></p>
<?php elseif ($results): ?>
	<p class="unimportant" style="text-align:center"><?= sprintf($L['shown'], count($results)) ?> <?= $next_cursor ? $L['more'] : '' ?></p>
	<?php foreach ($results as $r):
		$url = $config['root'] . sprintf($config['board_path'], $r['board']) . $config['dir']['res'] .
			link_for(array('id' => $r['id'], 'thread' => $r['thread']), false, array('uri' => $r['board'])) . ($r['thread'] ? '#' . $r['id'] : '');
		$thumb = null;
		foreach ((array)json_decode((string)$r['files']) as $f) {
			if (is_object($f) && !empty($f->thumb) && !in_array($f->thumb, array('spoiler', 'file', 'deleted')) && isset($f->file) && $f->file != 'deleted') {
				$thumb = $config['root'] . sprintf($config['board_path'], $r['board']) . $config['dir']['thumb'] . $f->thumb;
				break;
			}
		}
	?>
	<div class="r">
		<?php if ($thumb): ?><a href="<?= htmlspecialchars($url) ?>"><img src="<?= htmlspecialchars($thumb) ?>" alt="" loading="lazy"></a><?php endif; ?>
		<div class="t">
			<div class="h"><a href="<?= htmlspecialchars($url) ?>">/<?= $r['board'] ?>/ №<?= $r['id'] ?></a>
				<?= $r['thread'] ? '' : '<span class="unimportant">(' . $L['op'] . ')</span>' ?>
				<span class="unimportant"><?= date('d.m.Y H:i', $r['time']) ?></span>
				<?php if ($r['subject'] !== null && $r['subject'] !== ''): ?> <strong><?= nichan_search_snippet($r['subject'], $words) ?></strong><?php endif; ?></div>
			<div><?= nichan_search_snippet($r['body_nomarkup'], $words) ?></div>
		</div>
	</div>
	<?php endforeach; ?>
	<?php if ($next_cursor): ?>
	<p style="text-align:center"><a href="?<?= htmlspecialchars(http_build_query(array('q' => $q, 'board' => $board_uri, 'before' => $next_cursor, 'lang' => $en ? 'en' : null))) ?>"><?= $L['older'] ?> →</a></p>
	<?php endif; ?>
<?php endif; ?>
</div>
<?php
$body = ob_get_clean();

header('X-Robots-Tag: noindex');
echo Element('portal.html', array(
	'portal_section' => 'search',
	'portal_en' => $en,
	'config' => $config,
	'title' => $L['title'],
	'subtitle' => '',
	'body' => $body,
));
