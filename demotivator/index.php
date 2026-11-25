<?php
// /demotivator/ (the "dm" link): demotivator and meme maker. Pictures are made in the visitor's
// browser (js/nichan-dm.js); nothing is uploaded to or processed on the server.
chdir(dirname(__DIR__));
require_once 'inc/functions.php';

ob_start();
?>
<style>
	@font-face { font-family: 'NichanImpact'; font-weight: 200 700; font-display: block; src: local('Impact'), local('Impact Regular'), url(/stylesheets/fonts/oswald-cyrillic-ext.woff2) format('woff2'); unicode-range: U+0460-052F, U+1C80-1C8A, U+20B4, U+2DE0-2DFF, U+A640-A69F, U+FE2E-FE2F; }
	@font-face { font-family: 'NichanImpact'; font-weight: 200 700; font-display: block; src: local('Impact'), local('Impact Regular'), url(/stylesheets/fonts/oswald-cyrillic.woff2) format('woff2'); unicode-range: U+0301, U+0400-045F, U+0490-0491, U+04B0-04B1, U+2116; }
	@font-face { font-family: 'NichanImpact'; font-weight: 200 700; font-display: block; src: local('Impact'), local('Impact Regular'), url(/stylesheets/fonts/oswald-latin-ext.woff2) format('woff2'); unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF; }
	@font-face { font-family: 'NichanImpact'; font-weight: 200 700; font-display: block; src: local('Impact'), local('Impact Regular'), url(/stylesheets/fonts/oswald-latin.woff2) format('woff2'); unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD; }
	.dm { max-width: 980px; margin: 0 auto; padding: 0 8px; }
	.dm-grid { display: grid; grid-template-columns: minmax(260px, 340px) 1fr; gap: 16px; align-items: start; }
	.dm fieldset { margin: 0 0 10px; }
	.dm label.block { display: block; margin: 6px 0 2px; font-weight: bold; }
	.dm input[type="text"], .dm textarea { width: 100%; box-sizing: border-box; font-size: 15px; }
	.dm textarea { min-height: 3.2em; resize: vertical; }
	.dm-modes { display: flex; gap: 6px; }
	.dm-modes label { flex: 1; text-align: center; cursor: pointer; padding: 6px; border: 1px solid rgba(128,128,128,.6); border-radius: 4px; }
	.dm-modes input { position: absolute; opacity: 0; pointer-events: none; }
	.dm-modes input:checked + span { font-weight: bold; text-decoration: underline; }
	.dm-drop { border: 2px dashed rgba(128,128,128,.6); border-radius: 6px; padding: 18px 10px; text-align: center; cursor: pointer; }
	.dm-drop.over { border-color: #3987e5; }
	.dm-drop input { display: none; }
	.dm-preview { text-align: center; }
	.dm-preview canvas { max-width: 100%; height: auto; box-shadow: 0 2px 8px rgba(0,0,0,.4); }
	.dm-empty { padding: 60px 10px; border: 1px dashed rgba(128,128,128,.5); border-radius: 6px; }
	.dm-actions { margin-top: 10px; display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; }
	.dm-actions button { font-size: 15px; padding: 6px 14px; cursor: pointer; }
	.dm [hidden] { display: none !important; }
	@media (max-width: 720px) { .dm-grid { grid-template-columns: 1fr; } }
</style>
<div class="dm">
	<p class="unimportant" style="text-align:center">Завантажте картинку, оберіть, що зробити, і додайте текст. Картинка обробляється у вашому браузері й нікуди не надсилається.</p>
	<div class="dm-grid">
		<form class="dm-controls" id="dm-form" onsubmit="return false">
			<fieldset>
				<legend>Картинка</legend>
				<label class="dm-drop" id="dm-drop">
					<input type="file" id="dm-file" accept="image/*">
					<span id="dm-drop-text">Оберіть файл, перетягніть його сюди або вставте з буфера (Ctrl+V)</span>
				</label>
			</fieldset>
			<fieldset>
				<legend>Що зробити</legend>
				<div class="dm-modes">
					<label><input type="radio" name="dm-mode" value="demotivator" checked><span>Демотиватор</span></label>
					<label><input type="radio" name="dm-mode" value="meme"><span>Мем</span></label>
				</div>
				<div id="dm-demotivator-fields">
					<label class="block" for="dm-title">Заголовок</label>
					<textarea id="dm-title" maxlength="200" placeholder="ЗАГОЛОВОК"></textarea>
					<label class="block" for="dm-subtitle">Підпис</label>
					<textarea id="dm-subtitle" maxlength="400" placeholder="Дрібніший текст під заголовком"></textarea>
				</div>
				<div id="dm-meme-fields" hidden>
					<label class="block" for="dm-top">Текст зверху</label>
					<textarea id="dm-top" maxlength="200"></textarea>
					<label class="block" for="dm-bottom">Текст знизу</label>
					<textarea id="dm-bottom" maxlength="200"></textarea>
				</div>
				<?php if (nichan_setting('dm_watermark_optional', '0') === '1'): // mod.php -> Сайт: назва й меню ?>
				<label style="display:block;margin-top:8px"><input type="checkbox" id="dm-watermark" checked> підпис «<?= htmlspecialchars($config['nichan_site']['host']) ?>»</label>
				<?php endif; ?>
			</fieldset>
		</form>
		<div class="dm-preview">
			<div class="dm-empty" id="dm-empty">Тут з'явиться результат</div>
			<canvas id="dm-canvas" hidden></canvas>
			<div class="dm-actions" id="dm-actions" hidden>
				<button type="button" id="dm-save">Зберегти картинку</button>
				<button type="button" id="dm-copy" hidden>Копіювати</button>
			</div>
		</div>
	</div>
</div>
<script src="/js/nichan-dm.js"></script>
<?php
$body = ob_get_clean();

echo Element('portal.html', array(
	'portal_section' => 'dm',
	'config' => $config,
	'title' => 'Демотиватори та меми',
	'subtitle' => false,
	'body' => $body,
));
