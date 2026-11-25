<?php

/*
 * Built-in public pages. Their HTML overrides live in nichan_settings.
 */

defined('TINYBOARD') or exit;

function nichan_builtin_pages() {
	return array(
		'faq_uk' => array('title' => 'FAQ (українською)', 'url' => 'rules.php'),
		'faq_en' => array('title' => 'FAQ (English, /int/)', 'url' => 'intrules.php'),
		'extras' => array('title' => 'Цікавинки', 'url' => 'extras.php'),
	);
}

function nichan_builtin_html($key) {
	global $config;
	$html = nichan_setting($key);
	if ($html !== null) return $html;
	return $key === 'extras' ? Element('portal_extras.html', array('config' => $config)) : nichan_faq_default($key);
}

// Renders rules.php / intrules.php.
function nichan_faq_page($lang) {
	global $config;

	// No edit link here: the page is public and shouldn't point at the admin panel.
	$en = $lang == 'en';
	$body = '<div class="nichan-faq">';
	$body .= '<p class="nichan-faq-lang">' . ($en
		? '<a href="/rules.php">Ця сторінка українською</a>'
		: '<a href="/intrules.php">This page in English</a>') . '</p>';
	$body .= nichan_builtin_html($en ? 'faq_en' : 'faq_uk') . '</div>';
	$body .= '<style>
		.nichan-faq { max-width: 820px; margin: 0 auto; padding: 0 10px; line-height: 1.5; }
		.nichan-faq h2 { margin-top: 1.4em; border-bottom: 1px solid rgba(128,128,128,.4); padding-bottom: 3px; }
		.nichan-faq-lang { text-align: center; }
		.nichan-faq dt { font-weight: bold; margin-top: .6em; }
		.nichan-faq dd { margin-left: 1.2em; }
		.nichan-faq code { white-space: pre-wrap; }
	</style>';

	echo Element('portal.html', array(
		'portal_section' => 'about',
		'portal_en' => $en,
		'config' => $config,
		'title' => $en ? 'Information and rules' : 'Інформація та правила',
		'subtitle' => false,
		'body' => $body,
	));
}

// Keep old FAQ bookmarks and already-open edit forms working.
function mod_faq($lang = 'uk') {
	mod_builtin_page($lang === 'en' ? 'faq_en' : 'faq_uk');
}

function mod_builtin_page($key) {
	global $config;
	if (!hasPermission($config['mod']['nichan_edit_faq']))
		error($config['error']['noaccess']);
	$page = nichan_builtin_pages()[$key];
	$route = 'edit_pages/site/' . $key;

	if (isset($_POST['faq_html'])) {
		if (isset($_POST['reset']))
			nichan_delete_setting($key);
		elseif (is_string($_POST['faq_html']))
			nichan_set_setting($key, hasPermission($config['mod']['rawhtml']) ? $_POST['faq_html'] : purify_html($_POST['faq_html']));
		else
			error($config['error']['bot']);
		modLog('Edited site page: ' . $key);
		header('Location: ' . $config['root'] . $page['url'], true, $config['redirect_http']);
		return;
	}
	mod_page($page['title'], 'mod/faq.html', array(
		'url' => $config['root'] . $page['url'],
		'route' => $route,
		'html' => nichan_builtin_html($key),
		'token' => make_secure_link_token($route),
	));
}

function nichan_faq_default($key) {
	if ($key == 'faq_en') {
		return <<<'HTML'
<h2>About the site</h2>
<p>Welcome to Nichan, an anonymous Ukrainian imageboard. There’s no registration here. Everyone is anonymous, and anyone can start a thread or reply to one. In a world of algorithms, targeted ads, and a dossier on every visitor, we’re a little island of freedom and independence. The “Name” field on the reply form is optional, and we encourage you to leave it blank.</p>

<p>You can talk about anything on <a href="/b/">/b/ – Balachky</a> and <a href="/int/">/int/ – International</a>. Each of the other boards has its own topic. Use the buttons at the top of the page to switch boards.</p>

<h2 id="rules">Rules</h2>
<ul>
	<li>Use Ukrainian for discussions on the site. Limited use of other languages in images or quotations is allowed as long as the conversation continues in Ukrainian. The exception is <a href="/int/">/int/</a>, where you can post in any language.</li>
	<li>All visitors must be adults.</li>
	<li>Do not create or share content that violates Ukrainian or international law.</li>
	<li>Spam (posts made solely to advertise) and board wiping (flooding a board with repetitive posts to disrupt other people’s conversations) are prohibited.</li>
	<li>Do not spread anti-Ukrainian propaganda.</li>
</ul>

<p id="report">If you see someone breaking the rules, click the ▶ next to the post number and choose “Report” from the menu. Your report goes straight to a moderator.</p>
HTML;
	}

	return <<<'HTML'
<h2>Про сайт</h2>
<p>Вітаємо на Нічані – українському анонімному іміджборді. Це форум, на якому реєстрація відсутня. Усі відвідувачі анонімні, створювати нові теми і відповідати на них можуть усі. У світі алгоритмів, таргетованих реклам і досьє на кожного відвідувача, ми є острівцем свободи і незалежності. І хоч у формі для відповіді є поле "ім'я", воно не обов'язкове і ми заохочуємо вас лишити його порожнім.</p>

<p>Розділ <a href="/b/">/b/ - Балачки</a> та <a href="/int/">/int/ - International</a> не мають конкретної тематики, решта дошок тематичні. Переходити по дошкам можна натиснувши на відповідні кнопки у верхній частині сайту.</p>

<h2 id="rules">Правила</h2>
<ul>
	<li>Основною мовою спілкування на сайті є лише українська. Дозволяється обмежене використання інших мов у картинках чи цитатах, за умови, що спілкування продовжується українською. Виключення - дошка <a href="/int/">/int/</a>, спілкування на якій дозволене будь-якими мовами.</li>
	<li>Усі відвідувачі мають бути повнолітніми.</li>
	<li>Заборонено створювати чи поширювати контент, який суперечить українському чи міжнародному праву.</li>
	<li>Спам (повідомлення суто рекламного характеру) і вайп (надсилання однотипних повідомлень з метою ускладнити спілкування іншим) теж заборонені.</li>
	<li>Заборонено поширення антиукраїнської пропаганди.</li>
</ul>

<p id="report">Якщо ви бачите, що хтось порушує правила, ви можете поскаржитися на нього, натиснувши на ▶ біля номера допису і обравши там пункт "Поскаржитися". Ваша скарга негайно прилетить до модератора.</p>
HTML;
}
