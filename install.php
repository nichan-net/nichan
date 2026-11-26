<?php
declare(strict_types=1);

chdir(__DIR__);
const INSTALL_KEY = __DIR__ . '/inc/install.key';
const INSTALL_PROGRESS = __DIR__ . '/inc/install-progress';
const INSTALL_CONFIG = __DIR__ . '/inc/instance-config.php';

function setupClosed(): bool {
    return is_file('.installed') || (is_file(INSTALL_CONFIG) && !is_file(INSTALL_PROGRESS));
}

if (PHP_SAPI === 'cli') {
    if (($argv[1] ?? '') !== '--token' || setupClosed()) {
        fwrite(STDERR, "На новій копії виконайте php install.php --token\n");
        exit(1);
    }
    $key = bin2hex(random_bytes(32));
    umask(0077);
    $file = @fopen(INSTALL_KEY, 'x');
    if (!$file) {
        fwrite(STDERR, "Не вдалося створити inc/install.key. Якщо файл уже існує, використайте ключ із цього файлу.\n");
        exit(1);
    }
    if (fwrite($file, $key . "\n") !== strlen($key) + 1 || !fclose($file)) {
        unlink(INSTALL_KEY);
        exit(1);
    }
    echo $key, "\n";
    exit;
}

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
if (setupClosed()) {
    http_response_code(403);
    exit('Встановлення завершено. Панель керування доступна за адресою /mod.php.');
}
if (isset($_GET['license'])) {
    header('Content-Type: text/plain; charset=utf-8');
    readfile(__DIR__ . '/LICENSE.md');
    exit;
}
define('NICHAN_INSTALL', true);
require __DIR__ . '/inc/install-setup.php';

$issues = [];
if (PHP_VERSION_ID < 80100) $issues[] = 'Потрібна PHP 8.1 або новіша.';
foreach (['pdo_mysql', 'gd', 'mbstring', 'fileinfo', 'dom', 'intl', 'curl', 'zip'] as $ext) {
    if (!extension_loaded($ext)) $issues[] = 'Увімкніть розширення PHP ' . $ext . '.';
}
if (!function_exists('imagettftext') || !function_exists('imagewebp')) $issues[] = 'Для капчі та мініатюр потрібна GD з FreeType і WebP.';
foreach (['.', 'inc', 'tmp', 'tmp/cache', 'tmp/locks', 'templates/cache', 'static', 'static/flags', 'a', 'b', 'int', 'all'] as $dir) {
    if (!is_writable($dir)) $issues[] = 'Дозвольте користувачу PHP запис у ' . $dir . '/.';
}
$key = is_readable(INSTALL_KEY) ? trim((string)file_get_contents(INSTALL_KEY)) : '';
if (!preg_match('/^[A-Fa-f0-9]{64}$/D', $key)) $issues[] = 'Створіть одноразовий ключ за інструкцією в README.';
$notice = '';
$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$issues) {
    if (!hash_equals($key, installField('token'))) {
        http_response_code(403);
        $notice = 'Неправильний ключ встановлення.';
    } else {
        $lock = fopen(__DIR__ . '/tmp/install.lock', 'c');
        try {
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new DomainException('Інше встановлення вже виконується.');
            clearstatcache();
            if (setupClosed()) throw new DomainException('Сайт уже встановлено.');
            if (!is_file(INSTALL_CONFIG)) installDatabase();
            // Bootstrap at file scope: the engine and the theme builders share globals.
            $__version = '5.1.4';
            require __DIR__ . '/inc/functions.php';
            nichan_write_site_assets();
            buildJavascript();
            foreach (listBoards() as $setupBoard) {
                openBoard($setupBoard['uri']);
                buildIndex();
                nichan_archive_build_page($setupBoard['uri']);
            }
            unset($board);
            loadConfig();
            nichan_apply_site_settings();
            rebuildThemes('all');
            foreach (['main.js','recent.html','recent-classic.html','all/index.html','a/index.html','b/index.html','int/index.html'] as $path) {
                if (!is_file($path) || !filesize($path)) throw new RuntimeException('Не вдалося згенерувати сторінки.');
            }
            installPrivateFile(__DIR__ . '/.installed', $__version . "\n");
            unlink(INSTALL_KEY);
            unlink(INSTALL_PROGRESS);
            $done = true;
        } catch (DomainException $e) {
            http_response_code(409);
            $notice = $e->getMessage();
        } catch (PDOException $e) {
            http_response_code(422);
            $notice = 'Помилка бази даних (SQLSTATE ' . substr((string)$e->getCode(), 0, 5) . '). Перевірте реквізити та права користувача на створення таблиць.';
        } catch (Throwable $e) {
            http_response_code(500);
            $notice = 'Встановлення перервано. Перевірте права на запис і журнал PHP, потім продовжте з тим самим ключем.';
            error_log('Nichan install: ' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
        } finally {
            if ($lock) fclose($lock);
        }
    }
}
$resume = is_file(INSTALL_CONFIG) && is_file(INSTALL_PROGRESS);
?>
<!doctype html>
<html lang="uk"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Встановлення Нічану</title>
<style>
:root{color-scheme:light dark}body{font:1rem/1.5 system-ui,sans-serif;max-width:40rem;margin:3rem auto;padding:0 1rem}h1{font-size:1.8rem}h2{font-size:1.2rem}
fieldset{border:1px solid #888;border-radius:.4rem;margin:1rem 0;padding:1rem}label{display:block;margin:.65rem 0}input{display:block;box-sizing:border-box;padding:.5rem;width:100%;font:inherit}
button{font:inherit;padding:.6rem 1rem;cursor:pointer}code{overflow-wrap:anywhere}p[role=alert]{border-inline-start:3px solid #be5936;padding-inline-start:.7rem}
</style><main><h1>Встановлення Нічану</h1>
<?php if ($done): ?>
<p>Сайт готовий. Створено дошки <a href="/a/">/a/</a>, <a href="/b/">/b/</a> та <a href="/int/">/int/</a>.</p>
<p><a href="/">Відкрити головну</a> · <a href="/mod.php">Увійти до панелі керування</a></p>
<?php else: ?>
<?php if ($notice !== ''): ?><p role="alert"><?= htmlspecialchars($notice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif ?>
<?php if ($issues): ?><h2>Що потрібно підготувати</h2><ul><?php foreach ($issues as $issue): ?><li><?= htmlspecialchars($issue, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach ?></ul>
<?php else: ?>
<form method="post" action="/install.php">
<label>Одноразовий ключ із <code>inc/install.key</code><input name="token" type="password" required maxlength="64" autocomplete="off"></label>
<?php if (!$resume): ?>
<label>Публічна адреса сайту<input name="public_url" type="url" placeholder="https://board.example.org" required></label>
<fieldset><legend>Порожня база даних</legend>
<label>Сервер<input name="db_host" value="localhost" required></label><label>Порт<input name="db_port" type="number" value="3306" min="1" max="65535" required></label>
<label>Назва бази<input name="db_name" required></label><label>Користувач бази<input name="db_user" required></label>
<label>Пароль бази<input name="db_password" type="password" autocomplete="off"></label></fieldset>
<fieldset><legend>Перший адміністратор</legend><label>Логін<input name="admin" maxlength="30" required></label>
<label>Пароль (щонайменше 12 символів)<input name="admin_password" type="password" minlength="12" autocomplete="new-password" required></label></fieldset>
<?php endif ?><button type="submit"><?= $resume ? 'Завершити побудову сторінок' : 'Встановити сайт' ?></button></form>
<?php endif ?><p><a href="?license">Ліцензійні умови</a></p>
<?php endif ?></main></html>

