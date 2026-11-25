<?php
/*
 *  Copyright (c) 2010-2014 Tinyboard Development Group
 */

require_once 'inc/functions.php';
require_once 'inc/anti-bot.php';
require_once 'inc/bans.php';

if (isset($_POST['delete'])) {
	// Delete
	
	if (!isset($_POST['board'], $_POST['password']))
		error($config['error']['bot']);
	
	$password = &$_POST['password'];
	
	if (!is_string($password) || $password == '')
		error($config['error']['invalidpassword']);
	
	$delete = array();
	foreach ($_POST as $post => $value) {
		if (preg_match('/^delete_(\d+)$/', $post, $m)) {
			$delete[] = (int)$m[1];
		}
	}
	
	checkDNSBL();
		
	// Check if board exists
	if (!openBoard($_POST['board']))
		error($config['error']['noboard']);

	if ((!isset($_POST['mod']) || !$_POST['mod']) && $config['board_locked']) {
    	error("Board is locked");
	}
	
	// Check if banned
	checkBan($board['uri']);

	// Check if deletion enabled
	if (!$config['allow_delete'])
		error(_('Post deletion is not allowed!'));
	
	if (empty($delete))
		error($config['error']['nodelete']);

	// guessing passwords: at most 10 wrong ones in 10 minutes from one IP
	if (nichan_rate_count('delete', $_SERVER['REMOTE_ADDR'], 600) >= 10 && nichan_api_code('rate_limited'))
		error(strpos($config['locale'], 'en') === 0 ? 'Too many wrong passwords. Try again later.' : 'Забагато неправильних паролів. Спробуйте трохи пізніше.');

	// Pages are rebuilt once after all selected posts are gone (see rebuild_batch_begin()).
	rebuild_batch_begin();

	foreach ($delete as &$id) {
		$query = prepare(sprintf("SELECT `id`,`thread`,`time`,`password` FROM ``posts_%s`` WHERE `id` = :id", $board['uri']));
		$query->bindValue(':id', $id, PDO::PARAM_INT);
		$query->execute() or error(db_error($query));
		
		if ($post = $query->fetch(PDO::FETCH_ASSOC)) {
			$thread = false;
			if ($config['user_moderation'] && $post['thread']) {
				$thread_query = prepare(sprintf("SELECT `time`,`password` FROM ``posts_%s`` WHERE `id` = :id", $board['uri']));
				$thread_query->bindValue(':id', $post['thread'], PDO::PARAM_INT);
				$thread_query->execute() or error(db_error($query));

				$thread = $thread_query->fetch(PDO::FETCH_ASSOC);	
			}

			// (hashed or, in old posts, as typed; compared as strings)
			if ($password != '' && !nichan_post_password_ok($post['password'], $password) && (!$thread || !nichan_post_password_ok($thread['password'], $password))) {
				nichan_rate_hit('delete', $_SERVER['REMOTE_ADDR']);
				error($config['error']['invalidpassword']);
			}
			
			if ($post['time'] > time() - $config['delete_time'] && (!$thread || !nichan_post_password_ok($thread['password'], $password))) {
				error(sprintf($config['error']['delete_too_soon'], until($post['time'] + $config['delete_time'])));
			}
			
			if (isset($_POST['file'])) {
				// Delete just the file
				deleteFile($id);
				modLog("User deleted file from his own post #$id");
			} else {
				// Delete entire post
				deletePost($id);
				modLog("User deleted his own post #$id");
			}

			_syslog(LOG_INFO, 'Deleted post: ' .
				'/' . $board['dir'] . $config['dir']['res'] . link_for($post) . ($post['thread'] ? '#' . $id : '')
			);
		}
	}
	
	buildIndex();

	$is_mod = isset($_POST['mod']) && $_POST['mod'];
	$root = $is_mod ? $config['root'] . $config['file_mod'] . '?/' : $config['root'];

	if (!isset($_POST['json_response'])) {
		header('Location: ' . $root . $board['dir'] . $config['file_index'], true, $config['redirect_http']);
	} else {
		header('Content-Type: ' . (defined('NICHAN_API') ? 'application/json' : 'text/json'));
		echo json_encode(defined('NICHAN_API') ? array('ok' => true) : array('success' => true));
	}

        // We are already done, let's continue our heavy-lifting work in the background (if we run off FastCGI)
        if (function_exists('fastcgi_finish_request'))
                @fastcgi_finish_request();

	rebuildThemes('post-delete', $board['uri']);
	rebuild_batch_end();

} elseif (isset($_POST['report'])) {
	if (!isset($_POST['board'], $_POST['reason']))
		error($config['error']['bot']);
	
	$report = array();
	foreach ($_POST as $post => $value) {
		if (preg_match('/^delete_(\d+)$/', $post, $m)) {
			$report[] = (int)$m[1];
		}
	}
	
	checkDNSBL();
		
	// Check if board exists
	if (!openBoard($_POST['board']))
		error($config['error']['noboard']);

	if ((!isset($_POST['mod']) || !$_POST['mod']) && $config['board_locked']) {
   		error("Board is locked");
	}
	
	// Check if banned
	checkBan($board['uri']);
	
	if (empty($report))
		error($config['error']['noreport']);

	// at most 10 reports in 10 minutes from one IP (each goes to the moderators' Telegram chat)
	if (nichan_rate_count('report', $_SERVER['REMOTE_ADDR'], 600) + count($report) > 10 && nichan_api_code('rate_limited'))
		error(strpos($config['locale'], 'en') === 0 ? 'Too many reports. Try again later.' : 'Забагато скарг. Спробуйте трохи пізніше.');
	
	if (count($report) > $config['report_limit'])
		error($config['error']['toomanyreports']);

	$reason = escape_markup_modifiers(mb_substr((string)$_POST['reason'], 0, 300));
	markup($reason);

	foreach ($report as &$id) {
		$query = prepare(sprintf("SELECT `id`, `thread` FROM ``posts_%s`` WHERE `id` = :id", $board['uri']));
		$query->bindValue(':id', $id, PDO::PARAM_INT);
		$query->execute() or error(db_error($query));
		
		$post = $query->fetch(PDO::FETCH_ASSOC);
		if (!$post)
			continue; // no such post (deleted meanwhile, or made up)
		nichan_rate_hit('report', $_SERVER['REMOTE_ADDR']);
		
	        $error = event('report', array('ip' => $_SERVER['REMOTE_ADDR'], 'board' => $board['uri'], 'post' => $post, 'reason' => $reason, 'link' => link_for($post)));

	        if ($error) {
	                error($error);
	        }

		if ($config['syslog'])
			_syslog(LOG_INFO, 'Reported post: ' .
				'/' . $board['dir'] . $config['dir']['res'] . link_for($post) . ($post['thread'] ? '#' . $id : '') .
				' for "' . $reason . '"'
			);
		$query = prepare("INSERT INTO ``reports`` VALUES (NULL, :time, :ip, :board, :post, :reason)");
		$query->bindValue(':time', time(), PDO::PARAM_INT);
		$query->bindValue(':ip', $_SERVER['REMOTE_ADDR'], PDO::PARAM_STR);
		$query->bindValue(':board', $board['uri'], PDO::PARAM_STR);
		$query->bindValue(':post', $id, PDO::PARAM_INT);
		$query->bindValue(':reason', $reason, PDO::PARAM_STR);
		$query->execute() or error(db_error($query));

		nichan_telegram_report_new($pdo->lastInsertId(), $board['uri'], $id, $reason, $_SERVER['REMOTE_ADDR']);
	}
	
	$is_mod = isset($_POST['mod']) && $_POST['mod'];
	$root = $is_mod ? $config['root'] . $config['file_mod'] . '?/' : $config['root'];
	
	if (!isset($_POST['json_response'])) {
		$index = $root . $board['dir'] . $config['file_index'];
		echo Element('page.html', array('config' => $config, 'body' => '<div style="text-align:center"><a href="javascript:window.close()">[ ' . _('Close window') ." ]</a> <a href='$index'>[ " . _('Return') . ' ]</a></div>', 'title' => _('Report submitted!')));
	} else {
		header('Content-Type: ' . (defined('NICHAN_API') ? 'application/json' : 'text/json'));
		echo json_encode(defined('NICHAN_API') ? array('ok' => true) : array('success' => true));
	}
} elseif (isset($_POST['post'])) {
	if (!isset($_POST['body'], $_POST['board']))
		error($config['error']['bot']);

	$post = array('board' => $_POST['board'], 'files' => array());

	// Check if board exists
	if (!openBoard($post['board']))
		error($config['error']['noboard']);

	if ((!isset($_POST['mod']) || !$_POST['mod']) && $config['board_locked']) {
    	error("Board is locked");
	}
	
	if (!isset($_POST['name']))
		$_POST['name'] = $config['anonymous'];
	
	if (!isset($_POST['email']))
		$_POST['email'] = '';
	
	if (!isset($_POST['subject']))
		$_POST['subject'] = '';
	
	if (!isset($_POST['password']))
		$_POST['password'] = '';	
	
	if (isset($_POST['thread'])) {
		$post['op'] = false;
		$post['thread'] = round($_POST['thread']);
	} else
		$post['op'] = true;



	// Check if banned
	checkBan($board['uri']);

	// Check for CAPTCHA right after opening the board so the "return" link is in there
	// Staff posting from the mod panel skip the CAPTCHA.
	if (isset($_POST['mod']) && $_POST['mod'])
		check_login(false);
	// rules by country (mod.php?/nichan-countries): posting refused there, or a captcha even where the board has none
	nichan_country_block_check($_SERVER['REMOTE_ADDR'], $board['uri'], (bool)$mod);
	if (!$mod) {
		$config['nichan_captcha'] = nichan_captcha_type_for($board['uri'], $_SERVER['REMOTE_ADDR']);
		if ($config['nichan_captcha'] === 'unavailable') {
			nichan_api_code('captcha_unavailable');
			error(strpos($config['locale'], 'en') === 0 ? 'Captcha is temporarily unavailable. Please try again later.' : 'Капча тимчасово недоступна. Спробуйте дописати пізніше.');
		}
		$config['recaptcha'] = $config['nichan_captcha'] === 'recaptcha';
	}
	if ($config['recaptcha'] && !$mod) {
		// Checked with cURL: file_get_contents() of https URLs is often disabled on shared hosting,
		// which made every post fail silently before.
		if (!nichan_recaptcha_verify(isset($_POST['g-recaptcha-response']) ? (string)$_POST['g-recaptcha-response'] : '', $config['recaptcha_private']) && nichan_api_code('captcha'))
			error(strpos($config['locale'], 'en') === 0 ? 'Please confirm that you are not a robot (reCAPTCHA).' : 'Підтвердіть, що ви не робот (reCAPTCHA).');
	}
	// The site's own picture captcha (two words, Ukrainian or English).
	if (!$mod && ($config['nichan_captcha'] === 'uk' || $config['nichan_captcha'] === 'en')) {
		if (!nichan_captcha_check(isset($_POST['nichan_captcha_id']) ? $_POST['nichan_captcha_id'] : '', isset($_POST['nichan_captcha']) ? $_POST['nichan_captcha'] : '', $config['nichan_captcha']) && nichan_api_code('captcha'))
			error(strpos($config['locale'], 'en') === 0 ? 'Wrong captcha. Type the two words from the new picture.' : 'Неправильна капча. Введіть два слова з нової картинки.');
	}
	// too many posts from this IP: a cooldown (inc/nichan-antispam.php)
	nichan_flood_check($_SERVER['REMOTE_ADDR'], $post['op'], (bool)$mod);
	// too many new threads on the board: creating threads is switched off for a while
	if ($post['op'])
		nichan_board_lock_check($board['uri'], (bool)$mod);
//		if (!(($post['op'] && $_POST['post'] == $config['button_newtopic']) ||
//			(!$post['op'] && $_POST['post'] == $config['button_reply'])))
//			error($config['error']['bot']);

	// Check the referrer (an app sends none; the API uses no cookies, so there is no login to borrow)
	if ($config['referer_match'] !== false && !defined('NICHAN_API') &&
		(!isset($_SERVER['HTTP_REFERER']) || !preg_match($config['referer_match'], rawurldecode($_SERVER['HTTP_REFERER']))))
		error($config['error']['referer']);

	checkDNSBL();
	

	if ($post['mod'] = isset($_POST['mod']) && $_POST['mod']) {
		check_login(false);
		if (!$mod) {
			// Liar. You're not a mod.
			error($config['error']['notamod']);
		}
	
		$post['sticky'] = $post['op'] && isset($_POST['sticky']);
		$post['locked'] = $post['op'] && isset($_POST['lock']);
		$post['raw'] = isset($_POST['raw']);
	
		if ($post['sticky'] && !hasPermission($config['mod']['sticky'], $board['uri']))
			error($config['error']['noaccess']);
		if ($post['locked'] && !hasPermission($config['mod']['lock'], $board['uri']))
			error($config['error']['noaccess']);
		if ($post['raw'] && !hasPermission($config['mod']['rawhtml'], $board['uri']))
			error($config['error']['noaccess']);
	}

	// Hidden boards and closed posting: staff may still post (also from the public pages,
	// as long as they are logged in to the mod panel).
	$staff = $mod;
	if (!$staff && (nichan_board_visibility($board['uri']) != NICHAN_VISIBILITY_PUBLIC || nichan_posting_closed($board['uri']))) {
		check_login(false);
		$staff = $mod;
		$mod = false; // only used for this check; the post itself is still an ordinary one
	}
	if (!nichan_can_see_board($board['uri'], $staff))
		error($config['error']['noboard']);
	if (($closed = nichan_posting_closed($board['uri'])) && !$staff && nichan_api_code('closed'))
		error(nichan_posting_closed_message($closed, strpos($config['locale'], 'en') === 0));

	$is_tor = nichan_is_tor_request();
	if ($is_tor) {
		nichan_tor_post_check($_POST, $_FILES);
		// Only empty file controls can remain. Treat this as a text post, including for an OP.
		$_FILES = array();
		$post['no_longer_require_an_image_for_op'] = true;
	} elseif (defined('NICHAN_API')) {
		foreach ($_FILES as $file) {
			if (in_array($file['error'], array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true))
				nichan_api_fail(413, 'file_too_big', strpos($config['locale'], 'en') === 0 ? 'The file is too big.' : 'Файл завеликий.');
		}
	}

	// (the app API has no page with the hidden fields to send back: captcha, cooldowns and the rest still apply)
	if (!$post['mod'] && !defined('NICHAN_API')) {
		$post['antispam_hash'] = checkSpam(array($board['uri'], isset($post['thread']) ? $post['thread'] : ($config['try_smarter'] && isset($_POST['page']) ? 0 - (int)$_POST['page'] : null)));
		if ($post['antispam_hash'] === true)
			error($config['error']['spam']);
	}

	// (an "else" here used to reset $mod and $post['mod'] for every post, so staff posts lost their
	// capcode, sticky, lock and raw HTML)
	if ($config['robot_enable'] && $config['robot_mute']) {
		checkMute();
	}
	
	//Check if thread exists
	if (!$post['op']) {
		$query = prepare(sprintf("SELECT `sticky`,`locked`,`cycle`,`sage`,`slug` FROM ``posts_%s`` WHERE `id` = :id AND `thread` IS NULL LIMIT 1", $board['uri']));
		$query->bindValue(':id', $post['thread'], PDO::PARAM_INT);
		$query->execute() or error(db_error());
		
		if (!$thread = $query->fetch(PDO::FETCH_ASSOC)) {
			// Non-existant
			error($config['error']['nonexistant']);
		}
	}
	else {
		$thread = false;
	}
		
	
	// Check for an embed field
	if ($config['enable_embedding'] && isset($_POST['embed']) && !empty($_POST['embed'])) {
		// yep; validate it
		$value = $_POST['embed'];
		$value = trim($value);
		foreach ($config['embedding'] as &$embed) {
			if (preg_match($embed[0], $value)) {
				// Valid link
				$post['embed'] = $value;
				// This is bad, lol.
				$post['no_longer_require_an_image_for_op'] = true;
				break;
			}
		}
		if (!isset($post['embed'])) {
			error($config['error']['invalid_embed']);
		}
	}
	
	if (!hasPermission($config['mod']['bypass_field_disable'], $board['uri'])) {
		if ($config['field_disable_name'])
			$_POST['name'] = $config['anonymous']; // "forced anonymous"
	
		if ($config['field_disable_email'])
			$_POST['email'] = '';
	
		if ($config['field_disable_password'])
			$_POST['password'] = '';
	
		if ($config['field_disable_subject'] || (!$post['op'] && $config['field_disable_reply_subject']))
			$_POST['subject'] = '';
	}
	
	if ($config['allow_upload_by_url'] && isset($_POST['file_url']) && !empty($_POST['file_url'])) {
		$post['file_url'] = $_POST['file_url'];
		if (!preg_match('@^https?://@', $post['file_url']))
			error($config['error']['invalidimg']);
		
		if (mb_strpos($post['file_url'], '?') !== false)
			$url_without_params = mb_substr($post['file_url'], 0, mb_strpos($post['file_url'], '?'));
		else
			$url_without_params = $post['file_url'];

		$post['extension'] = strtolower(mb_substr($url_without_params, mb_strrpos($url_without_params, '.') + 1));

		if ($post['op'] && $config['allowed_ext_op']) {
			if (!in_array($post['extension'], $config['allowed_ext_op']))
				error($config['error']['unknownext']);
		}
		else if (!in_array($post['extension'], $config['allowed_ext']) && !in_array($post['extension'], $config['allowed_ext_files']))
			error($config['error']['unknownext']);

		$post['file_tmp'] = tempnam($config['tmp'], 'url');
		function unlink_tmp_file($file) {
			@unlink($file);
			fatal_error_handler();
		}
		register_shutdown_function('unlink_tmp_file', $post['file_tmp']);
		
		$fp = fopen($post['file_tmp'], 'w');
		
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $post['file_url']);
		curl_setopt($curl, CURLOPT_FAILONERROR, true);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($curl, CURLOPT_TIMEOUT, $config['upload_by_url_timeout']);
		curl_setopt($curl, CURLOPT_USERAGENT, 'Tinyboard');
		curl_setopt($curl, CURLOPT_BINARYTRANSFER, true);
		curl_setopt($curl, CURLOPT_FILE, $fp);
		curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
		
		if (curl_exec($curl) === false)
			error($config['error']['nomove'] . '<br/>Curl says: ' . curl_error($curl));
		
		curl_close($curl);
		
		fclose($fp);

		$_FILES['file'] = array(
			'name' => basename($url_without_params),
			'tmp_name' => $post['file_tmp'],
			'file_tmp' => true,
			'error' => 0,
			'size' => filesize($post['file_tmp'])
		);
	}
	
	$post['name'] = $_POST['name'] != '' ? $_POST['name'] : $config['anonymous'];
	$post['subject'] = $_POST['subject'];
	$post['email'] = str_replace(' ', '%20', htmlspecialchars($_POST['email']));
	$post['body'] = $_POST['body'];
	$post['password'] = $_POST['password'];
	$post['has_file'] = (!isset($post['embed']) && (($post['op'] && !isset($post['no_longer_require_an_image_for_op']) && $config['force_image_op']) || count($_FILES) > 0));
	

	if (!($post['has_file'] || isset($post['embed'])) || (($post['op'] && $config['force_body_op']) || (!$post['op'] && $config['force_body']))) {
		$stripped_whitespace = preg_replace('/[\s]/u', '', $post['body']);
		if ($stripped_whitespace == '') {
			error($config['error']['tooshort_body']);
		}
	}

	if (!$post['op']) {
		// Check if thread is locked
		// but allow mods to post
		if ($thread['locked'] && !hasPermission($config['mod']['postinlocked'], $board['uri']))
			error($config['error']['locked']);
	
		$numposts = numPosts($post['thread']);
	
		if ($config['reply_hard_limit'] != 0 && $config['reply_hard_limit'] <= $numposts['replies'])
			error($config['error']['reply_hard_limit']);
	
		if ($post['has_file'] && $config['image_hard_limit'] != 0 && $config['image_hard_limit'] <= $numposts['images'])
			error($config['error']['image_hard_limit']);
	}
	else {
		if (!$post['op']) {
                        $numposts = numPosts($post['thread']);
		}
	}
		
	if ($post['has_file']) {
		// Determine size sanity
		$size = 0;
		if ($config['multiimage_method'] == 'split') {
			foreach ($_FILES as $key => $file) {
				$size += $file['size'];
			}
		} elseif ($config['multiimage_method'] == 'each') {
			foreach ($_FILES as $key => $file) {
				if ($file['size'] > $size) {
					$size = $file['size'];
				}
			}
		} else {
			error(_('Unrecognized file size determination method.'));
		}

		if ($size > $config['max_filesize'])
			error(sprintf3($config['error']['filesize'], array(
				'sz' => number_format($size),
				'filesz' => number_format($size),
				'maxsz' => number_format($config['max_filesize'])
			)));
		$post['filesize'] = $size;
	}
	
	
	$post['capcode'] = false;
	
	// "Sign as ## Admin/Mod" checkbox in the moderators' post form.
	if ($mod && !empty($_POST['nichan_capcode']) && strpos($post['name'], '##') === false && nichan_default_capcode($mod) !== '') {
		$post['name'] = (trim($post['name']) !== '' && $post['name'] !== $config['anonymous'] ? trim($post['name']) . ' ' : '') . '## ' . nichan_default_capcode($mod);
	}

	if ($mod && preg_match('/^((.+) )?## (.+)$/', $post['name'], $matches)) {
		$name = $matches[2] != '' ? $matches[2] : $config['anonymous'];
		$cap = $matches[3];
		
		if (isset($config['mod']['capcode'][$mod['type']])) {
			if (	$config['mod']['capcode'][$mod['type']] === true ||
				(is_array($config['mod']['capcode'][$mod['type']]) &&
					in_array($cap, $config['mod']['capcode'][$mod['type']])
				)) {
				
				$post['capcode'] = utf8tohtml($cap);
				$post['name'] = $name;
			}
		}
	}
	
	// the author of an OP-marked thread: "## OP" on their replies (same password or IP; inc/nichan-threads.php)
	if (!$post['op'] && !$post['capcode'] && nichan_opmark_is_op($board['uri'], $post['thread'], $_SERVER['REMOTE_ADDR'], $post['password'])) {
		$post['capcode'] = 'OP';
		$post['nichan_op'] = true;
	}

	// the code after "#" (or "##") in the name field, for the custom tripcodes below
	$trip_code = preg_match('/^[^#]*##?(.+)$/s', $post['name'], $trip_match) ? $trip_match[1] : '';
	$trip = generate_tripcode($post['name']);
	$post['name'] = $trip[0];
	$post['trip'] = isset($trip[1]) ? $trip[1] : ''; // XX: Dropped posts and tripcodes

	// Apply the matching custom tripcode after resolving the ordinary one.
	if ($custom_trip = nichan_trip_for($_SERVER['REMOTE_ADDR'], $trip_code))
		$post['trip'] = '!!ct' . $custom_trip['id'];
	
	$noko = false;
	if (strtolower($post['email']) == 'noko') {
		$noko = true;
		$post['email'] = '';
	} elseif (strtolower($post['email']) == 'nonoko'){
		$noko = false;
		$post['email'] = '';
	} else $noko = $config['always_noko'];
	
	if ($post['has_file']) {
		$i = 0;
		foreach ($_FILES as $key => $file) {
			if (!in_array($file['error'], array(UPLOAD_ERR_NO_FILE, UPLOAD_ERR_OK))) {
				error(sprintf3($config['error']['phpfileserror'], array(
					'index' => $i+1,
					'code' => $file['error']
				)));
			}

			if ($file['size'] && $file['tmp_name']) {
				$file['filename'] = urldecode($file['name']);
				$file['extension'] = strtolower(mb_substr($file['filename'], mb_strrpos($file['filename'], '.') + 1));
				if (isset($config['filename_func']))
					$file['file_id'] = $config['filename_func']($file);
				else
					$file['file_id'] = time() . substr(microtime(), 2, 3);

				if (sizeof($_FILES) > 1)
					$file['file_id'] .= "-$i";
				
				$file['file'] = $board['dir'] . $config['dir']['img'] . $file['file_id'] . '.' . $file['extension'];
				$file['thumb'] = $board['dir'] . $config['dir']['thumb'] . $file['file_id'] . '.' . ($config['thumb_ext'] ? $config['thumb_ext'] : $file['extension']);
				$post['files'][] = $file;
				$i++;
			}
		}
	}

	if (empty($post['files'])) $post['has_file'] = false;

	// Check for a file
	if ($post['op'] && !isset($post['no_longer_require_an_image_for_op'])) {
		if (!$post['has_file'] && $config['force_image_op'])
			error($config['error']['noimage']);
	}

	// Check for too many files
	if (sizeof($post['files']) > $config['max_images'])
		error($config['error']['toomanyimages']);

	if ($config['strip_combining_chars']) {
		$post['name'] = strip_combining_chars($post['name']);
		$post['email'] = strip_combining_chars($post['email']);
		$post['subject'] = strip_combining_chars($post['subject']);
		$post['body'] = strip_combining_chars($post['body']);
	}
	
	// Check string lengths
	if (mb_strlen($post['name']) > 35)
		error(sprintf($config['error']['toolong'], 'name'));	
	if (mb_strlen($post['email']) > 40)
		error(sprintf($config['error']['toolong'], 'email'));
	if (mb_strlen($post['subject']) > 100)
		error(sprintf($config['error']['toolong'], 'subject'));
	if (!$mod && mb_strlen($post['body']) > $config['max_body'])
		error($config['error']['toolong_body']);
	if (mb_strlen($post['password']) > 20)
		error(sprintf($config['error']['toolong'], 'password'));
	foreach($config['spamwords'] as $a) {
            if (stripos($post['body'], $a) !== false)
                error($config['error']['spamword']);
        }
	wordfilters($post['body']);
	// word filters from mod.php?/nichan-filters (blocked words, replacements; staff may use blocked words)
	nichan_word_filters_post($post, (bool)$mod);
//    $post['body'] = urldecode($post['body']); // фіксимо посилання на вікі
    
// links to posts of this site become >>>/board/ links (the site's address: mod.php?/nichan-site)
	$post['body'] = nichan_site_links_to_cites($post['body']);

	$post['body'] = escape_markup_modifiers($post['body']);
	
	if ($mod && isset($post['raw']) && $post['raw']) {
		$post['body'] .= "\n<tinyboard raw html>1</tinyboard>";
	}
	if ($config['country_flags'] && (!$config['allow_no_country'] || !isset($_POST['no_country']))) {
		if ($is_tor && in_array($board['uri'], array('int', 'pol'), true)) {
			$geo_flag = array('t1', 'Tor');
		} else {
			$geo_flag = nichan_geo_flag($_SERVER['REMOTE_ADDR'], !empty($config['nichan_geo_mode']) && $config['nichan_geo_mode'] == 2,
				isset($config['nichan_geo_text']) ? $config['nichan_geo_text'] : '', strpos($config['locale'], 'en') === 0 ? 'en' : 'uk');
		}
		if ($geo_flag) {
			$post['body'] .= "\n<tinyboard flag>" . $geo_flag[0] . "</tinyboard>" .
				"\n<tinyboard flag alt>" . utf8tohtml($geo_flag[1]) . "</tinyboard>";
		}
	}

	// «Спитати долю» (mod.php -> board): drawn here, kept as a modifier, so the text of a post can't fake it
	if (!empty($config['nichan_fate']) && !empty($_POST['nichan_fate']) && ($fate = nichan_fate_roll()))
		$post['body'] .= "\n<tinyboard nichan fate>" . $fate . "</tinyboard>";

	if ($config['user_flag'] && isset($_POST['user_flag']))
	if (!empty($_POST['user_flag']) ){
		
		$user_flag = $_POST['user_flag'];
		
		if (!isset($config['user_flags'][$user_flag]))
			error(_('Invalid flag selection!'));

		$flag_alt = isset($user_flag_alt) ? $user_flag_alt : $config['user_flags'][$user_flag];

		$post['body'] .= "\n<tinyboard flag>" . strtolower($user_flag) . "</tinyboard>" .
		"\n<tinyboard flag alt>" . $flag_alt . "</tinyboard>";
	}

	if ($config['allowed_tags'] && $post['op'] && isset($_POST['tag']) && isset($config['allowed_tags'][$_POST['tag']])) {
		$post['body'] .= "\n<tinyboard tag>" . $_POST['tag'] . "</tinyboard>";
	}

        if ($config['proxy_save'] && isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
		$proxy = preg_replace("/[^0-9a-fA-F.,: ]/", '', $_SERVER['HTTP_X_FORWARDED_FOR']);
		$post['body'] .= "\n<tinyboard proxy>".$proxy."</tinyboard>";
	}
	
	if (mysql_version() >= 50503) {
		$post['body_nomarkup'] = $post['body']; // Assume we're using the utf8mb4 charset
	} else {
		// MySQL's `utf8` charset only supports up to 3-byte symbols
		// Remove anything >= 0x010000
		
		$chars = preg_split('//u', $post['body'], -1, PREG_SPLIT_NO_EMPTY);
		$post['body_nomarkup'] = '';
		foreach ($chars as $char) {
			$o = 0;
			$ord = ordutf8($char, $o);
			if ($ord >= 0x010000)
				continue;
			$post['body_nomarkup'] .= $char;
		}
	}
	
	$post['tracked_cites'] = markup($post['body'], true);

	
	
	if ($post['has_file']) {
		$md5cmd = false;
		if ($config['bsd_md5'])  $md5cmd = '/sbin/md5 -r';
		if ($config['gnu_md5'])  $md5cmd = 'md5sum';

		$allhashes = '';

		foreach ($post['files'] as $key => &$file) {
			if ($post['op'] && $config['allowed_ext_op']) {
				if (!in_array($file['extension'], $config['allowed_ext_op']))
					error($config['error']['unknownext']);
			}
			elseif (!in_array($file['extension'], $config['allowed_ext']) && !in_array($file['extension'], $config['allowed_ext_files']))
				error($config['error']['unknownext']);
			
			$file['is_an_image'] = !in_array($file['extension'], $config['allowed_ext_files']);
			
			// Truncate filename if it is too long
			$file['filename'] = mb_substr($file['filename'], 0, $config['max_filename_len']);
			
			$upload = $file['tmp_name'];
			
			if (!is_readable($upload))
				error($config['error']['nomove']);

			if ($md5cmd) {
				$output = shell_exec_error($md5cmd . " " . escapeshellarg($upload));
				$output = explode(' ', $output);
				$hash = $output[0];
			}
			else {
				$hash = md5_file($upload);
			}

			$file['hash'] = $hash;
			$allhashes .= $hash;
		}

		if (count ($post['files']) == 1) {
			$post['filehash'] = $hash;
		}
		else {
			$post['filehash'] = md5($allhashes);
		}

		// files banned by moderators (exact copies and re-saved copies of the picture)
		nichan_check_banned_upload($post);
	}

	if (!hasPermission($config['mod']['bypass_filters'], $board['uri'])) {
		require_once 'inc/filters.php';

		do_filters($post);
	}

	if ($post['has_file']) {
		foreach ($post['files'] as $key => &$file) {
		if ($file['is_an_image']) {
			if ($config['ie_mime_type_detection'] !== false) {
				// Check IE MIME type detection XSS exploit
				$buffer = file_get_contents($upload, null, null, null, 255);
				if (preg_match($config['ie_mime_type_detection'], $buffer)) {
					undoImage($post);
					error($config['error']['mime_exploit']);
				}
			}
			
			require_once 'inc/image.php';
			
			// find dimensions of an image using GD
			if (!$size = @getimagesize($file['tmp_name'])) {
				error($config['error']['invalidimg']);
			}
			if (!in_array($size[2], array(IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_BMP, defined('IMAGETYPE_WEBP') ? IMAGETYPE_WEBP : 18))) {
				error($config['error']['invalidimg']);
			}
			if ($size[0] > $config['max_width'] || $size[1] > $config['max_height']) {
				error($config['error']['maxsize']);
			}
			
			
			if ($config['convert_auto_orient'] && ($file['extension'] == 'jpg' || $file['extension'] == 'jpeg')) {
				// The following code corrects the image orientation.
				// Currently only works with the 'convert' option selected but it could easily be expanded to work with the rest if you can be bothered.
				if (!($config['redraw_image'] || (($config['strip_exif'] && !$config['use_exiftool']) && ($file['extension'] == 'jpg' || $file['extension'] == 'jpeg')))) {
					if (in_array($config['thumb_method'], array('convert', 'convert+gifsicle', 'gm', 'gm+gifsicle'))) {
						$exif = @exif_read_data($file['tmp_name']);
						$gm = in_array($config['thumb_method'], array('gm', 'gm+gifsicle'));
						if (isset($exif['Orientation']) && $exif['Orientation'] != 1) {
							if ($config['convert_manual_orient']) {
								$error = shell_exec_error(($gm ? 'gm ' : '') . 'convert ' .
									escapeshellarg($file['tmp_name']) . ' ' .
									ImageConvert::jpeg_exif_orientation(false, $exif) . ' ' .
									($config['strip_exif'] ? '+profile "*"' :
										($config['use_exiftool'] ? '' : '+profile "*"')
									) . ' ' .
									escapeshellarg($file['tmp_name']));
								if ($config['use_exiftool'] && !$config['strip_exif']) {
									if ($exiftool_error = shell_exec_error(
										'exiftool -overwrite_original -q -q -orientation=1 -n ' .
											escapeshellarg($file['tmp_name'])))
										error(_('exiftool failed!'), null, $exiftool_error);
								} else {
									// TODO: Find another way to remove the Orientation tag from the EXIF profile
									// without needing `exiftool`.
								}
							} else {
								$error = shell_exec_error(($gm ? 'gm ' : '') . 'convert ' .
										escapeshellarg($file['tmp_name']) . ' -auto-orient ' . escapeshellarg($upload));
							}
							if ($error)
								error(_('Could not auto-orient image!'), null, $error);
							$size = @getimagesize($file['tmp_name']);
							if ($config['strip_exif'])
								$file['exif_stripped'] = true;
						}
					}
				}
			}
			
			// create image object
			$image = new Image($file['tmp_name'], $file['extension'], $size);
			if ($image->size->width > $config['max_width'] || $image->size->height > $config['max_height']) {
				$image->delete();
				error($config['error']['maxsize']);
			}
			
			$file['width'] = $image->size->width;
			$file['height'] = $image->size->height;
			
			if ($config['spoiler_images'] && isset($_POST['spoiler'])) {
				$file['thumb'] = 'spoiler';
				
				$size = @getimagesize($config['spoiler_image']);
				$file['thumbwidth'] = $size[0];
				$file['thumbheight'] = $size[1];
			} elseif ($config['minimum_copy_resize'] &&
				$image->size->width <= $config['thumb_width'] &&
				$image->size->height <= $config['thumb_height'] &&
				$file['extension'] == ($config['thumb_ext'] ? $config['thumb_ext'] : $file['extension'])) {
			
				// Copy, because there's nothing to resize
				copy($file['tmp_name'], $file['thumb']);
			
				$file['thumbwidth'] = $image->size->width;
				$file['thumbheight'] = $image->size->height;
			} else {
				$thumb = $image->resize(
					$config['thumb_ext'] ? $config['thumb_ext'] : $file['extension'],
					$post['op'] ? $config['thumb_op_width'] : $config['thumb_width'],
					$post['op'] ? $config['thumb_op_height'] : $config['thumb_height']
				);
				
				$thumb->to($file['thumb']);
			
				$file['thumbwidth'] = $thumb->width;
				$file['thumbheight'] = $thumb->height;
			
				$thumb->_destroy();
			}
			
			if ($config['redraw_image'] || (!@$file['exif_stripped'] && $config['strip_exif'] && ($file['extension'] == 'jpg' || $file['extension'] == 'jpeg'))) {
				if (!$config['redraw_image'] && $config['use_exiftool']) {
					if($error = shell_exec_error('exiftool -overwrite_original -ignoreMinorErrors -q -q -all= ' .
						escapeshellarg($file['tmp_name'])))
						error(_('Could not strip EXIF metadata!'), null, $error);
				} else {
					$image->to($file['file']);
					$dont_copy_file = true;
				}
			}
			$image->destroy();
		} else {
			// not an image
			//copy($config['file_thumb'], $post['thumb']);
			$file['thumb'] = 'file';

			$size = @getimagesize(sprintf($config['file_thumb'],
				isset($config['file_icons'][$file['extension']]) ?
					$config['file_icons'][$file['extension']] : $config['file_icons']['default']));
			$file['thumbwidth'] = $size[0];
			$file['thumbheight'] = $size[1];

			// an MP4 or WebM video gets a frame as its thumbnail, when the server has ffmpeg (inc/nichan-video.php)
			if (in_array($file['extension'], array('mp4', 'webm')) && !($config['spoiler_images'] && isset($_POST['spoiler'])) &&
					($t = nichan_video_thumb($file['tmp_name'], $board['dir'] . $config['dir']['thumb'] . $file['file_id'] . '.webp',
						$post['op'] ? $config['thumb_op_width'] : $config['thumb_width'], $post['op'] ? $config['thumb_op_height'] : $config['thumb_height']))) {
				$file['thumb'] = $file['file_id'] . '.webp';
				list($file['thumbwidth'], $file['thumbheight'], $file['width'], $file['height']) = $t;
			}
		}

		if ($config['tesseract_ocr'] && $file['thumb'] != 'file') { // Let's OCR it!
			$fname = $file['tmp_name'];

			if ($file['height'] > 500 || $file['width'] > 500) {
				$fname = $file['thumb'];
			}

			if ($fname == 'spoiler') { // We don't have that much CPU time, do we?
			}
			else {
				$tmpname = "tmp/tesseract/".rand(0,10000000);

				// Preprocess command is an ImageMagick b/w quantization
				$error = shell_exec_error(sprintf($config['tesseract_preprocess_command'], escapeshellarg($fname)) . " | " .
                                                          'tesseract stdin '.escapeshellarg($tmpname).' '.$config['tesseract_params']);
				$tmpname .= ".txt";

				$value = @file_get_contents($tmpname);
				@unlink($tmpname);

				if ($value && trim($value)) {
					// This one has an effect, that the body is appended to a post body. So you can write a correct
					// spamfilter.
					$post['body_nomarkup'] .= "<tinyboard ocr image $key>".htmlspecialchars($value)."</tinyboard>";
				}
			}
		}
		
		if (!isset($dont_copy_file) || !$dont_copy_file) {
			if (isset($file['file_tmp'])) {
				if (!@rename($file['tmp_name'], $file['file']))
					error($config['error']['nomove']);
				chmod($file['file'], 0644);
			} elseif (!@move_uploaded_file($file['tmp_name'], $file['file']))
				error($config['error']['nomove']);
			}
		}
		// no GPS position, phone model etc. in published pictures (inc/nichan-metadata.php)
		nichan_strip_metadata($file['file'], $file['extension']);
		if (isset($file['thumb']) && !in_array($file['thumb'], array('spoiler', 'file')))
			nichan_strip_metadata($file['thumb']);

		if ($config['image_reject_repost']) {
			if ($p = getPostByHash($post['filehash'])) {
				undoImage($post);
				error(sprintf($config['error']['fileexists'], 
					($post['mod'] ? $config['root'] . $config['file_mod'] . '?/' : $config['root']) .
					($board['dir'] . $config['dir']['res'] .
						($p['thread'] ?
							$p['thread'] . '.html#' . $p['id']
						:
							$p['id'] . '.html'
						))
				));
			}
		} else if (!$post['op'] && $config['image_reject_repost_in_thread']) {
			if ($p = getPostByHashInThread($post['filehash'], $post['thread'])) {
				undoImage($post);
				error(sprintf($config['error']['fileexistsinthread'], 
					($post['mod'] ? $config['root'] . $config['file_mod'] . '?/' : $config['root']) .
					($board['dir'] . $config['dir']['res'] .
						($p['thread'] ?
							$p['thread'] . '.html#' . $p['id']
						:
							$p['id'] . '.html'
						))
				));
			}
		}
		}
	
	// Do filters again if OCRing
	if ($config['tesseract_ocr'] && !hasPermission($config['mod']['bypass_filters'], $board['uri'])) {
		do_filters($post);
	}

	if (!hasPermission($config['mod']['postunoriginal'], $board['uri']) && $config['robot_enable'] && checkRobot($post['body_nomarkup'])) {
		undoImage($post);
		if ($config['robot_mute']) {
			error(sprintf($config['error']['muted'], mute()));
		} else {
			error($config['error']['unoriginal']);
		}
	}
	
	// Remove board directories before inserting them into the database.
	if ($post['has_file']) {
		foreach ($post['files'] as $key => &$file) {
			$file['file_path'] = $file['file'];
			$file['thumb_path'] = $file['thumb'];
			$file['file'] = mb_substr($file['file'], mb_strlen($board['dir'] . $config['dir']['img']));
			if ($file['is_an_image'] && $file['thumb'] != 'spoiler')
				$file['thumb'] = mb_substr($file['thumb'], mb_strlen($board['dir'] . $config['dir']['thumb']));
		}
	}
	
	$post = (object)$post;
	$post->files = array_map(function($a) { return (object)$a; }, $post->files);

	$error = event('post', $post);
	$post->files = array_map(function($a) { return (array)$a; }, $post->files);

	if ($error) {
		undoImage((array)$post);
		error($error);
	}
	$post = (array)$post;

	if ($post['files'])
		$post['files'] = $post['files'];
	$post['num_files'] = sizeof($post['files']);
	
	// a new thread started with "ОП-мітка" marks its author's replies (kept below, or when a moderator approves it)
	$post['nichan_opmark'] = $post['op'] && !empty($_POST['nichan_opmark']);
	// premoderation (a raid, or switched on in mod.php): the new thread waits for a moderator
	if ($post['op'] && !$mod && nichan_premod_until($board['uri'])) {
		list($pending_id, $pending_token) = nichan_premod_add($post);
		nichan_premod_respond($pending_id, $pending_token, $post);
	}

	$post['id'] = $id = post($post);
	if ($post['nichan_opmark'])
		nichan_opmark_save($board['uri'], $id, $_SERVER['REMOTE_ADDR'], $post['password']);
	if (!$mod)
		nichan_flood_log($_SERVER['REMOTE_ADDR'], $post['op']);
	nichan_board_alert_check($board['uri']); // many posts on the board: tell the moderators
	nichan_push_replies($board['uri'], $post); // the authors of the posts it answers get a push notification
	$post['slug'] = slugify($post);
	

	insertFloodPost($post);

	// Handle cyclical threads
	if (!$post['op'] && isset($thread['cycle']) && $thread['cycle']) {
		// Query is a bit weird due to "This version of MariaDB doesn't yet support 'LIMIT & IN/ALL/ANY/SOME subquery'" (MariaDB Ver 15.1 Distrib 10.0.17-MariaDB, for Linux (x86_64))
		$query = prepare(sprintf('DELETE FROM ``posts_%s`` WHERE `thread` = :thread AND `id` NOT IN (SELECT `id` FROM (SELECT `id` FROM ``posts_%s`` WHERE `thread` = :thread ORDER BY `id` DESC LIMIT :limit) i)', $board['uri'], $board['uri']));
		$query->bindValue(':thread', $post['thread']);
		$query->bindValue(':limit', $config['cycle_limit'], PDO::PARAM_INT);
		$query->execute() or error(db_error($query));
	}
	
	if (isset($post['antispam_hash'])) {
		incrementSpamHash($post['antispam_hash']);
	}
	
	if (isset($post['tracked_cites']) && !empty($post['tracked_cites'])) {
		$insert_rows = array();
		foreach ($post['tracked_cites'] as $cite) {
			$insert_rows[] = '(' .
				$pdo->quote($board['uri']) . ', ' . (int)$id . ', ' .
				$pdo->quote($cite[0]) . ', ' . (int)$cite[1] . ')';
		}
		query('INSERT INTO ``cites`` VALUES ' . implode(', ', $insert_rows)) or error(db_error());
	}
	
	if (!$post['op'] && strtolower($post['email']) != 'sage' && !$thread['sage'] && ($config['reply_limit'] == 0 || $numposts['replies']+1 < $config['reply_limit'])) {
		bumpThread($post['thread']);
	}
	
	if (isset($_SERVER['HTTP_REFERER'])) {
		// Tell Javascript that we posted successfully
		if (isset($_COOKIE[$config['cookies']['js']]))
			$js = json_decode($_COOKIE[$config['cookies']['js']]);
		else
			$js = (object) array();
		// Tell it to delete the cached post for referer
		$js->{$_SERVER['HTTP_REFERER']} = true;
		// Encode and set cookie
		setcookie($config['cookies']['js'], json_encode($js), 0, $config['cookies']['jail'] ? $config['cookies']['path'] : '/', null, false, false);
	}
	
	$root = $post['mod'] ? $config['root'] . $config['file_mod'] . '?/' : $config['root'];
	
	if ($noko) {
		$redirect = $root . $board['dir'] . $config['dir']['res'] .
			link_for($post, false, false, $thread) . (!$post['op'] ? '#' . $id : '');
	   	
		if (!$post['op'] && isset($_SERVER['HTTP_REFERER'])) {
			$regex = array(
				'board' => str_replace('%s', '(\w{1,8})', preg_quote($config['board_path'], '/')),
				'page' => str_replace('%d', '(\d+)', preg_quote($config['file_page'], '/')),
				'page50' => '(' . str_replace('%d', '(\d+)', preg_quote($config['file_page50'], '/')) . '|' .
						  str_replace(array('%d', '%s'), array('(\d+)', '[a-z0-9-]+'), preg_quote($config['file_page50_slug'], '/')) . ')',
				'res' => preg_quote($config['dir']['res'], '/'),
			);

			if (preg_match('/\/' . $regex['board'] . $regex['res'] . $regex['page50'] . '([?&].*)?$/', $_SERVER['HTTP_REFERER'])) {
				$redirect = $root . $board['dir'] . $config['dir']['res'] .
					link_for($post, true, false, $thread) . (!$post['op'] ? '#' . $id : '');
			}
		}
	} else {
		$redirect = $root . $board['dir'] . $config['file_index'];
		
	}

	buildThread($post['op'] ? $id : $post['thread']);
	// "create a continuation" of a thread at the reply limit: the two threads link to each other
	if ($post['op'] && !empty($_POST['nichan_continues']))
		nichan_continuation_save($board['uri'], (int)$_POST['nichan_continues'], $id);
	
	if ($config['syslog'])
		_syslog(LOG_INFO, 'New post: /' . $board['dir'] . $config['dir']['res'] .
			link_for($post) . (!$post['op'] ? '#' . $id : ''));
	
	if (!$post['mod']) header('X-Associated-Content: "' . $redirect . '"');

	if (defined('NICHAN_API')) {
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('ok' => true, 'board' => $board['uri'], 'id' => (int)$id,
			'thread' => (int)($post['op'] ? $id : $post['thread']), 'url' => $redirect));
	} elseif (!isset($_POST['json_response'])) {
		header('Location: ' . $redirect, true, $config['redirect_http']);
	} else {
		header('Content-Type: text/json; charset=utf-8');
		echo json_encode(array(
			'redirect' => $redirect,
			'noko' => $noko,
			'id' => $id
		));
	}
	
	if ($config['try_smarter'] && $post['op'])
		$build_pages = range(1, $config['max_pages']);
	
	if ($post['op'])
		clean($id);
	
	event('post-after', $post);
	
	buildIndex();

	// We are already done, let's continue our heavy-lifting work in the background (if we run off FastCGI)
	if (function_exists('fastcgi_finish_request'))
		@fastcgi_finish_request();

	if ($post['op'])
		rebuildThemes('post-thread', $board['uri']);
	else
		rebuildThemes('post', $board['uri']);
	
} elseif (isset($_POST['appeal'])) {
	if (!isset($_POST['ban_id']))
		error($config['error']['bot']);
	
	$ban_id = (int)$_POST['ban_id'];
	
	$bans = Bans::find($_SERVER['REMOTE_ADDR']);
	foreach ($bans as $_ban) {
		if ($_ban['id'] == $ban_id) {
			$ban = $_ban;
			break;
		}
	}
	
	if (!isset($ban)) {
		error(_("That ban doesn't exist or is not for you."));
	}
	
	if ($ban['expires'] && $ban['expires'] - $ban['created'] <= $config['ban_appeals_min_length']) {
		error(_("You cannot appeal a ban of this length."));
	}
	
	$query = query("SELECT `denied` FROM ``ban_appeals`` WHERE `ban_id` = $ban_id") or error(db_error());
	$ban_appeals = $query->fetchAll(PDO::FETCH_COLUMN);
	
	if (count($ban_appeals) >= $config['ban_appeals_max']) {
		error(_("You cannot appeal this ban again."));
	}
	
	foreach ($ban_appeals as $is_denied) {
		if (!$is_denied)
			error(_("There is already a pending appeal for this ban."));
	}
	
	// nichan: some words are needed, and not a whole book
	$appeal = trim(mb_substr((string)$_POST['appeal'], 0, 2000));
	if ($appeal === '')
		error(strpos($config['locale'], 'en') === 0 ? 'Write why the ban should be lifted.' : 'Напишіть, чому бан варто зняти.');

	$query = prepare("INSERT INTO ``ban_appeals`` VALUES (NULL, :ban_id, :time, :message, 0)");
	$query->bindValue(':ban_id', $ban_id, PDO::PARAM_INT);
	$query->bindValue(':time', time(), PDO::PARAM_INT);
	$query->bindValue(':message', $appeal);
	$query->execute() or error(db_error($query));
	// the moderators' chat hears of it, with buttons to lift the ban or reject the appeal
	nichan_telegram_appeal_new((int)$GLOBALS['pdo']->lastInsertId(), $ban, $appeal);

	displayBan($ban);
} else {
	if (!file_exists($config['has_installed'])) {
		header('Location: install.php', true, $config['redirect_http']);
	} else {
		// They opened post.php in their browser manually.
		error($config['error']['nopost']);
	}
}
