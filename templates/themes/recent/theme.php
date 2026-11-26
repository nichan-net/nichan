<?php
	require 'info.php';
	
	function recentposts_build($action, $settings, $board) {
		// Possible values for $action:
		//	- all (rebuild everything, initialization)
		//	- news (news has been updated)
		//	- boards (board list changed)
		//	- post (a post has been made)
		//	- post-thread (a thread has been made)
		
		$b = new RecentPosts();
		$b->build($action, $settings);
	}

	// Keep inline formatting in the linked preview. Only emit our own tags and
	// escape text after parsing so literal user markup remains visible as text.
	function recent_snippet_html($body, $limit = 240) {
		$remaining = $limit;
		$truncated = false;
		$text = function ($value) use (&$remaining, &$truncated) {
			$length = mb_strlen($value, 'UTF-8');
			if ($length > $remaining) {
				$value = mb_substr($value, 0, $remaining, 'UTF-8') . '…';
				$truncated = true;
			}
			$remaining -= min($length, $remaining);
			return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		};
		if (strpos($body, '<') === false)
			return $text(html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

		$doc = new DOMDocument();
		if (!$doc->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><body>' . $body . '</body>',
			LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING))
			return '';
		$render = function ($node) use (&$render, &$truncated, $text) {
			if ($node instanceof DOMText)
				return $text($node->data);
			if (!($node instanceof DOMElement) || in_array($node->tagName, array('script', 'style', 'template'), true))
				return '';
			if ($node->tagName === 'br')
				return $text(' ');
			if ($node->tagName === 'math' && $node->hasAttribute('aria-label'))
				return $text($node->getAttribute('aria-label'));

			$html = '';
			foreach ($node->childNodes as $child) {
				$html .= $render($child);
				if ($truncated) break;
			}
			if ($html === '') return '';
			if (in_array($node->tagName, array('b', 'strong', 'i', 'em', 'u', 's', 'strike', 'del', 'code', 'sub', 'sup'), true))
				return '<' . $node->tagName . '>' . $html . '</' . $node->tagName . '>';
			if ($node->tagName === 'span') {
				$classes = array_intersect(array('spoiler', 'heading', 'quote'), preg_split('/\s+/', $node->getAttribute('class')));
				if ($classes)
					return '<span class="' . implode(' ', $classes) . '">' . $html . '</span>';
			}
			return $html;
		};
		return $render($doc->getElementsByTagName('body')->item(0));
	}

	function recent_is_image_thumb($thumb) {
		return is_string($thumb) && preg_match('/^[a-z0-9_-][a-z0-9_.-]*\.(?:jpe?g|gif|png|webp|avif)$/iD', $thumb);
	}

	// Called with the attachment's board open, so its thumbnail directory and file icons apply.
	function recent_attachment_thumbs($files, $show_spoilers) {
		global $config;
		$thumbs = array();
		$attachments = json_decode((string)$files, true);
		if (!is_array($attachments)) return $thumbs;
		foreach ($attachments as $attachment) {
			if (empty($attachment['file']) || $attachment['file'] === 'deleted') continue;
			$thumb = $attachment['thumb'] ?? '';
			if ($thumb === 'deleted') continue;
			if ($thumb === 'spoiler') {
				if (!$show_spoilers) continue;
				$thumbs[] = $config['root'] . $config['spoiler_image'];
			} elseif (recent_is_image_thumb($thumb)) {
				$thumbs[] = $config['uri_thumb'] . $thumb;
			} else {
				$ext = strtolower(pathinfo($attachment['file'], PATHINFO_EXTENSION));
				$icon = $config['file_icons'][$ext] ?? $config['file_icons']['default'];
				$thumbs[] = $config['root'] . sprintf($config['file_thumb'], $icon);
			}
		}
		return $thumbs;
	}
	// Wrap functions in a class so they don't interfere with normal Tinyboard operations
	
	class RecentPosts {
		public $excluded = array();
		private $popular_expires = 0;

		public function build($action, $settings) {
			global $config;
			
			if ($action == 'all') {
				copy('templates/themes/recent/' . $settings['basecss'], $config['dir']['home'] . $settings['css']);
			}
			
			// Boards left out (mod.php?/nichan-front) and every non-public board never show up on the front page.
			$this->excluded = nichan_front_excluded();
			
			if (in_array($action, array('all', 'post', 'post-thread', 'post-delete', 'news', 'popular-expired'), true)) {
				$path = $config['dir']['home'] . $settings['html'];
				$default_home = basename($path) === 'recent.html' && realpath(dirname($path)) === realpath('.');
				$deadline = 'tmp/front-popular-until';
				$lock = fopen('tmp/front-page.lock', 'c');
				if (!$lock || !flock($lock, LOCK_EX))
					throw new RuntimeException('Could not lock the home page');
				try {
					clearstatcache(true, $deadline);
					if ($action === 'popular-expired' && (!$default_home || !is_file($deadline) || (int)file_get_contents($deadline) > time()))
						return;
					$this->writeHomepage($settings, $path, $default_home, $deadline, $action);
				} finally {
					fclose($lock);
				}
			}
		}

		private function writeHomepage($settings, $path, $default_home, $deadline, $action) {
			$classic_file = pathinfo($settings['html'], PATHINFO_FILENAME) . '-classic.html';
			$classic_path = dirname($path) . '/' . $classic_file;
			$classic_url = preg_replace('~[^/]+$~', $classic_file, $settings['html']);
			$strategy = $action === 'popular-expired' ? 'rebuild' : generation_strategy('sb_recent', array());
			if ($strategy === 'delete') {
				file_unlink($path);
				file_unlink($classic_path);
			} elseif ($strategy === 'rebuild') {
				$pages = $this->homepages($settings, $default_home ? '' : $settings['html'], $classic_url);
				// Enable the deadline-aware route before publishing a time-sensitive page.
				if ($default_home && $this->popular_expires && file_put_contents($deadline, (string)$this->popular_expires) === false)
					throw new RuntimeException('Could not write the popular threads deadline');
				file_write($classic_path, $pages['classic']);
				file_write($path, $pages['modern']);
			} else {
				return;
			}
			if ($default_home && !$this->popular_expires && is_file($deadline))
				unlink($deadline);
		}
		
		// Fetch images independently: a run of text replies must not empty the classic gallery.
		private function gallery($boards, $front) {
			$limit = $front['images'];
			if (!$limit || !$boards) return array();
			$parts = array();
			foreach ($boards as $b) {
				$parts[] = sprintf("(SELECT id, thread, slug, time, subject, body_nomarkup, files, '%s' AS board
					FROM ``posts_%s`` WHERE files IS NOT NULL %s ORDER BY time DESC, id DESC LIMIT %d)",
					$b['uri'], $b['uri'], $front['images_op'] ? 'AND thread IS NULL' : '', $limit * 5 + 5);
			}
			$result = query(implode(' UNION ALL ', $parts) . ' ORDER BY time DESC, id DESC, board LIMIT ' . ($limit * 5 + 5)) or error(db_error());
			$images = array();
			while (count($images) < $limit && ($post = $result->fetch(PDO::FETCH_ASSOC))) {
				if (nichan_front_blocked($post['subject'] . "\n" . $post['body_nomarkup'])) continue;
				foreach (json_decode($post['files'], true) ?: array() as $file) {
					if (empty($file['file']) || $file['file'] === 'deleted') continue;
					$thumb = $file['thumb'] ?? '';
					if (!(($thumb === 'spoiler' && $front['images_spoilers']) || recent_is_image_thumb($thumb))) continue;
					$post['thumb'] = $thumb;
					$images[] = $post;
					break;
				}
			}
			return $images;
		}

		private function homepages($settings, $modern_url, $classic_url) {
			global $config, $board;
			$original_board = isset($board['uri']) ? $board['uri'] : false;
			
			$front = nichan_front_settings();
			$settings['limit_posts'] = $front['posts'];
			$recent_posts = Array();
			$boards = array_filter(listBoards(), function ($b) {
				return !in_array($b['uri'], $this->excluded, true);
			});
			
			$query = '';
			foreach ($boards as $_board) {
				$query .= sprintf("(SELECT p.id, p.thread, p.time, p.body, p.body_nomarkup, p.files, p.subject,
					CASE WHEN p.thread IS NULL THEN p.subject ELSE op.subject END AS thread_subject,
					CASE WHEN p.thread IS NULL THEN p.slug ELSE op.slug END AS thread_slug, '%s' AS board
					FROM ``posts_%s`` p LEFT JOIN ``posts_%s`` op ON op.id = p.thread
					ORDER BY p.time DESC, p.id DESC LIMIT %d) UNION ALL ",
					$_board['uri'], $_board['uri'], $_board['uri'], (int)$settings['limit_posts'] * 2 + 5);
			}
			$query = preg_replace('/UNION ALL $/', 'ORDER BY `time` DESC, `id` DESC, `board` LIMIT ' . ((int)$settings['limit_posts'] * 2 + 5), $query);
			if ($boards)
				$query = query($query) or error(db_error());
			
			while ($boards && ($post = $query->fetch(PDO::FETCH_ASSOC))) {
				if (count($recent_posts) >= (int)$settings['limit_posts'])
					break;
				if (nichan_front_blocked($post['subject'] . "\n" . $post['body_nomarkup']))
					continue;
				if (nichan_front_blocked($post['thread_subject']))
					$post['thread_subject'] = '';
				if ($post['body'] != "") {
					$post['snippet_html'] = recent_snippet_html($post['body']);
					$post['classic_snippet'] = recent_snippet_html($post['body'], 80);
				} else
					$post['snippet'] = _("(no comment)");
				$recent_posts[] = $post;
			}
			// The OP's stored bump honours sage, moderator actions and each board's bump limit.
			$threads = array();
			$limit = (int)$settings['limit_posts'];
			if ($limit && $boards) {
				$parts = array();
				foreach ($boards as $b) {
					$parts[] = sprintf("(SELECT id, bump, subject, slug, body, body_nomarkup, files, '%s' AS board
						FROM ``posts_%s`` WHERE thread IS NULL ORDER BY bump DESC, id DESC LIMIT %d)", $b['uri'], $b['uri'], $limit * 2 + 5);
				}
				$sql = implode(' UNION ALL ', $parts) . ' ORDER BY bump DESC, id DESC, board';
				$result = query($sql) or error(db_error());
				while (count($threads) < $limit && ($op = $result->fetch(PDO::FETCH_ASSOC))) {
					if (nichan_front_blocked($op['subject'] . "\n" . $op['body_nomarkup'])) continue;
					$op['snippet_html'] = recent_snippet_html($op['body']);
					$threads[] = $op;
				}
			}

			// Resolve board-specific URL formats once per board while preserving both feeds' order.
			$images = $this->gallery($boards, $front);
			$posts_by_board = $threads_by_board = $images_by_board = array();
			foreach ($recent_posts as $i => $post) $posts_by_board[$post['board']][] = $i;
			foreach ($threads as $i => $op) $threads_by_board[$op['board']][] = $i;
			foreach ($images as $i => $image) $images_by_board[$image['board']][] = $i;
			foreach (array_keys($posts_by_board + $threads_by_board + $images_by_board) as $uri) {
				openBoard($uri);
				$prefix = $config['root'] . $board['dir'] . $config['dir']['res'];
				foreach ($posts_by_board[$uri] ?? array() as $i) {
					$post = $recent_posts[$i];
					$recent_posts[$i]['board_name'] = $board['name'];
					$thread = array('id' => $post['thread'] ?: $post['id'], 'slug' => (string)$post['thread_slug']);
					$recent_posts[$i]['link'] = $prefix . link_for($thread) . '#' . $post['id'];
					$recent_posts[$i]['thumbs'] = recent_attachment_thumbs($recent_posts[$i]['files'], $front['images_spoilers']);
				}
				foreach ($threads_by_board[$uri] ?? array() as $i) {
					$threads[$i]['link'] = $prefix . link_for($threads[$i]);
					$threads[$i]['thumbs'] = recent_attachment_thumbs($threads[$i]['files'], $front['images_spoilers']);
				}
				foreach ($images_by_board[$uri] ?? array() as $i) {
					$image = $images[$i];
					$images[$i]['link'] = $prefix . link_for($image) . '#' . $image['id'];
					$images[$i]['src'] = $image['thumb'] === 'spoiler' ? $config['root'] . $config['spoiler_image'] : $config['uri_thumb'] . $image['thumb'];
				}
			}
			// Render the public home with site-wide language, assets and theme defaults.
			$board = false;
			loadConfig();
			nichan_apply_site_settings();
			$popular = $front['popular_count'] ? nichan_popular_threads() : null;
			$this->popular_expires = $popular['expires'] ?? 0;
			$data = array(
				'settings' => $settings,
				'config' => $config,
				'portal_section' => 'home',
				'modern_url' => $config['root'] . $modern_url,
				'classic_url' => $config['root'] . $classic_url,
				'recent_posts' => $recent_posts,
				'boards' => nichan_boards_for(false),
				'threads' => $threads,
				'popular' => $popular,
				'news' => nichan_news_active(10)
			);
			$pages['modern'] = Element('themes/recent/recent.html', $data);
			$data['classic_home'] = true;
			$data['recent_images'] = $images;
			$logo = trim((string)nichan_setting('site_logo'));
			$data['classic_logo'] = $config['nichan_site']['header_image']
				? $config['root'] . $config['nichan_site']['header_image']
				: ($logo === '-' ? '' : ($logo ?: $config['root'] . 'images/78.png'));
			$data['totals'] = nichan_site_totals($this->excluded);
			$data['speed_table'] = nichan_stats_speed_table(nichan_stats(NICHAN_STATS_MAX_AGE, true));
			$pages['classic'] = Element('themes/recent/classic.html', $data);
			if ($original_board !== false) openBoard($original_board);
			return $pages;
		}
	};
	
?>
