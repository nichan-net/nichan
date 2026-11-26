<?php

/*
 * Nichan additions to the moderation panel: mass moderation and thread merging.
 * Routes are registered in mod.php.
 */

defined('TINYBOARD') or exit;

// Delete (or strip files from) every post ticked on a board/thread page, optionally banning the
// posters, and rebuild the affected pages once.
function mod_mass_delete($boardName) {
	global $config, $mod, $board;

	if (!openBoard($boardName))
		error($config['error']['noboard']);

	if (!hasPermission($config['mod']['delete'], $boardName))
		error($config['error']['noaccess']);

	$ids = array();
	foreach ($_POST as $key => $value) {
		if (preg_match('/^delete_(\d+)$/', $key, $m))
			$ids[] = (int)$m[1];
	}
	$ids = array_unique($ids);
	if (empty($ids))
		error($config['error']['nodelete']);

	$files_only = isset($_POST['file']) && $_POST['file'];
	if ($files_only && !hasPermission($config['mod']['deletefile'], $boardName))
		error($config['error']['noaccess']);

	$ban_length = isset($_POST['mass_ban_length']) ? trim($_POST['mass_ban_length']) : '';
	if (!in_array($ban_length, array('', '+1 hour', '+1 day', '+1 week', '+30 days', 'forever'), true))
		error('Неправильний строк бану.');
	if ($ban_length !== '' && !hasPermission($config['mod']['ban'], $boardName))
		error($config['error']['noaccess']);

	$query = prepare(sprintf('SELECT `id`, `thread`, `ip`, `files` FROM ``posts_%s`` WHERE `id` IN (%s) ORDER BY `id` DESC', $boardName, implode(',', $ids)));
	$query->execute() or error(db_error($query));
	$posts = $query->fetchAll(PDO::FETCH_ASSOC);
	if (empty($posts))
		error($config['error']['invalidpost']);

	rebuild_batch_begin();

	if ($ban_length !== '') {
		require_once 'inc/mod/ban.php';
		$reason = isset($_POST['mass_ban_reason']) ? trim($_POST['mass_ban_reason']) : '';
		$banned = array();
		foreach ($posts as $post) {
			if (isset($banned[$post['ip']]))
				continue;
			$banned[$post['ip']] = true;
			Bans::new_ban($post['ip'], $reason, $ban_length === 'forever' ? false : $ban_length, $boardName);
		}
	}

	$done = array();
	foreach ($posts as $post) {
		if ($files_only) {
			if (!$post['files'])
				continue;
			$files = json_decode($post['files']);
			if (empty($files[0]) || ($files[0]->file == 'deleted' && count($files) == 1))
				continue;
			deleteFile($post['id']);
		} else {
			// Replies of an OP deleted earlier in this loop are already gone.
			if (!deletePost($post['id'], false))
				continue;
		}
		$done[] = '#' . $post['id'];
	}

	buildIndex();
	rebuildThemes('post-delete', $boardName);

	if ($done) {
		modLog(($files_only ? 'Deleted files from ' : 'Deleted ') . count($done) . ' posts: ' . implode(', ', array_reverse($done)) .
			($ban_length !== '' ? ' (posters banned: ' . utf8tohtml($ban_length) . ')' : ''), $boardName);
	}

	rebuild_batch_end();

	// Back to the page the moderator came from, if it was a mod page of this board.
	$back = '?/' . sprintf($config['board_path'], $boardName) . $config['file_index'];
	if (isset($_POST['return_to']) && preg_match('!^\?/' . preg_quote(sprintf($config['board_path'], $boardName), '!') . '[a-z0-9_./+-]*$!i', $_POST['return_to']))
		$back = $_POST['return_to'];
	header('Location: ' . $back, true, $config['redirect_http']);
}

// Pull the thread number out of "123", ">>123", ">>>/b/123" or a thread URL.
// Returns array(board or null, thread number).
function nichan_parse_thread_ref($input) {
	$input = trim($input);
	if (preg_match('~/([a-z0-9_]+)/res/(\d+)~i', $input, $m))
		return array($m[1], (int)$m[2]);
	if (preg_match('~>>>/([a-z0-9_]+)/(\d+)~i', $input, $m))
		return array($m[1], (int)$m[2]);
	if (preg_match('~(\d+)~', $input, $m))
		return array(null, (int)$m[1]);
	return array(null, 0);
}

function mod_merge($originBoard, $postID) {
	global $board, $config, $mod;

	if (!openBoard($originBoard))
		error($config['error']['noboard']);

	if (!hasPermission($config['mod']['merge'], $originBoard))
		error($config['error']['noaccess']);

	$query = prepare(sprintf('SELECT * FROM ``posts_%s`` WHERE `id` = :id AND `thread` IS NULL', $originBoard));
	$query->bindValue(':id', $postID, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	if (!$op = $query->fetch(PDO::FETCH_ASSOC))
		error($config['error']['404']);

	if (isset($_POST['target_board'], $_POST['target_thread'])) {
		$targetBoard = $_POST['target_board'];
		list($ref_board, $targetThread) = nichan_parse_thread_ref($_POST['target_thread']);
		// A pasted link or >>>/board/N must agree with the selected board; merging can't be undone.
		if ($ref_board !== null && $ref_board !== $targetBoard)
			error(sprintf('Посилання вказує на тред дошки /%s/, а цільовою вибрано /%s/.', utf8tohtml($ref_board), utf8tohtml($targetBoard)));

		if (!getBoardInfo($targetBoard))
			error($config['error']['noboard']);
		if (!hasPermission($config['mod']['merge'], $targetBoard))
			error($config['error']['noaccess']);
		if ($targetBoard === $originBoard && $targetThread == $postID)
			error("Тред не можна об'єднати сам із собою.");

		$query = prepare(sprintf('SELECT `id` FROM ``posts_%s`` WHERE `id` = :id AND `thread` IS NULL', $targetBoard));
		$query->bindValue(':id', $targetThread, PDO::PARAM_INT);
		$query->execute() or error(db_error($query));
		if (!$query->fetchColumn())
			error('Цільового треду не існує.');

		rebuild_batch_begin();

		if ($targetBoard === $originBoard)
			$moved = nichan_merge_same_board($originBoard, $op, $targetThread);
		else
			$moved = nichan_merge_cross_board($originBoard, $op, $targetBoard, $targetThread);

		openBoard($originBoard);
		modLog("Merged thread #{$postID} ({$moved} posts) into " . sprintf($config['board_abbreviation'], $targetBoard) . $targetThread, $originBoard);

		rebuild_batch_end();

		openBoard($targetBoard);
		header('Location: ?/' . sprintf($config['board_path'], $targetBoard) . $config['dir']['res'] . sprintf($config['file_page'], $targetThread), true, $config['redirect_http']);
		return;
	}

	$query = prepare(sprintf('SELECT COUNT(*) FROM ``posts_%s`` WHERE `thread` = :id', $originBoard));
	$query->bindValue(':id', $postID, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$replies = (int)$query->fetchColumn();

	mod_page(_('Merge threads'), 'mod/merge.html', array(
		'post' => $postID,
		'op' => $op,
		'replies' => $replies,
		'board' => $originBoard,
		'boards' => listBoards(),
		'token' => make_secure_link_token($originBoard . '/merge/' . $postID),
	));
}

// Same board: post numbers stay the same, the old OP and its replies simply join the target thread.
function nichan_merge_same_board($uri, array $op, $target) {
	global $board, $config;

	openBoard($uri);
	$source = (int)$op['id'];

	$query = prepare(sprintf('SELECT `id` FROM ``posts_%s`` WHERE `id` = :id OR `thread` = :id', $uri));
	$query->bindValue(':id', $source, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$ids = array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));

	$query = prepare(sprintf('UPDATE ``posts_%s`` SET `thread` = :target, `sticky` = 0, `locked` = 0, `cycle` = 0, `sage` = 0, `slug` = NULL WHERE `id` = :id OR `thread` = :id', $uri));
	$query->bindValue(':target', $target, PDO::PARAM_INT);
	$query->bindValue(':id', $source, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));

	// The merged thread is as fresh as the fresher of the two.
	$query = prepare(sprintf('UPDATE ``posts_%s`` SET `bump` = GREATEST(`bump`, :bump) WHERE `id` = :target', $uri));
	$query->bindValue(':bump', (int)$op['bump'], PDO::PARAM_INT);
	$query->bindValue(':target', $target, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));

	nichan_remove_thread_files($uri, $op);

	// Links to the merged posts pointed at the old thread page; re-render every post citing them.
	$query = prepare('SELECT `board`, `post` FROM ``cites`` WHERE `target_board` = :board AND `target` IN (' . implode(',', $ids) . ')');
	$query->bindValue(':board', $uri);
	$query->execute() or error(db_error($query));
	foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $cite) {
		openBoard($cite['board']);
		rebuildPost($cite['post']);
	}

	openBoard($uri);
	buildThread($target);
	buildIndex();
	rebuildThemes('post-delete', $uri);

	return count($ids);
}

// Another board: posts are re-created in the target thread (they get new numbers there), files are
// moved, >>links between the moved posts are renumbered and links to other posts of the old board
// become >>>/board/N links.
function nichan_merge_cross_board($originBoard, array $op, $targetBoard, $target) {
	global $board, $config, $pdo;

	openBoard($originBoard);
	$source = (int)$op['id'];
	$origin_dir = $board['dir'];

	$query = prepare(sprintf('SELECT * FROM ``posts_%s`` WHERE `id` = :id OR `thread` = :id ORDER BY `id`', $originBoard));
	$query->bindValue(':id', $source, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$posts = $query->fetchAll(PDO::FETCH_ASSOC);

	openBoard($targetBoard);

	$last_time = 0;
	$new_ids = array();
	$to_move = array();
	foreach ($posts as $post) {
		$post['mod'] = true;
		$post['op'] = false;
		$post['thread'] = $target;
		$last_time = max($last_time, (int)$post['time']);

		if ($post['files']) {
			// Entries of files removed entirely are stored as null; keep only real ones.
			$post['files'] = array_values(array_filter((array)json_decode($post['files'], true), 'is_array'));
			$post['has_file'] = !empty($post['files']);
			foreach ($post['files'] as &$file)
				unset($file['file_path'], $file['thumb_path']);
			unset($file);
			$post['num_files'] = count($post['files']);
		} else {
			$post['has_file'] = false;
		}

		// Renumber >>N links: to a post that moved along -> its new number; to a post that stayed
		// on the old board -> >>>/old/N.
		$modifiers = extract_modifiers($post['body_nomarkup']);
		$body = remove_modifiers($post['body_nomarkup']);
		$body = preg_replace_callback('/(^|[^>\d])>>(\d+)(?!\d)/m', function ($m) use ($new_ids, $originBoard) {
			if (isset($new_ids[$m[2]]))
				return $m[1] . '>>' . $new_ids[$m[2]];
			return $m[1] . '>>>/' . $originBoard . '/' . $m[2];
		}, $body);
		$body = preg_replace_callback('~>>>/' . preg_quote($originBoard, '~') . '/(\d+)(?!\d)~', function ($m) use ($new_ids) {
			return isset($new_ids[$m[1]]) ? '>>' . $new_ids[$m[1]] : $m[0];
		}, $body);
		foreach ($modifiers as $key => $value)
			$body .= "<tinyboard $key>$value</tinyboard>";

		$post['body_nomarkup'] = $body;
		$post['body'] = $body;
		$tracked = markup($post['body'], true);

		$old_id = (int)$post['id'];
		$new_id = post($post);
		$new_ids[$old_id] = $new_id;

		if ($post['has_file'])
			$to_move = array_merge($to_move, $post['files']);

		if (!empty($tracked)) {
			$rows = array();
			foreach ($tracked as $cite)
				$rows[] = '(' . $pdo->quote($targetBoard) . ', ' . (int)$new_id . ', ' . $pdo->quote($cite[0]) . ', ' . (int)$cite[1] . ')';
			query('INSERT INTO ``cites`` VALUES ' . implode(', ', $rows)) or error(db_error());
		}
	}

	// MyISAM has no transactions: files are moved only after every post was copied successfully, and
	// the old thread is removed only after that.
	foreach ($to_move as $file) {
		if ($file['file'] !== 'deleted')
			@rename($origin_dir . $config['dir']['img'] . $file['file'], $board['dir'] . $config['dir']['img'] . $file['file']);
		if (isset($file['thumb']) && !in_array($file['thumb'], array('spoiler', 'deleted', 'file')))
			@rename($origin_dir . $config['dir']['thumb'] . $file['thumb'], $board['dir'] . $config['dir']['thumb'] . $file['thumb']);
	}

	$query = prepare(sprintf('UPDATE ``posts_%s`` SET `bump` = GREATEST(`bump`, :bump) WHERE `id` = :target', $targetBoard));
	$query->bindValue(':bump', $last_time, PDO::PARAM_INT);
	$query->bindValue(':target', $target, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	buildThread($target);
	buildIndex();
	rebuildThemes('post', $targetBoard);

	// The files were moved, so deleting the old thread must not unlink them.
	openBoard($originBoard);
	$query = prepare(sprintf('UPDATE ``posts_%s`` SET `files` = NULL, `num_files` = 0 WHERE `id` = :id OR `thread` = :id', $originBoard));
	$query->bindValue(':id', $source, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	deletePost($source, false, false);
	buildIndex();
	rebuildThemes('post-delete', $originBoard);

	return count($posts);
}

// Remove the static page, noko50 page, JSON and caches of a thread that no longer exists as such.
function nichan_remove_thread_files($uri, array $op) {
	global $board, $config;
	openBoard($uri);
	file_unlink($board['dir'] . $config['dir']['res'] . link_for($op));
	file_unlink($board['dir'] . $config['dir']['res'] . link_for($op, true));
	file_unlink($board['dir'] . $config['dir']['res'] . sprintf('%d.json', $op['id']));
	if ($config['cache']['enabled']) {
		cache::delete("thread_index_{$uri}_{$op['id']}");
		cache::delete("thread_{$uri}_{$op['id']}");
	}
	$query = prepare('DELETE FROM ``antispam`` WHERE `board` = :board AND `thread` = :thread');
	$query->bindValue(':board', $uri);
	$query->bindValue(':thread', (int)$op['id'], PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
}

// mod.php?/nichan-boards: who can see each board, and whether posting is open.
function mod_nichan_boards() {
	global $config, $mod;

	$can_visibility = hasPermission($config['mod']['nichan_board_visibility']);
	$can_close = hasPermission($config['mod']['nichan_close_board']);
	$can_close_site = hasPermission($config['mod']['nichan_close_site']);
	$can_captcha = hasPermission($config['mod']['nichan_captcha']);
	$can_integration = hasPermission($config['mod']['nichan_integration']);
	if (!$can_visibility && !$can_close && !$can_close_site && !$can_captcha && !$can_integration)
		error($config['error']['noaccess']);

	if (isset($_POST['save']))
		nichan_lock_board_access_files();
	$boards = nichan_sort_boards(listBoards());
	$recaptcha_ready = nichan_recaptcha_ready();
	$text_ready = nichan_text_captcha_ready();

	if (isset($_POST['save'])) {
		$changes = array();
		foreach ($boards as $b) {
			$uri = $b['uri'];
			$sets = array();
			if ($can_visibility && isset($_POST['visibility'][$uri])) {
				$v = max(0, min(3, (int)$_POST['visibility'][$uri]));
				if ($v != (int)$b['visibility']) {
					if (in_array($v, array(NICHAN_VISIBILITY_STAFF, NICHAN_VISIBILITY_ADMINS), true))
						nichan_protect_board_files($uri);
					$sets['visibility'] = $v;
					$changes[] = "/$uri/ visibility $v";
				}
			}
			if ($can_close && hasPermission($config['mod']['nichan_close_board'], $uri)) {
				$closed = isset($_POST['closed'][$uri]) ? 1 : 0;
				if ($closed != (int)$b['posting_closed']) {
					$sets['posting_closed'] = $closed;
					$changes[] = "/$uri/ posting " . ($closed ? 'closed' : 'opened');
				}
			}
			if ($sets) {
				$sql = array();
				foreach ($sets as $col => $val)
					$sql[] = "`$col` = " . (int)$val;
				$query = prepare('UPDATE ``boards`` SET ' . implode(', ', $sql) . ' WHERE `uri` = :uri');
				$query->bindValue(':uri', $uri);
				$query->execute() or error(db_error($query));
				if ($config['cache']['enabled'])
					cache::delete('board_' . $uri);
			}
			// Captcha of the board. reCAPTCHA can only be chosen once its keys are set.
			if ($can_captcha && isset($_POST['captcha'][$uri])) {
				$type = (string)$_POST['captcha'][$uri];
				$old = (string)nichan_setting('captcha_' . $uri, '');
				if (in_array($type, array('', 'recaptcha', 'uk', 'en'), true) && $type !== $old
					&& ($type !== 'recaptcha' || $recaptcha_ready || $can_integration)) {
					nichan_set_setting('captcha_' . $uri, $type);
					$changes[] = "/$uri/ captcha " . ($type === '' ? '-' : $type);
				}
			}
		}
		// Board order (shared by the menu, the front page and the statistics), dragged into place.
		if ($can_visibility && isset($_POST['order_list'])) {
			$order = array();
			foreach (preg_split('/[\s,]+/', (string)$_POST['order_list'], -1, PREG_SPLIT_NO_EMPTY) as $uri) {
				if (getBoardInfo($uri) && !in_array($uri, $order))
					$order[] = $uri;
			}
			$new = implode(' ', $order);
			if ($order && $new !== implode(' ', nichan_board_order())) {
				nichan_set_setting('board_order', $new);
				$changes[] = 'board order: ' . $new;
			}
		}
		if ($can_captcha) {
			$all = isset($_POST['captcha_all']) ? 1 : 0;
			if ($all != (int)nichan_setting('captcha_all', 0)) {
				nichan_set_setting('captcha_all', $all);
				$changes[] = 'captcha on all boards ' . ($all ? 'on' : 'off');
			}
		}
		if ($can_close_site) {
			$site = isset($_POST['site_closed']) ? 1 : 0;
			if ($site != (int)nichan_setting('site_closed', 0)) {
				nichan_set_setting('site_closed', $site);
				$changes[] = 'site posting ' . ($site ? 'closed' : 'opened');
			}
		}
		// Integration: keys and tokens are never shown again; a blank field keeps the stored value.
		if ($can_integration) {
			foreach (array('recaptcha_public', 'recaptcha_private', 'telegram_bot_token', 'telegram_chat_id', 'cf_zone_id', 'cf_api_token', 'azure_translator_key') as $k) {
				if (isset($_POST[$k . '_clear'])) {
					nichan_set_setting($k, '');
					$changes[] = $k . ' removed';
				} elseif (isset($_POST[$k]) && trim($_POST[$k]) !== '') {
					nichan_set_setting($k, trim($_POST[$k]));
					$changes[] = $k . ' changed';
				}
			}
			if (isset($_POST['telegram_ban_length']) && in_array($_POST['telegram_ban_length'], array('86400', '604800', '2592000', '0'), true)
				&& $_POST['telegram_ban_length'] !== (string)nichan_setting('telegram_ban_length', 604800)) {
				nichan_set_setting('telegram_ban_length', $_POST['telegram_ban_length']);
				$changes[] = 'telegram ban length ' . $_POST['telegram_ban_length'];
			}
			// Azure Translator (the app's translations): the region and the address aren't secret, so they are shown
			if (isset($_POST['azure_translator_region'])) {
				$region = strtolower(trim($_POST['azure_translator_region']));
				if (preg_match('/^[a-z0-9]{0,40}$/', $region) && $region !== (string)nichan_setting('azure_translator_region', '')) {
					nichan_set_setting('azure_translator_region', $region);
					$changes[] = 'azure translator region ' . ($region === '' ? '-' : $region);
				}
			}
			if (isset($_POST['azure_translator_endpoint'])) {
				$endpoint = rtrim(trim($_POST['azure_translator_endpoint']), '/');
				if (($endpoint === '' || nichan_translate_endpoint_valid($endpoint)) && $endpoint !== (string)nichan_setting('azure_translator_endpoint', '')) {
					nichan_set_setting('azure_translator_endpoint', $endpoint);
					$changes[] = 'azure translator endpoint ' . ($endpoint === '' ? '-' : $endpoint);
				}
			}
			foreach (array('translate_month_chars' => 2000000, 'translate_day_chars' => 200000) as $k => $default) {
				$v = isset($_POST[$k]) ? str_replace(' ', '', $_POST[$k]) : '';
				if (ctype_digit($v) && (int)$v !== (int)nichan_setting($k, $default)) {
					nichan_set_setting($k, (string)(int)$v);
					$changes[] = $k . ' ' . (int)$v;
				}
			}
			// a changed key, region or address is tried at once (one word into English)
			if (preg_grep('/^azure/', $changes)) {
				$check = nichan_translate_ready() ? nichan_translate_check() : null;
				nichan_set_setting('azure_translator_check', $check === null ? '' : ($check['ok'] ? 'ok' : $check['error']));
			}
		}

		if ($changes) {
			if ($config['cache']['enabled']) {
				cache::delete('all_boards');
				cache::delete('all_boards_uri');
			}
			nichan_write_board_access_files();
			nichan_write_posting_status_files();
			// Menus and board lists on the front page / catalog / RSS depend on visibility and order:
			// rebuild the themes and every board's index pages (thread pages follow on "Rebuild").
			$lists = preg_grep('/visibility|board order/', $changes);
			if ($lists) {
				nichan_stats_flush();
				rebuild_batch_begin();
				foreach (listBoards() as $b) {
					openBoard($b['uri']);
					buildIndex();
				}
				// most themes (front page, catalog, sitemap, RSS) ignore the 'boards' action
				rebuildThemes('all');
				rebuild_batch_end();
			}
			modLog('Board settings: ' . implode(', ', $changes));
		}
		header('Location: ?/nichan-boards', true, $config['redirect_http']);
		return;
	}

	foreach ($boards as &$b) {
		$b['captcha'] = (string)nichan_setting('captcha_' . $b['uri'], '');
		$b['captcha_effective'] = nichan_captcha_type($b['uri']);
		$b['english'] = nichan_board_is_english($b['uri']);
		// (what the board's own page has switched on, shown next to its name)
		$b['features'] = array_filter(array(
			nichan_board_geo_mode($b['uri']) == 2 ? 'геолокація з областями' : (nichan_board_geo_on($b['uri']) ? 'геолокація' : ''),
			nichan_board_flags_on($b['uri']) ? 'позначки' : '',
			nichan_board_translate_on($b['uri']) ? '[translate]' : '',
			nichan_board_fate_on($b['uri']) ? 'доля' : '',
		));
	}
	unset($b);

	mod_page('Налаштування дошок', 'mod/nichan_boards.html', array(
		'boards' => $boards,
		'site_closed' => nichan_site_closed(),
		'captcha_all' => (bool)nichan_setting('captcha_all', 0),
		'recaptcha_ready' => $recaptcha_ready,
		'text_ready' => $text_ready,
		'secrets' => array(
			'recaptcha_public' => (string)nichan_setting('recaptcha_public', '') !== '',
			'recaptcha_private' => (string)nichan_setting('recaptcha_private', '') !== '',
			'telegram_bot_token' => (string)nichan_setting('telegram_bot_token', '') !== '',
			'telegram_chat_id' => (string)nichan_setting('telegram_chat_id', '') !== '',
			'cf_zone_id' => (string)nichan_setting('cf_zone_id', '') !== '',
			'cf_api_token' => (string)nichan_setting('cf_api_token', '') !== '',
			'azure_translator_key' => nichan_translate_ready(),
		),
		'azure' => $can_integration ? array(
			'region' => (string)nichan_setting('azure_translator_region', ''),
			'endpoint' => (string)nichan_setting('azure_translator_endpoint', ''),
			'default_endpoint' => NICHAN_TRANSLATE_ENDPOINT,
			'budgets' => nichan_translate_budgets(),
			'month_used' => nichan_translate_month_used(),
			'day_used' => nichan_translate_used(null, 86400),
			'check' => (string)nichan_setting('azure_translator_check', ''),
			'error' => (string)nichan_setting('azure_translator_error', ''),
		) : null,
		// (checked with a real purge of robots.txt, only when the page is opened by an admin)
		'cf_check' => $can_integration ? nichan_cf_check() : null,
		'cf_last_error' => $can_integration ? (string)nichan_setting('cf_last_error', '') : '',
		// what the site's own PHP finds (the terminal's may differ): for the videos' thumbnails
		'ffmpeg' => $can_integration ? array('path' => nichan_ffmpeg(), 'shell' => function_exists('shell_exec'), 'webp' => function_exists('imagewebp')) : null,
		'can_visibility' => $can_visibility,
		'can_close' => $can_close,
		'can_close_site' => $can_close_site,
		'can_captcha' => $can_captcha,
		'can_integration' => $can_integration,
		'tg_webhook' => $can_integration ? nichan_telegram_webhook_info() : null,
		'tg_ban_length' => (string)nichan_setting('telegram_ban_length', 604800),
		'tg_connect_link' => 'nichan-telegram-connect/' . make_secure_link_token('nichan-telegram-connect'),
		'token' => make_secure_link_token('nichan-boards'),
	));
}

// mod.php?/nichan-board/<uri>: one board's own settings: the poster name, the [translate] button, the flags a
// poster picks ("позначки") and the flags found from the address (off / the country / regions of Ukraine).
function mod_nichan_board($uri) {
	global $config;
	if (!hasPermission($config['mod']['nichan_board_visibility']))
		error($config['error']['noaccess']);
	if (!($info = getBoardInfo($uri)))
		error($config['error']['noboard']);

	if ($_SERVER['REQUEST_METHOD'] == 'POST') {
		$changes = array();
		$rebuild = false; // the post form (flag menu, «Без прапора») is on every page of the board
		$anon = mb_substr(trim(isset($_POST['anon']) ? (string)$_POST['anon'] : ''), 0, 35);
		if ($anon !== (string)nichan_setting('board_anon_' . $uri, '')) {
			if ($anon === '')
				nichan_delete_setting('board_anon_' . $uri);
			else
				nichan_set_setting('board_anon_' . $uri, $anon);
			$changes[] = 'anonymous name ' . ($anon === '' ? '(default)' : $anon);
		}
		$tr = !empty($_POST['translate']);
		if ($tr !== nichan_board_translate_on($uri)) {
			nichan_set_setting('board_translate_' . $uri, $tr ? '1' : '0');
			$changes[] = 'translate ' . ($tr ? 'on' : 'off');
		}
		$fate = !empty($_POST['fate']);
		if ($fate !== nichan_board_fate_on($uri)) {
			nichan_set_setting('board_fate_' . $uri, $fate ? '1' : '0');
			$changes[] = 'fate ' . ($fate ? 'on' : 'off');
			$rebuild = true; // (the checkbox in the form)
		}
		$flags = !empty($_POST['flags']);
		if ($flags !== nichan_board_flags_on($uri)) {
			nichan_set_setting('board_flags_' . $uri, $flags ? '1' : '0');
			$changes[] = 'flags ' . ($flags ? 'on' : 'off');
			$rebuild = true;
		}
		$mode = isset($_POST['geo']) ? max(0, min(2, (int)$_POST['geo'])) : 0;
		$old = nichan_board_geo_mode($uri);
		if ($mode !== $old) {
			nichan_set_setting('board_geo_' . $uri, (string)$mode);
			$changes[] = 'geolocation ' . array('off', 'country', 'regions')[$mode];
			if (($mode > 0) !== ($old > 0))
				$rebuild = true;
		}
		$required = !empty($_POST['geo_required']);
		if ($required !== nichan_board_geo_required($uri)) {
			nichan_set_setting('board_geo_required_' . $uri, $required ? '1' : '0');
			$changes[] = 'flag ' . ($required ? 'compulsory' : 'optional');
			$rebuild = $rebuild || $mode > 0;
		}
		$text = mb_substr(trim(isset($_POST['geo_text']) ? (string)$_POST['geo_text'] : ''), 0, 40);
		if ($text !== nichan_board_geo_text($uri)) {
			if ($text === '')
				nichan_delete_setting('board_geo_text_' . $uri);
			else
				nichan_set_setting('board_geo_text_' . $uri, $text);
			$changes[] = 'flag text ' . ($text === '' ? '(country name)' : $text);
		}
		if ($changes) {
			nichan_write_posting_status_files(); // site-status.js: the boards with [translate]
			nichan_write_flags_css();
			nichan_write_geo_css();
			if ($rebuild && openBoard($uri)) {
				rebuild_batch_begin();
				buildIndex();
				$threads = query(sprintf('SELECT `id` FROM ``posts_%s`` WHERE `thread` IS NULL', $uri)) or error(db_error());
				while ($id = $threads->fetchColumn())
					buildThread($id);
				rebuild_batch_end();
			}
			modLog('Board settings /' . $uri . '/: ' . implode(', ', $changes), $uri);
		}
		header('Location: ?/nichan-board/' . $uri, true, $config['redirect_http']);
		return;
	}

	mod_page('Налаштування /' . $uri . '/', 'mod/nichan_board.html', array(
		'b' => $info,
		'anon' => (string)nichan_setting('board_anon_' . $uri, ''),
		'anon_default' => nichan_board_default_anonymous($uri, $config['anonymous']),
		'translate' => nichan_board_translate_on($uri),
		'fate' => nichan_board_fate_on($uri),
		'flags' => nichan_board_flags_on($uri),
		'geo' => nichan_board_geo_mode($uri),
		'geo_required' => nichan_board_geo_required($uri),
		'geo_text' => nichan_board_geo_text($uri),
		'geo_own' => count(nichan_board_geo_flags($uri)),
		'city_db' => is_file(nichan_geoip_city_file()),
		'can_flags' => hasPermission($config['mod']['nichan_flags'], $uri),
		'token' => make_secure_link_token('nichan-board/' . $uri),
	));
}

// mod.php?/nichan-geo-flags/<uri>: the board's own pictures for the flags found from the address: the regions of
// Ukraine and any country (e.g. polandballs). Old posts show them too (static/flags/geo.css).
function mod_nichan_geo_flags($uri) {
	global $config;
	if (!getBoardInfo($uri))
		error($config['error']['noboard']);
	if (!hasPermission($config['mod']['nichan_flags'], $uri))
		error($config['error']['noaccess']);
	$list = nichan_board_geo_flags($uri);
	$regions = nichan_ua_regions();

	if (isset($_POST['save'])) {
		$dir = 'static/flags/geo/' . $uri;
		$save = function ($field, $code) use ($dir, $uri) {
			if (empty($_FILES[$field]['tmp_name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK)
				return null;
			if ($_FILES[$field]['size'] > 200 * 1024)
				error('Картинка прапорця завелика (до 200 КБ).');
			$info = @getimagesize($_FILES[$field]['tmp_name']);
			$ext = $info ? array(IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_JPEG => 'jpg', 18 => 'webp')[$info[2]] ?? null : null;
			if (!$ext)
				error('Прапорець має бути картинкою PNG, GIF, JPEG або WEBP.');
			if (!is_dir($dir) && !@mkdir($dir, 0755, true))
				error('Не вдалося створити папку ' . $dir);
			foreach (glob($dir . '/' . $code . '.*') ?: array() as $old)
				@unlink($old);
			if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dir . '/' . $code . '.' . $ext))
				error('Не вдалося зберегти картинку.');
			return 'geo/' . $uri . '/' . $code . '.' . $ext . '?' . time();
		};
		$changes = array();
		foreach (array_keys($list) as $code) {
			if (isset($_POST['delete'][$code])) {
				foreach (glob($dir . '/' . $code . '.*') ?: array() as $old)
					@unlink($old);
				unset($list[$code]);
				$changes[] = "-$code";
			}
		}
		// a picture per region (field "r_<region>") and per country already in the list ("c_<code>")
		foreach ($regions as $code => $names)
			if ($file = $save('r_' . $code, 'ua-' . $code)) {
				$list['ua-' . $code] = $file;
				$changes[] = "ua-$code";
			}
		foreach (array_keys($list) as $code)
			if (!preg_match('/^ua-/', $code) && ($file = $save('c_' . $code, $code))) {
				$list[$code] = $file;
				$changes[] = $code;
			}
		$new = strtolower(trim(isset($_POST['new_code']) ? (string)$_POST['new_code'] : ''));
		if ($new !== '') {
			if (!preg_match('/^[a-z]{2}$/', $new))
				error('Код країни — дві латинські літери, як-от pl, de, us.');
			if (!($file = $save('new_image', $new)))
				error('Додайте картинку для прапорця ' . strtoupper($new) . '.');
			$list[$new] = $file;
			$changes[] = $new;
		}
		if ($changes) {
			ksort($list);
			nichan_set_setting('geo_flags_' . $uri, json_encode($list, JSON_UNESCAPED_SLASHES));
			nichan_write_geo_css();
			modLog('Flags of geolocation /' . $uri . '/: ' . implode(', ', $changes), $uri);
		}
		header('Location: ?/nichan-geo-flags/' . $uri, true, $config['redirect_http']);
		return;
	}

	$countries = array();
	foreach ($list as $code => $file)
		if (!preg_match('/^ua-/', $code))
			$countries[$code] = array('file' => $file, 'name' => class_exists('Locale') ? Locale::getDisplayRegion('-' . strtoupper($code), 'uk') : strtoupper($code));
	mod_page('Прапорці геолокації /' . $uri . '/', 'mod/nichan_geo_flags.html', array(
		'uri' => $uri,
		'regions' => $regions,
		'list' => $list,
		'countries' => $countries,
		'geo' => nichan_board_geo_mode($uri),
		'token' => make_secure_link_token('nichan-geo-flags/' . $uri),
	));
}

// mod.php?/nichan-banners: galleries of banners (300×100 above the board's name) and the boards each is shown on.
// A board shows one banner of its galleries at random; a board in no gallery shows none.
function mod_nichan_banners() {
	global $config, $nichan_settings;
	if (!hasPermission($config['mod']['nichan_banners']))
		error($config['error']['noaccess']);
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		// All galleries share one setting. Serialize the read, update and status-file write.
		$lock = fopen('tmp/locks/banner-galleries.lock', 'c');
		if (!$lock || !flock($lock, LOCK_EX)) error('Не вдалося зберегти галерею.');
		$nichan_settings = null;
	}
	$galleries = nichan_banner_galleries();
	$uris = array_map(function ($b) { return $b['uri']; }, listBoards());

	if ($_SERVER['REQUEST_METHOD'] == 'POST') {
		$created = $remove = array();
		$remove_dir = null;
		register_shutdown_function(function () use (&$created) {
			foreach ($created as $path) @unlink($path);
		});
		$read_link = static function ($value) {
			$url = nichan_banner_link($value);
			if ($url === null)
				error('Вкажіть посилання у форматі https://example.org або /b/. Залиште поле порожнім для банера без посилання.');
			return $url;
		};
		foreach (array('links', 'upload_links') as $field)
			if (isset($_POST[$field]) && !is_array($_POST[$field])) error('Некоректні посилання банерів.');
		$upload = function ($id, &$links) use ($read_link, &$created) {
			$saved = array();
			if (empty($_FILES['files']['name']) || !is_array($_FILES['files']['name']))
				return $saved;
			$dir = 'static/banners/' . (int)$id;
			foreach ($_FILES['files']['name'] as $i => $name) {
				if ($_FILES['files']['error'][$i] === UPLOAD_ERR_NO_FILE)
					continue;
				$link = $read_link($_POST['upload_links'][$i] ?? '');
				if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK)
					error('Не вдалося завантажити ' . utf8tohtml($name) . ' (помилка ' . (int)$_FILES['files']['error'][$i] . ').');
				if ($_FILES['files']['size'][$i] > 1024 * 1024)
					error('Банер ' . utf8tohtml($name) . ' завеликий (до 1 МБ).');
				$info = @getimagesize($_FILES['files']['tmp_name'][$i]);
				$ext = $info ? array(IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_JPEG => 'jpg', 18 => 'webp')[$info[2]] ?? null : null;
				if (!$ext)
					error(utf8tohtml($name) . ' — не картинка PNG, GIF, JPEG чи WEBP.');
				if (!is_dir($dir) && !@mkdir($dir, 0755, true))
					error('Не вдалося створити папку ' . $dir);
				$file = bin2hex(random_bytes(6)) . '.' . $ext;
				if (!move_uploaded_file($_FILES['files']['tmp_name'][$i], $dir . '/' . $file))
					error('Не вдалося зберегти ' . utf8tohtml($name) . '.');
				$created[] = $dir . '/' . $file;
				if ($link !== '') $links[$file] = $link;
				$saved[] = $file;
			}
			return $saved;
		};
		$boards = array_values(array_intersect($uris, isset($_POST['boards']) ? (array)$_POST['boards'] : array()));
		$name = mb_substr(trim(isset($_POST['name']) ? (string)$_POST['name'] : ''), 0, 60);
		$gid = isset($_POST['gallery']) ? (int)$_POST['gallery'] : 0;

		if (!$gid) {
			// a new gallery
			$id = 1;
			foreach ($galleries as $g)
				$id = max($id, (int)$g['id'] + 1);
			$links = array();
			$files = $upload($id, $links);
			if (!$files)
				error('Додайте хоча б один банер.');
			$galleries[] = array('id' => $id, 'name' => $name !== '' ? $name : 'Галерея ' . $id, 'boards' => $boards, 'files' => $files, 'links' => $links);
			modLog('Banners: new gallery #' . $id . ' (' . count($files) . ' banners; ' . ($boards ? '/' . implode('/, /', $boards) . '/' : 'no boards') . ')');
		} else {
			foreach ($galleries as $k => &$g) {
				if ((int)$g['id'] !== $gid)
					continue;
				$dir = 'static/banners/' . $gid;
				if (!empty($_POST['delete_gallery'])) {
					$remove = glob($dir . '/*') ?: array();
					$remove_dir = $dir;
					unset($galleries[$k]);
					modLog('Banners: deleted gallery #' . $gid);
					break;
				}
				$links = $g['links'] ?? array();
				$delete = (array)($_POST['delete'] ?? array());
				foreach ($g['files'] as $pos => $file) {
					if (in_array($file, $delete, true)) {
						$remove[] = $dir . '/' . basename($file);
						unset($g['files'][$pos], $links[$file]);
					} elseif (isset($_POST['links'][$file])) {
						$link = $read_link($_POST['links'][$file]);
						if ($link === '') unset($links[$file]);
						else $links[$file] = $link;
					}
				}
				$g['files'] = array_merge($g['files'], $upload($gid, $links));
				$g['links'] = $links;
				$g['boards'] = $boards;
				if ($name !== '')
					$g['name'] = $name;
				modLog('Banners: gallery #' . $gid . ' (' . count($g['files']) . ' banners; ' . ($boards ? '/' . implode('/, /', $boards) . '/' : 'no boards') . ')');
				break;
			}
			unset($g);
		}
		nichan_banner_galleries_save($galleries, $created);
		foreach ($remove as $path) @unlink($path);
		if ($remove_dir !== null) @rmdir($remove_dir);
		header('Location: ?/nichan-banners', true, $config['redirect_http']);
		return;
	}

	foreach ($galleries as &$g) {
		$g['pictures'] = array();
		foreach ($g['files'] as $file) {
			$size = @getimagesize('static/banners/' . (int)$g['id'] . '/' . $file);
			$g['pictures'][] = array('file' => $file, 'link' => $g['links'][$file] ?? '', 'w' => $size ? $size[0] : 0, 'h' => $size ? $size[1] : 0);
		}
	}
	unset($g);
	mod_page('Банери', 'mod/nichan_banners.html', array(
		'galleries' => $galleries,
		'boards' => nichan_sort_boards(listBoards()),
		'token' => make_secure_link_token('nichan-banners'),
	));
}

// mod.php?/nichan-messages: the messages under the post form, each with its boards. HTML is allowed (links, pictures);
// a picture can be uploaded and is added to the end of the text. The boards whose message changed are built again.
function mod_nichan_messages() {
	global $config;
	if (!hasPermission($config['mod']['nichan_messages']))
		error($config['error']['noaccess']);
	$uris = array_map(function ($b) { return $b['uri']; }, listBoards());
	$list = nichan_board_messages();
	$from_config = $list === null;
	if ($from_config) // never saved: the message of instance-config.php, on every board
		$list = trim((string)$config['global_message']) !== '' ? array(array('id' => 1, 'text' => (string)$config['global_message'], 'boards' => $uris)) : array();

	if ($_SERVER['REQUEST_METHOD'] == 'POST') {
		$before = array();
		foreach ($uris as $uri)
			$before[$uri] = nichan_board_message($uri);
		$id = isset($_POST['message']) ? (int)$_POST['message'] : 0;
		$text = trim(str_replace("\r", '', isset($_POST['text']) ? (string)$_POST['text'] : ''));
		$boards = array_values(array_intersect($uris, isset($_POST['boards']) ? (array)$_POST['boards'] : array()));
		// a picture: saved in static/messages/ and added to the text
		if (!empty($_FILES['image']['tmp_name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
			if ($_FILES['image']['size'] > 2 * 1024 * 1024)
				error('Картинка завелика (до 2 МБ).');
			$info = @getimagesize($_FILES['image']['tmp_name']);
			$ext = $info ? array(IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_JPEG => 'jpg', 18 => 'webp')[$info[2]] ?? null : null;
			if (!$ext)
				error('Картинка має бути PNG, GIF, JPEG або WEBP.');
			if (!is_dir('static/messages') && !@mkdir('static/messages', 0755, true))
				error('Не вдалося створити папку static/messages');
			$file = bin2hex(random_bytes(6)) . '.' . $ext;
			if (!move_uploaded_file($_FILES['image']['tmp_name'], 'static/messages/' . $file))
				error('Не вдалося зберегти картинку.');
			$text .= ($text !== '' ? ' ' : '') . '<img src="' . $config['root'] . 'static/messages/' . $file . '" alt="" style="max-width:100%;vertical-align:middle">';
		}
		if (!$id) {
			if ($text === '')
				error('Напишіть текст повідомлення.');
			$next = 1;
			foreach ($list as $m)
				$next = max($next, (int)$m['id'] + 1);
			$list[] = array('id' => $next, 'text' => $text, 'boards' => $boards);
		} else {
			foreach ($list as $k => $m) {
				if ((int)$m['id'] !== $id)
					continue;
				if (!empty($_POST['delete']) || $text === '')
					unset($list[$k]);
				else
					$list[$k] = array('id' => $id, 'text' => $text, 'boards' => $boards);
			}
		}
		nichan_set_setting('board_messages', json_encode(array_values($list), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		// the message is part of every page of the board: build again the boards where it changed
		$changed = array();
		foreach ($uris as $uri)
			if (nichan_board_message($uri) !== $before[$uri])
				$changed[] = $uri;
		if ($changed) {
			rebuild_batch_begin();
			foreach ($changed as $uri) {
				if (!openBoard($uri))
					continue;
				buildIndex();
				$threads = query(sprintf('SELECT `id` FROM ``posts_%s`` WHERE `thread` IS NULL', $uri)) or error(db_error());
				while ($t = $threads->fetchColumn())
					buildThread($t);
			}
			rebuild_batch_end();
		}
		modLog('Messages under the form: ' . ($changed ? '/' . implode('/, /', $changed) . '/ changed' : 'no board changed'));
		header('Location: ?/nichan-messages', true, $config['redirect_http']);
		return;
	}

	mod_page('Повідомлення під формою', 'mod/nichan_messages.html', array(
		'messages' => $list,
		'from_config' => $from_config,
		'boards' => nichan_sort_boards(listBoards()),
		'token' => make_secure_link_token('nichan-messages'),
	));
}

// mod.php?/nichan-telegram-connect: connect the report buttons (Telegram webhook) and go back.
function mod_nichan_telegram_connect() {
	global $config;
	if (!hasPermission($config['mod']['nichan_integration']))
		error($config['error']['noaccess']);
	$res = nichan_telegram_connect_webhook();
	modLog('Telegram buttons: ' . (!empty($res['ok']) ? 'connected' : 'failed (' . (isset($res['description']) ? $res['description'] : '?') . ')'));
	if (empty($res['ok']))
		error('Telegram не підключив кнопки: ' . utf8tohtml(isset($res['description']) ? $res['description'] : 'немає відповіді'));
	header('Location: ?/nichan-boards', true, $config['redirect_http']);
}

// mod.php?/<board>/unspoiler/<post>/<file>: show a file's real thumbnail again.
function mod_unspoiler_image($boardName, $postID, $fileIndex) {
	global $config, $board;

	if (!openBoard($boardName))
		error($config['error']['noboard']);
	if (!hasPermission($config['mod']['spoilerimage'], $boardName))
		error($config['error']['noaccess']);

	$query = prepare(sprintf('SELECT `files`, `thread` FROM ``posts_%s`` WHERE `id` = :id', $boardName));
	$query->bindValue(':id', $postID, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	if (!$post = $query->fetch(PDO::FETCH_ASSOC))
		error($config['error']['404']);
	$files = json_decode($post['files']);
	if (!isset($files[$fileIndex]) || !is_object($files[$fileIndex]) || $files[$fileIndex]->thumb != 'spoiler')
		error($config['error']['invalidpost']);
	$f = $files[$fileIndex];
	$thumb_dir = $board['dir'] . $config['dir']['thumb'];

	// the thumbnail was deleted when the file was spoilered: make a new one from the file
	$src = $board['dir'] . $config['dir']['img'] . $f->file;
	$ext = strtolower(pathinfo($f->file, PATHINFO_EXTENSION));
	$size = @getimagesize($src);
	if (!is_file($src) || !$size)
		error('Для цього файлу не вдається створити мініатюру (це не зображення або файл відсутній).');
	require_once 'inc/image.php';
	$thumb_ext = $config['thumb_ext'] ? $config['thumb_ext'] : $ext;
	$name = preg_replace('/\.[^.]+$/', '', $f->file) . '.' . $thumb_ext;
	$op = !$post['thread'];
	$image = new Image($src, $ext, $size);
	$thumb = $image->resize($thumb_ext,
		$op ? $config['thumb_op_width'] : $config['thumb_width'],
		$op ? $config['thumb_op_height'] : $config['thumb_height']);
	$thumb->to($thumb_dir . $name);
	$f->thumb = $name;
	$f->thumbwidth = $thumb->width;
	$f->thumbheight = $thumb->height;
	$thumb->_destroy();
	$image->destroy();

	$query = prepare(sprintf('UPDATE ``posts_%s`` SET `files` = :files WHERE `id` = :id', $boardName));
	$query->bindValue(':files', json_encode($files));
	$query->bindValue(':id', $postID, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));

	modLog("Unspoilered file from post #{$postID}");

	rebuild_batch_begin();
	buildThread($post['thread'] ? $post['thread'] : $postID);
	buildIndex();
	rebuildThemes('post', $boardName);
	rebuild_batch_end();

	header('Location: ?/' . sprintf($config['board_path'], $boardName) . $config['dir']['res'] .
		link_for(array('id' => $post['thread'] ? $post['thread'] : $postID)) . '#' . $postID, true, $config['redirect_http']);
}

/*
 * Ban list rendered on the server (overrides mod_bans(): mod.php calls mod_page_bans first).
 * The old list was built in the browser from bans.json and stayed empty whenever that request
 * failed (double-compressed responses, a missing script...).
 */
function mod_page_bans() {
	global $config, $mod;

	if (!hasPermission($config['mod']['view_banlist']))
		error($config['error']['noaccess']);

	if (isset($_POST['unban'])) {
		if (!hasPermission($config['mod']['unban']))
			error($config['error']['noaccess']);
		$unban = array();
		foreach ($_POST as $name => $unused) {
			if (preg_match('/^ban_(\d+)$/', $name, $m))
				$unban[] = (int)$m[1];
		}
		if (!empty($config['mod']['unban_limit']) && count($unban) > $config['mod']['unban_limit'])
			error(sprintf($config['error']['toomanyunban'], $config['mod']['unban_limit'], count($unban)));
		foreach ($unban as $id)
			Bans::delete($id, true, $mod['boards'], true);
		rebuildThemes('bans');
		$back = '?/bans';
		// (back to the page and search it was on; a bare "&" would match no page of mod.php)
		if (isset($_POST['return_query']) && $_POST['return_query'] !== '' && preg_match('/^[a-z0-9_=&%.:\-+]*$/i', $_POST['return_query']))
			$back .= '&' . $_POST['return_query'];
		header('Location: ' . $back, true, $config['redirect_http']);
		return;
	}

	$active = !isset($_GET['all']);
	$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
	$page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
	$per_page = 100;

	$where = array('1');
	$binds = array();
	if ($active) {
		$where[] = '(`expires` IS NULL OR `expires` = 0 OR `expires` > :now)';
		$binds[':now'] = time();
	}
	if ($q !== '') {
		$ip = @inet_pton($q);
		if ($ip !== false) {
			$where[] = '((`ipend` IS NULL AND `ipstart` = :ip) OR (`ipend` IS NOT NULL AND `ipstart` <= :ip AND `ipend` >= :ip))';
			$binds[':ip'] = $ip;
		} else {
			$where[] = '(`reason` LIKE :q OR ``bans``.`board` = :qb OR `username` = :qb)';
			$binds[':q'] = '%' . $q . '%';
			$binds[':qb'] = $q;
		}
	}
	$sql_where = implode(' AND ', $where);

	$query = prepare('SELECT COUNT(*) FROM ``bans`` LEFT JOIN ``mods`` ON ``mods``.`id` = `creator` WHERE ' . $sql_where);
	foreach ($binds as $k => $v) $query->bindValue($k, $v);
	$query->execute() or error(db_error($query));
	$total = (int)$query->fetchColumn();
	$pages = max(1, (int)ceil($total / $per_page));
	$page = min($page, $pages);

	$query = prepare('SELECT ``bans``.*, `username` FROM ``bans`` LEFT JOIN ``mods`` ON ``mods``.`id` = `creator` WHERE ' . $sql_where .
		' ORDER BY `created` DESC LIMIT ' . (($page - 1) * $per_page) . ', ' . $per_page);
	foreach ($binds as $k => $v) $query->bindValue($k, $v);
	$query->execute() or error(db_error($query));

	$show_staff = hasPermission($config['mod']['view_banstaff']);
	$bans = $query->fetchAll(PDO::FETCH_ASSOC);
	// the appeals of the shown bans: pending (a link to it on the page of appeals) or rejected
	$appeals = array();
	if ($config['ban_appeals'] && $bans) {
		$ids = implode(',', array_map(function ($b) { return (int)$b['id']; }, $bans));
		foreach (query("SELECT `ban_id`, MAX(`denied`) AS `denied`, MAX(`id`) AS `id` FROM ``ban_appeals`` WHERE `ban_id` IN ($ids) GROUP BY `ban_id`")->fetchAll(PDO::FETCH_ASSOC) as $a)
			$appeals[(int)$a['ban_id']] = array('denied' => (bool)$a['denied'], 'id' => (int)$a['id']);
	}
	$rows = array();
	foreach ($bans as $ban) {
		$mask = Bans::range_to_string(array($ban['ipstart'], $ban['ipend']));
		$post = $ban['post'] ? json_decode($ban['post'], true) : null;
		$own = $mod['boards'][0] == '*' || in_array($ban['board'], $mod['boards']);
		$rows[] = array(
			'id' => $ban['id'],
			'mask' => $mask,
			'single' => filter_var($mask, FILTER_VALIDATE_IP) !== false,
			'reason' => $ban['reason'],
			'board' => $ban['board'],
			'created' => $ban['created'],
			'expires' => $ban['expires'],
			'expired' => $ban['expires'] && $ban['expires'] < time(),
			'staff' => $show_staff ? ($ban['username'] ? $ban['username'] : ($ban['creator'] == -1 ? 'system' : '?')) : '?',
			'seen' => $ban['seen'],
			'message' => $post && isset($post['body']) ? $post['body'] : null,
			'can_unban' => $own,
			'appeal' => isset($appeals[(int)$ban['id']]) ? $appeals[(int)$ban['id']] : null,
		);
	}

	$base = ($active ? '' : '&all=1') . ($q !== '' ? '&q=' . urlencode($q) : '');
	mod_page(sprintf('%s (%d)', _('Ban list'), $total), 'mod/nichan_bans.html', array(
		'bans' => $rows,
		'total' => $total,
		'page' => $page,
		'pages' => $pages,
		'active' => $active,
		'q' => $q,
		'base' => $base,
		'return_query' => ltrim($base . ($page > 1 ? '&page=' . $page : ''), '&'),
		'can_unban' => hasPermission($config['mod']['unban']),
		'token' => make_secure_link_token('bans'),
	));
}

// mod.php?/nichan-trips: manage custom tripcodes.
function mod_nichan_trips() {
	global $config, $pdo;
	if (!hasPermission($config['mod']['nichan_trips']))
		error($config['error']['noaccess']);

	if (isset($_POST['delete_trip'])) {
		$query = prepare('DELETE FROM ``nichan_trips`` WHERE `id` = :id');
		$query->bindValue(':id', (int)$_POST['delete_trip'], PDO::PARAM_INT);
		$query->execute() or error(db_error($query));
		modLog('Deleted custom tripcode #' . (int)$_POST['delete_trip']);
		header('Location: ?/nichan-trips', true, $config['redirect_http']);
		return;
	}

	if (isset($_POST['text'])) {
		$id = (int)($_POST['id'] ?? 0);
		$current = $id ? (nichan_trips()[$id] ?? null) : null;
		if ($id && !$current)
			error('Тріпкод не знайдено.');
		$bind_ip = ($_POST['bind_ip'] ?? '') === '1';
		$require_code = ($_POST['require_code'] ?? '') === '1';
		if (!$bind_ip && !$require_code)
			error('Виберіть хоча б одну умову для тріпкоду.');
		$ips = null;
		if ($bind_ip) {
			$list = $_POST['ips'] ?? '';
			$addresses = is_string($list) ? nichan_trip_ips($list) : null;
			if (!$addresses)
				error('Введіть одну або кілька дійсних IP-адрес.');
			$ips = implode("\n", $addresses);
			if (strlen($ips) > 65535)
				error('Список IP-адрес задовгий.');
		}
		$text = is_string($_POST['text']) ? trim($_POST['text']) : '';
		if ($text === '' || mb_strlen($text) > 40)
			error('Текст тріпкоду має бути від 1 до 40 символів.');
		$secret = $require_code ? ($_POST['secret'] ?? '') : '';
		if (!is_string($secret) || mb_strlen($secret) > 100 || preg_match('/[#\x00-\x1f\x7f]/', $secret) || trim($secret) !== $secret)
			error('Код має містити до 100 символів, без #, керівних символів і пробілів на краях.');
		if ($require_code && $secret === '' && (!$current || $current['always'] || $current['secret'] === ''))
			error('Введіть секретний код.');
		$style = json_decode(isset($_POST['style']) ? $_POST['style'] : '', true);
		$clean = array('font' => '', 'blink' => false, 'letters' => array());
		if (is_array($style)) {
			$clean['font'] = isset($style['font']) && in_array($style['font'], array('', 'serif', 'sans', 'mono', 'cursive', 'impact'), true) ? $style['font'] : '';
			$clean['blink'] = !empty($style['blink']);
			if (isset($style['letters']) && is_array($style['letters'])) {
				foreach (array_slice($style['letters'], 0, 40) as $l) {
					$clean['letters'][] = array(
						'c' => isset($l['c']) && preg_match('/^#[0-9a-f]{6}$/i', $l['c']) ? $l['c'] : '',
						'b' => !empty($l['b']),
						'i' => !empty($l['i']),
					);
				}
			}
		}
		if ($id) {
			// Keep an existing required code when editing the other fields.
			$keep_code = $require_code && $secret === '';
			$query = prepare('UPDATE ``nichan_trips`` SET `ips` = :ips, `text` = :text, `style` = :style, `note` = :note, `always` = :always' .
				($keep_code ? '' : ', `secret` = :secret') . ' WHERE `id` = :id');
			$query->bindValue(':id', $id, PDO::PARAM_INT);
		} else {
			$keep_code = false;
			$query = prepare('INSERT INTO ``nichan_trips`` (`ips`, `text`, `style`, `note`, `secret`, `always`, `created`) VALUES (:ips, :text, :style, :note, :secret, :always, :created)');
			$query->bindValue(':created', time(), PDO::PARAM_INT);
		}
		$query->bindValue(':ips', $ips, $ips === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
		$query->bindValue(':text', $text);
		$query->bindValue(':style', json_encode($clean));
		$query->bindValue(':note', mb_substr(trim(isset($_POST['note']) ? $_POST['note'] : ''), 0, 200));
		$query->bindValue(':always', $require_code ? 0 : 1, PDO::PARAM_INT);
		if (!$keep_code)
			$query->bindValue(':secret', $secret === '' ? '' : nichan_trip_hash_code($secret));
		$query->execute() or error(db_error($query));
		modLog(($id ? 'Edited' : 'Added') . ' custom tripcode #' . ($id ?: $pdo->lastInsertId()));
		header('Location: ?/nichan-trips', true, $config['redirect_http']);
		return;
	}

	$trips = array_values(nichan_trips());
	$current = null;
	$edit = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
	foreach ($trips as &$t) {
		$t['has_code'] = $t['secret'] !== '';
		unset($t['secret']);
		$t['html'] = nichan_trip_html('!!ct' . $t['id']);
		if ($t['id'] == $edit)
			$current = $t;
	}
	unset($t);
	mod_page('Тріпкоди', 'mod/nichan_trips.html', array(
		'trips' => $trips,
		'current' => $current,
		'prefill_ip' => isset($_GET['ip']) && filter_var($_GET['ip'], FILTER_VALIDATE_IP) ? $_GET['ip'] : '',
		'token' => make_secure_link_token('nichan-trips'),
	));
}

// mod.php?/nichan-flags/<board>: add, rename, re-picture and delete the board's selectable flags.
function mod_nichan_flags($boardName) {
	global $config;
	if (!openBoard($boardName))
		error($config['error']['noboard']);
	if (!hasPermission($config['mod']['nichan_flags'], $boardName))
		error($config['error']['noaccess']);

	// (from the board's own config.php list: $config['user_flags'] already holds the finished list)
	$flags = nichan_user_flags($boardName, isset($config['nichan_config_user_flags']) ? $config['nichan_config_user_flags'] : array());

	if (isset($_POST['save'])) {
		$dir = 'static/flags/custom/' . $boardName;
		$save_upload = function ($field, $key) use ($dir) {
			if (empty($_FILES[$field]['tmp_name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK)
				return null;
			if ($_FILES[$field]['size'] > 200 * 1024)
				error('Картинка позначки завелика (до 200 КБ).');
			$info = @getimagesize($_FILES[$field]['tmp_name']);
			$ext = $info ? array(IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_JPEG => 'jpg', 18 => 'webp')[$info[2]] ?? null : null;
			if (!$ext)
				error('Позначка має бути картинкою PNG, GIF, JPEG або WEBP.');
			if (!is_dir($dir) && !@mkdir($dir, 0755, true))
				error('Не вдалося створити папку ' . $dir);
			foreach (glob($dir . '/' . $key . '.*') ?: array() as $old)
				@unlink($old);
			if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dir . '/' . $key . '.' . $ext))
				error('Не вдалося зберегти картинку.');
			return 'custom/' . basename($dir) . '/' . $key . '.' . $ext . '?' . time();
		};

		$new = array();
		foreach ($flags as $i => $f) {
			if (isset($_POST['delete'][$i]))
				continue;
			$label = isset($_POST['label'][$i]) ? trim($_POST['label'][$i]) : $f['label'];
			if ($label === '')
				$label = $f['label'];
			$file = $save_upload('image_' . $i, $f['key']);
			$new[] = array('key' => $f['key'], 'label' => $label, 'file' => $file ? $file : $f['file'],
				'pos' => isset($_POST['pos'][$i]) ? (float)$_POST['pos'][$i] : $i);
		}
		$key = isset($_POST['new_key']) ? strtolower(trim($_POST['new_key'])) : '';
		if ($key !== '') {
			if (!preg_match('/^[a-z0-9_-]{2,20}$/', $key))
				error('Код позначки: 2–20 латинських літер, цифр, _ або -.');
			foreach ($new as $f)
				if ($f['key'] === $key)
					error('Позначка з таким кодом уже є.');
			if (strlen($key) == 2)
				error('Двобуквені коди зайняті прапорами країн; візьміть довший.');
			$file = $save_upload('new_image', $key);
			if (!$file)
				error('Додайте картинку для нової позначки.');
			$new[] = array('key' => $key, 'label' => trim($_POST['new_label']) !== '' ? trim($_POST['new_label']) : $key, 'file' => $file, 'pos' => 1000);
		}
		usort($new, function ($a, $b) { return $a['pos'] == $b['pos'] ? 0 : ($a['pos'] < $b['pos'] ? -1 : 1); });
		foreach ($new as &$f)
			unset($f['pos']);
		unset($f);

		nichan_set_setting('user_flags_' . $boardName, json_encode($new, JSON_UNESCAPED_UNICODE));
		nichan_write_flags_css();
		modLog('Edited the flags of ' . sprintf($config['board_abbreviation'], $boardName), $boardName);
		// the choice list is part of the post form on every page of the board, threads included
		// (the board was opened with the old list)
		$config['user_flags'] = array();
		foreach ($new as $f)
			$config['user_flags'][$f['key']] = $f['label'];
		rebuild_batch_begin();
		buildIndex();
		$threads = query(sprintf('SELECT `id` FROM ``posts_%s`` WHERE `thread` IS NULL', $boardName)) or error(db_error());
		while ($id = $threads->fetchColumn())
			buildThread($id);
		rebuild_batch_end();
		header('Location: ?/nichan-flags/' . $boardName, true, $config['redirect_http']);
		return;
	}

	mod_page('Позначки ' . sprintf($config['board_abbreviation'], $boardName), 'mod/nichan_flags.html', array(
		'board' => $boardName,
		'flags' => $flags,
		'token' => make_secure_link_token('nichan-flags/' . $boardName),
	));
}

// mod.php?/<board>/banfile/<post>/<file>: ban a file (its exact copies and re-saved copies of the picture)
// and remove it from the site. GET shows what will happen, POST does it.
function mod_nichan_ban_file($boardName, $postID, $fileIndex) {
	global $config, $mod, $board;

	if (!openBoard($boardName))
		error($config['error']['noboard']);
	if (!hasPermission($config['mod']['nichan_ban_file'], $boardName))
		error($config['error']['noaccess']);

	$query = prepare(sprintf('SELECT `id`, `thread`, `files` FROM ``posts_%s`` WHERE `id` = :id', $boardName));
	$query->bindValue(':id', $postID, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	if (!$post = $query->fetch(PDO::FETCH_ASSOC))
		error($config['error']['404']);
	$files = json_decode($post['files'], true);
	if (!isset($files[$fileIndex]['hash']) || $files[$fileIndex]['file'] === 'deleted')
		error('У цього поста немає такого файлу (або його вже видалено).');
	$file = $files[$fileIndex];
	$md5 = $file['hash'];
	$copies = nichan_find_file_copies($md5);
	$origin = sprintf('/%s/%d', $boardName, $postID);

	if ($_SERVER['REQUEST_METHOD'] == 'POST') {
		$dhash = nichan_dhash($board['dir'] . $config['dir']['img'] . $file['file']);
		nichan_ban_file_add($md5, $dhash, trim(isset($_POST['reason']) ? (string)$_POST['reason'] : ''), $origin, $mod['username']);

		// the post itself, and with "all copies" every other post with the same file
		$targets = isset($_POST['all_copies']) ? $copies : array(array($boardName, (int)$postID, (int)$fileIndex));
		$whole = isset($_POST['delete_posts']) && hasPermission($config['mod']['delete'], $boardName);
		rebuild_batch_begin();
		$done = 0;
		foreach ($targets as $t) {
			list($uri, $id, $index) = $t;
			if (!openBoard($uri))
				continue;
			if ($whole)
				deletePost($id, false);
			else
				deleteFile($id, false, $index);
			$done++;
		}
		rebuild_batch_end();
		openBoard($boardName);
		modLog(sprintf('Banned file %s (%s)%s, removed from %d post(s)%s', $md5, $origin,
			$dhash ? ' incl. similar pictures' : '', $done, $whole ? ' (posts deleted)' : ''), $boardName);
		header('Location: ?/nichan-banned-files', true, $config['redirect_http']);
		return;
	}

	mod_page('Заборонити файл', 'mod/nichan_ban_file.html', array(
		'board' => $boardName,
		'post' => $post,
		'file' => $file,
		'file_index' => (int)$fileIndex,
		'md5' => $md5,
		'copies' => $copies,
		'can_delete' => hasPermission($config['mod']['delete'], $boardName),
		'token' => make_secure_link_token($boardName . '/banfile/' . $postID . '/' . $fileIndex),
	));
}

// mod.php?/nichan-banned-files: the list, lifting bans, adding MD5s by hand.
function mod_nichan_banned_files() {
	global $config, $mod;
	if (!hasPermission($config['mod']['nichan_ban_file']))
		error($config['error']['noaccess']);
	$can_unban = hasPermission($config['mod']['nichan_unban_file']);

	if ($_SERVER['REQUEST_METHOD'] == 'POST') {
		if (isset($_POST['unban']) && $can_unban) {
			$query = prepare('DELETE FROM ``nichan_banned_files`` WHERE `id` = :id');
			$query->bindValue(':id', (int)$_POST['unban'], PDO::PARAM_INT);
			$query->execute() or error(db_error($query));
			modLog('Lifted a file ban #' . (int)$_POST['unban']);
		}
		if (!empty($_POST['add_md5'])) {
			preg_match_all('/\b[0-9a-f]{32}\b/i', (string)$_POST['add_md5'], $m);
			$reason = trim(isset($_POST['add_reason']) ? (string)$_POST['add_reason'] : '');
			foreach (array_unique(array_map('strtolower', $m[0])) as $md5)
				nichan_ban_file_add($md5, null, $reason, 'вручну', $mod['username']);
			if ($m[0])
				modLog('Banned ' . count($m[0]) . ' file(s) by MD5');
		}
		header('Location: ?/nichan-banned-files', true, $config['redirect_http']);
		return;
	}

	$query = query('SELECT * FROM ``nichan_banned_files`` ORDER BY `created` DESC') or error(db_error());
	mod_page('Заборонені файли', 'mod/nichan_banned_files.html', array(
		'bans' => $query->fetchAll(PDO::FETCH_ASSOC),
		'can_unban' => $can_unban,
		'token' => make_secure_link_token('nichan-banned-files'),
	));
}

// mod.php?/nichan-filters: blocked words, replacements, flood limits and active cooldowns.
function mod_nichan_words() {
	return mod_nichan_filters('words');
}

// mod.php?/nichan-filters (flood, raids, premoderation) and mod.php?/nichan-words (blocked words, replacements)
function mod_nichan_filters($section = 'flood') {
	global $config, $mod, $board;
	$page = $section === 'words' ? 'nichan-words' : 'nichan-filters';
	if (!hasPermission($config['mod']['nichan_filters']))
		error($config['error']['noaccess']);
	$can_flood = hasPermission($config['mod']['nichan_flood_settings']);
	$test = null;

	if ($_SERVER['REQUEST_METHOD'] == 'POST') {
		if (!empty($_POST['add_pattern'])) {
			$f = array(
				'action' => (isset($_POST['add_action']) && $_POST['add_action'] === 'replace') ? 'replace' : 'block',
				'pattern' => trim((string)$_POST['add_pattern']),
				'is_regex' => empty($_POST['add_regex']) ? 0 : 1,
				'whole_word' => empty($_POST['add_whole']) ? 0 : 1,
				'replacement' => isset($_POST['add_replacement']) ? (string)$_POST['add_replacement'] : '',
				'note' => trim(isset($_POST['add_note']) ? (string)$_POST['add_note'] : ''),
			);
			if ($f['pattern'] === '' || nichan_word_filter_regex($f) === null)
				error('Регулярний вираз з помилкою: ' . utf8tohtml($f['pattern']));
			if ($f['is_regex'] && preg_match(nichan_word_filter_regex($f), ''))
				error('Цей вираз збігається з порожнім текстом, тобто з будь-яким постом.');
			$query = prepare('INSERT INTO ``nichan_word_filters`` (`action`, `pattern`, `is_regex`, `whole_word`, `replacement`, `note`, `created`, `mod`)
				VALUES (:action, :pattern, :is_regex, :whole_word, :replacement, :note, :created, :mod)');
			foreach ($f as $k => $v)
				$query->bindValue(':' . $k, $v);
			$query->bindValue(':created', time(), PDO::PARAM_INT);
			$query->bindValue(':mod', $mod['username']);
			$query->execute() or error(db_error($query));
			modLog('Added a word filter (' . $f['action'] . '): ' . utf8tohtml($f['pattern']));
		}
		if (isset($_POST['delete'])) {
			$query = prepare('DELETE FROM ``nichan_word_filters`` WHERE `id` = :id');
			$query->bindValue(':id', (int)$_POST['delete'], PDO::PARAM_INT);
			$query->execute() or error(db_error($query));
			modLog('Deleted word filter #' . (int)$_POST['delete']);
		}
		if (isset($_POST['lift'])) {
			// also forget the recent posts, or the very next post counts them again and starts a new break at once
			foreach (array('nichan_cooldowns', 'nichan_post_log') as $table) {
				$query = prepare('DELETE FROM ``' . $table . '`` WHERE `ip` = :ip');
				$query->bindValue(':ip', (string)$_POST['lift']);
				$query->execute() or error(db_error($query));
			}
			modLog('Lifted a flood cooldown for <a href="?/IP/' . utf8tohtml((string)$_POST['lift']) . '">' . utf8tohtml((string)$_POST['lift']) . '</a>');
		}
		if (isset($_POST['flood_save']) && $can_flood) {
			nichan_set_setting('flood_enabled', empty($_POST['flood_enabled']) ? '0' : '1');
			foreach (array('replies_1m', 'replies_10m', 'threads_30m') as $k)
				nichan_set_setting('flood_' . $k, (string)max(0, (int)(isset($_POST[$k]) ? $_POST[$k] : 0)));
			foreach (array('ban_first', 'ban_repeat', 'repeat_window') as $k) // minutes in the form
				nichan_set_setting('flood_' . $k, (string)(60 * max(1, (int)(isset($_POST[$k]) ? $_POST[$k] : 1))));
			modLog('Changed the flood limits');
		}
		if (isset($_POST['board_lock_save']) && $can_flood) {
			nichan_set_setting('board_lock_enabled', empty($_POST['board_lock_enabled']) ? '0' : '1');
			nichan_set_setting('board_lock_threads', (string)max(1, (int)(isset($_POST['bl_threads']) ? $_POST['bl_threads'] : 10)));
			foreach (array('window', 'length') as $k) // minutes in the form
				nichan_set_setting('board_lock_' . $k, (string)(60 * max(1, (int)(isset($_POST['bl_' . $k]) ? $_POST['bl_' . $k] : 1))));
			nichan_set_setting('board_lock_action', isset($_POST['bl_action']) && $_POST['bl_action'] === 'premod' ? 'premod' : 'lock');
			modLog('Changed the new-thread limits of the boards');
		}
		// premoderation of new threads on a board, switched on by hand for a while (or off)
		if (isset($_POST['premod_board']) && hasPermission($config['mod']['nichan_premod']) && openBoard((string)$_POST['premod_board'])) {
			$minutes = isset($_POST['premod_minutes']) ? (int)$_POST['premod_minutes'] : 0;
			nichan_premod_set($board['uri'], $minutes > 0 ? time() + 60 * $minutes : 0);
			modLog($minutes > 0 ? "Premoderation of new threads on for $minutes min" : 'Premoderation of new threads off', $board['uri']);
			nichan_moderators_notice($minutes > 0 ? "🕓 Премодерація нових ниток на /{$board['uri']}/ увімкнена на " . nichan_minutes_uk($minutes) . " ({$mod['username']})." : "✅ Премодерацію нових ниток на /{$board['uri']}/ вимкнено ({$mod['username']}).");
		}
		if (isset($_POST['board_alert_save']) && $can_flood) {
			nichan_set_setting('board_alert_enabled', empty($_POST['board_alert_enabled']) ? '0' : '1');
			nichan_set_setting('board_alert_posts', (string)max(1, (int)(isset($_POST['ba_posts']) ? $_POST['ba_posts'] : 100)));
			nichan_set_setting('board_alert_window', (string)(60 * max(1, (int)(isset($_POST['ba_window']) ? $_POST['ba_window'] : 10))));
			modLog('Changed the busy-board notice');
		}
		if (isset($_POST['board_unlock']) && $can_flood && openBoard((string)$_POST['board_unlock'])) {
			nichan_board_lock_lift($board['uri']);
			nichan_moderators_notice("✅ Нитки на /{$board['uri']}/ знову увімкнено достроково ({$mod['username']}).");
			modLog('Switched new threads back on', $board['uri']);
		}
		if (isset($_POST['test'])) {
			// no redirect: show what the filters do with this text
			$texts = array((string)$_POST['test']);
			$block = nichan_word_filters_apply($texts, true, false);
			$test = array('text' => (string)$_POST['test'], 'result' => $texts[0], 'block' => $block);
		} else {
			header('Location: ?/' . $page, true, $config['redirect_http']);
			return;
		}
	}

	$filters = array('block' => array(), 'replace' => array());
	foreach (nichan_word_filters() as $f) {
		$f['broken'] = nichan_word_filter_regex($f) === null;
		$filters[$f['action']][] = $f;
	}
	$query = prepare('SELECT * FROM ``nichan_cooldowns`` WHERE `until` > :now ORDER BY `until` DESC');
	$query->bindValue(':now', time(), PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$locked = array();
	$premod = array();
	foreach (listBoards() as $b) {
		if ($until = nichan_board_locked_until($b['uri']))
			$locked[$b['uri']] = $until;
		$premod[$b['uri']] = nichan_premod_until($b['uri']);
	}
	mod_page($section === 'words' ? 'Заборонені слова й автозаміна' : 'Флуд і рейди', 'mod/nichan_filters.html', array(
		'section' => $section,
		'page' => $page,
		'board_lock' => nichan_board_lock_settings(),
		'board_alert' => nichan_board_alert_settings(),
		'premod' => $premod,
		'can_premod' => hasPermission($config['mod']['nichan_premod']),
		'locked_boards' => $locked,
		'filters' => $filters,
		'flood' => nichan_flood_settings(),
		'cooldowns' => $query->fetchAll(PDO::FETCH_ASSOC),
		'can_flood' => $can_flood,
		'test' => $test,
		'now' => time(),
		'token' => make_secure_link_token($page),
	));
}

// mod.php?/<board>/nichan-note/<post>: a note under a post, in red italics (empty removes it).
function mod_nichan_note($boardName, $postID) {
	global $config, $mod, $board;
	if (!openBoard($boardName))
		error($config['error']['noboard']);
	if (!hasPermission($config['mod']['nichan_note'], $board['uri']))
		error($config['error']['noaccess']);
	$query = prepare(sprintf('SELECT `id`, `thread`, `body_nomarkup` FROM ``posts_%s`` WHERE `id` = :id', $board['uri']));
	$query->bindValue(':id', (int)$postID, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	if (!$post = $query->fetch(PDO::FETCH_ASSOC))
		error($config['error']['404']);
	$link = '?/' . $board['dir'] . $config['dir']['res'] . ($post['thread'] ?: $post['id']) . '.html#' . $post['id'];
	if ($_SERVER['REQUEST_METHOD'] == 'POST') {
		$text = isset($_POST['note']) ? (string)$_POST['note'] : '';
		nichan_modnote_set($post['id'], $text);
		modLog(trim($text) !== '' ? 'Moderator note on post #' . (int)$post['id'] . ': ' . utf8tohtml(mb_substr(trim($text), 0, 200)) : 'Removed the moderator note from post #' . (int)$post['id'], $board['uri']);
		header('Location: ' . $link, true, $config['redirect_http']);
		return;
	}
	mod_page('Примітка під дописом', 'mod/nichan_note.html', array(
		'post' => $post,
		'preview' => nichan_plain_text($post['body_nomarkup'], 300),
		'note' => nichan_modnote_get($post['id']),
		'link' => $link,
		'board_uri' => $board['uri'],
		'token' => make_secure_link_token($board['uri'] . '/nichan-note/' . $post['id']),
	));
}

// mod.php?/nichan-pending: new threads waiting for a moderator (premoderation), approve or reject.
function mod_nichan_pending() {
	global $config, $mod;
	if (!hasPermission($config['mod']['nichan_premod']))
		error($config['error']['noaccess']);
	if ($_SERVER['REQUEST_METHOD'] == 'POST') {
		$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
		if (isset($_POST['approve']) && ($pid = nichan_premod_approve($id)))
			modLog("Approved a waiting thread (#$pid)");
		elseif (isset($_POST['reject']) && nichan_premod_reject($id))
			modLog("Rejected a waiting thread (queue #$id)");
		header('Location: ?/nichan-pending', true, $config['redirect_http']);
		return;
	}
	mod_page('Нитки на перевірці', 'mod/nichan_pending.html', array(
		'items' => nichan_premod_list(),
		'token' => make_secure_link_token('nichan-pending'),
	));
}

// mod.php?/nichan-modstats: who did what (deleted, banned, handled reports…), per moderator and per day.
function mod_nichan_modstats($days = 30) {
	global $config;
	if (!hasPermission($config['mod']['nichan_modstats']))
		error($config['error']['noaccess']);
	$days = in_array((int)$days, array(7, 30, 90)) ? (int)$days : 30;
	$stats = nichan_modstats($days);
	mod_page('Статистика модерації', 'mod/nichan_modstats.html', array(
		'stats' => $stats,
		'days' => $days,
		'labels' => array('delete' => 'Видалено', 'ban' => 'Бани', 'report' => 'Відхилено скарг', 'lock' => 'Закрито ниток', 'note' => 'Примітки', 'premod' => 'Перевірка ниток'),
	));
}

// mod.php?/nichan-visits[/all]: the threads by visitors in the last 24 hours (the rows online.php keeps: a random
// id per browser, no IP), with the visitors right now and the posts of the day. 50 at first, /all: every one.
function mod_nichan_visits($all = null) {
	global $config, $mod;
	if (!hasPermission($config['mod']['nichan_visits']))
		error($config['error']['noaccess']);
	$day = time() - 86400;
	$now = time() - 180;
	$site = query("SELECT COUNT(DISTINCT `visitor`) AS `day`, COUNT(DISTINCT IF(`seen` > $now, `visitor`, NULL)) AS `now`
		FROM ``nichan_online`` WHERE `seen` > $day")->fetch(PDO::FETCH_ASSOC);
	$boards = array();
	foreach (query("SELECT `board`, COUNT(DISTINCT `visitor`) AS `day`, COUNT(DISTINCT IF(`seen` > $now, `visitor`, NULL)) AS `now`
		FROM ``nichan_online`` WHERE `seen` > $day AND `board` <> '' GROUP BY `board` ORDER BY `day` DESC")->fetchAll(PDO::FETCH_ASSOC) as $b) {
		if (getBoardInfo($b['board']) && nichan_can_see_board($b['board'], $mod))
			$boards[] = $b;
	}
	// the boards with people right now, the busiest first
	$boards_now = array_values(array_filter($boards, function ($b) { return $b['now'] > 0; }));
	usort($boards_now, function ($a, $b) { return $b['now'] - $a['now']; });
	// the threads by the day's visitors, or (&sort=now) by those right now: the first 50 of either order
	$sort = isset($_GET['sort']) && $_GET['sort'] === 'now' ? 'now' : 'day';
	$order = $sort === 'now' ? '`now` DESC, `day` DESC' : '`day` DESC, `now` DESC';
	$rows = array();
	foreach (query("SELECT `board`, `thread`, COUNT(*) AS `day`, SUM(`seen` > $now) AS `now`
		FROM ``nichan_online`` WHERE `seen` > $day AND `thread` > 0 GROUP BY `board`, `thread` ORDER BY $order")->fetchAll(PDO::FETCH_ASSOC) as $r) {
		if (getBoardInfo($r['board']) && nichan_can_see_board($r['board'], $mod))
			$rows[] = $r;
	}
	$total = count($rows);
	if ($all !== 'all')
		$rows = array_slice($rows, 0, 50);

	// the threads' names and the day's posts, one pair of queries per board
	$ids = array();
	foreach ($rows as $r)
		$ids[$r['board']][] = (int)$r['thread'];
	$names = array();
	$posts = array();
	foreach ($ids as $uri => $list) {
		$in = implode(',', $list);
		foreach (query(sprintf('SELECT `id`, `subject`, `body_nomarkup`, `sticky`, `locked` FROM ``posts_%s`` WHERE `thread` IS NULL AND `id` IN (%s)', $uri, $in))->fetchAll(PDO::FETCH_ASSOC) as $t)
			$names[$uri . ':' . $t['id']] = $t;
		foreach (query(sprintf('SELECT IFNULL(`thread`, `id`) AS `t`, COUNT(*) AS `n` FROM ``posts_%s`` WHERE `time` > %d AND IFNULL(`thread`, `id`) IN (%s) GROUP BY `t`', $uri, $day, $in))->fetchAll(PDO::FETCH_ASSOC) as $p)
			$posts[$uri . ':' . $p['t']] = (int)$p['n'];
	}
	foreach ($rows as &$r) {
		$key = $r['board'] . ':' . $r['thread'];
		$t = isset($names[$key]) ? $names[$key] : null;
		$r['exists'] = (bool)$t;
		$r['title'] = $t ? (trim((string)$t['subject']) !== '' ? $t['subject'] : nichan_plain_text($t['body_nomarkup'], 90)) : '';
		$r['sticky'] = $t && $t['sticky'];
		$r['locked'] = $t && $t['locked'];
		$r['posts'] = isset($posts[$key]) ? $posts[$key] : 0;
	}
	unset($r);
	mod_page('Журнал відвідувань', 'mod/nichan_visits.html', array(
		'site' => $site,
		'boards' => $boards,
		'boards_now' => $boards_now,
		'rows' => $rows,
		'total' => $total,
		'all' => $all === 'all',
		'sort' => $sort,
	));
}

// mod.php?/<board>/unarchive/<thread>: back to the top of the board (admins)
function mod_nichan_unarchive($boardName, $thread) {
	global $config, $board;
	if (!openBoard($boardName))
		error($config['error']['noboard']);
	if (!hasPermission($config['mod']['nichan_unarchive'], $board['uri']))
		error($config['error']['noaccess']);
	if (nichan_unarchive($board['uri'], (int)$thread))
		modLog('Took thread #' . (int)$thread . ' out of the archive', $board['uri']);
	header('Location: ?/' . $board['dir'] . $config['dir']['res'] . (int)$thread . '.html', true, $config['redirect_http']);
}

// mod.php?/<board>/archive: the archived threads with a "take out" link for admins
function mod_nichan_archive_list($boardName) {
	global $config, $board, $mod;
	if (!openBoard($boardName))
		error($config['error']['noboard']);
	$query = prepare(sprintf('SELECT `p`.`id`, `p`.`subject`, `p`.`body_nomarkup`, `a`.`time` AS `archived`
		FROM ``nichan_archive`` AS `a` JOIN ``posts_%s`` AS `p` ON `p`.`id` = `a`.`thread`
		WHERE `a`.`board` = :b ORDER BY `a`.`time` DESC, `p`.`bump` DESC', $board['uri']));
	$query->bindValue(':b', $board['uri']);
	$query->execute() or error(db_error($query));
	$rows = array();
	foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $r) {
		$r['title'] = trim((string)$r['subject']) !== '' ? $r['subject'] : nichan_plain_text($r['body_nomarkup'], 90);
		$r['token'] = make_secure_link_token($board['uri'] . '/unarchive/' . $r['id']);
		$rows[] = $r;
	}
	mod_page('Архів /' . $board['uri'] . '/', 'mod/nichan_archive.html', array(
		'rows' => $rows,
		'board_uri' => $board['uri'],
		'can_unarchive' => hasPermission($config['mod']['nichan_unarchive'], $board['uri']),
	));
}

// mod.php?/nichan-archive: how many pages a board shows before threads go to the archive, and each board's archive.
function mod_nichan_archive_settings() {
	global $config;
	if (!hasPermission($config['mod']['nichan_filters']))
		error($config['error']['noaccess']);
	$can_edit = hasPermission($config['mod']['nichan_flood_settings']);
	if ($_SERVER['REQUEST_METHOD'] == 'POST') {
		if (isset($_POST['archive_pages']) && $can_edit) {
			nichan_set_setting('archive_pages', (string)max(0, min(100, (int)$_POST['archive_pages'])));
			modLog('Changed the archive: boards show ' . (int)$_POST['archive_pages'] . ' pages');
		}
		header('Location: ?/nichan-archive', true, $config['redirect_http']);
		return;
	}
	$counts = array();
	if ($query = query('SELECT `board`, COUNT(*) AS `n` FROM ``nichan_archive`` GROUP BY `board`'))
		foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $r)
			$counts[$r['board']] = (int)$r['n'];
	$boards = array();
	foreach (listBoards() as $b)
		$boards[] = array('uri' => $b['uri'], 'title' => $b['title'], 'count' => isset($counts[$b['uri']]) ? $counts[$b['uri']] : 0);
	mod_page('Архів ниток', 'mod/nichan_archive_settings.html', array(
		'archive_pages' => nichan_archive_pages(),
		'boards' => $boards,
		'can_edit' => $can_edit,
		'token' => make_secure_link_token('nichan-archive'),
	));
}

// mod.php?/nichan-front: what the front page shows (inc/nichan-front.php); saving rebuilds it.
function mod_nichan_front() {
	global $config;
	if (!hasPermission($config['mod']['nichan_front']))
		error($config['error']['noaccess']);
	if ($_SERVER['REQUEST_METHOD'] == 'POST') {
		foreach (array('posts', 'popular_count', 'popular_hours', 'popular_min') as $k)
			nichan_set_setting('front_' . $k, (string)max(0, (int)(isset($_POST[$k]) ? $_POST[$k] : 0)));
		foreach (array('images_spoilers', 'popular_skip_bumplimit', 'popular_skip_locked', 'popular_skip_sticky') as $k)
			nichan_set_setting('front_' . $k, empty($_POST[$k]) ? '0' : '1');
		$exclude = array();
		foreach (listBoards() as $b)
			if (!empty($_POST['exclude_' . $b['uri']]))
				$exclude[] = $b['uri'];
		nichan_set_setting('front_exclude', implode(' ', $exclude));
		nichan_set_setting('front_words', mb_substr(isset($_POST['words']) ? (string)$_POST['words'] : '', 0, 5000));
		$GLOBALS['nichan_front_settings'] = null;
		rebuildTheme('recent', 'all');
		modLog('Changed the front page settings');
		header('Location: ?/nichan-front', true, $config['redirect_http']);
		return;
	}
	$s = nichan_front_settings();
	mod_page('Налаштування головної', 'mod/nichan_front.html', array(
		's' => $s,
		'words' => implode("\n", $s['words']),
		'boards' => array_values(array_filter(listBoards(), function ($b) { return nichan_board_listed($b['uri']); })),
		'token' => make_secure_link_token('nichan-front'),
	));
}

// Global and board-specific country rules, block messages and IP lookup.
function mod_nichan_countries() {
	global $config;
	if (!hasPermission($config['mod']['nichan_country_block']))
		error($config['error']['noaccess']);
	$p = nichan_country_policy();
	$boards = array();
	foreach (listBoards() as $b)
		$boards[$b['uri']] = $b['title'];
	$lookup = null;
	if ($_SERVER['REQUEST_METHOD'] == 'POST') {
		$read_scope = function ($prefix) {
			$scope = array('enabled' => !empty($_POST[$prefix . '_enabled']));
			foreach (NICHAN_COUNTRY_RULES as $type => $label) {
				$field = $prefix . '_' . $type;
				$mode = $_POST[$field . '_mode'] ?? null;
				$list = $_POST[$field . '_countries'] ?? null;
				if (!in_array($mode, ['only', 'except'], true) || !is_string($list))
					error('Форма правил неповна. Оновіть сторінку й повторіть збереження.');
				$scope[$type] = nichan_country_row(array('mode' => $mode,
					'countries' => preg_split('/[\s,]+/', strtolower($list), -1, PREG_SPLIT_NO_EMPTY)));
			}
			return $scope;
		};
		if (isset($_POST['check_ip'])) {
			$ip = trim((string)$_POST['check_ip']);
			if (!filter_var($ip, FILTER_VALIDATE_IP))
				error('Введіть дійсну IP-адресу.');
			$c = nichan_geoip_country($ip);
			$lookup = array('ip' => $ip, 'code' => $c[0] ?? null, 'name' => $c[1] ?? null, 'boards' => array());
			foreach ($boards as $uri => $title) {
				$action = nichan_country_action_for($ip, $uri);
				$what = ($action[0] ?? '') === 'block' ? 'дописи заблоковано' : '';
				if ($what === '') {
					$type = nichan_captcha_type_for($uri, $ip);
					$what = $type === 'unavailable' ? 'капча недоступна' : (NICHAN_COUNTRY_RULES[$type] ?? '');
				}
				$lookup['boards'][] = array('uri' => $uri, 'what' => $what);
			}
		} else {
			if (isset($_POST['save_global'])) {
				$p['global'] = $read_scope('g');
				modLog('Changed the global rules by country (' . ($p['global']['enabled'] ? 'on' : 'off') . ')');
			} elseif (isset($_POST['save_boards'])) {
				$p['boards'] = array();
				foreach ($boards as $uri => $title)
					$p['boards'][$uri] = $read_scope('b_' . $uri);
				modLog('Changed the rules by country of the boards');
			} elseif (isset($_POST['save_default'])) {
				$text = mb_substr(trim((string)$_POST['default_message']), 0, 500);
				if ($text === '' || $text === NICHAN_COUNTRY_MESSAGE)
					unset($p['messages']['default']);
				else
					$p['messages']['default'] = $text;
				modLog('Changed the default message of the block by country');
			} elseif (isset($_POST['add_message']) && preg_match('/^[a-z]{2}$/', $code = strtolower(trim((string)$_POST['code'])))) {
				$text = mb_substr(trim((string)$_POST['message']), 0, 500);
				if ($text !== '')
					$p['messages'][$code] = $text;
				modLog('Set the block message for ' . strtoupper($code));
			} elseif (isset($_POST['remove_message']) && preg_match('/^[a-z]{2}$/', $code = (string)$_POST['remove_message'])) {
				unset($p['messages'][$code]);
				modLog('Removed the block message for ' . strtoupper($code));
			}
			nichan_country_policy_save($p);
			header('Location: ?/nichan-countries', true, $config['redirect_http']);
			return;
		}
	}
	$names = nichan_country_names();
	$scopes = array();
	foreach ($boards as $uri => $title)
		$scopes[$uri] = isset($p['boards'][$uri]) ? $p['boards'][$uri] : nichan_country_scope_clean(null);
	$messages = array();
	foreach ($p['messages'] as $code => $text)
		if ($code !== 'default')
			$messages[] = array('code' => $code, 'name' => isset($names[$code]) ? $names[$code] : strtoupper($code), 'text' => $text);
	mod_page('Правила за країною', 'mod/nichan_countries.html', array(
		'global' => $p['global'],
		'boards' => $boards,
		'scopes' => $scopes,
		'default_message' => isset($p['messages']['default']) ? $p['messages']['default'] : NICHAN_COUNTRY_MESSAGE,
		'messages' => $messages,
		'names' => $names,
		'rules' => NICHAN_COUNTRY_RULES,
		'recaptcha_ready' => nichan_recaptcha_ready(),
		'picture_ready' => nichan_text_captcha_ready(),
		'lookup' => $lookup,
		'token' => make_secure_link_token('nichan-countries'),
	));
}

// array('xx' => 'name') of every country, sorted by name; from the intl extension (empty without it).
function nichan_country_names() {
	if (!class_exists('Locale'))
		return array();
	$unknown = Locale::getDisplayRegion('-ZZ', 'uk');
	$skip = array('EU', 'EZ', 'UN', 'QO', 'XA', 'XB', 'ZZ');
	$names = array();
	foreach (range('A', 'Z') as $a)
		foreach (range('A', 'Z') as $b) {
			$code = $a . $b;
			$name = Locale::getDisplayRegion('-' . $code, 'uk');
			if ($name && $name !== $code && $name !== $unknown && !in_array($code, $skip))
				$names[strtolower($code)] = $name;
		}
	if (class_exists('Collator'))
		(new Collator('uk'))->asort($names);
	else
		asort($names);
	return $names;
}

// mod.php?/nichan-site: the site's name, address, preview texts, menu links and front page texts
// (inc/nichan-site.php). An emptied field goes back to the default.
function mod_nichan_site() {
	global $config;
	if (!hasPermission($config['mod']['nichan_front']))
		error($config['error']['noaccess']);
	$defaults = nichan_site_defaults();
	if ($_SERVER['REQUEST_METHOD'] == 'POST') {
		$updates = array();
		foreach ($defaults as $k => $default) {
			if (!isset($_POST[$k]))
				continue;
			if (!is_string($_POST[$k]))
				error('Некоректне значення поля.');
			$v = trim(str_replace("\r", '', $_POST[$k]));
			if ($k === 'public_url' && $v !== '') {
				$v = rtrim($v, '/');
				if (!preg_match('~^https?://[^/@?#\s]+$~i', $v) || !parse_url($v, PHP_URL_HOST))
					error('Адреса сайту має виглядати як https://example.com');
			}
			$updates['site_' . $k] = ($v === '' || $v === $default) ? null : $v;
		}
		if (isset($_POST['board_view'])) {
			$view = $_POST['board_view'] === 'compact' ? 'compact' : 'standard';
			if ($view !== nichan_setting('board_view_default', 'standard'))
				$updates['board_view_default'] = $view;
		}
		$dm = !empty($_POST['dm_watermark_optional']) ? '1' : '0';
		if ($dm !== nichan_setting('dm_watermark_optional', '0'))
			$updates['dm_watermark_optional'] = $dm;

		$upload = $_FILES['header_image'] ?? null;
		if ($upload !== null && $upload['error'] !== UPLOAD_ERR_NO_FILE) {
			if (!empty($_POST['reset_header_image']))
				error('Обери нове зображення або повернення стандартного.');
			if (in_array($upload['error'], array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true) || $upload['size'] > 2 * 1024 * 1024)
				error('Зображення для шапки завелике. Максимальний розмір — 2 МБ.');
			if ($upload['error'] !== UPLOAD_ERR_OK)
				error('Не вдалося завантажити зображення для шапки.');
			$type = @getimagesize($upload['tmp_name']);
			$formats = array(IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp');
			if (!$type || !isset($formats[$type[2]]))
				error('Обери зображення PNG, JPEG, WebP або GIF.');
			$dir = 'static/header';
			if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir))
				error('Не вдалося створити папку для зображення шапки.');
			// Keep old URLs working for cached pages and reuse a file when it is uploaded again.
			$digest = hash_file('sha256', $upload['tmp_name']);
			if ($digest === false)
				error('Не вдалося прочитати зображення для шапки.');
			$path = $dir . '/' . $digest . '.' . $formats[$type[2]];
			if (!is_file($path) && !move_uploaded_file($upload['tmp_name'], $path))
				error('Не вдалося зберегти зображення для шапки.');
			$updates['site_header_image'] = $path;
		} elseif (!empty($_POST['reset_header_image'])) {
			$updates['site_header_image'] = null;
		}

		$changed = false;
		foreach ($updates as $key => $value) {
			if (nichan_setting($key) === $value)
				continue;
			if ($value === null) nichan_delete_setting($key);
			else nichan_set_setting($key, $value);
			$changed = true;
		}
		nichan_write_site_assets();
		if ($changed) {
			nichan_write_posting_status_files();
			rebuildThemes('all');
			modLog('Changed the site settings');
		}
		header('Location: ?/nichan-site', true, $config['redirect_http']);
		return;
	}
	$values = array();
	foreach ($defaults as $k => $default) {
		$stored = nichan_setting('site_' . $k);
		$values[$k] = trim((string)$stored) === '' ? $default : $stored;
	}
	mod_page('Сайт: назва й меню', 'mod/nichan_site.html', array(
		'values' => $values,
		'defaults' => $defaults,
		'dm_watermark_optional' => nichan_setting('dm_watermark_optional', '0') === '1',
		'board_view' => (string)nichan_setting('board_view_default', 'standard'),
		'token' => make_secure_link_token('nichan-site'),
	));
}
