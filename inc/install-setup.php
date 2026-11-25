<?php
// Викликається лише з вебмайстра після перевірки одноразового ключа.
defined('NICHAN_INSTALL') or exit;

function installField(string $name): string {
    return is_string($_POST[$name] ?? null) ? $_POST[$name] : '';
}

function installPrivateFile(string $path, string $contents): void {
    $tmp = tempnam(dirname($path), 'install-');
    if ($tmp === false) throw new RuntimeException('Не вдалося створити тимчасовий файл.');
    try {
        if (!chmod($tmp, 0600) || file_put_contents($tmp, $contents) !== strlen($contents) || !rename($tmp, $path)) {
            throw new RuntimeException('Не вдалося записати файл налаштувань. Перевірте права на запис.');
        }
    } finally {
        if (file_exists($tmp)) unlink($tmp);
    }
}

function installDatabase(): void {
    $host = trim(installField('db_host'));
    $port = filter_var(installField('db_port'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    $name = trim(installField('db_name'));
    $user = trim(installField('db_user'));
    $admin = trim(installField('admin'));
    $password = installField('admin_password');
    $url = rtrim(trim(installField('public_url')), '/');
    $parts = parse_url($url);
    if (!$port || !preg_match('/^[a-z0-9._:-]+$/iD', $host) || !preg_match('/^[a-z0-9_$-]{1,64}$/iD', $name) || $user === '') {
        throw new DomainException('Перевірте ім’я сервера, порт, назву бази та ім’я користувача.');
    }
    if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true)
        || empty($parts['host']) || isset($parts['path']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
        throw new DomainException('Вкажіть повну адресу кореня сайту, наприклад https://board.example.org.');
    }
    if (!preg_match('/^[\pL\pN_.-]{1,30}$/uD', $admin) || mb_strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
        throw new DomainException('Логін має містити 1–30 літер, цифр, крапок, дефісів чи підкреслень. Пароль — щонайменше 12 символів і щонайбільше 72 байти.');
    }
    $db = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, installField('db_password'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 8,
    ]);
    $db->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
    $lock = $db->prepare('SELECT GET_LOCK(?, 5)');
    $lock->execute(['nichan-install-' . substr(hash('sha256', $name), 0, 40)]);
    if ((int)$lock->fetchColumn() !== 1) throw new DomainException('База зайнята іншим встановленням. Спробуйте ще раз.');

    $id = hash('sha256', serialize([$host, $port, $name, $user, $admin, $url]));
    if (is_file(INSTALL_PROGRESS)) {
        if (!hash_equals(trim(file_get_contents(INSTALL_PROGRESS)), $id)) {
            throw new DomainException('Для продовження вкажіть ті самі базу, адресу сайту та логін адміністратора.');
        }
    } else {
        if ($db->query('SHOW FULL TABLES')->fetch() !== false) {
            throw new DomainException('У базі вже є таблиці. Для нового сайту потрібна окрема порожня база.');
        }
        installPrivateFile(INSTALL_PROGRESS, $id);
    }

    $schema = file_get_contents(__DIR__ . '/install-schema.sql');
    foreach (explode(";\n", trim(str_replace("\r\n", "\n", $schema)) . "\n") as $statement) {
        if (trim($statement) !== '') $db->exec($statement);
    }
    $boards = ['a' => 'Аніме та манґа', 'b' => 'Балачки', 'int' => 'International'];
    $postTable = file_get_contents(dirname(__DIR__) . '/templates/posts.sql');
    $addBoard = $db->prepare('INSERT INTO boards (uri, title) VALUES (?, ?) ON DUPLICATE KEY UPDATE uri = VALUES(uri)');
    foreach ($boards as $uri => $title) {
        $db->exec(str_replace(['{{ board }}', '``'], [$uri, '`'], $postTable));
        $addBoard->execute([$uri, $title]);
    }
    $themes = [
        'recent' => ['title' => 'Нічан', 'basecss' => 'recent.css', 'css' => 'recent.css', 'exclude' => '', 'html' => 'recent.html', 'limit_posts' => '15'],
        'ukko' => ['exclude' => '', 'subtitle' => 'Свіжі нитки з усіх дошок', 'thread_limit' => '15', 'title' => 'Усі нитки', 'uri' => 'all'],
        'catalog' => ['boards' => 'a b int', 'title' => 'Каталог', 'update_on_posts' => '0', 'use_tooltipster' => '1'],
        'sitemap' => ['boards' => 'a b int', 'changefreq' => 'hourly', 'path' => 'sitemap.xml', 'regen_time' => '', 'url' => $url . '/'],
        'rss' => ['base_url' => $url, 'exclude' => '', 'limit_posts' => '30', 'title' => 'Нові дописи', 'xml' => 'recent.xml'],
    ];
    $addSetting = $db->prepare('INSERT INTO theme_settings (theme, name, value) SELECT ?, ?, ? FROM DUAL WHERE NOT EXISTS
        (SELECT 1 FROM theme_settings WHERE theme = ? AND name <=> ?)');
    foreach ($themes as $theme => $settings) {
        $addSetting->execute([$theme, null, null, $theme, null]);
        foreach ($settings as $key => $value) $addSetting->execute([$theme, $key, $value, $theme, $key]);
    }
    $existing = $db->query('SELECT username, password FROM mods LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        if ($existing['username'] !== $admin || !password_verify($password, $existing['password'])) {
            throw new DomainException('Для продовження введіть початкові облікові дані адміністратора.');
        }
    } else {
        $db->prepare("INSERT INTO mods (username, password, version, type, boards) VALUES (?, ?, 1, 30, '*')")
           ->execute([$admin, password_hash($password, PASSWORD_BCRYPT)]);
    }

    $values = ['db_host' => $host, 'db_dsn' => "port=$port", 'db_name' => $name, 'db_user' => $user,
        'db_password' => installField('db_password'), 'public_url' => $url,
        'cookie_salt' => bin2hex(random_bytes(32)), 'trip_salt' => bin2hex(random_bytes(32))];
    $replace = [];
    foreach ($values as $key => $value) $replace["'{{" . $key . "}}'"] = var_export($value, true);
    installPrivateFile(INSTALL_CONFIG, strtr(file_get_contents(__DIR__ . '/instance-config.example.php'), $replace));
}

