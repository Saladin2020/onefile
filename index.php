<?php
/**
 * Onefile PHP CMS — single-file SPA with install wizard, auth, articles CRUD, settings.
 */
declare(strict_types=1);

// === CONFIG_BEGIN ===
$CONFIG = array(
  'installed' => false,
  'db' => array(
    'driver' => null,
  ),
);
// === CONFIG_END ===

session_start();

/* -------------------------------------------------------------------------- */
/* Shared helpers (available during install and app)                          */
/* -------------------------------------------------------------------------- */

function json_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_ok($data = null): void
{
    json_out(array('ok' => true, 'data' => $data));
}

function json_err(string $message, int $code = 400): void
{
    json_out(array('ok' => false, 'error' => $message), $code);
}

function request_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return $_POST ?: array();
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Swiss-mark logo: black field, white vertical bar, red horizontal bar. */
function logo_svg_markup(): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" role="img" aria-label="Onefile">'
        . '<rect width="32" height="32" fill="#1c1916"/>'
        . '<rect x="6" y="4" width="8" height="24" fill="#f2efe9"/>'
        . '<rect x="6" y="12" width="20" height="8" fill="#7a2e2e"/>'
        . '</svg>';
}

function logo_favicon_data_uri(): string
{
    return 'data:image/svg+xml,' . rawurlencode(logo_svg_markup());
}

function self_source_path(): string
{
    return __FILE__;
}

function self_validate_source(string $content): void
{
    if ($content === '') {
        json_err('File content is empty');
    }
    if (strlen($content) > 5 * 1024 * 1024) {
        json_err('File too large (max 5MB)');
    }
    $trim = ltrim($content, "\xEF\xBB\xBF");
    if (strpos($trim, '<?php') !== 0) {
        json_err('Source must start with <?php');
    }
    // Split marker literals so self-rewrite regex cannot span this check.
    $markBegin = '// === CONFIG_' . 'BEGIN ===';
    $markEnd = '// === CONFIG_' . 'END ===';
    if (strpos($content, $markBegin) === false || strpos($content, $markEnd) === false) {
        json_err('Missing CONFIG markers');
    }
    if (strpos($content, 'function handle_app_action') === false) {
        json_err('Missing application handler — refused to write broken source');
    }
}

function self_is_php_cli_binary(string $bin): bool
{
    $base = strtolower(basename($bin));
    if ($base === '' || strpos($base, 'httpd') !== false || strpos($base, 'apache') !== false) {
        return false;
    }
    if ($base === 'php-cgi' || $base === 'php-cgi.exe' || $base === 'php-fpm' || $base === 'php-fpm.exe') {
        return false;
    }
    return (bool) preg_match('/^php(\d+(\.\d+)*)?(\.exe)?$/', $base);
}

function self_resolve_php_cli(): ?string
{
    $candidates = array();
    if (defined('PHP_BINARY') && PHP_BINARY) {
        $candidates[] = PHP_BINARY;
    }
    if (defined('PHP_BINDIR') && PHP_BINDIR) {
        $candidates[] = rtrim(PHP_BINDIR, '\\/') . DIRECTORY_SEPARATOR . (strncasecmp(PHP_OS, 'WIN', 3) === 0 ? 'php.exe' : 'php');
    }
    // XAMPP/WAMP: PHP_BINARY is often httpd.exe → walk up to {root}/php/php.exe
    if (defined('PHP_BINARY') && PHP_BINARY && stripos(PHP_BINARY, 'httpd') !== false) {
        $root = dirname(dirname(dirname(PHP_BINARY))); // e.g. C:\xampp2
        $candidates[] = $root . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'php.exe';
        $candidates[] = $root . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'php';
    }
    $candidates[] = 'php.exe';
    $candidates[] = 'php';

    foreach ($candidates as $bin) {
        if (!is_string($bin) || $bin === '' || !self_is_php_cli_binary($bin)) {
            continue;
        }
        if ($bin !== 'php' && $bin !== 'php.exe' && !is_file($bin)) {
            continue;
        }
        $out = array();
        $code = 0;
        @exec(escapeshellarg($bin) . ' -v', $out, $code);
        if ($code === 0 && !empty($out) && stripos(implode(' ', $out), 'PHP') !== false) {
            return $bin;
        }
    }
    return null;
}

/**
 * @return array{ok:bool,error:?string,skipped:bool,php:?string}
 */
function self_syntax_check(string $path): array
{
    $php = self_resolve_php_cli();
    if ($php === null) {
        // Do not block writes when CLI php is unavailable (common under Apache on Windows).
        return array('ok' => true, 'error' => null, 'skipped' => true, 'php' => null);
    }
    $cmd = escapeshellarg($php) . ' -l ' . escapeshellarg($path) . ' 2>&1';
    $out = array();
    $code = 0;
    @exec($cmd, $out, $code);
    if ($code !== 0) {
        return array(
            'ok' => false,
            'error' => trim(implode("\n", $out)) ?: 'PHP syntax check failed',
            'skipped' => false,
            'php' => $php,
        );
    }
    return array('ok' => true, 'error' => null, 'skipped' => false, 'php' => $php);
}

function self_write_source(string $content): array
{
    self_validate_source($content);
    $path = self_source_path();
    if (!is_writable($path) && !is_writable(dirname($path))) {
        json_err('index.php is not writable', 500);
    }
    $dir = dirname($path);
    $tmp = $dir . DIRECTORY_SEPARATOR . '.onefile-write-' . bin2hex(random_bytes(4)) . '.tmp';
    $written = file_put_contents($tmp, $content, LOCK_EX);
    if ($written === false) {
        json_err('Cannot write temporary file', 500);
    }
    $syntax = self_syntax_check($tmp);
    if (!$syntax['ok']) {
        @unlink($tmp);
        json_err('Syntax error — write aborted: ' . $syntax['error']);
    }
    // Windows-safe replace (rename over existing file often fails while Apache has it open)
    $backup = $path . '.bak-self';
    if (is_file($path)) {
        @copy($path, $backup);
    }
    $replaced = false;
    if (@rename($tmp, $path)) {
        $replaced = true;
    } else {
        // Prefer overwrite copy when target is locked by the web server
        if (@copy($tmp, $path)) {
            $replaced = true;
            @unlink($tmp);
        }
    }
    if (!$replaced) {
        @unlink($tmp);
        json_err('Cannot replace index.php (file may be locked). Check permissions.', 500);
    }
    @unlink($backup);
    clearstatcache(true, $path);
    return array(
        'path' => $path,
        'size' => strlen($content),
        'mtime' => @filemtime($path),
        'writable' => is_writable($path),
        'syntax_skipped' => !empty($syntax['skipped']),
        'php_cli' => $syntax['php'],
    );
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function require_csrf(array $body): void
{
    $token = isset($body['csrf']) ? (string) $body['csrf'] : '';
    if ($token === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        json_err('Invalid CSRF token', 403);
    }
}

function slugify(string $title): string
{
    $slug = strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug);
    $slug = trim((string) $slug, '-');
    if ($slug === '') {
        $slug = 'post-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }
    return $slug;
}

function now_iso(): string
{
    return gmdate('c');
}

function db_dsn(array $db): string
{
    $driver = isset($db['driver']) ? $db['driver'] : '';
    if ($driver === 'sqlite') {
        $path = isset($db['path']) ? $db['path'] : (__DIR__ . DIRECTORY_SEPARATOR . 'data.sqlite');
        return 'sqlite:' . $path;
    }
    if ($driver === 'mysql') {
        $host = isset($db['host']) ? $db['host'] : '127.0.0.1';
        $port = isset($db['port']) ? (int) $db['port'] : 3306;
        $name = isset($db['name']) ? $db['name'] : '';
        $charset = isset($db['charset']) ? $db['charset'] : 'utf8mb4';
        return sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);
    }
    throw new RuntimeException('Unsupported database driver');
}

function db_connect(array $db): PDO
{
    $driver = isset($db['driver']) ? $db['driver'] : '';
    $dsn = db_dsn($db);
    if ($driver === 'sqlite') {
        $path = isset($db['path']) ? $db['path'] : (__DIR__ . DIRECTORY_SEPARATOR . 'data.sqlite');
        $dir = dirname($path);
        if (!is_dir($dir) && $dir !== '.' && $dir !== '') {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create SQLite directory: ' . $dir);
            }
        }
        $pdo = new PDO($dsn);
    } elseif ($driver === 'mysql') {
        $user = isset($db['user']) ? $db['user'] : '';
        $pass = isset($db['pass']) ? $db['pass'] : '';
        $pdo = new PDO($dsn, $user, $pass);
    } else {
        throw new RuntimeException('Unsupported database driver');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    if ($driver === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}

function migrate(PDO $pdo, string $driver): void
{
    if ($driver === 'sqlite') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS articles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                slug TEXT NOT NULL UNIQUE,
                body TEXT NOT NULL,
                published INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at TEXT NOT NULL,
                username TEXT,
                action TEXT NOT NULL,
                detail TEXT,
                ip TEXT
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS tags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                slug TEXT NOT NULL UNIQUE
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS article_tags (
                article_id INTEGER NOT NULL,
                tag_id INTEGER NOT NULL,
                PRIMARY KEY (article_id, tag_id)
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS article_revisions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                article_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                slug TEXT NOT NULL,
                body TEXT NOT NULL,
                meta_description TEXT NOT NULL DEFAULT \'\',
                created_at TEXT NOT NULL,
                username TEXT
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip TEXT NOT NULL,
                username TEXT NOT NULL,
                attempted_at TEXT NOT NULL
            )'
        );
        migrate_article_columns($pdo, 'sqlite');
        return;
    }

    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(191) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS articles (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                slug VARCHAR(191) NOT NULL UNIQUE,
                body MEDIUMTEXT NOT NULL,
                published TINYINT(1) NOT NULL DEFAULT 0,
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS settings (
                `key` VARCHAR(191) PRIMARY KEY,
                value TEXT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS audit_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                created_at VARCHAR(40) NOT NULL,
                username VARCHAR(191) NULL,
                action VARCHAR(191) NOT NULL,
                detail TEXT NULL,
                ip VARCHAR(64) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS tags (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(191) NOT NULL UNIQUE,
                slug VARCHAR(191) NOT NULL UNIQUE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS article_tags (
                article_id INT NOT NULL,
                tag_id INT NOT NULL,
                PRIMARY KEY (article_id, tag_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS article_revisions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                article_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                slug VARCHAR(191) NOT NULL,
                body MEDIUMTEXT NOT NULL,
                meta_description VARCHAR(320) NOT NULL DEFAULT \'\',
                created_at VARCHAR(40) NOT NULL,
                username VARCHAR(191) NULL,
                INDEX (article_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS login_attempts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip VARCHAR(64) NOT NULL,
                username VARCHAR(191) NOT NULL,
                attempted_at VARCHAR(40) NOT NULL,
                INDEX (ip),
                INDEX (attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        migrate_article_columns($pdo, 'mysql');
        return;
    }

    throw new RuntimeException('Unsupported database driver for migrate');
}

function table_columns(PDO $pdo, string $driver, string $table): array
{
    $cols = array();
    if ($driver === 'sqlite') {
        foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll() as $row) {
            $cols[strtolower($row['name'])] = true;
        }
        return $cols;
    }
    $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
    foreach ($stmt->fetchAll() as $row) {
        $cols[strtolower($row['Field'])] = true;
    }
    return $cols;
}

function migrate_article_columns(PDO $pdo, string $driver): void
{
    $cols = table_columns($pdo, $driver, 'articles');
    $add = function ($name, $sqliteSql, $mysqlSql) use ($pdo, $driver, &$cols) {
        if (!empty($cols[$name])) {
            return;
        }
        $pdo->exec($driver === 'sqlite' ? $sqliteSql : $mysqlSql);
        $cols[$name] = true;
    };
    $add(
        'meta_description',
        'ALTER TABLE articles ADD COLUMN meta_description TEXT NOT NULL DEFAULT \'\'',
        'ALTER TABLE articles ADD COLUMN meta_description VARCHAR(320) NOT NULL DEFAULT \'\''
    );
    $add(
        'pinned',
        'ALTER TABLE articles ADD COLUMN pinned INTEGER NOT NULL DEFAULT 0',
        'ALTER TABLE articles ADD COLUMN pinned TINYINT(1) NOT NULL DEFAULT 0'
    );
    $add(
        'publish_at',
        'ALTER TABLE articles ADD COLUMN publish_at TEXT NULL',
        'ALTER TABLE articles ADD COLUMN publish_at VARCHAR(40) NULL'
    );
}

function publish_due_articles(PDO $pdo): int
{
    $now = now_iso();
    $stmt = $pdo->prepare(
        'UPDATE articles
         SET published = 1, updated_at = ?
         WHERE published = 0
           AND publish_at IS NOT NULL
           AND publish_at != \'\'
           AND publish_at <= ?'
    );
    $stmt->execute(array($now, $now));
    return $stmt->rowCount();
}

function article_revision_save(PDO $pdo, array $article, ?string $username = null): void
{
    $ins = $pdo->prepare(
        'INSERT INTO article_revisions (article_id, title, slug, body, meta_description, created_at, username)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->execute(array(
        (int) $article['id'],
        (string) $article['title'],
        (string) $article['slug'],
        (string) $article['body'],
        isset($article['meta_description']) ? (string) $article['meta_description'] : '',
        now_iso(),
        $username,
    ));
    // Keep last 30 revisions per article
    $ids = $pdo->prepare(
        'SELECT id FROM article_revisions WHERE article_id = ? ORDER BY id DESC'
    );
    $ids->execute(array((int) $article['id']));
    $all = $ids->fetchAll(PDO::FETCH_COLUMN);
    if (count($all) > 30) {
        $drop = array_slice($all, 30);
        $in = implode(',', array_map('intval', $drop));
        if ($in !== '') {
            $pdo->exec('DELETE FROM article_revisions WHERE id IN (' . $in . ')');
        }
    }
}

function markdown_to_html(string $md): string
{
    $md = str_replace(array("\r\n", "\r"), "\n", $md);
    $lines = explode("\n", $md);
    $html = array();
    $inUl = false;
    $inOl = false;
    $para = array();
    $flushPara = function () use (&$html, &$para) {
        if (!$para) {
            return;
        }
        $text = trim(implode(' ', $para));
        if ($text !== '') {
            $html[] = '<p>' . inline_md($text) . '</p>';
        }
        $para = array();
    };
    $closeLists = function () use (&$html, &$inUl, &$inOl) {
        if ($inUl) {
            $html[] = '</ul>';
            $inUl = false;
        }
        if ($inOl) {
            $html[] = '</ol>';
            $inOl = false;
        }
    };
    foreach ($lines as $line) {
        if (preg_match('/^\s*$/', $line)) {
            $flushPara();
            $closeLists();
            continue;
        }
        if (preg_match('/^(#{1,3})\s+(.+)$/', $line, $m)) {
            $flushPara();
            $closeLists();
            $level = strlen($m[1]) + 1; // h2-h4
            if ($level > 3) {
                $level = 3;
            }
            $html[] = '<h' . $level . '>' . inline_md(trim($m[2])) . '</h' . $level . '>';
            continue;
        }
        if (preg_match('/^\s*[-*]\s+(.+)$/', $line, $m)) {
            $flushPara();
            if ($inOl) {
                $html[] = '</ol>';
                $inOl = false;
            }
            if (!$inUl) {
                $html[] = '<ul>';
                $inUl = true;
            }
            $html[] = '<li>' . inline_md(trim($m[1])) . '</li>';
            continue;
        }
        if (preg_match('/^\s*\d+\.\s+(.+)$/', $line, $m)) {
            $flushPara();
            if ($inUl) {
                $html[] = '</ul>';
                $inUl = false;
            }
            if (!$inOl) {
                $html[] = '<ol>';
                $inOl = true;
            }
            $html[] = '<li>' . inline_md(trim($m[1])) . '</li>';
            continue;
        }
        if (preg_match('/^\s*>\s?(.*)$/', $line, $m)) {
            $flushPara();
            $closeLists();
            $html[] = '<blockquote>' . inline_md(trim($m[1])) . '</blockquote>';
            continue;
        }
        $closeLists();
        $para[] = $line;
    }
    $flushPara();
    $closeLists();
    return implode("\n", $html);
}

function inline_md(string $text): string
{
    $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $text = preg_replace('/\[(.+?)\]\((https?:\/\/[^\s)]+|\/uploads\/[^\s)]+|uploads\/[^\s)]+)\)/', '<a href="$2" rel="noopener noreferrer">$1</a>', $text);
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
    $text = preg_replace('/`(.+?)`/', '<code>$1</code>', $text);
    return $text;
}

function base32_encode_secret(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $binary = '';
    foreach (str_split($data) as $char) {
        $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($binary, 5) as $chunk) {
        if (strlen($chunk) < 5) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        }
        $out .= $alphabet[bindec($chunk)];
    }
    return $out;
}

function base32_decode_secret(string $b32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
    $binary = '';
    for ($i = 0, $len = strlen($b32); $i < $len; $i++) {
        $val = strpos($alphabet, $b32[$i]);
        if ($val === false) {
            continue;
        }
        $binary .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($binary, 8) as $chunk) {
        if (strlen($chunk) === 8) {
            $out .= chr(bindec($chunk));
        }
    }
    return $out;
}

function totp_code(string $secretBase32, ?int $timeSlice = null): string
{
    if ($timeSlice === null) {
        $timeSlice = (int) floor(time() / 30);
    }
    $secret = base32_decode_secret($secretBase32);
    $time = pack('N*', 0, $timeSlice);
    $hash = hash_hmac('sha1', $time, $secret, true);
    $offset = ord($hash[19]) & 0xf;
    $value = (
        ((ord($hash[$offset]) & 0x7f) << 24) |
        ((ord($hash[$offset + 1]) & 0xff) << 16) |
        ((ord($hash[$offset + 2]) & 0xff) << 8) |
        (ord($hash[$offset + 3]) & 0xff)
    ) % 1000000;
    return str_pad((string) $value, 6, '0', STR_PAD_LEFT);
}

function totp_verify(string $secretBase32, string $code, int $window = 1): bool
{
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6}$/', $code)) {
        return false;
    }
    $slice = (int) floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code($secretBase32, $slice + $i), $code)) {
            return true;
        }
    }
    return false;
}

function login_is_locked(PDO $pdo, string $ip, string $username): bool
{
    $since = gmdate('c', time() - 900);
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE attempted_at >= ? AND (ip = ? OR username = ?)'
    );
    $stmt->execute(array($since, $ip, $username));
    return (int) $stmt->fetchColumn() >= 5;
}

function login_attempt_record(PDO $pdo, string $ip, string $username): void
{
    $pdo->prepare('INSERT INTO login_attempts (ip, username, attempted_at) VALUES (?, ?, ?)')
        ->execute(array($ip, $username, now_iso()));
    // prune older than 1 day
    $cutoff = gmdate('c', time() - 86400);
    $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute(array($cutoff));
}

function login_attempt_clear(PDO $pdo, string $ip, string $username): void
{
    $pdo->prepare('DELETE FROM login_attempts WHERE ip = ? OR username = ?')
        ->execute(array($ip, $username));
}

function absolute_url(string $path = ''): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $base = rtrim(str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/index.php')), '/');
    if ($base === '') {
        $base = '';
    }
    return $scheme . '://' . $host . $base . '/' . ltrim($path, '/');
}

function reading_time_minutes(string $body): int
{
    $text = trim(html_entity_decode(strip_tags($body), ENT_QUOTES, 'UTF-8'));
    if ($text === '') {
        return 1;
    }
    $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $count = is_array($words) ? count($words) : 0;
    return max(1, (int) ceil($count / 200));
}

function normalize_tag_names($raw): array
{
    if (is_string($raw)) {
        $parts = preg_split('/[,;]+/', $raw);
    } elseif (is_array($raw)) {
        $parts = $raw;
    } else {
        return array();
    }
    $out = array();
    $seen = array();
    foreach ($parts as $part) {
        $name = trim((string) $part);
        if ($name === '') {
            continue;
        }
        if (function_exists('mb_substr')) {
            $name = mb_substr($name, 0, 40);
        } else {
            $name = substr($name, 0, 40);
        }
        $key = strtolower($name);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $name;
        if (count($out) >= 12) {
            break;
        }
    }
    return $out;
}

function tags_ensure(PDO $pdo, array $names): array
{
    $result = array();
    $find = $pdo->prepare('SELECT id, name, slug FROM tags WHERE slug = ? LIMIT 1');
    $ins = $pdo->prepare('INSERT INTO tags (name, slug) VALUES (?, ?)');
    foreach ($names as $name) {
        $slug = slugify($name);
        $find->execute(array($slug));
        $row = $find->fetch();
        if (!$row) {
            try {
                $ins->execute(array($name, $slug));
                $id = (int) $pdo->lastInsertId();
                $row = array('id' => $id, 'name' => $name, 'slug' => $slug);
            } catch (Throwable $e) {
                $find->execute(array($slug));
                $row = $find->fetch();
            }
        }
        if ($row) {
            $result[] = array(
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'slug' => $row['slug'],
            );
        }
    }
    return $result;
}

function article_set_tags(PDO $pdo, int $articleId, array $names): array
{
    $tags = tags_ensure($pdo, $names);
    $pdo->prepare('DELETE FROM article_tags WHERE article_id = ?')->execute(array($articleId));
    $ins = $pdo->prepare('INSERT INTO article_tags (article_id, tag_id) VALUES (?, ?)');
    foreach ($tags as $tag) {
        $ins->execute(array($articleId, $tag['id']));
    }
    return $tags;
}

function article_get_tags(PDO $pdo, int $articleId): array
{
    $stmt = $pdo->prepare(
        'SELECT t.id, t.name, t.slug
         FROM tags t
         INNER JOIN article_tags at ON at.tag_id = t.id
         WHERE at.article_id = ?
         ORDER BY t.name ASC'
    );
    $stmt->execute(array($articleId));
    return $stmt->fetchAll();
}

function tags_list_all(PDO $pdo): array
{
    return $pdo->query(
        'SELECT t.id, t.name, t.slug, COUNT(at.article_id) AS article_count
         FROM tags t
         LEFT JOIN article_tags at ON at.tag_id = t.id
         GROUP BY t.id, t.name, t.slug
         ORDER BY t.name ASC'
    )->fetchAll();
}

function article_neighbors(PDO $pdo, array $article, bool $publicOnly): array
{
    $pubSql = $publicOnly ? ' AND published = 1' : '';
    $prev = $pdo->prepare(
        'SELECT id, title FROM articles
         WHERE (created_at < ? OR (created_at = ? AND id < ?))' . $pubSql . '
         ORDER BY created_at DESC, id DESC LIMIT 1'
    );
    $prev->execute(array($article['created_at'], $article['created_at'], (int) $article['id']));
    $next = $pdo->prepare(
        'SELECT id, title FROM articles
         WHERE (created_at > ? OR (created_at = ? AND id > ?))' . $pubSql . '
         ORDER BY created_at ASC, id ASC LIMIT 1'
    );
    $next->execute(array($article['created_at'], $article['created_at'], (int) $article['id']));
    return array(
        'prev' => $prev->fetch() ?: null,
        'next' => $next->fetch() ?: null,
    );
}

function client_ip(): string
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
}

function audit_log(PDO $pdo, string $action, $detail = null, ?string $username = null): void
{
    try {
        if ($username === null) {
            $username = isset($_SESSION['username']) ? (string) $_SESSION['username'] : null;
        }
        $detailStr = null;
        if ($detail !== null) {
            $detailStr = is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE);
        }
        $stmt = $pdo->prepare('INSERT INTO audit_logs (created_at, username, action, detail, ip) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute(array(now_iso(), $username, $action, $detailStr, client_ip()));
    } catch (Throwable $e) {
        // Never break primary flow because of audit failure
    }
}

// === HTACCESS_TEMPLATE_BEGIN ===
// Single source of truth for Apache rules (lives inside index.php; written out to .htaccess on disk).
$HTACCESS_TEMPLATE = <<<'HTA'
# Onefile CMS — starter Apache rules
# Generated from index.php (HTACCESS_TEMPLATE). Do not rely on hand-edited copies.
# Blocks direct download of database / backup / temp files.

Options -Indexes

<IfModule mod_authz_core.c>
  <FilesMatch "(?i)\.(sqlite|sqlite3|db|sql|bak|log)$">
    Require all denied
  </FilesMatch>
  <FilesMatch "(?i)^(data\.sqlite|.*\.bak-self|\.onefile-write-.*)$">
    Require all denied
  </FilesMatch>
  <FilesMatch "^\.">
    Require all denied
  </FilesMatch>
</IfModule>

<IfModule !mod_authz_core.c>
  <FilesMatch "(?i)\.(sqlite|sqlite3|db|sql|bak|log)$">
    Order allow,deny
    Deny from all
  </FilesMatch>
</IfModule>

DirectoryIndex index.php
HTA;
// === HTACCESS_TEMPLATE_END ===

// === UPLOADS_HTACCESS_TEMPLATE_BEGIN ===
$UPLOADS_HTACCESS_TEMPLATE = <<<'HTA'
# Onefile CMS — uploads protection (from index.php)
# Allow static assets, deny script execution / dangerous types.

Options -Indexes -ExecCGI
<IfModule mod_php.c>
  php_flag engine off
</IfModule>
<IfModule mod_php7.c>
  php_flag engine off
</IfModule>
<IfModule mod_php8.c>
  php_flag engine off
</IfModule>

RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phps .phar .cgi .pl .py .exe .sh .bat .cmd
RemoveType .php .phtml .php3 .php4 .php5 .php7 .php8 .phps .phar

<IfModule mod_authz_core.c>
  <FilesMatch "(?i)\.(php|phtml|phar|cgi|pl|py|exe|sh|bat|cmd|shtml|htaccess)$">
    Require all denied
  </FilesMatch>
</IfModule>
<IfModule !mod_authz_core.c>
  <FilesMatch "(?i)\.(php|phtml|phar|cgi|pl|py|exe|sh|bat|cmd)$">
    Order allow,deny
    Deny from all
  </FilesMatch>
</IfModule>
HTA;
// === UPLOADS_HTACCESS_TEMPLATE_END ===

function htaccess_starter_content(): string
{
    global $HTACCESS_TEMPLATE;
    if (is_string($HTACCESS_TEMPLATE) && $HTACCESS_TEMPLATE !== '') {
        return $HTACCESS_TEMPLATE;
    }
    // Fallback if template block was damaged
    return "# Onefile CMS\nOptions -Indexes\n<FilesMatch \"(?i)\\.(sqlite|sqlite3|db)$\">\n  Require all denied\n</FilesMatch>\nDirectoryIndex index.php\n";
}

function uploads_htaccess_content(): string
{
    global $UPLOADS_HTACCESS_TEMPLATE;
    if (is_string($UPLOADS_HTACCESS_TEMPLATE) && $UPLOADS_HTACCESS_TEMPLATE !== '') {
        return $UPLOADS_HTACCESS_TEMPLATE;
    }
    return "Options -Indexes\n<FilesMatch \"(?i)\\.(php|phtml|phar)$\">\n  Require all denied\n</FilesMatch>\n";
}

function htaccess_path(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . '.htaccess';
}

function htaccess_write(bool $overwrite = false): array
{
    $path = htaccess_path();
    $exists = is_file($path);
    if ($exists && !$overwrite) {
        return array(
            'path' => $path,
            'written' => false,
            'exists' => true,
            'message' => '.htaccess already exists',
            'source' => 'index.php HTACCESS_TEMPLATE',
        );
    }
    $ok = @file_put_contents($path, htaccess_starter_content(), LOCK_EX);
    if ($ok === false) {
        throw new RuntimeException('Cannot write .htaccess');
    }
    return array(
        'path' => $path,
        'written' => true,
        'exists' => true,
        'message' => $exists ? '.htaccess overwritten from index.php' : '.htaccess created from index.php',
        'source' => 'index.php HTACCESS_TEMPLATE',
    );
}

/** Ensure disk .htaccess exists (Apache cannot read rules from PHP). */
function htaccess_ensure(): void
{
    try {
        if (!is_file(htaccess_path())) {
            htaccess_write(false);
        }
    } catch (Throwable $e) {
        // Non-fatal
    }
}

function uploads_dir(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
}

function uploads_url_prefix(): string
{
    return 'uploads/';
}

function uploads_allowed_map(): array
{
    return array(
        'jpg' => array('image/jpeg'),
        'jpeg' => array('image/jpeg'),
        'png' => array('image/png'),
        'gif' => array('image/gif'),
        'webp' => array('image/webp'),
        'pdf' => array('application/pdf'),
        'txt' => array('text/plain'),
        'md' => array('text/plain', 'text/markdown'),
        'zip' => array('application/zip', 'application/x-zip-compressed'),
    );
}

function uploads_max_bytes(): int
{
    return 5 * 1024 * 1024;
}

function uploads_ensure(): void
{
    $dir = uploads_dir();
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create uploads directory');
        }
    }
    $ht = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    // Always refresh protection rules from the template embedded in index.php
    if (@file_put_contents($ht, uploads_htaccess_content(), LOCK_EX) === false) {
        throw new RuntimeException('Cannot write uploads/.htaccess');
    }
    $index = $dir . DIRECTORY_SEPARATOR . 'index.html';
    if (!is_file($index)) {
        @file_put_contents($index, "<!-- forbidden -->\n", LOCK_EX);
    }
}

function uploads_detect_mime(string $path): string
{
    if (function_exists('finfo_open')) {
        $f = finfo_open(FILEINFO_MIME_TYPE);
        if ($f) {
            $mime = finfo_file($f, $path);
            finfo_close($f);
            if (is_string($mime) && $mime !== '') {
                return strtolower($mime);
            }
        }
    }
    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($path);
        if (is_string($mime) && $mime !== '') {
            return strtolower($mime);
        }
    }
    return 'application/octet-stream';
}

function uploads_safe_basename(string $name): string
{
    $name = basename(str_replace(array('\\', '/'), DIRECTORY_SEPARATOR, $name));
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
    $name = trim((string) $name, '.-_');
    if ($name === '') {
        $name = 'file';
    }
    // Collapse double extensions like file.php.jpg — keep only last ext after sanitize
    return $name;
}

function uploads_ext_from_name(string $name): string
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return preg_replace('/[^a-z0-9]/', '', $ext);
}

function uploads_list(): array
{
    uploads_ensure();
    $dir = uploads_dir();
    $files = array();
    $dh = opendir($dir);
    if (!$dh) {
        return $files;
    }
    $allowed = uploads_allowed_map();
    while (($entry = readdir($dh)) !== false) {
        if ($entry === '.' || $entry === '..' || $entry === '.htaccess' || $entry === 'index.html') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if (!is_file($path)) {
            continue;
        }
        $ext = uploads_ext_from_name($entry);
        if (!isset($allowed[$ext])) {
            continue;
        }
        $files[] = array(
            'name' => $entry,
            'url' => uploads_url_prefix() . rawurlencode($entry),
            'size' => filesize($path),
            'mtime' => filemtime($path),
            'ext' => $ext,
            'is_image' => in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp'), true),
        );
    }
    closedir($dh);
    usort($files, function ($a, $b) {
        return $b['mtime'] <=> $a['mtime'];
    });
    return $files;
}

function uploads_handle_upload(array $file): array
{
    uploads_ensure();
    if (!isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed (code ' . (isset($file['error']) ? (int) $file['error'] : -1) . ')');
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Invalid upload');
    }
    $size = isset($file['size']) ? (int) $file['size'] : 0;
    if ($size < 1 || $size > uploads_max_bytes()) {
        throw new RuntimeException('File too large (max 5MB)');
    }
    $original = uploads_safe_basename(isset($file['name']) ? (string) $file['name'] : 'file');
    $ext = uploads_ext_from_name($original);
    $allowed = uploads_allowed_map();
    if ($ext === '' || !isset($allowed[$ext])) {
        throw new RuntimeException('File type not allowed');
    }
    // Reject multi-dot tricks like evil.php.jpg already reduced by pathinfo last ext,
    // but also reject if basename contains .php. etc.
    if (preg_match('/\.(php|phtml|phar|cgi|pl|exe|sh|bat|cmd)(\.|$)/i', $original)) {
        throw new RuntimeException('Dangerous filename rejected');
    }
    $mime = uploads_detect_mime($file['tmp_name']);
    if (!in_array($mime, $allowed[$ext], true)) {
        // jpeg sometimes reported as image/pjpeg
        if (!($ext === 'jpg' || $ext === 'jpeg') || strpos($mime, 'jpeg') === false) {
            throw new RuntimeException('MIME type mismatch (' . $mime . ')');
        }
    }
    $stored = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = uploads_dir() . DIRECTORY_SEPARATOR . $stored;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Cannot save uploaded file');
    }
    @chmod($dest, 0644);
    return array(
        'name' => $stored,
        'original' => $original,
        'url' => uploads_url_prefix() . rawurlencode($stored),
        'size' => filesize($dest),
        'mime' => $mime,
        'ext' => $ext,
        'is_image' => in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp'), true),
    );
}

function uploads_delete(string $name): void
{
    $name = basename($name);
    if ($name === '' || $name === '.htaccess' || $name === 'index.html') {
        throw new RuntimeException('Invalid file');
    }
    $ext = uploads_ext_from_name($name);
    if (!isset(uploads_allowed_map()[$ext])) {
        throw new RuntimeException('Invalid file');
    }
    $path = uploads_dir() . DIRECTORY_SEPARATOR . $name;
    if (!is_file($path)) {
        throw new RuntimeException('File not found');
    }
    if (!@unlink($path)) {
        throw new RuntimeException('Cannot delete file');
    }
}

function build_backup_payload(PDO $pdo, string $driver): array
{
    $settings = settings_get_all($pdo, $driver);
    $articles = $pdo->query('SELECT id, title, slug, body, published, created_at, updated_at FROM articles ORDER BY id ASC')->fetchAll();
    $users = $pdo->query('SELECT id, username, password_hash FROM users ORDER BY id ASC')->fetchAll();
    $audits = $pdo->query('SELECT id, created_at, username, action, detail, ip FROM audit_logs ORDER BY id ASC LIMIT 5000')->fetchAll();
    return array(
        'format' => 'onefile-backup',
        'version' => 1,
        'exported_at' => now_iso(),
        'settings' => $settings,
        'articles' => $articles,
        'users' => $users,
        'audit_logs' => $audits,
    );
}

function settings_set(PDO $pdo, string $driver, string $key, string $value): void
{
    if ($driver === 'mysql') {
        $stmt = $pdo->prepare('INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
        $stmt->execute(array($key, $value));
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $stmt->execute(array($key, $value));
}

function settings_get_all(PDO $pdo, string $driver): array
{
    if ($driver === 'mysql') {
        $stmt = $pdo->query('SELECT `key` AS k, value AS v FROM settings');
    } else {
        $stmt = $pdo->query('SELECT key AS k, value AS v FROM settings');
    }
    $out = array();
    foreach ($stmt->fetchAll() as $row) {
        $out[$row['k']] = $row['v'];
    }
    return $out;
}

function default_settings(): array
{
    return array(
        'site_title' => 'Onefile CMS',
        'theme' => 'system',
        'posts_per_page' => '10',
        'site_intro' => 'A home for the unusual, the unfinished, and the carefully kept.',
        'totp_enabled' => '0',
        'totp_secret' => '',
    );
}

// === INSTALL_BEGIN ===
function install_env_checks(): array
{
    $phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
    $checks = array(
        array('id' => 'php', 'label' => 'PHP >= 7.4', 'ok' => $phpOk, 'detail' => PHP_VERSION),
        array('id' => 'pdo', 'label' => 'PDO extension', 'ok' => extension_loaded('pdo'), 'detail' => extension_loaded('pdo') ? 'loaded' : 'missing'),
        array('id' => 'pdo_sqlite', 'label' => 'pdo_sqlite', 'ok' => extension_loaded('pdo_sqlite'), 'detail' => extension_loaded('pdo_sqlite') ? 'loaded' : 'missing'),
        array('id' => 'pdo_mysql', 'label' => 'pdo_mysql', 'ok' => extension_loaded('pdo_mysql'), 'detail' => extension_loaded('pdo_mysql') ? 'loaded' : 'missing'),
        array('id' => 'session', 'label' => 'session', 'ok' => function_exists('session_start'), 'detail' => 'ok'),
        array('id' => 'json', 'label' => 'json', 'ok' => function_exists('json_encode'), 'detail' => 'ok'),
        array(
            'id' => 'dir_writable',
            'label' => 'Directory writable',
            'ok' => is_writable(__DIR__),
            'detail' => __DIR__,
        ),
        array(
            'id' => 'self_writable',
            'label' => 'index.php writable',
            'ok' => is_writable(__FILE__),
            'detail' => __FILE__,
        ),
    );
    return $checks;
}

function install_normalize_db(array $input): array
{
    $driver = isset($input['driver']) ? (string) $input['driver'] : '';
    if ($driver === 'sqlite') {
        $path = isset($input['path']) && trim((string) $input['path']) !== ''
            ? (string) $input['path']
            : (__DIR__ . DIRECTORY_SEPARATOR . 'data.sqlite');
        if ($path[0] !== '/' && !preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
            $path = __DIR__ . DIRECTORY_SEPARATOR . ltrim(str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $path), '/\\');
        }
        return array('driver' => 'sqlite', 'path' => $path);
    }
    if ($driver === 'mysql') {
        return array(
            'driver' => 'mysql',
            'host' => isset($input['host']) && $input['host'] !== '' ? (string) $input['host'] : '127.0.0.1',
            'port' => isset($input['port']) ? (int) $input['port'] : 3306,
            'name' => isset($input['name']) ? (string) $input['name'] : '',
            'user' => isset($input['user']) ? (string) $input['user'] : '',
            'pass' => isset($input['pass']) ? (string) $input['pass'] : '',
            'charset' => 'utf8mb4',
        );
    }
    throw new InvalidArgumentException('Choose sqlite or mysql');
}

function install_build_config_block(array $config): string
{
    $export = var_export($config, true);
    return "// === CONFIG_BEGIN ===\n\$CONFIG = " . $export . ";\n// === CONFIG_END ===";
}

function install_self_finalize(array $newConfig): void
{
    $src = file_get_contents(__FILE__);
    if ($src === false) {
        throw new RuntimeException('Cannot read index.php for self-edit');
    }

    $configBlock = install_build_config_block($newConfig);
    $replaced = preg_replace(
        '/\/\/ === CONFIG_BEGIN ===.*?\/\/ === CONFIG_END ===/s',
        $configBlock,
        $src,
        1,
        $countConfig
    );
    if ($replaced === null || $countConfig !== 1) {
        throw new RuntimeException('Failed to replace CONFIG block');
    }

    $stripped = preg_replace(
        '/\/\/ === INSTALL_BEGIN ===.*?\/\/ === INSTALL_END ===\s*/s',
        '',
        $replaced,
        1,
        $countInstall
    );
    if ($stripped === null || $countInstall !== 1) {
        throw new RuntimeException('Failed to remove INSTALL block');
    }

    if (strpos($stripped, '// === INSTALL_BEGIN ===') !== false) {
        throw new RuntimeException('INSTALL markers still present after strip');
    }
    if (strpos($stripped, '// === CONFIG_BEGIN ===') === false) {
        throw new RuntimeException('CONFIG markers missing after rewrite');
    }

    $ok = file_put_contents(__FILE__, $stripped, LOCK_EX);
    if ($ok === false) {
        throw new RuntimeException('Cannot write index.php (permission denied). Database may already be initialized; fix file permissions and retry carefully.');
    }
}

function handle_setup_action(string $action, array $CONFIG): void
{
    if (!empty($CONFIG['installed'])) {
        json_err('Already installed', 403);
    }

    if ($action === 'setup_check') {
        $checks = install_env_checks();
        $canSqlite = false;
        $canMysql = false;
        $requiredOk = true;
        foreach ($checks as $c) {
            if ($c['id'] === 'pdo_sqlite') {
                $canSqlite = $c['ok'];
                continue;
            }
            if ($c['id'] === 'pdo_mysql') {
                $canMysql = $c['ok'];
                continue;
            }
            if (!$c['ok'] && in_array($c['id'], array('php', 'pdo', 'session', 'json', 'dir_writable', 'self_writable'), true)) {
                $requiredOk = false;
            }
        }
        if (!$canSqlite && !$canMysql) {
            $requiredOk = false;
        }
        json_ok(array(
            'checks' => $checks,
            'can_continue' => $requiredOk && ($canSqlite || $canMysql),
            'drivers' => array(
                'sqlite' => $canSqlite,
                'mysql' => $canMysql,
            ),
            'default_sqlite_path' => 'data.sqlite',
        ));
    }

    if ($action === 'setup_test_db') {
        $body = request_json();
        try {
            $db = install_normalize_db($body);
            if ($db['driver'] === 'sqlite' && !extension_loaded('pdo_sqlite')) {
                json_err('pdo_sqlite is not available');
            }
            if ($db['driver'] === 'mysql') {
                if (!extension_loaded('pdo_mysql')) {
                    json_err('pdo_mysql is not available');
                }
                if ($db['name'] === '') {
                    json_err('Database name is required');
                }
            }
            $pdo = db_connect($db);
            $pdo->query('SELECT 1');
            json_ok(array('message' => 'Connection successful', 'driver' => $db['driver']));
        } catch (Throwable $e) {
            json_err('Connection failed: ' . $e->getMessage());
        }
    }

    if ($action === 'setup_install') {
        $body = request_json();
        try {
            $checks = install_env_checks();
            foreach ($checks as $c) {
                if (in_array($c['id'], array('php', 'pdo', 'session', 'json', 'dir_writable', 'self_writable'), true) && !$c['ok']) {
                    json_err('Environment check failed: ' . $c['label']);
                }
            }

            $db = install_normalize_db(isset($body['db']) && is_array($body['db']) ? $body['db'] : $body);
            $siteTitle = isset($body['site_title']) ? trim((string) $body['site_title']) : 'Onefile CMS';
            $username = isset($body['username']) ? trim((string) $body['username']) : '';
            $password = isset($body['password']) ? (string) $body['password'] : '';
            $password2 = isset($body['password_confirm']) ? (string) $body['password_confirm'] : '';

            if ($siteTitle === '') {
                $siteTitle = 'Onefile CMS';
            }
            if ($username === '' || strlen($username) < 3) {
                json_err('Username must be at least 3 characters');
            }
            if (strlen($password) < 8) {
                json_err('Password must be at least 8 characters');
            }
            if ($password !== $password2) {
                json_err('Passwords do not match');
            }
            if ($db['driver'] === 'mysql' && $db['name'] === '') {
                json_err('Database name is required');
            }

            $pdo = db_connect($db);
            migrate($pdo, $db['driver']);

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
            try {
                $stmt->execute(array($username, $hash));
            } catch (Throwable $e) {
                // Re-install against existing DB: update admin password instead
                $upd = $pdo->prepare('UPDATE users SET password_hash = ? WHERE username = ?');
                $upd->execute(array($hash, $username));
                if ($upd->rowCount() < 1) {
                    throw $e;
                }
                $find = $pdo->prepare('SELECT id FROM users WHERE username = ?');
                $find->execute(array($username));
                $row = $find->fetch();
                $userId = $row ? (int) $row['id'] : 0;
            }
            if (!isset($userId)) {
                $userId = (int) $pdo->lastInsertId();
            }

            $defaults = default_settings();
            $defaults['site_title'] = $siteTitle;
            foreach ($defaults as $k => $v) {
                settings_set($pdo, $db['driver'], $k, (string) $v);
            }

            try {
                htaccess_write(false);
                uploads_ensure();
            } catch (Throwable $e) {
                // Non-fatal during install
            }

            audit_log($pdo, 'install', array('driver' => $db['driver'], 'site_title' => $siteTitle), $username);

            $newConfig = array(
                'installed' => true,
                'installed_at' => now_iso(),
                'db' => $db,
            );

            try {
                install_self_finalize($newConfig);
            } catch (Throwable $e) {
                json_err('Database initialized, but could not self-edit index.php: ' . $e->getMessage() . ' Fix permissions, set installed=true manually, and remove the INSTALL block.', 500);
            }

            $_SESSION['user_id'] = $userId;
            $_SESSION['username'] = $username;
            session_regenerate_id(true);
            csrf_token();

            json_ok(array(
                'message' => 'Installation complete',
                'username' => $username,
            ));
        } catch (Throwable $e) {
            json_err('Install failed: ' . $e->getMessage(), 500);
        }
    }

    json_err('Unknown setup action', 404);
}

if (empty($CONFIG['installed'])) {
    $action = isset($_GET['action']) ? (string) $_GET['action'] : '';
    if ($action !== '' && strpos($action, 'setup_') === 0) {
        handle_setup_action($action, $CONFIG);
    }
}
// === INSTALL_END ===

/* -------------------------------------------------------------------------- */
/* Application                                                                */
/* -------------------------------------------------------------------------- */

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    return array(
        'id' => (int) $_SESSION['user_id'],
        'username' => isset($_SESSION['username']) ? (string) $_SESSION['username'] : '',
    );
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        json_err('Authentication required', 401);
    }
    return $user;
}

function str_truncate_text(string $text, int $len): string
{
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text) <= $len) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $len - 1)) . '…';
    }
    if (strlen($text) <= $len) {
        return $text;
    }
    return rtrim(substr($text, 0, $len - 1)) . '...';
}

function article_excerpt(string $body, int $len = 170): string
{
    $text = html_entity_decode(strip_tags($body), ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', trim((string) $text));
    if ($text === '') {
        return '';
    }
    return str_truncate_text($text, $len);
}

function article_cover_image(string $body): ?string
{
    if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $body, $m)) {
        $src = trim($m[1]);
        if ($src !== '' && preg_match('#^(https?://|/|uploads/|\./uploads/)#i', $src)) {
            return $src;
        }
    }
    return null;
}

function unique_slug(PDO $pdo, string $base, ?int $excludeId = null): string
{
    $slug = $base;
    $i = 2;
    while (true) {
        if ($excludeId === null) {
            $stmt = $pdo->prepare('SELECT id FROM articles WHERE slug = ? LIMIT 1');
            $stmt->execute(array($slug));
        } else {
            $stmt = $pdo->prepare('SELECT id FROM articles WHERE slug = ? AND id != ? LIMIT 1');
            $stmt->execute(array($slug, $excludeId));
        }
        if (!$stmt->fetch()) {
            return $slug;
        }
        $slug = $base . '-' . $i;
        $i++;
    }
}

function handle_app_action(string $action, array $CONFIG): void
{
    if (empty($CONFIG['installed'])) {
        json_err('Not installed', 503);
    }

    try {
        $pdo = db_connect($CONFIG['db']);
        migrate($pdo, $CONFIG['db']['driver']);
        publish_due_articles($pdo);
    } catch (Throwable $e) {
        json_err('Database connection failed', 500);
    }
    $driver = $CONFIG['db']['driver'];

    if ($action === 'rss') {
        $settings = array_merge(default_settings(), settings_get_all($pdo, $driver));
        $stmt = $pdo->query(
            'SELECT id, title, slug, body, meta_description, created_at, updated_at
             FROM articles WHERE published = 1
             ORDER BY pinned DESC, created_at DESC, id DESC LIMIT 30'
        );
        $items = $stmt->fetchAll();
        header('Content-Type: application/rss+xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<rss version="2.0"><channel>';
        echo '<title>' . h($settings['site_title']) . '</title>';
        echo '<link>' . h(absolute_url()) . '</link>';
        echo '<description>' . h($settings['site_intro']) . '</description>';
        foreach ($items as $item) {
            $link = absolute_url('index.php#/articles/' . (int) $item['id']);
            $desc = $item['meta_description'] !== '' ? $item['meta_description'] : article_excerpt((string) $item['body'], 280);
            echo '<item>';
            echo '<title>' . h($item['title']) . '</title>';
            echo '<link>' . h($link) . '</link>';
            echo '<guid>' . h($link) . '</guid>';
            echo '<pubDate>' . h(gmdate('D, d M Y H:i:s O', strtotime($item['created_at']) ?: time())) . '</pubDate>';
            echo '<description>' . h($desc) . '</description>';
            echo '</item>';
        }
        echo '</channel></rss>';
        exit;
    }

    if ($action === 'me') {
        $user = current_user();
        $settings = array();
        try {
            $settings = settings_get_all($pdo, $driver);
        } catch (Throwable $e) {
            $settings = default_settings();
        }
        $merged = array_merge(default_settings(), $settings);
        $publicSettings = $merged;
        unset($publicSettings['totp_secret']);
        $publicSettings['totp_enabled'] = !empty($merged['totp_enabled']) && $merged['totp_enabled'] !== '0' ? '1' : '0';
        json_ok(array(
            'user' => $user,
            'csrf' => csrf_token(),
            'installed' => true,
            'settings' => $publicSettings,
        ));
    }

    if ($action === 'login') {
        $body = request_json();
        $username = isset($body['username']) ? trim((string) $body['username']) : '';
        $password = isset($body['password']) ? (string) $body['password'] : '';
        $ip = client_ip();
        if ($username === '' || $password === '') {
            json_err('Username and password required');
        }
        if (login_is_locked($pdo, $ip, $username)) {
            json_err('Too many failed attempts. Try again in 15 minutes.', 429);
        }
        $stmt = $pdo->prepare('SELECT id, username, password_hash FROM users WHERE username = ? LIMIT 1');
        $stmt->execute(array($username));
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, $row['password_hash'])) {
            login_attempt_record($pdo, $ip, $username);
            json_err('Invalid username or password', 401);
        }
        $settings = array_merge(default_settings(), settings_get_all($pdo, $driver));
        $totpOn = !empty($settings['totp_enabled']) && $settings['totp_enabled'] !== '0' && $settings['totp_secret'] !== '';
        if ($totpOn) {
            $_SESSION['pending_2fa_user'] = array(
                'id' => (int) $row['id'],
                'username' => $row['username'],
                'expires' => time() + 300,
            );
            json_ok(array(
                'need_2fa' => true,
                'csrf' => csrf_token(),
                'message' => 'Enter authenticator code',
            ));
        }
        login_attempt_clear($pdo, $ip, $username);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $row['id'];
        $_SESSION['username'] = $row['username'];
        unset($_SESSION['pending_2fa_user']);
        audit_log($pdo, 'login', null, $row['username']);
        json_ok(array(
            'user' => array('id' => (int) $row['id'], 'username' => $row['username']),
            'csrf' => csrf_token(),
            'need_2fa' => false,
        ));
    }

    if ($action === 'login_2fa') {
        $body = request_json();
        require_csrf($body);
        $code = isset($body['code']) ? (string) $body['code'] : '';
        $pending = isset($_SESSION['pending_2fa_user']) ? $_SESSION['pending_2fa_user'] : null;
        if (!$pending || empty($pending['expires']) || time() > (int) $pending['expires']) {
            unset($_SESSION['pending_2fa_user']);
            json_err('2FA session expired. Login again.', 401);
        }
        $settings = array_merge(default_settings(), settings_get_all($pdo, $driver));
        if (empty($settings['totp_secret']) || !totp_verify($settings['totp_secret'], $code)) {
            login_attempt_record($pdo, client_ip(), (string) $pending['username']);
            json_err('Invalid authenticator code', 401);
        }
        login_attempt_clear($pdo, client_ip(), (string) $pending['username']);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $pending['id'];
        $_SESSION['username'] = (string) $pending['username'];
        unset($_SESSION['pending_2fa_user']);
        audit_log($pdo, 'login_2fa', null, (string) $pending['username']);
        json_ok(array(
            'user' => array('id' => (int) $pending['id'], 'username' => (string) $pending['username']),
            'csrf' => csrf_token(),
        ));
    }

    if ($action === 'logout') {
        $body = request_json();
        require_csrf($body);
        $uname = isset($_SESSION['username']) ? (string) $_SESSION['username'] : null;
        audit_log($pdo, 'logout', null, $uname);
        $_SESSION = array();
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
        }
        session_destroy();
        session_start();
        csrf_token();
        json_ok(array('message' => 'Logged out'));
    }

    if ($action === 'password_change') {
        $user = require_login();
        $body = request_json();
        require_csrf($body);
        $current = isset($body['current_password']) ? (string) $body['current_password'] : '';
        $new = isset($body['new_password']) ? (string) $body['new_password'] : '';
        $confirm = isset($body['new_password_confirm']) ? (string) $body['new_password_confirm'] : '';
        if (strlen($new) < 8) {
            json_err('New password must be at least 8 characters');
        }
        if ($new !== $confirm) {
            json_err('Passwords do not match');
        }
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute(array($user['id']));
        $row = $stmt->fetch();
        if (!$row || !password_verify($current, $row['password_hash'])) {
            json_err('Current password is incorrect', 403);
        }
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $upd = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $upd->execute(array($hash, $user['id']));
        audit_log($pdo, 'password_change', array('user_id' => $user['id']));
        json_ok(array('message' => 'Password updated'));
    }

    if ($action === 'articles') {
        $user = current_user();
        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $settings = array_merge(default_settings(), settings_get_all($pdo, $driver));
        $perPage = max(1, min(100, (int) $settings['posts_per_page']));
        $offset = ($page - 1) * $perPage;
        $q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
        $status = isset($_GET['status']) ? trim((string) $_GET['status']) : 'all';
        $tag = isset($_GET['tag']) ? trim((string) $_GET['tag']) : '';

        $where = array();
        $params = array();
        if (!$user) {
            $where[] = 'a.published = 1';
        } elseif ($status === 'published') {
            $where[] = 'a.published = 1';
        } elseif ($status === 'draft') {
            $where[] = 'a.published = 0 AND (a.publish_at IS NULL OR a.publish_at = \'\')';
        } elseif ($status === 'scheduled') {
            $where[] = 'a.published = 0 AND a.publish_at IS NOT NULL AND a.publish_at != \'\'';
        } elseif ($status === 'pinned') {
            $where[] = 'a.pinned = 1';
        }
        if ($q !== '') {
            $where[] = '(a.title LIKE ? OR a.body LIKE ? OR a.slug LIKE ? OR a.meta_description LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($tag !== '') {
            $where[] = 'EXISTS (
                SELECT 1 FROM article_tags at
                INNER JOIN tags t ON t.id = at.tag_id
                WHERE at.article_id = a.id AND t.slug = ?
            )';
            $params[] = slugify($tag);
        }
        $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM articles a' . $whereSql);
        $countStmt->execute($params);
        $count = (int) $countStmt->fetchColumn();

        $sql = 'SELECT a.id, a.title, a.slug, a.body, a.published, a.created_at, a.updated_at, a.meta_description, a.pinned, a.publish_at
                FROM articles a' . $whereSql . ' ORDER BY a.pinned DESC, a.created_at DESC, a.id DESC LIMIT ? OFFSET ?';
        $stmt = $pdo->prepare($sql);
        $bind = 1;
        foreach ($params as $p) {
            $stmt->bindValue($bind++, $p);
        }
        $stmt->bindValue($bind++, $perPage, PDO::PARAM_INT);
        $stmt->bindValue($bind, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = array();
        foreach ($stmt->fetchAll() as $row) {
            $body = isset($row['body']) ? (string) $row['body'] : '';
            $meta = isset($row['meta_description']) ? (string) $row['meta_description'] : '';
            $excerptSource = $meta !== '' ? $meta : $body;
            $isScheduled = !(int) $row['published'] && !empty($row['publish_at']);
            $items[] = array(
                'id' => $row['id'],
                'title' => $row['title'],
                'slug' => $row['slug'],
                'published' => $row['published'],
                'pinned' => !empty($row['pinned']) ? 1 : 0,
                'publish_at' => $row['publish_at'],
                'scheduled' => $isScheduled ? 1 : 0,
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'meta_description' => $meta,
                'excerpt' => article_excerpt($excerptSource, 170),
                'cover' => article_cover_image($body),
                'tags' => article_get_tags($pdo, (int) $row['id']),
                'reading_time' => reading_time_minutes($body),
            );
        }
        json_ok(array(
            'items' => $items,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $count,
            'total_pages' => (int) max(1, (int) ceil(max($count, 1) / $perPage)),
            'site_title' => isset($settings['site_title']) ? $settings['site_title'] : 'Onefile',
            'filters' => array('q' => $q, 'status' => $status, 'tag' => $tag),
            'tags' => tags_list_all($pdo),
        ));
    }

    if ($action === 'article_get') {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id < 1) {
            json_err('Invalid article id');
        }
        $stmt = $pdo->prepare('SELECT * FROM articles WHERE id = ? LIMIT 1');
        $stmt->execute(array($id));
        $row = $stmt->fetch();
        if (!$row) {
            json_err('Article not found', 404);
        }
        $user = current_user();
        if (!(int) $row['published'] && !$user) {
            json_err('Article not found', 404);
        }
        $body = isset($row['body']) ? (string) $row['body'] : '';
        $meta = isset($row['meta_description']) ? (string) $row['meta_description'] : '';
        if ($meta === '') {
            $meta = article_excerpt($body, 160);
        }
        $neighbors = article_neighbors($pdo, $row, !$user);
        $row['tags'] = article_get_tags($pdo, (int) $row['id']);
        $row['reading_time'] = reading_time_minutes($body);
        $row['cover'] = article_cover_image($body);
        $row['meta_description'] = $meta;
        $row['prev'] = $neighbors['prev'];
        $row['next'] = $neighbors['next'];
        $row['excerpt'] = article_excerpt($meta !== '' ? $meta : $body, 170);
        json_ok($row);
    }

    if ($action === 'article_save') {
        require_login();
        $body = request_json();
        require_csrf($body);
        $id = isset($body['id']) ? (int) $body['id'] : 0;
        $autosave = !empty($body['autosave']);
        $title = isset($body['title']) ? trim((string) $body['title']) : '';
        $content = isset($body['body']) ? (string) $body['body'] : '';
        $published = !empty($body['published']) ? 1 : 0;
        $pinned = !empty($body['pinned']) ? 1 : 0;
        $publishAt = isset($body['publish_at']) ? trim((string) $body['publish_at']) : '';
        if ($publishAt !== '') {
            $tsCheck = strtotime($publishAt);
            if ($tsCheck === false) {
                json_err('Invalid publish_at datetime');
            }
            $publishAt = gmdate('c', $tsCheck);
            if ($tsCheck > time()) {
                $published = 0; // schedule for later
            } else {
                $published = 1;
                $publishAt = '';
            }
        } else {
            $publishAt = null;
        }
        $metaDescription = isset($body['meta_description']) ? trim((string) $body['meta_description']) : '';
        if (function_exists('mb_substr')) {
            $metaDescription = mb_substr($metaDescription, 0, 320);
        } else {
            $metaDescription = substr($metaDescription, 0, 320);
        }
        $tagNames = normalize_tag_names(isset($body['tags']) ? $body['tags'] : array());
        if ($title === '') {
            if ($autosave) {
                $title = 'Untitled draft';
            } else {
                json_err('Title is required');
            }
        }
        $ts = now_iso();
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT * FROM articles WHERE id = ?');
            $stmt->execute(array($id));
            $existing = $stmt->fetch();
            if (!$existing) {
                json_err('Article not found', 404);
            }
            if (!$autosave) {
                article_revision_save($pdo, $existing, isset($_SESSION['username']) ? (string) $_SESSION['username'] : null);
            }
            $slugInput = isset($body['slug']) ? trim((string) $body['slug']) : '';
            $base = $slugInput !== '' ? slugify($slugInput) : slugify($title);
            $slug = unique_slug($pdo, $base, $id);
            $upd = $pdo->prepare('UPDATE articles SET title = ?, slug = ?, body = ?, published = ?, meta_description = ?, pinned = ?, publish_at = ?, updated_at = ? WHERE id = ?');
            $upd->execute(array($title, $slug, $content, $published, $metaDescription, $pinned, $publishAt, $ts, $id));
            $tags = article_set_tags($pdo, $id, $tagNames);
            if (!$autosave) {
                audit_log($pdo, 'article_update', array('id' => $id, 'title' => $title, 'published' => $published, 'pinned' => $pinned));
            }
            json_ok(array('id' => $id, 'slug' => $slug, 'tags' => $tags, 'autosave' => $autosave, 'updated_at' => $ts, 'published' => $published, 'publish_at' => $publishAt, 'pinned' => $pinned));
        } else {
            $slugInput = isset($body['slug']) ? trim((string) $body['slug']) : '';
            $base = $slugInput !== '' ? slugify($slugInput) : slugify($title);
            $slug = unique_slug($pdo, $base, null);
            $ins = $pdo->prepare('INSERT INTO articles (title, slug, body, published, meta_description, pinned, publish_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $ins->execute(array($title, $slug, $content, $published, $metaDescription, $pinned, $publishAt, $ts, $ts));
            $newId = (int) $pdo->lastInsertId();
            $tags = article_set_tags($pdo, $newId, $tagNames);
            if (!$autosave) {
                audit_log($pdo, 'article_create', array('id' => $newId, 'title' => $title, 'published' => $published));
            } else {
                audit_log($pdo, 'article_autosave_create', array('id' => $newId));
            }
            json_ok(array('id' => $newId, 'slug' => $slug, 'tags' => $tags, 'autosave' => $autosave, 'updated_at' => $ts, 'published' => $published, 'publish_at' => $publishAt, 'pinned' => $pinned));
        }
    }

    if ($action === 'article_delete') {
        require_login();
        $body = request_json();
        require_csrf($body);
        $id = isset($body['id']) ? (int) $body['id'] : 0;
        if ($id < 1) {
            json_err('Invalid article id');
        }
        $titleStmt = $pdo->prepare('SELECT title FROM articles WHERE id = ?');
        $titleStmt->execute(array($id));
        $old = $titleStmt->fetch();
        $pdo->prepare('DELETE FROM article_tags WHERE article_id = ?')->execute(array($id));
        $pdo->prepare('DELETE FROM article_revisions WHERE article_id = ?')->execute(array($id));
        $stmt = $pdo->prepare('DELETE FROM articles WHERE id = ?');
        $stmt->execute(array($id));
        audit_log($pdo, 'article_delete', array('id' => $id, 'title' => $old ? $old['title'] : null));
        json_ok(array('deleted' => $id));
    }

    if ($action === 'tags_list') {
        json_ok(array('items' => tags_list_all($pdo)));
    }

    if ($action === 'dashboard') {
        require_login();
        $total = (int) $pdo->query('SELECT COUNT(*) FROM articles')->fetchColumn();
        $published = (int) $pdo->query('SELECT COUNT(*) FROM articles WHERE published = 1')->fetchColumn();
        $drafts = (int) $pdo->query('SELECT COUNT(*) FROM articles WHERE published = 0')->fetchColumn();
        $tagCount = (int) $pdo->query('SELECT COUNT(*) FROM tags')->fetchColumn();
        $auditCount = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
        $uploads = 0;
        try {
            $uploads = count(uploads_list());
        } catch (Throwable $e) {
            $uploads = 0;
        }
        $recentArticles = $pdo->query(
            'SELECT id, title, published, updated_at FROM articles ORDER BY updated_at DESC LIMIT 6'
        )->fetchAll();
        $recentAudits = $pdo->query(
            'SELECT created_at, username, action, detail FROM audit_logs ORDER BY id DESC LIMIT 8'
        )->fetchAll();
        json_ok(array(
            'stats' => array(
                'articles' => $total,
                'published' => $published,
                'drafts' => $drafts,
                'tags' => $tagCount,
                'uploads' => $uploads,
                'audits' => $auditCount,
            ),
            'recent_articles' => $recentArticles,
            'recent_audits' => $recentAudits,
        ));
    }

    if ($action === 'settings_get') {
        require_login();
        $settings = array_merge(default_settings(), settings_get_all($pdo, $driver));
        $settings['totp_enabled'] = !empty($settings['totp_enabled']) && $settings['totp_enabled'] !== '0' ? '1' : '0';
        unset($settings['totp_secret']);
        json_ok($settings);
    }

    if ($action === 'settings_save') {
        require_login();
        $body = request_json();
        require_csrf($body);
        $allowed = array('site_title', 'theme', 'posts_per_page', 'site_intro');
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            $val = trim((string) $body[$key]);
            if ($key === 'theme' && !in_array($val, array('light', 'dark', 'system'), true)) {
                json_err('Theme must be light, dark, or system');
            }
            if ($key === 'posts_per_page') {
                $n = (int) $val;
                if ($n < 1 || $n > 100) {
                    json_err('posts_per_page must be 1–100');
                }
                $val = (string) $n;
            }
            if ($key === 'site_title' && $val === '') {
                json_err('Site title cannot be empty');
            }
            if ($key === 'site_intro') {
                if (function_exists('mb_substr')) {
                    $val = mb_substr($val, 0, 500);
                } else {
                    $val = substr($val, 0, 500);
                }
            }
            settings_set($pdo, $driver, $key, $val);
        }
        audit_log($pdo, 'settings_save', array_intersect_key($body, array_flip($allowed)));
        $merged = array_merge(default_settings(), settings_get_all($pdo, $driver));
        unset($merged['totp_secret']);
        json_ok($merged);
    }

    if ($action === 'revisions_list') {
        require_login();
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id < 1) {
            json_err('Invalid article id');
        }
        $stmt = $pdo->prepare(
            'SELECT id, article_id, title, slug, meta_description, created_at, username,
                    LENGTH(body) AS body_size
             FROM article_revisions WHERE article_id = ? ORDER BY id DESC LIMIT 30'
        );
        $stmt->execute(array($id));
        json_ok(array('items' => $stmt->fetchAll()));
    }

    if ($action === 'revision_get') {
        require_login();
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id < 1) {
            json_err('Invalid revision id');
        }
        $stmt = $pdo->prepare('SELECT * FROM article_revisions WHERE id = ? LIMIT 1');
        $stmt->execute(array($id));
        $row = $stmt->fetch();
        if (!$row) {
            json_err('Revision not found', 404);
        }
        json_ok($row);
    }

    if ($action === 'revision_restore') {
        require_login();
        $body = request_json();
        require_csrf($body);
        $revId = isset($body['id']) ? (int) $body['id'] : 0;
        $stmt = $pdo->prepare('SELECT * FROM article_revisions WHERE id = ? LIMIT 1');
        $stmt->execute(array($revId));
        $rev = $stmt->fetch();
        if (!$rev) {
            json_err('Revision not found', 404);
        }
        $cur = $pdo->prepare('SELECT * FROM articles WHERE id = ?');
        $cur->execute(array((int) $rev['article_id']));
        $existing = $cur->fetch();
        if (!$existing) {
            json_err('Article not found', 404);
        }
        article_revision_save($pdo, $existing, isset($_SESSION['username']) ? (string) $_SESSION['username'] : null);
        $upd = $pdo->prepare(
            'UPDATE articles SET title = ?, slug = ?, body = ?, meta_description = ?, updated_at = ? WHERE id = ?'
        );
        $upd->execute(array(
            $rev['title'],
            $rev['slug'],
            $rev['body'],
            $rev['meta_description'],
            now_iso(),
            (int) $rev['article_id'],
        ));
        audit_log($pdo, 'revision_restore', array('article_id' => (int) $rev['article_id'], 'revision_id' => $revId));
        json_ok(array('id' => (int) $rev['article_id'], 'message' => 'Revision restored'));
    }

    if ($action === 'article_import') {
        require_login();
        $body = request_json();
        require_csrf($body);
        $format = isset($body['format']) ? strtolower(trim((string) $body['format'])) : 'markdown';
        $raw = isset($body['content']) ? (string) $body['content'] : '';
        $title = isset($body['title']) ? trim((string) $body['title']) : '';
        if (trim($raw) === '') {
            json_err('Content is required');
        }
        if ($format === 'markdown' || $format === 'md') {
            $html = markdown_to_html($raw);
            if ($title === '' && preg_match('/^#\s+(.+)$/m', $raw, $m)) {
                $title = trim($m[1]);
            }
        } elseif ($format === 'html') {
            $html = $raw;
        } else {
            json_err('Format must be markdown or html');
        }
        if ($title === '') {
            $title = 'Imported ' . gmdate('Y-m-d H:i');
        }
        $ts = now_iso();
        $slug = unique_slug($pdo, slugify($title), null);
        $meta = article_excerpt($html, 160);
        $ins = $pdo->prepare(
            'INSERT INTO articles (title, slug, body, published, meta_description, pinned, publish_at, created_at, updated_at)
             VALUES (?, ?, ?, 0, ?, 0, NULL, ?, ?)'
        );
        $ins->execute(array($title, $slug, $html, $meta, $ts, $ts));
        $newId = (int) $pdo->lastInsertId();
        audit_log($pdo, 'article_import', array('id' => $newId, 'format' => $format));
        json_ok(array('id' => $newId, 'slug' => $slug, 'title' => $title));
    }

    if ($action === 'totp_setup') {
        require_login();
        $body = request_json();
        require_csrf($body);
        $secret = base32_encode_secret(random_bytes(20));
        $_SESSION['totp_setup_secret'] = $secret;
        $label = rawurlencode((isset($_SESSION['username']) ? $_SESSION['username'] : 'admin') . '@onefile');
        $issuer = rawurlencode('Onefile CMS');
        $otpauth = 'otpauth://totp/' . $issuer . ':' . $label . '?secret=' . $secret . '&issuer=' . $issuer . '&digits=6&period=30';
        json_ok(array(
            'secret' => $secret,
            'otpauth' => $otpauth,
            'message' => 'Scan with authenticator app, then confirm with a code',
        ));
    }

    if ($action === 'totp_enable') {
        require_login();
        $body = request_json();
        require_csrf($body);
        $code = isset($body['code']) ? (string) $body['code'] : '';
        $secret = isset($_SESSION['totp_setup_secret']) ? (string) $_SESSION['totp_setup_secret'] : '';
        if ($secret === '' || !totp_verify($secret, $code)) {
            json_err('Invalid code — setup not confirmed');
        }
        settings_set($pdo, $driver, 'totp_secret', $secret);
        settings_set($pdo, $driver, 'totp_enabled', '1');
        unset($_SESSION['totp_setup_secret']);
        audit_log($pdo, 'totp_enable', null);
        json_ok(array('message' => 'Two-factor authentication enabled'));
    }

    if ($action === 'totp_disable') {
        $user = require_login();
        $body = request_json();
        require_csrf($body);
        $code = isset($body['code']) ? (string) $body['code'] : '';
        $password = isset($body['password']) ? (string) $body['password'] : '';
        $settings = array_merge(default_settings(), settings_get_all($pdo, $driver));
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute(array($user['id']));
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, $row['password_hash'])) {
            json_err('Password incorrect', 403);
        }
        if (!empty($settings['totp_secret']) && !totp_verify($settings['totp_secret'], $code)) {
            json_err('Invalid authenticator code');
        }
        settings_set($pdo, $driver, 'totp_enabled', '0');
        settings_set($pdo, $driver, 'totp_secret', '');
        audit_log($pdo, 'totp_disable', null);
        json_ok(array('message' => 'Two-factor authentication disabled'));
    }

    if ($action === 'file_self_get') {
        require_login();
        $path = self_source_path();
        $content = file_get_contents($path);
        if ($content === false) {
            json_err('Cannot read index.php', 500);
        }
        json_ok(array(
            'path' => $path,
            'basename' => basename($path),
            'size' => strlen($content),
            'mtime' => filemtime($path),
            'writable' => is_writable($path),
            'content' => $content,
        ));
    }

    if ($action === 'file_self_put') {
        require_login();
        $body = request_json();
        require_csrf($body);
        if (!isset($body['content']) || !is_string($body['content'])) {
            json_err('content string required');
        }
        $meta = self_write_source($body['content']);
        audit_log($pdo, 'file_self_put', array('size' => $meta['size']));
        json_ok(array(
            'message' => 'index.php written',
            'file' => $meta,
        ));
    }

    if ($action === 'audit_list') {
        require_login();
        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $perPage = 30;
        $offset = ($page - 1) * $perPage;
        $total = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
        $stmt = $pdo->prepare('SELECT id, created_at, username, action, detail, ip FROM audit_logs ORDER BY id DESC LIMIT ? OFFSET ?');
        $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        json_ok(array(
            'items' => $stmt->fetchAll(),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => (int) max(1, (int) ceil($total / $perPage)),
        ));
    }

    if ($action === 'backup_export') {
        require_login();
        $payload = build_backup_payload($pdo, $driver);
        audit_log($pdo, 'backup_export', array(
            'articles' => count($payload['articles']),
            'users' => count($payload['users']),
        ));
        json_ok($payload);
    }

    if ($action === 'backup_restore') {
        require_login();
        $body = request_json();
        require_csrf($body);
        $payload = isset($body['backup']) && is_array($body['backup']) ? $body['backup'] : $body;
        if (!isset($payload['format']) || $payload['format'] !== 'onefile-backup') {
            json_err('Invalid backup format');
        }
        if ((int) (isset($payload['version']) ? $payload['version'] : 0) !== 1) {
            json_err('Unsupported backup version');
        }
        try {
            $pdo->beginTransaction();
            $pdo->exec('DELETE FROM articles');
            $pdo->exec('DELETE FROM settings');
            if (!empty($payload['replace_users']) && !empty($payload['users']) && is_array($payload['users'])) {
                $pdo->exec('DELETE FROM users');
                $insU = $pdo->prepare('INSERT INTO users (id, username, password_hash) VALUES (?, ?, ?)');
                foreach ($payload['users'] as $u) {
                    $insU->execute(array(
                        (int) $u['id'],
                        (string) $u['username'],
                        (string) $u['password_hash'],
                    ));
                }
            }
            if (!empty($payload['settings']) && is_array($payload['settings'])) {
                foreach ($payload['settings'] as $k => $v) {
                    settings_set($pdo, $driver, (string) $k, (string) $v);
                }
            }
            if (!empty($payload['articles']) && is_array($payload['articles'])) {
                $insA = $pdo->prepare('INSERT INTO articles (id, title, slug, body, published, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
                foreach ($payload['articles'] as $a) {
                    $insA->execute(array(
                        (int) $a['id'],
                        (string) $a['title'],
                        (string) $a['slug'],
                        (string) $a['body'],
                        !empty($a['published']) ? 1 : 0,
                        (string) $a['created_at'],
                        (string) $a['updated_at'],
                    ));
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            json_err('Restore failed: ' . $e->getMessage(), 500);
        }
        audit_log($pdo, 'backup_restore', array(
            'articles' => isset($payload['articles']) ? count($payload['articles']) : 0,
            'replace_users' => !empty($payload['replace_users']),
        ));
        json_ok(array('message' => 'Backup restored'));
    }

    if ($action === 'data_clear') {
        require_login();
        $body = request_json();
        require_csrf($body);
        $confirm = isset($body['confirm']) ? trim((string) $body['confirm']) : '';
        if ($confirm !== 'CLEAR') {
            json_err('Type CLEAR to confirm');
        }
        $mode = isset($body['mode']) ? (string) $body['mode'] : 'content';
        $cleared = array();
        if ($mode === 'all' || $mode === 'content') {
            $pdo->exec('DELETE FROM articles');
            $cleared[] = 'articles';
        }
        if ($mode === 'all' || $mode === 'audit') {
            $pdo->exec('DELETE FROM audit_logs');
            $cleared[] = 'audit_logs';
        }
        if ($mode === 'all') {
            $pdo->exec('DELETE FROM settings');
            foreach (default_settings() as $k => $v) {
                settings_set($pdo, $driver, $k, (string) $v);
            }
            $cleared[] = 'settings';
        }
        audit_log($pdo, 'data_clear', array('mode' => $mode, 'cleared' => $cleared));
        json_ok(array('message' => 'Data cleared', 'cleared' => $cleared));
    }

    if ($action === 'htaccess_status') {
        require_login();
        $path = htaccess_path();
        $exists = is_file($path);
        json_ok(array(
            'path' => $path,
            'exists' => $exists,
            'writable' => is_writable(__DIR__) || ($exists && is_writable($path)),
            'size' => $exists ? filesize($path) : 0,
            'preview' => htaccess_starter_content(),
        ));
    }

    if ($action === 'htaccess_write') {
        require_login();
        $body = request_json();
        require_csrf($body);
        $overwrite = !empty($body['overwrite']);
        try {
            $result = htaccess_write($overwrite);
            uploads_ensure();
        } catch (Throwable $e) {
            json_err($e->getMessage(), 500);
        }
        audit_log($pdo, 'htaccess_write', $result);
        json_ok($result);
    }

    if ($action === 'upload_list') {
        require_login();
        try {
            $items = uploads_list();
        } catch (Throwable $e) {
            json_err($e->getMessage(), 500);
        }
        json_ok(array(
            'items' => $items,
            'max_bytes' => uploads_max_bytes(),
            'allowed' => array_keys(uploads_allowed_map()),
        ));
    }

    if ($action === 'upload_file') {
        require_login();
        $csrf = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';
        require_csrf(array('csrf' => $csrf));
        if (empty($_FILES['file'])) {
            json_err('No file uploaded');
        }
        try {
            $meta = uploads_handle_upload($_FILES['file']);
        } catch (Throwable $e) {
            json_err($e->getMessage());
        }
        audit_log($pdo, 'upload_file', array('name' => $meta['name'], 'size' => $meta['size'], 'mime' => $meta['mime']));
        json_ok($meta);
    }

    if ($action === 'upload_delete') {
        require_login();
        $body = request_json();
        require_csrf($body);
        $name = isset($body['name']) ? (string) $body['name'] : '';
        try {
            uploads_delete($name);
        } catch (Throwable $e) {
            json_err($e->getMessage());
        }
        audit_log($pdo, 'upload_delete', array('name' => $name));
        json_ok(array('deleted' => $name));
    }

    json_err('Unknown action', 404);
}

// Apache needs a real .htaccess file on disk; template lives in this PHP file.
if (!empty($CONFIG['installed'])) {
    htaccess_ensure();
    try {
        uploads_ensure();
    } catch (Throwable $e) {
        // Non-fatal on boot
    }
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : '';
if ($action !== '') {
    if (strpos($action, 'setup_') === 0) {
        if (!empty($CONFIG['installed'])) {
            json_err('Already installed', 403);
        }
        // If INSTALL block was stripped, setup handlers are gone — should not reach here when installed=false without handlers.
        json_err('Installer is not available', 404);
    }
    handle_app_action($action, $CONFIG);
}

$bootedInstalled = !empty($CONFIG['installed']);
$bootCsrf = csrf_token();
$bootFavicon = logo_favicon_data_uri();
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Onefile</title>
<link rel="icon" href="<?php echo h($bootFavicon); ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?php echo h($bootFavicon); ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;0,700;1,500&family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&display=swap" rel="stylesheet">
<style>
:root {
  --bg: #f2efe9;
  --bg-deep: #ebe6de;
  --ink: #1c1916;
  --muted: #7a756c;
  --line: rgba(28, 25, 22, 0.14);
  --line-strong: rgba(28, 25, 22, 0.28);
  --accent: #7a2e2e;
  --accent-soft: #f0e2df;
  --panel: rgba(255, 252, 247, 0.88);
  --fill: #f7f3ec;
  --font: "DM Sans", "Helvetica Neue", Helvetica, Arial, sans-serif;
  --serif: "Cormorant Garamond", "Times New Roman", Times, serif;
  --radius: 2px;
  --shadow: 0 18px 50px rgba(28, 25, 22, 0.06);
}
html[data-theme="dark"] {
  --bg: #161412;
  --bg-deep: #100e0c;
  --ink: #f3eee6;
  --muted: #a39b90;
  --line: rgba(243, 238, 230, 0.14);
  --line-strong: rgba(243, 238, 230, 0.28);
  --accent: #d08a84;
  --accent-soft: #2a1c1a;
  --panel: rgba(28, 24, 20, 0.92);
  --fill: #1d1915;
  --shadow: 0 18px 50px rgba(0, 0, 0, 0.35);
}
* { box-sizing: border-box; }
body {
  margin: 0;
  min-height: 100vh;
  font-family: var(--font);
  color: var(--ink);
  background:
    radial-gradient(900px 420px at 12% -8%, rgba(122, 46, 46, 0.08), transparent 55%),
    radial-gradient(700px 380px at 100% 0%, rgba(28, 25, 22, 0.05), transparent 50%),
    linear-gradient(180deg, var(--bg), var(--bg-deep));
  line-height: 1.55;
  font-size: 15px;
  -webkit-font-smoothing: antialiased;
}
a { color: var(--ink); text-decoration: none; border-bottom: 1px solid transparent; transition: color .2s, border-color .2s; }
a:hover { color: var(--accent); border-bottom-color: var(--accent); }
.wrap {
  width: min(1080px, calc(100% - 2.75rem));
  margin: 0 auto;
  padding: 1.75rem 0 5rem;
}
.top {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 1.25rem;
  align-items: center;
  padding: 0.35rem 0 1.25rem;
  margin-bottom: 2.25rem;
  border-bottom: 1px solid var(--line-strong);
}
.brand {
  display: inline-flex;
  align-items: center;
  gap: .85rem;
  border-bottom: 0;
}
.brand:hover { color: var(--ink); border-bottom-color: transparent; }
.brand .logo { width: 30px; height: 30px; display: block; flex: 0 0 auto; border-radius: 2px; }
.brand-text {
  font-family: var(--serif);
  font-size: 1.55rem;
  font-weight: 600;
  letter-spacing: .14em;
  text-transform: uppercase;
  line-height: 1;
}
.brand-sub {
  display: block;
  margin-top: .28rem;
  font-size: .68rem;
  font-weight: 500;
  letter-spacing: .16em;
  color: var(--muted);
  text-transform: uppercase;
}
.nav { display: flex; gap: .45rem; flex-wrap: wrap; align-items: center; }
.nav-rss {
  font-size: .72rem;
  font-weight: 600;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: var(--muted);
  border-bottom: none;
  padding: .72rem .4rem;
}
.nav-rss:hover { color: var(--accent); }
button, .btn {
  appearance: none;
  font-family: var(--font);
  font-size: .72rem;
  font-weight: 600;
  letter-spacing: .12em;
  text-transform: uppercase;
  border: 1px solid var(--line-strong);
  background: transparent;
  color: var(--ink);
  padding: .72rem 1.05rem;
  cursor: pointer;
  border-radius: var(--radius);
  margin: 0;
  transition: background .2s, color .2s, border-color .2s, transform .15s;
}
button:hover, .btn:hover { background: var(--ink); color: var(--bg); border-color: var(--ink); }
button.primary {
  background: var(--accent);
  border-color: var(--accent);
  color: #fff;
}
button.primary:hover { background: var(--ink); border-color: var(--ink); color: var(--bg); }
button.btn-danger { color: var(--accent); border-color: rgba(122, 46, 46, 0.35); }
button.btn-danger:hover { background: var(--accent); border-color: var(--accent); color: #fff; }
button:disabled { opacity: .35; cursor: not-allowed; }
button.ghost {
  border: 0;
  padding: .45rem .55rem;
  letter-spacing: .08em;
}
.muted { color: var(--muted); }
.panel {
  background: var(--panel);
  border: 1px solid var(--line);
  padding: clamp(1.35rem, 3vw, 2rem);
  box-shadow: var(--shadow);
  backdrop-filter: blur(10px);
}
.stack { display: grid; gap: 1.15rem; }
label {
  display: grid;
  gap: .45rem;
  font-size: .68rem;
  font-weight: 600;
  letter-spacing: .14em;
  text-transform: uppercase;
  color: var(--muted);
}
input, textarea, select {
  width: 100%;
  border: 1px solid var(--line-strong);
  border-radius: var(--radius);
  padding: .8rem .9rem;
  font: inherit;
  font-size: 1rem;
  font-weight: 400;
  letter-spacing: 0;
  text-transform: none;
  background: var(--fill);
  color: var(--ink);
  transition: border-color .2s, box-shadow .2s;
}
input:focus, textarea:focus, select:focus, .editor-surface:focus {
  outline: none;
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(122, 46, 46, 0.12);
}
textarea { min-height: 240px; resize: vertical; line-height: 1.6; }
textarea.code {
  min-height: 60vh;
  font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
  font-size: .82rem;
  line-height: 1.45;
  letter-spacing: 0;
  text-transform: none;
  background: var(--fill);
}
.row { display: flex; gap: .5rem; flex-wrap: wrap; align-items: center; }
.steps {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: .4rem;
  margin-bottom: 1.25rem;
}
.step-pill {
  padding: .7rem .45rem;
  font-size: .65rem;
  font-weight: 600;
  letter-spacing: .12em;
  text-transform: uppercase;
  text-align: center;
  color: var(--muted);
  border: 1px solid var(--line);
  background: transparent;
}
.step-pill.on {
  background: var(--accent-soft);
  color: var(--accent);
  border-color: rgba(122, 46, 46, 0.25);
}
.check {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 1rem;
  padding: .85rem 0;
  border-bottom: 1px solid var(--line);
}
.check:last-child { border-bottom: 0; }
.ok { color: var(--ink); font-weight: 600; letter-spacing: .08em; }
.bad { color: var(--accent); font-weight: 600; letter-spacing: .08em; }
.journal-hero {
  margin-bottom: 1.75rem;
  padding-bottom: 1.35rem;
  border-bottom: 1px solid var(--line);
}
.journal-hero h1 {
  max-width: 14ch;
  margin-bottom: .55rem;
}
.journal-hero .lede {
  max-width: 36rem;
  color: var(--muted);
  font-size: 1.02rem;
  line-height: 1.55;
  margin: 0;
}
.list { display: grid; gap: 0; }
.item {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 1.25rem;
  align-items: start;
  padding: 1.65rem 0;
  border-bottom: 1px solid var(--line);
  animation: rise .45s ease both;
}
.item:nth-child(1) { animation-delay: .02s; }
.item:nth-child(2) { animation-delay: .06s; }
.item:nth-child(3) { animation-delay: .1s; }
.item:nth-child(4) { animation-delay: .14s; }
.item:nth-child(5) { animation-delay: .18s; }
.item:last-child { border-bottom: 0; }
.item-main {
  display: grid;
  grid-template-columns: 112px 1fr;
  gap: 1.15rem;
  align-items: start;
  min-width: 0;
}
.item-main.no-cover { grid-template-columns: 1fr; }
.item-cover {
  display: block;
  width: 112px;
  height: 112px;
  object-fit: cover;
  border: 1px solid var(--line);
  background: var(--fill);
  transition: transform .35s ease, opacity .35s ease;
}
.item:hover .item-cover { transform: scale(1.02); }
.item-copy { min-width: 0; }
.item h2 {
  margin: 0 0 .45rem;
  font-family: var(--serif);
  font-size: clamp(1.45rem, 2.4vw, 1.85rem);
  font-weight: 600;
  letter-spacing: .005em;
  text-transform: none;
  line-height: 1.15;
}
.item h2 a {
  border-bottom: 0;
  background-image: linear-gradient(currentColor, currentColor);
  background-position: 0 100%;
  background-repeat: no-repeat;
  background-size: 0 1px;
  transition: background-size .35s ease, color .2s ease;
}
.item h2 a:hover {
  color: var(--accent);
  background-size: 100% 1px;
  border-bottom-color: transparent;
}
.item-meta {
  display: flex;
  flex-wrap: wrap;
  gap: .55rem .75rem;
  align-items: center;
  color: var(--muted);
  font-size: .78rem;
  letter-spacing: .04em;
  margin-bottom: .55rem;
}
.item-excerpt {
  margin: 0;
  color: var(--muted);
  font-size: .98rem;
  line-height: 1.55;
  max-width: 40rem;
}
.item-actions { display: flex; gap: .45rem; flex-wrap: wrap; }
.empty-journal {
  padding: 2rem 0 1rem;
  text-align: left;
}
.empty-journal h2 {
  font-family: var(--serif);
  font-size: 1.8rem;
  font-weight: 600;
  margin: 0 0 .5rem;
}
.empty-journal p { color: var(--muted); margin: 0 0 1.1rem; max-width: 28rem; }
@keyframes rise {
  from { opacity: 0; transform: translateY(8px); }
  to { opacity: 1; transform: translateY(0); }
}
.badge {
  display: inline-block;
  font-size: .62rem;
  font-weight: 600;
  letter-spacing: .14em;
  text-transform: uppercase;
  padding: .25rem .55rem;
  border: 1px solid var(--line-strong);
  color: var(--muted);
  border-radius: 999px;
}
.badge.pub {
  color: var(--accent);
  background: var(--accent-soft);
  border-color: transparent;
}
.badge.pin {
  color: #5c4a1e;
  background: rgba(184, 148, 58, .16);
  border-color: transparent;
}
.badge.sched {
  color: #1e3a5c;
  background: rgba(58, 120, 184, .14);
  border-color: transparent;
}
.item.pinned-item {
  border-left: 3px solid var(--accent);
  padding-left: .85rem;
}
.article-body {
  line-height: 1.75;
  margin-top: 1.75rem;
  padding-top: 1.75rem;
  border-top: 1px solid var(--line);
  font-size: 1.05rem;
}
.article-body p { margin: 0 0 1rem; }
.article-body h2, .article-body h3 {
  font-family: var(--serif);
  font-weight: 600;
  margin: 1.5rem 0 .7rem;
  line-height: 1.2;
}
.article-body ul, .article-body ol { padding-left: 1.25rem; margin: 0 0 1rem; }
.article-body a { color: var(--accent); border-bottom-color: rgba(122,46,46,.35); }
.article-body img, .editor-surface img {
  max-width: 100%;
  height: auto;
  display: block;
  margin: 1rem 0;
  border: 1px solid var(--line);
}
.article-body blockquote {
  margin: 1rem 0;
  padding: .2rem 0 .2rem 1rem;
  border-left: 2px solid var(--accent);
  color: var(--muted);
  font-family: var(--serif);
  font-size: 1.15rem;
}
.media-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: .75rem;
  margin-top: .75rem;
}
.media-card {
  border: 1px solid var(--line);
  background: var(--fill);
  padding: .55rem;
  cursor: pointer;
  text-align: left;
}
.media-card:hover { border-color: var(--accent); }
.media-card img {
  width: 100%; height: 90px; object-fit: cover; display: block;
  margin: 0 0 .45rem; border: 0;
}
.media-card .name {
  font-size: .68rem; word-break: break-all; color: var(--ink); line-height: 1.3;
}
.media-card .meta { font-size: .62rem; color: var(--muted); margin-top: .25rem; }
.media-actions { display: flex; gap: .35rem; margin-top: .45rem; flex-wrap: wrap; }
.media-actions button { padding: .35rem .5rem; font-size: .62rem; }
.error, .success {
  border: 1px solid var(--line);
  padding: .9rem 1.05rem;
  margin-bottom: 1rem;
  font-size: .78rem;
  font-weight: 600;
  letter-spacing: .08em;
  text-transform: uppercase;
  border-radius: var(--radius);
}
.error { background: var(--accent); color: #fff; border-color: var(--accent); }
.success { background: var(--ink); color: var(--bg); }
h1 {
  margin: 0 0 .85rem;
  font-family: var(--serif);
  font-size: clamp(2.2rem, 5vw, 3.4rem);
  font-weight: 600;
  letter-spacing: -.01em;
  text-transform: none;
  line-height: 1.05;
}
.kicker {
  font-size: .68rem;
  font-weight: 600;
  letter-spacing: .18em;
  text-transform: uppercase;
  color: var(--accent);
  margin-bottom: .55rem;
}
.hero-install { margin: 2.5rem auto; width: min(720px, calc(100% - 2rem)); }
.install-brand {
  display: flex;
  align-items: center;
  gap: 1rem;
  margin-bottom: 1.5rem;
  padding-bottom: 1.1rem;
  border-bottom: 1px solid var(--line-strong);
}
.install-brand .logo { width: 42px; height: 42px; }
.install-brand strong {
  font-family: var(--serif);
  font-size: 1.45rem;
  letter-spacing: .14em;
  text-transform: uppercase;
  font-weight: 600;
}
.meta-line {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  font-size: .68rem;
  font-weight: 600;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: var(--muted);
  margin-bottom: .75rem;
}
.hidden { display: none !important; }
.checkbox {
  display: flex;
  align-items: center;
  gap: .55rem;
  text-transform: uppercase;
  letter-spacing: .1em;
  font-size: .68rem;
  font-weight: 600;
  color: var(--muted);
}
.checkbox input { width: auto; }
.rule { border: 0; border-top: 1px solid var(--line); margin: 1.35rem 0; }
.editor {
  border: 1px solid var(--line-strong);
  border-radius: var(--radius);
  background: var(--fill);
  overflow: hidden;
}
.editor-toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: .2rem;
  padding: .45rem;
  border-bottom: 1px solid var(--line);
  background: rgba(255,255,255,.35);
}
html[data-theme="dark"] .editor-toolbar { background: rgba(0,0,0,.2); }
.editor-toolbar button {
  min-width: 2rem;
  padding: .45rem .55rem;
  border: 0;
  background: transparent;
  font-size: .78rem;
  letter-spacing: 0;
  text-transform: none;
  font-weight: 600;
  color: var(--ink);
}
.editor-toolbar button:hover { background: var(--accent-soft); color: var(--accent); }
.editor-toolbar .sep {
  width: 1px;
  align-self: stretch;
  background: var(--line);
  margin: .2rem .25rem;
}
.editor-surface {
  min-height: 280px;
  padding: 1rem 1.05rem;
  outline: none;
  line-height: 1.7;
  font-size: 1.02rem;
  color: var(--ink);
}
.editor-surface:empty:before {
  content: attr(data-placeholder);
  color: var(--muted);
  opacity: .7;
  pointer-events: none;
}
.editor-surface:focus { box-shadow: none; border-color: transparent; }
.editor-surface p { margin: 0 0 .8rem; }
.editor-surface h2, .editor-surface h3 {
  font-family: var(--serif);
  margin: 1rem 0 .55rem;
}
.editor-surface ul, .editor-surface ol { padding-left: 1.2rem; margin: 0 0 .8rem; }
.modal-root {
  position: fixed; inset: 0; z-index: 1000;
  display: none; align-items: center; justify-content: center;
  padding: 1.25rem;
}
.modal-root.open { display: flex; }
.modal-backdrop {
  position: absolute; inset: 0;
  background: rgba(28, 25, 22, 0.42);
  backdrop-filter: blur(3px);
}
.modal-card {
  position: relative;
  width: min(440px, 100%);
  background: var(--panel);
  border: 1px solid var(--line-strong);
  box-shadow: var(--shadow);
  padding: 1.5rem 1.5rem 1.25rem;
}
.modal-kicker {
  font-size: .65rem; font-weight: 600; letter-spacing: .16em;
  text-transform: uppercase; color: var(--accent); margin-bottom: .45rem;
}
.modal-title {
  font-family: var(--serif);
  font-size: 1.75rem; font-weight: 600; margin: 0 0 .65rem; line-height: 1.15;
}
.modal-body {
  color: var(--muted); font-size: .95rem; line-height: 1.55; margin-bottom: 1.25rem;
}
.modal-body strong { color: var(--ink); font-weight: 600; }
.modal-input {
  width: 100%; margin: .75rem 0 0; border: 1px solid var(--line-strong);
  background: var(--fill); color: var(--ink); padding: .7rem .8rem; font: inherit;
}
.modal-actions { display: flex; gap: .5rem; flex-wrap: wrap; justify-content: flex-end; }
.audit-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
.audit-table th, .audit-table td {
  text-align: left; padding: .65rem .35rem; border-bottom: 1px solid var(--line);
  vertical-align: top;
}
.audit-table th {
  font-size: .65rem; letter-spacing: .12em; text-transform: uppercase;
  color: var(--muted); font-weight: 600;
}
.audit-action { font-weight: 600; }
.audit-detail { color: var(--muted); word-break: break-word; max-width: 280px; }
.filters {
  display: grid;
  grid-template-columns: 1fr auto auto;
  gap: .55rem;
  margin: 0 0 1.25rem;
}
.filters input, .filters select { margin: 0; }
.tag-cloud { display: flex; flex-wrap: wrap; gap: .4rem; margin: 0 0 1.2rem; }
.tag-chip {
  appearance: none;
  border: 1px solid var(--line-strong);
  background: transparent;
  color: var(--muted);
  padding: .35rem .65rem;
  font-size: .68rem;
  letter-spacing: .08em;
  text-transform: uppercase;
  cursor: pointer;
}
.tag-chip.on, .tag-chip:hover { color: var(--accent); border-color: var(--accent); background: var(--accent-soft); }
.item-tags { display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .65rem; }
.item-tags span {
  font-size: .62rem; letter-spacing: .08em; text-transform: uppercase;
  color: var(--accent); border: 1px solid rgba(122,46,46,.2); padding: .15rem .45rem;
}
.stats-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: .75rem;
  margin: 0 0 1.5rem;
}
.stat-card {
  border: 1px solid var(--line);
  background: var(--fill);
  padding: 1rem 1.05rem;
}
.stat-card .label {
  font-size: .65rem; letter-spacing: .14em; text-transform: uppercase; color: var(--muted);
}
.stat-card .value {
  font-family: var(--serif); font-size: 2rem; font-weight: 600; margin-top: .25rem; line-height: 1;
}
.dash-cols {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1.25rem;
}
.dash-list { list-style: none; padding: 0; margin: 0; }
.dash-list li {
  padding: .7rem 0; border-bottom: 1px solid var(--line);
  display: flex; justify-content: space-between; gap: .75rem; align-items: baseline;
}
.dash-list li:last-child { border-bottom: 0; }
.reading-meta {
  display: flex; flex-wrap: wrap; gap: .55rem .9rem; align-items: center;
  color: var(--muted); font-size: .82rem; margin: .35rem 0 0;
}
.reading-nav {
  display: grid; grid-template-columns: 1fr 1fr; gap: .75rem;
  margin-top: 2rem; padding-top: 1.25rem; border-top: 1px solid var(--line);
}
.reading-nav a {
  border: 1px solid var(--line); padding: .9rem 1rem; display: block; border-bottom: 0;
  transition: border-color .2s, background .2s;
}
.reading-nav a:hover { border-color: var(--accent); background: var(--accent-soft); color: var(--ink); }
.reading-nav .dir {
  display: block; font-size: .65rem; letter-spacing: .12em; text-transform: uppercase; color: var(--muted); margin-bottom: .35rem;
}
.reading-nav .ttl { font-family: var(--serif); font-size: 1.15rem; font-weight: 600; }
.autosave-status {
  font-size: .72rem; letter-spacing: .06em; text-transform: uppercase; color: var(--muted);
}
.autosave-status.on { color: var(--accent); }
@media (max-width: 700px) {
  .top { grid-template-columns: 1fr; }
  .steps { grid-template-columns: 1fr 1fr; }
  .item { grid-template-columns: 1fr; }
  .item-main { grid-template-columns: 84px 1fr; gap: .85rem; }
  .item-cover { width: 84px; height: 84px; }
  .filters { grid-template-columns: 1fr; }
  .stats-grid, .dash-cols, .reading-nav { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<div id="app"></div>
<div id="modal-root" class="modal-root" aria-hidden="true">
  <div class="modal-backdrop" data-modal-dismiss></div>
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <div class="modal-kicker" id="modal-kicker">Confirm</div>
    <h2 class="modal-title" id="modal-title">Are you sure?</h2>
    <div class="modal-body" id="modal-body"></div>
    <div class="modal-actions" id="modal-actions"></div>
  </div>
</div>
<script>
window.__BOOT__ = {
  installed: <?php echo $bootedInstalled ? 'true' : 'false'; ?>,
  csrf: <?php echo json_encode($bootCsrf, JSON_UNESCAPED_UNICODE); ?>
};

const app = document.getElementById('app');
const state = {
  installed: window.__BOOT__.installed,
  user: null,
  csrf: window.__BOOT__.csrf,
  settings: { site_title: 'Onefile CMS', theme: 'system', posts_per_page: '10', site_intro: '', totp_enabled: '0' },
  route: '',
  error: '',
  flash: '',
  login2fa: false,
  install: { step: 1, checks: [], drivers: { sqlite: true, mysql: false }, db: { driver: 'sqlite', path: 'data.sqlite', host: '127.0.0.1', port: 3306, name: '', user: '', pass: '' }, site_title: 'Onefile CMS', username: 'admin', password: '', password_confirm: '', testMsg: '' }
};

async function api(action, opts = {}) {
  const method = opts.method || (opts.body ? 'POST' : 'GET');
  const headers = { 'Accept': 'application/json' };
  let body = undefined;
  if (opts.body) {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(Object.assign({ csrf: state.csrf }, opts.body));
  }
  const res = await fetch('?action=' + encodeURIComponent(action) + (opts.query || ''), {
    method, headers, body, credentials: 'same-origin'
  });
  let data;
  try { data = await res.json(); } catch (e) { throw new Error('Invalid server response'); }
  if (data && data.data && data.data.csrf) state.csrf = data.data.csrf;
  if (!data.ok) {
    const err = new Error(data.error || 'Request failed');
    err.status = res.status;
    throw err;
  }
  return data.data;
}

function esc(s) {
  return String(s == null ? '' : s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function nl2brEsc(s) {
  return esc(s).replace(/\n/g, '<br>');
}

function looksLikeHtml(s) {
  return /<[a-z][\s\S]*>/i.test(String(s || ''));
}

function sanitizeHtml(html) {
  const allowed = {
    P: [], BR: [], STRONG: [], B: [], EM: [], I: [], U: [],
    UL: [], OL: [], LI: [], H2: [], H3: [], BLOCKQUOTE: [],
    A: ['href', 'title', 'target', 'rel'], IMG: ['src', 'alt', 'title', 'width', 'height'],
    SPAN: [], DIV: [], FIGURE: [], FIGCAPTION: []
  };
  const safeSrc = (src) => {
    src = String(src || '').trim();
    if (!src) return '';
    if (/^(https?:\/\/|\/|uploads\/|\.\/uploads\/)/i.test(src)) return src;
    return '';
  };
  const doc = new DOMParser().parseFromString('<div id="root">' + String(html || '') + '</div>', 'text/html');
  const walk = (node) => {
    [...node.childNodes].forEach((child) => {
      if (child.nodeType === 3) return;
      if (child.nodeType !== 1) { child.remove(); return; }
      const tag = child.tagName;
      if (!allowed[tag]) {
        const parent = child.parentNode;
        while (child.firstChild) parent.insertBefore(child.firstChild, child);
        child.remove();
        return;
      }
      [...child.attributes].forEach((attr) => {
        const name = attr.name.toLowerCase();
        if (!(allowed[tag] || []).includes(name)) child.removeAttribute(attr.name);
        if (name.startsWith('on') || /^javascript:/i.test(attr.value || '')) child.removeAttribute(attr.name);
      });
      if (tag === 'A') {
        const href = child.getAttribute('href') || '';
        if (!/^(https?:|mailto:|#|\/|uploads\/)/i.test(href)) child.removeAttribute('href');
        child.setAttribute('rel', 'noopener noreferrer');
      }
      if (tag === 'IMG') {
        const src = safeSrc(child.getAttribute('src') || '');
        if (!src) { child.remove(); return; }
        child.setAttribute('src', src);
      }
      walk(child);
    });
  };
  const root = doc.getElementById('root');
  walk(root);
  return root.innerHTML;
}

function renderBodyHtml(body) {
  if (!body) return '';
  if (looksLikeHtml(body)) return sanitizeHtml(body);
  return nl2brEsc(body);
}

function editorToolbarHtml() {
  return `<div class="editor-toolbar" role="toolbar" aria-label="Text formatting">
    <button type="button" data-cmd="bold" title="Bold"><b>B</b></button>
    <button type="button" data-cmd="italic" title="Italic"><i>I</i></button>
    <button type="button" data-cmd="underline" title="Underline"><u>U</u></button>
    <span class="sep"></span>
    <button type="button" data-cmd="formatBlock" data-value="H2" title="Heading">H2</button>
    <button type="button" data-cmd="formatBlock" data-value="H3" title="Subheading">H3</button>
    <button type="button" data-cmd="formatBlock" data-value="P" title="Paragraph">P</button>
    <span class="sep"></span>
    <button type="button" data-cmd="insertUnorderedList" title="Bullet list">• List</button>
    <button type="button" data-cmd="insertOrderedList" title="Numbered list">1. List</button>
    <button type="button" data-cmd="formatBlock" data-value="BLOCKQUOTE" title="Quote">Quote</button>
    <span class="sep"></span>
    <button type="button" data-cmd="createLink" title="Link">Link</button>
    <button type="button" data-cmd="insertImage" title="Insert image">Image</button>
    <button type="button" data-cmd="pickFile" title="Choose uploaded file">Files</button>
    <button type="button" data-cmd="unlink" title="Remove link">Unlink</button>
    <button type="button" data-cmd="removeFormat" title="Clear formatting">Clear</button>
  </div>`;
}

async function apiUpload(file) {
  const fd = new FormData();
  fd.append('csrf', state.csrf);
  fd.append('file', file);
  const res = await fetch('?action=upload_file', { method: 'POST', body: fd, credentials: 'same-origin' });
  let data;
  try { data = await res.json(); } catch (e) { throw new Error('Invalid upload response'); }
  if (data && data.data && data.data.csrf) state.csrf = data.data.csrf;
  if (!data.ok) throw new Error(data.error || 'Upload failed');
  return data.data;
}

function insertHtmlAtCursor(surface, html) {
  surface.focus();
  const ok = document.execCommand('insertHTML', false, html);
  if (!ok) {
    surface.innerHTML = sanitizeHtml((surface.innerHTML || '') + html);
  }
}

function openMediaPicker(opts = {}) {
  return new Promise(async (resolve) => {
    const mode = opts.mode || 'any'; // image | any | link
    let items = [];
    try {
      const data = await api('upload_list');
      items = data.items || [];
      if (mode === 'image') items = items.filter((x) => x.is_image);
    } catch (e) {
      await uiAlert(e.message, { title: 'Media library' });
      resolve(null);
      return;
    }
    const root = document.getElementById('modal-root');
    const kicker = document.getElementById('modal-kicker');
    const title = document.getElementById('modal-title');
    const body = document.getElementById('modal-body');
    const actions = document.getElementById('modal-actions');
    kicker.textContent = 'Media';
    title.textContent = opts.title || 'Choose a file';
    const grid = items.map((f) => `
      <div class="media-card" data-pick="${esc(f.name)}" data-url="${esc(f.url)}" data-image="${f.is_image ? '1' : '0'}">
        ${f.is_image ? `<img src="${esc(f.url)}" alt="">` : `<div class="meta" style="height:90px;display:flex;align-items:center;justify-content:center;border:1px solid var(--line)">.${esc(f.ext)}</div>`}
        <div class="name">${esc(f.name)}</div>
        <div class="meta">${Math.round((f.size || 0) / 1024)} KB</div>
      </div>`).join('') || `<p class="muted">No files yet. Upload one below.</p>`;
    body.innerHTML = `<div class="media-grid">${grid}</div>
      <div class="row" style="margin-top:1rem">
        <input type="file" id="media-file-input" accept="${mode === 'image' ? 'image/jpeg,image/png,image/gif,image/webp' : 'image/*,.pdf,.txt,.md,.zip'}">
      </div>`;
    actions.innerHTML = '';
    const cancel = document.createElement('button');
    cancel.type = 'button'; cancel.textContent = 'Cancel';
    const done = (value) => {
      root.classList.remove('open');
      root.setAttribute('aria-hidden', 'true');
      resolve(value);
    };
    cancel.addEventListener('click', () => done(null));
    actions.appendChild(cancel);
    root.querySelectorAll('[data-modal-dismiss]').forEach((el) => { el.onclick = () => done(null); });
    body.querySelectorAll('[data-pick]').forEach((card) => {
      card.addEventListener('click', () => {
        done({
          name: card.getAttribute('data-pick'),
          url: card.getAttribute('data-url'),
          is_image: card.getAttribute('data-image') === '1'
        });
      });
    });
    const input = document.getElementById('media-file-input');
    input.addEventListener('change', async () => {
      const file = input.files && input.files[0];
      if (!file) return;
      try {
        const meta = await apiUpload(file);
        done(meta);
      } catch (e) {
        await uiAlert(e.message, { title: 'Upload failed' });
      }
    });
    root.classList.add('open');
    root.setAttribute('aria-hidden', 'false');
  });
}

function mountEditor(surfaceId, initialHtml) {
  const surface = document.getElementById(surfaceId);
  if (!surface) return;
  const html = String(initialHtml || '');
  surface.innerHTML = looksLikeHtml(html) ? sanitizeHtml(html) : (html ? nl2brEsc(html) : '');
  const tb = surface.parentElement.querySelector('.editor-toolbar');
  if (!tb) return;
  tb.addEventListener('mousedown', (e) => e.preventDefault());
  tb.addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-cmd]');
    if (!btn) return;
    const cmd = btn.getAttribute('data-cmd');
    const value = btn.getAttribute('data-value');
    surface.focus();
    if (cmd === 'createLink') {
      const url = await uiPrompt('Paste a URL, or use Files to link an upload.', {
        title: 'Insert link',
        defaultValue: 'https://',
        okText: 'Insert'
      });
      if (!url) return;
      surface.focus();
      document.execCommand('createLink', false, url);
      return;
    }
    if (cmd === 'insertImage') {
      const file = await openMediaPicker({ mode: 'image', title: 'Insert image' });
      if (!file) return;
      insertHtmlAtCursor(surface, `<img src="${esc(file.url)}" alt="${esc(file.name || 'image')}">`);
      return;
    }
    if (cmd === 'pickFile') {
      const file = await openMediaPicker({ mode: 'any', title: 'Insert file' });
      if (!file) return;
      if (file.is_image) {
        insertHtmlAtCursor(surface, `<img src="${esc(file.url)}" alt="${esc(file.name || 'image')}">`);
      } else {
        const label = file.name || file.url;
        insertHtmlAtCursor(surface, `<a href="${esc(file.url)}" rel="noopener noreferrer">${esc(label)}</a>`);
      }
      return;
    }
    if (cmd === 'formatBlock') {
      document.execCommand('formatBlock', false, value || 'P');
      return;
    }
    document.execCommand(cmd, false, value || null);
  });
}

function getEditorHtml(surfaceId) {
  const surface = document.getElementById(surfaceId);
  if (!surface) return '';
  return sanitizeHtml(surface.innerHTML).trim();
}

const LOGO_SVG = `<svg class="logo" viewBox="0 0 32 32" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><rect width="32" height="32" fill="#1c1916"/><rect x="6" y="4" width="8" height="24" fill="#f2efe9"/><rect x="6" y="12" width="20" height="8" fill="#7a2e2e"/></svg>`;

function resolveTheme(pref) {
  const mode = pref || state.settings.theme || 'system';
  if (mode === 'dark' || mode === 'light') return mode;
  try {
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  } catch (e) {
    return 'light';
  }
}

function applyTheme() {
  document.documentElement.setAttribute('data-theme', resolveTheme());
}

if (!window.__onefileThemeListener) {
  window.__onefileThemeListener = true;
  try {
    const mq = window.matchMedia('(prefers-color-scheme: dark)');
    const onChange = () => {
      if ((state.settings.theme || 'system') === 'system') applyTheme();
    };
    if (mq.addEventListener) mq.addEventListener('change', onChange);
    else if (mq.addListener) mq.addListener(onChange);
  } catch (e) {}
}

function toDatetimeLocalValue(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '';
  const pad = (n) => String(n).padStart(2, '0');
  return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
}

function articleStatusBadge(a, { forPublic = false } = {}) {
  const parts = [];
  if (a.pinned == 1) parts.push('<span class="badge pin">Pinned</span>');
  if (forPublic) return parts.join('');
  if (a.scheduled == 1 || (a.published != 1 && a.publish_at)) parts.push('<span class="badge sched">Scheduled</span>');
  else if (a.published == 1) parts.push('<span class="badge pub">Published</span>');
  else parts.push('<span class="badge">Draft</span>');
  return parts.join('');
}

async function openImportDialog() {
  const raw = await uiPrompt('Paste Markdown or HTML. First line `# Title` becomes the title for Markdown.', {
    title: 'Import article',
    kicker: 'Import',
    placeholder: '# Title\n\nYour content…',
    okText: 'Import',
    multiline: true
  });
  if (raw === null) return;
  const looksHtml = /^\s*</.test(raw);
  const format = looksHtml ? 'html' : 'markdown';
  try {
    const data = await api('article_import', { body: { content: raw, format } });
    state.flash = 'Imported';
    go('#/articles/' + data.id + '/edit');
  } catch (e) {
    await uiAlert(e.message, { title: 'Import failed' });
  }
}

function parseRoute() {
  const raw = (location.hash || '#/').replace(/^#/, '') || '/';
  const pathOnly = raw.split('?')[0];
  const parts = pathOnly.split('/').filter(Boolean);
  if (!parts.length) return { name: 'home', params: {} };
  if (parts[0] === 'login') return { name: 'login', params: {} };
  if (parts[0] === 'dashboard') return { name: 'dashboard', params: {} };
  if (parts[0] === 'settings') return { name: 'settings', params: {} };
  if (parts[0] === 'audit') return { name: 'audit', params: {} };
  if (parts[0] === 'media') return { name: 'media', params: {} };
  if (parts[0] === 'file') return { name: 'file', params: {} };
  if (parts[0] === 'articles') {
    if (parts[1] === 'new') return { name: 'edit', params: { id: null } };
    if (parts[2] === 'edit') return { name: 'edit', params: { id: parts[1] } };
    if (parts[1]) return { name: 'view', params: { id: parts[1] } };
  }
  return { name: 'home', params: {} };
}

function go(path) {
  location.hash = path;
}

function uiDialog(opts) {
  return new Promise((resolve) => {
    const root = document.getElementById('modal-root');
    const kicker = document.getElementById('modal-kicker');
    const title = document.getElementById('modal-title');
    const body = document.getElementById('modal-body');
    const actions = document.getElementById('modal-actions');
    kicker.textContent = opts.kicker || (opts.mode === 'alert' ? 'Notice' : 'Confirm');
    title.textContent = opts.title || 'Please confirm';
    body.innerHTML = '';
    if (opts.html) body.innerHTML = opts.html;
    else body.textContent = opts.message || '';
    let inputEl = null;
    if (opts.prompt) {
      if (opts.multiline) {
        inputEl = document.createElement('textarea');
        inputEl.className = 'modal-input';
        inputEl.rows = opts.rows || 10;
      } else {
        inputEl = document.createElement('input');
        inputEl.className = 'modal-input';
        inputEl.type = opts.inputType || 'text';
      }
      inputEl.value = opts.defaultValue || '';
      inputEl.placeholder = opts.placeholder || '';
      body.appendChild(inputEl);
    }
    actions.innerHTML = '';
    const done = (value) => {
      root.classList.remove('open');
      root.setAttribute('aria-hidden', 'true');
      document.removeEventListener('keydown', onKey);
      resolve(value);
    };
    const onKey = (e) => {
      if (e.key === 'Escape') done(opts.mode === 'alert' ? true : (opts.prompt ? null : false));
      if (e.key === 'Enter' && opts.mode !== 'confirm' && !(opts.multiline && e.target === inputEl)) {
        if (opts.prompt) done(inputEl.value);
        else if (opts.mode === 'alert') done(true);
      }
    };
    document.addEventListener('keydown', onKey);
    if (opts.mode === 'alert') {
      const ok = document.createElement('button');
      ok.type = 'button'; ok.className = 'primary'; ok.textContent = opts.okText || 'OK';
      ok.addEventListener('click', () => done(true));
      actions.appendChild(ok);
    } else if (opts.prompt) {
      const cancel = document.createElement('button');
      cancel.type = 'button'; cancel.textContent = opts.cancelText || 'Cancel';
      cancel.addEventListener('click', () => done(null));
      const ok = document.createElement('button');
      ok.type = 'button'; ok.className = 'primary'; ok.textContent = opts.okText || 'Continue';
      ok.addEventListener('click', () => done(inputEl.value));
      actions.appendChild(cancel); actions.appendChild(ok);
    } else {
      const cancel = document.createElement('button');
      cancel.type = 'button'; cancel.textContent = opts.cancelText || 'Cancel';
      cancel.addEventListener('click', () => done(false));
      const ok = document.createElement('button');
      ok.type = 'button'; ok.className = opts.danger ? 'btn-danger' : 'primary';
      ok.textContent = opts.okText || 'OK';
      ok.addEventListener('click', () => done(true));
      actions.appendChild(cancel); actions.appendChild(ok);
    }
    root.querySelectorAll('[data-modal-dismiss]').forEach((el) => {
      el.onclick = () => done(opts.mode === 'alert' ? true : (opts.prompt ? null : false));
    });
    root.classList.add('open');
    root.setAttribute('aria-hidden', 'false');
    setTimeout(() => { if (inputEl) inputEl.focus(); else {
      const btn = actions.querySelector('button.primary, button.btn-danger, button');
      if (btn) btn.focus();
    }}, 30);
  });
}

function uiConfirm(message, options = {}) {
  return uiDialog(Object.assign({
    mode: 'confirm',
    kicker: 'Confirm',
    title: options.title || 'Please confirm',
    message,
    okText: options.okText || 'OK',
    cancelText: options.cancelText || 'Cancel',
    danger: !!options.danger
  }, options));
}

function uiAlert(message, options = {}) {
  return uiDialog(Object.assign({
    mode: 'alert',
    kicker: 'Notice',
    title: options.title || 'Notice',
    message,
    okText: options.okText || 'OK'
  }, options));
}

function uiPrompt(message, options = {}) {
  return uiDialog(Object.assign({
    mode: 'prompt',
    prompt: true,
    kicker: 'Input',
    title: options.title || 'Enter value',
    message,
    okText: options.okText || 'Continue',
    cancelText: options.cancelText || 'Cancel',
    defaultValue: options.defaultValue || '',
    placeholder: options.placeholder || ''
  }, options));
}

function topbar() {
  const title = esc(state.settings.site_title || 'Onefile');
  const authed = !!state.user;
  return `<div class="top">
    <a class="brand" href="#/">${LOGO_SVG}<span><span class="brand-text">${title}</span><span class="brand-sub">A place for writing</span></span></a>
    <div class="nav">
      <a class="nav-rss" href="?action=rss" target="_blank" rel="noopener">RSS</a>
      ${authed ? `<button type="button" class="primary" data-go="#/articles/new">New</button>
        <button type="button" id="btn-import">Import</button>
        <button type="button" data-go="#/dashboard">Dashboard</button>
        <button type="button" data-go="#/media">Media</button>
        <button type="button" data-go="#/settings">Settings</button>
        <button type="button" data-go="#/audit">Audit</button>
        <button type="button" data-go="#/file">Self file</button>
        <button type="button" id="btn-logout">Logout</button>`
        : `<button type="button" data-go="#/login">Login</button>`}
    </div>
  </div>`;
}

function banner() {
  let html = '';
  if (state.error) html += `<div class="error">${esc(state.error)}</div>`;
  if (state.flash) html += `<div class="success">${esc(state.flash)}</div>`;
  return html;
}

async function renderInstall() {
  const st = state.install;
  let body = '';
  if (st.step === 1) {
    const rows = (st.checks || []).map(c => `<div class="check"><div><strong>${esc(c.label)}</strong><div class="muted">${esc(c.detail || '')}</div></div><div class="${c.ok ? 'ok' : 'bad'}">${c.ok ? 'OK' : 'FAIL'}</div></div>`).join('');
    body = `<div class="panel stack">
      <h1>Environment</h1>
      <p class="muted">ตรวจสอบก่อนติดตั้ง Onefile CMS</p>
      ${rows || '<p class="muted">Loading…</p>'}
      <div class="row">
        <button type="button" id="recheck">Recheck</button>
        <button type="button" class="primary" id="to-step2" ${st.can_continue ? '' : 'disabled'}>Next</button>
      </div>
    </div>`;
  } else if (st.step === 2) {
    body = `<div class="panel stack">
      <h1>Database</h1>
      <label>Driver
        <select id="db-driver">
          <option value="sqlite" ${st.db.driver==='sqlite'?'selected':''} ${st.drivers.sqlite?'':'disabled'}>SQLite</option>
          <option value="mysql" ${st.db.driver==='mysql'?'selected':''} ${st.drivers.mysql?'':'disabled'}>MySQL / MariaDB</option>
        </select>
      </label>
      <div id="sqlite-fields" class="${st.db.driver==='sqlite'?'':'hidden'} stack">
        <label>SQLite path (relative to index.php)
          <input id="db-path" value="${esc(st.db.path || 'data.sqlite')}">
        </label>
      </div>
      <div id="mysql-fields" class="${st.db.driver==='mysql'?'':'hidden'} stack">
        <label>Host <input id="db-host" value="${esc(st.db.host)}"></label>
        <label>Port <input id="db-port" type="number" value="${esc(st.db.port)}"></label>
        <label>Database name <input id="db-name" value="${esc(st.db.name)}"></label>
        <label>Username <input id="db-user" value="${esc(st.db.user)}"></label>
        <label>Password <input id="db-pass" type="password" value="${esc(st.db.pass)}"></label>
      </div>
      ${st.testMsg ? `<div class="success">${esc(st.testMsg)}</div>` : ''}
      <div class="row">
        <button type="button" id="to-step1">Back</button>
        <button type="button" id="test-db">Test connection</button>
        <button type="button" class="primary" id="to-step3">Next</button>
      </div>
    </div>`;
  } else if (st.step === 3) {
    body = `<div class="panel stack">
      <h1>Site & admin</h1>
      <label>Site title <input id="site-title" value="${esc(st.site_title)}"></label>
      <label>Admin username <input id="admin-user" value="${esc(st.username)}" autocomplete="username"></label>
      <label>Password <input id="admin-pass" type="password" value="${esc(st.password)}" autocomplete="new-password"></label>
      <label>Confirm password <input id="admin-pass2" type="password" value="${esc(st.password_confirm)}" autocomplete="new-password"></label>
      <div class="row">
        <button type="button" id="back-step2">Back</button>
        <button type="button" class="primary" id="do-install">Install</button>
      </div>
    </div>`;
  } else {
    body = `<div class="panel stack"><h1>Done</h1><p>Installation complete. Reloading…</p></div>`;
  }

  app.innerHTML = `<div class="hero-install">
    <div class="install-brand">${LOGO_SVG}<div><strong>Onefile</strong><div class="muted">A place for writing</div></div></div>
    <div class="steps">
      <span class="step-pill ${st.step===1?'on':''}">01 Env</span>
      <span class="step-pill ${st.step===2?'on':''}">02 Database</span>
      <span class="step-pill ${st.step===3?'on':''}">03 Admin</span>
      <span class="step-pill ${st.step===4?'on':''}">04 Finish</span>
    </div>
    ${banner()}${body}
  </div>`;

  const $ = (id) => document.getElementById(id);
  if (st.step === 1) {
    $('recheck') && $('recheck').addEventListener('click', () => loadInstallChecks());
    $('to-step2') && $('to-step2').addEventListener('click', () => { state.install.step = 2; state.error=''; render(); });
  }
  if (st.step === 2) {
    const syncDb = () => {
      state.install.db.driver = $('db-driver').value;
      state.install.db.path = $('db-path') ? $('db-path').value : state.install.db.path;
      state.install.db.host = $('db-host') ? $('db-host').value : state.install.db.host;
      state.install.db.port = $('db-port') ? parseInt($('db-port').value, 10) : state.install.db.port;
      state.install.db.name = $('db-name') ? $('db-name').value : state.install.db.name;
      state.install.db.user = $('db-user') ? $('db-user').value : state.install.db.user;
      state.install.db.pass = $('db-pass') ? $('db-pass').value : state.install.db.pass;
    };
    $('db-driver').addEventListener('change', () => { syncDb(); state.install.testMsg=''; render(); });
    $('to-step1').addEventListener('click', () => { state.install.step = 1; render(); });
    $('test-db').addEventListener('click', async () => {
      syncDb();
      state.error = '';
      try {
        const payload = state.install.db.driver === 'sqlite'
          ? { driver: 'sqlite', path: state.install.db.path }
          : { driver: 'mysql', host: state.install.db.host, port: state.install.db.port, name: state.install.db.name, user: state.install.db.user, pass: state.install.db.pass };
        const data = await api('setup_test_db', { body: payload });
        state.install.testMsg = data.message || 'OK';
        render();
      } catch (e) {
        state.error = e.message;
        state.install.testMsg = '';
        render();
      }
    });
    $('to-step3').addEventListener('click', () => { syncDb(); state.install.step = 3; state.error=''; render(); });
  }
  if (st.step === 3) {
    $('back-step2').addEventListener('click', () => { state.install.step = 2; render(); });
    $('do-install').addEventListener('click', async () => {
      state.install.site_title = $('site-title').value;
      state.install.username = $('admin-user').value;
      state.install.password = $('admin-pass').value;
      state.install.password_confirm = $('admin-pass2').value;
      state.error = '';
      try {
        const db = state.install.db.driver === 'sqlite'
          ? { driver: 'sqlite', path: state.install.db.path }
          : { driver: 'mysql', host: state.install.db.host, port: state.install.db.port, name: state.install.db.name, user: state.install.db.user, pass: state.install.db.pass };
        await api('setup_install', {
          body: {
            db,
            site_title: state.install.site_title,
            username: state.install.username,
            password: state.install.password,
            password_confirm: state.install.password_confirm
          }
        });
        state.install.step = 4;
        state.flash = 'Installed successfully';
        render();
        setTimeout(() => { location.hash = '#/'; location.reload(); }, 600);
      } catch (e) {
        state.error = e.message;
        render();
      }
    });
  }
}

async function loadInstallChecks() {
  state.error = '';
  try {
    const data = await api('setup_check');
    state.install.checks = data.checks;
    state.install.can_continue = data.can_continue;
    state.install.drivers = data.drivers;
    if (data.default_sqlite_path) state.install.db.path = data.default_sqlite_path;
    if (!data.drivers.sqlite && data.drivers.mysql) state.install.db.driver = 'mysql';
    render();
  } catch (e) {
    state.error = e.message;
    render();
  }
}

async function ensureSession() {
  if (!state.installed) return;
  const data = await api('me');
  state.user = data.user;
  state.csrf = data.csrf || state.csrf;
  state.settings = Object.assign({}, state.settings, data.settings || {});
  applyTheme();
}

function formatDate(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return String(iso);
  try {
    return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
  } catch (e) {
    return d.toDateString();
  }
}

function hashQuery() {
  const raw = (location.hash || '').split('?')[1] || '';
  const out = {};
  raw.split('&').filter(Boolean).forEach((pair) => {
    const [k, v] = pair.split('=');
    out[decodeURIComponent(k || '')] = decodeURIComponent(v || '');
  });
  return out;
}

function setHomeFilters(next) {
  const q = [];
  if (next.q) q.push('q=' + encodeURIComponent(next.q));
  if (next.status && next.status !== 'all') q.push('status=' + encodeURIComponent(next.status));
  if (next.tag) q.push('tag=' + encodeURIComponent(next.tag));
  location.hash = '#/' + (q.length ? '?' + q.join('&') : '');
}

function setSeoMeta(opts) {
  const title = opts.title || 'Onefile';
  document.title = title;
  const ensure = (selector, attr, create) => {
    let el = document.querySelector(selector);
    if (!el && create) {
      el = document.createElement('meta');
      if (create === 'name') el.setAttribute('name', attr);
      if (create === 'property') el.setAttribute('property', attr);
      document.head.appendChild(el);
    }
    return el;
  };
  const desc = ensure('meta[name="description"]', 'description', 'name');
  if (desc) desc.setAttribute('content', opts.description || '');
  const ogTitle = ensure('meta[property="og:title"]', 'og:title', 'property');
  if (ogTitle) ogTitle.setAttribute('content', title);
  const ogDesc = ensure('meta[property="og:description"]', 'og:description', 'property');
  if (ogDesc) ogDesc.setAttribute('content', opts.description || '');
  const ogType = ensure('meta[property="og:type"]', 'og:type', 'property');
  if (ogType) ogType.setAttribute('content', opts.type || 'website');
  let ogImage = document.querySelector('meta[property="og:image"]');
  if (opts.image) {
    if (!ogImage) {
      ogImage = document.createElement('meta');
      ogImage.setAttribute('property', 'og:image');
      document.head.appendChild(ogImage);
    }
    ogImage.setAttribute('content', opts.image);
  } else if (ogImage) {
    ogImage.remove();
  }
}

function tagsToInput(tags) {
  if (!Array.isArray(tags)) return '';
  return tags.map((t) => t.name || t).join(', ');
}

async function renderHome() {
  let listHtml = '<p class="muted">Loading…</p>';
  let total = 0;
  let tags = [];
  const siteTitle = state.settings.site_title || 'Onefile';
  const siteIntro = state.settings.site_intro || 'A home for the unusual, the unfinished, and the carefully kept.';
  const filters = Object.assign({ q: '', status: 'all', tag: '' }, hashQuery());
  if (!state.user) filters.status = 'all';
  try {
    let query = '&page=1';
    if (filters.q) query += '&q=' + encodeURIComponent(filters.q);
    if (state.user && filters.status && filters.status !== 'all') query += '&status=' + encodeURIComponent(filters.status);
    if (filters.tag) query += '&tag=' + encodeURIComponent(filters.tag);
    const data = await api('articles', { query });
    total = data.total || 0;
    tags = data.tags || [];
    if (!data.items.length) {
      listHtml = `<div class="empty-journal">
        <h2>${filters.q || filters.tag || filters.status !== 'all' ? 'No matches.' : (state.user ? 'The page is blank on purpose.' : 'Nothing on the shelf yet.')}</h2>
        <p>${filters.q || filters.tag || filters.status !== 'all'
          ? 'Try another keyword, tag, or clear filters.'
          : (state.user
            ? 'Start with one short note. Titles can be imperfect — the point is to begin.'
            : 'Published writing will appear here. Check back soon.')}</p>
        ${state.user && !(filters.q || filters.tag || filters.status !== 'all')
          ? `<div class="row"><button type="button" class="primary" data-go="#/articles/new">Write the first piece</button>
             <button type="button" id="btn-import-empty">Import</button></div>`
          : `<div class="row"><button type="button" id="clear-filters">Clear filters</button></div>`}
      </div>`;
    } else {
      listHtml = `<div class="list">${data.items.map(a => {
        const cover = a.cover ? `<img class="item-cover" src="${esc(a.cover)}" alt="">` : '';
        const excerpt = a.excerpt
          ? `<p class="item-excerpt">${esc(a.excerpt)}</p>`
          : `<p class="item-excerpt">No excerpt yet.</p>`;
        const tagHtml = (a.tags || []).length
          ? `<div class="item-tags">${a.tags.map((t) => `<span>${esc(t.name)}</span>`).join('')}</div>`
          : '';
        const badges = [];
        if (a.pinned == 1) badges.push('<span class="badge pin">Pinned</span>');
        if (state.user) {
          if (a.scheduled == 1) badges.push('<span class="badge sched">Scheduled</span>');
          else if (a.published == 1) badges.push('<span class="badge pub">Published</span>');
          else badges.push('<span class="badge">Draft</span>');
        }
        return `<article class="item ${a.pinned==1?'pinned-item':''}">
          <div class="item-main ${a.cover ? '' : 'no-cover'}">
            ${cover}
            <div class="item-copy">
              <h2><a href="#/articles/${a.id}">${esc(a.title)}</a></h2>
              <div class="item-meta">
                <span>${esc(formatDate(a.updated_at || a.created_at))}</span>
                <span>${a.reading_time || 1} min read</span>
                ${badges.join('')}
                ${state.user && a.publish_at && a.scheduled == 1 ? `<span class="muted">${esc(formatDate(a.publish_at))}</span>` : ''}
              </div>
              ${excerpt}
              ${tagHtml}
            </div>
          </div>
          ${state.user ? `<div class="item-actions">
            <button type="button" data-go="#/articles/${a.id}/edit">Edit</button>
            <button type="button" class="btn-danger" data-del="${a.id}">Delete</button>
          </div>` : ''}
        </article>`;
      }).join('')}</div>`;
    }
  } catch (e) {
    state.error = e.message;
    listHtml = '';
  }

  const heroTitle = total > 0 ? 'Notes & essays' : 'Begin here';
  const statusSelect = state.user ? `<select id="f-status">
      <option value="all" ${filters.status==='all'?'selected':''}>All</option>
      <option value="published" ${filters.status==='published'?'selected':''}>Published</option>
      <option value="draft" ${filters.status==='draft'?'selected':''}>Drafts</option>
      <option value="scheduled" ${filters.status==='scheduled'?'selected':''}>Scheduled</option>
      <option value="pinned" ${filters.status==='pinned'?'selected':''}>Pinned</option>
    </select>` : '';
  app.innerHTML = `<div class="wrap">${topbar()}${banner()}
    <div class="panel">
      <div class="journal-hero">
        <div class="kicker">Journal</div>
        <h1>${esc(heroTitle)}</h1>
        <p class="lede">${esc(siteIntro)}</p>
      </div>
      <div class="filters">
        <input id="f-q" type="search" placeholder="Search titles and text…" value="${esc(filters.q || '')}">
        ${statusSelect}
        <button type="button" class="primary" id="f-go">Search</button>
      </div>
      ${tags.length ? `<div class="tag-cloud">
        <button type="button" class="tag-chip ${!filters.tag?'on':''}" data-tag="">All tags</button>
        ${tags.map((t) => `<button type="button" class="tag-chip ${filters.tag===t.slug?'on':''}" data-tag="${esc(t.slug)}">${esc(t.name)} (${esc(String(t.article_count||0))})</button>`).join('')}
      </div>` : ''}
      ${listHtml}
    </div></div>`;
  bindChrome();
  setSeoMeta({
    title: (state.settings.site_title || 'Onefile') + ' — Journal',
    description: 'A home for notes, essays, and unfinished thoughts.',
    type: 'website'
  });
  const applyFilters = () => {
    setHomeFilters({
      q: (document.getElementById('f-q') || {}).value || '',
      status: (document.getElementById('f-status') || {}).value || 'all',
      tag: filters.tag || ''
    });
  };
  const goBtn = document.getElementById('f-go');
  if (goBtn) goBtn.addEventListener('click', applyFilters);
  const qInput = document.getElementById('f-q');
  if (qInput) qInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') applyFilters(); });
  document.querySelectorAll('[data-tag]').forEach((btn) => {
    btn.addEventListener('click', () => {
      setHomeFilters({
        q: (document.getElementById('f-q') || {}).value || '',
        status: (document.getElementById('f-status') || {}).value || 'all',
        tag: btn.getAttribute('data-tag') || ''
      });
    });
  });
  const clearBtn = document.getElementById('clear-filters');
  if (clearBtn) clearBtn.addEventListener('click', () => { location.hash = '#/'; });
  const importEmpty = document.getElementById('btn-import-empty');
  if (importEmpty) importEmpty.addEventListener('click', () => openImportDialog());
}

async function renderView(id) {
  try {
    const a = await api('article_get', { query: '&id=' + encodeURIComponent(id) });
    const tags = (a.tags || []).map((t) => `<button type="button" class="tag-chip" data-tag="${esc(t.slug)}">${esc(t.name)}</button>`).join('');
    const prev = a.prev ? `<a href="#/articles/${a.prev.id}"><span class="dir">Previous</span><span class="ttl">${esc(a.prev.title)}</span></a>` : `<div></div>`;
    const next = a.next ? `<a href="#/articles/${a.next.id}" style="text-align:right"><span class="dir">Next</span><span class="ttl">${esc(a.next.title)}</span></a>` : `<div></div>`;
    app.innerHTML = `<div class="wrap">${topbar()}${banner()}
      <div class="panel">
        <div class="row" style="justify-content:space-between;align-items:flex-start">
          <div>
            <div class="kicker">Reading</div>
            <h1>${esc(a.title)}</h1>
            <div class="reading-meta">
              <span>${esc(formatDate(a.updated_at || a.created_at))}</span>
              <span>${a.reading_time || 1} min read</span>
              ${articleStatusBadge(Object.assign({}, a, { scheduled: a.published!=1 && a.publish_at ? 1 : 0 }), { forPublic: !state.user })}
            </div>
            ${tags ? `<div class="tag-cloud" style="margin-top:.85rem">${tags}</div>` : ''}
          </div>
          <div class="row">
            <button type="button" data-go="#/">← Journal</button>
            ${state.user ? `<button type="button" data-go="#/articles/${a.id}/edit">Edit</button>` : ''}
          </div>
        </div>
        <div class="article-body">${renderBodyHtml(a.body)}</div>
        <div class="reading-nav">${prev}${next}</div>
      </div></div>`;
    bindChrome();
    setSeoMeta({
      title: a.title + ' — ' + (state.settings.site_title || 'Onefile'),
      description: a.meta_description || a.excerpt || '',
      image: a.cover || '',
      type: 'article'
    });
    app.querySelectorAll('[data-tag]').forEach((btn) => {
      btn.addEventListener('click', () => {
        setHomeFilters({ q: '', status: 'all', tag: btn.getAttribute('data-tag') || '' });
      });
    });
  } catch (e) {
    state.error = e.message;
    go('#/');
  }
}

async function renderEdit(id) {
  if (!state.user) { go('#/login'); return; }
  let article = { id: null, title: '', body: '', published: 0, slug: '', meta_description: '', tags: [], pinned: 0, publish_at: '' };
  if (id) {
    try { article = await api('article_get', { query: '&id=' + encodeURIComponent(id) }); }
    catch (e) { state.error = e.message; go('#/'); return; }
  }
  const localKey = 'onefile-draft-' + (article.id || 'new');
  const publishLocal = toDatetimeLocalValue(article.publish_at || '');
  app.innerHTML = `<div class="wrap">${topbar()}${banner()}
    <div class="panel stack">
      <div class="row" style="justify-content:space-between;align-items:end">
        <div>
          <div class="kicker">${article.id ? 'Edit' : 'Compose'}</div>
          <h1>${article.id ? 'Edit article' : 'New article'}</h1>
        </div>
        <div class="autosave-status" id="autosave-status">Autosave idle</div>
      </div>
      <label>Title <input id="a-title" value="${esc(article.title === 'Untitled draft' ? '' : (article.title || ''))}" placeholder="Title"></label>
      <label>Slug <input id="a-slug" value="${esc(article.slug || '')}" placeholder="url-slug"></label>
      <label>SEO description <textarea id="a-meta" rows="2" placeholder="Short summary for search and social shares">${esc(article.meta_description || '')}</textarea></label>
      <label>Tags <input id="a-tags" value="${esc(tagsToInput(article.tags || []))}" placeholder="essay, notes, travel"></label>
      <label>Body
        <div class="editor">
          ${editorToolbarHtml()}
          <div id="a-body" class="editor-surface" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="Start writing…"></div>
        </div>
      </label>
      <div class="row" style="align-items:center;flex-wrap:wrap">
        <label class="checkbox"><input type="checkbox" id="a-pub" ${article.published==1?'checked':''}> Published</label>
        <label class="checkbox"><input type="checkbox" id="a-pin" ${article.pinned==1?'checked':''}> Pin to top</label>
      </div>
      <label>Schedule publish <input id="a-schedule" type="datetime-local" value="${esc(publishLocal)}">
        <span class="muted" style="display:block;margin-top:.35rem;font-size:.85rem">Leave empty to publish now (if checked) or keep as draft. Future times force draft until due.</span>
      </label>
      <div class="row">
        <button type="button" class="primary" id="a-save">Save</button>
        <button type="button" data-go="#/">Cancel</button>
      </div>
      ${article.id ? `<hr class="rule">
        <div class="kicker">History</div>
        <h2 style="font-size:1.35rem;margin:0">Revisions</h2>
        <p class="muted">Manual saves keep up to 30 snapshots. Restore replaces the current draft.</p>
        <div id="rev-list" class="muted">Loading revisions…</div>` : ''}
    </div></div>`;
  bindChrome();
  mountEditor('a-body', article.body || '');
  let currentId = article.id || 0;
  let saveTimer = null;
  let saving = false;
  const statusEl = document.getElementById('autosave-status');
  const collectPayload = (autosave) => ({
    id: currentId || 0,
    title: document.getElementById('a-title').value,
    slug: document.getElementById('a-slug').value,
    meta_description: document.getElementById('a-meta').value,
    tags: document.getElementById('a-tags').value,
    body: getEditorHtml('a-body'),
    published: document.getElementById('a-pub').checked ? 1 : 0,
    pinned: document.getElementById('a-pin').checked ? 1 : 0,
    publish_at: document.getElementById('a-schedule').value || '',
    autosave: !!autosave
  });
  const persistLocal = () => {
    try {
      localStorage.setItem(localKey, JSON.stringify(Object.assign({ saved_at: Date.now() }, collectPayload(true))));
    } catch (e) {}
  };
  const runAutosave = async () => {
    if (saving) return;
    const payload = collectPayload(true);
    if (!payload.title && !payload.body) return;
    saving = true;
    statusEl.textContent = 'Saving…';
    statusEl.classList.add('on');
    try {
      persistLocal();
      const data = await api('article_save', { body: payload });
      if (!currentId && data.id) {
        currentId = data.id;
        history.replaceState(null, '', '#/articles/' + data.id + '/edit');
      }
      if (data.slug && !document.getElementById('a-slug').value) {
        document.getElementById('a-slug').value = data.slug;
      }
      statusEl.textContent = 'Saved ' + new Date().toLocaleTimeString();
    } catch (e) {
      statusEl.textContent = 'Autosave failed';
      statusEl.classList.remove('on');
    } finally {
      saving = false;
    }
  };
  const scheduleAutosave = () => {
    persistLocal();
    statusEl.textContent = 'Unsaved changes';
    statusEl.classList.remove('on');
    clearTimeout(saveTimer);
    saveTimer = setTimeout(runAutosave, 1800);
  };
  ['a-title', 'a-slug', 'a-meta', 'a-tags', 'a-pub', 'a-pin', 'a-schedule'].forEach((idName) => {
    const el = document.getElementById(idName);
    el.addEventListener('input', scheduleAutosave);
    el.addEventListener('change', scheduleAutosave);
  });
  document.getElementById('a-body').addEventListener('input', scheduleAutosave);
  document.getElementById('a-save').addEventListener('click', async () => {
    state.error = '';
    clearTimeout(saveTimer);
    try {
      const data = await api('article_save', { body: collectPayload(false) });
      try { localStorage.removeItem(localKey); } catch (e) {}
      state.flash = data.publish_at ? 'Scheduled' : 'Saved';
      go('#/articles/' + data.id);
    } catch (e) {
      state.error = e.message;
      await uiAlert(e.message, { title: 'Save failed' });
    }
  });
  const loadRevisions = async () => {
    const box = document.getElementById('rev-list');
    if (!box || !currentId) return;
    try {
      const data = await api('revisions_list', { query: '&id=' + encodeURIComponent(currentId) });
      const items = data.items || [];
      if (!items.length) {
        box.innerHTML = '<p class="muted">No revisions yet. Save once to create the first snapshot.</p>';
        return;
      }
      box.innerHTML = `<ul class="dash-list">${items.map((r) => `<li>
        <span><strong>${esc(formatDate(r.created_at))}</strong> · ${esc(r.username || '—')} · ${esc(r.title)} <span class="muted">(${esc(String(r.body_size||0))} chars)</span></span>
        <span class="row">
          <button type="button" data-rev-preview="${r.id}">Preview</button>
          <button type="button" data-rev-restore="${r.id}">Restore</button>
        </span>
      </li>`).join('')}</ul>`;
      box.querySelectorAll('[data-rev-preview]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          try {
            const rev = await api('revision_get', { query: '&id=' + btn.getAttribute('data-rev-preview') });
            await uiDialog({
              mode: 'alert',
              kicker: 'Revision',
              title: rev.title || 'Revision',
              html: `<p class="muted">${esc(formatDate(rev.created_at))} · ${esc(rev.username || '')}</p>
                     <div class="article-body" style="max-height:50vh;overflow:auto">${renderBodyHtml(rev.body || '')}</div>`,
              okText: 'Close'
            });
          } catch (e) {
            await uiAlert(e.message, { title: 'Preview failed' });
          }
        });
      });
      box.querySelectorAll('[data-rev-restore]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const ok = await uiConfirm('Restore this revision? Current content will be saved as a new revision first.', {
            title: 'Restore revision',
            okText: 'Restore'
          });
          if (!ok) return;
          try {
            await api('revision_restore', { body: { id: parseInt(btn.getAttribute('data-rev-restore'), 10) } });
            state.flash = 'Revision restored';
            render();
          } catch (e) {
            await uiAlert(e.message, { title: 'Restore failed' });
          }
        });
      });
    } catch (e) {
      box.textContent = e.message;
    }
  };
  loadRevisions();
}

async function renderDashboard() {
  if (!state.user) { go('#/login'); return; }
  let data = { stats: {}, recent_articles: [], recent_audits: [] };
  try {
    data = await api('dashboard');
  } catch (e) {
    state.error = e.message;
  }
  const s = data.stats || {};
  app.innerHTML = `<div class="wrap">${topbar()}${banner()}
    <div class="panel stack">
      <div class="kicker">Admin</div>
      <h1>Dashboard</h1>
      <p class="muted">Snapshot of writing, media, and recent activity.</p>
      <div class="stats-grid">
        <div class="stat-card"><div class="label">Articles</div><div class="value">${esc(String(s.articles||0))}</div></div>
        <div class="stat-card"><div class="label">Published</div><div class="value">${esc(String(s.published||0))}</div></div>
        <div class="stat-card"><div class="label">Drafts</div><div class="value">${esc(String(s.drafts||0))}</div></div>
        <div class="stat-card"><div class="label">Tags</div><div class="value">${esc(String(s.tags||0))}</div></div>
        <div class="stat-card"><div class="label">Uploads</div><div class="value">${esc(String(s.uploads||0))}</div></div>
        <div class="stat-card"><div class="label">Audit events</div><div class="value">${esc(String(s.audits||0))}</div></div>
      </div>
      <div class="row">
        <button type="button" class="primary" data-go="#/articles/new">New article</button>
        <button type="button" id="btn-import-dash">Import</button>
        <a class="btn" href="?action=rss" target="_blank" rel="noopener">RSS</a>
        <button type="button" data-go="#/media">Media</button>
        <button type="button" data-go="#/audit">Audit log</button>
        <button type="button" data-go="#/settings">Settings</button>
      </div>
      <div class="dash-cols">
        <div>
          <div class="kicker">Recent writing</div>
          <ul class="dash-list">
            ${(data.recent_articles||[]).map((a) => `<li>
              <a href="#/articles/${a.id}">${esc(a.title)}</a>
              <span class="muted">${a.published==1?'Published':'Draft'} · ${esc(formatDate(a.updated_at))}</span>
            </li>`).join('') || '<li class="muted">No articles yet.</li>'}
          </ul>
        </div>
        <div>
          <div class="kicker">Recent activity</div>
          <ul class="dash-list">
            ${(data.recent_audits||[]).map((a) => `<li>
              <span><strong>${esc(a.action)}</strong> · ${esc(a.username || '—')}</span>
              <span class="muted">${esc(formatDate(a.created_at))}</span>
            </li>`).join('') || '<li class="muted">No activity yet.</li>'}
          </ul>
        </div>
      </div>
    </div></div>`;
  bindChrome();
  setSeoMeta({ title: 'Dashboard — ' + (state.settings.site_title || 'Onefile'), description: 'Admin dashboard' });
}

async function renderLogin() {
  if (state.user) { go('#/'); return; }
  if (state.login2fa) {
    app.innerHTML = `<div class="wrap">${topbar()}${banner()}
      <div class="panel stack" style="max-width:420px;margin:0 auto">
        <div class="kicker">Security</div>
        <h1>Authenticator code</h1>
        <p class="muted">Enter the 6-digit code from your authenticator app.</p>
        <label>Code <input id="login-otp" inputmode="numeric" autocomplete="one-time-code" maxlength="8" placeholder="123456"></label>
        <div class="row">
          <button type="button" class="primary" id="login-2fa">Verify</button>
          <button type="button" id="login-2fa-back">Back</button>
        </div>
      </div></div>`;
    bindChrome();
    document.getElementById('login-2fa-back').addEventListener('click', () => {
      state.login2fa = false;
      render();
    });
    document.getElementById('login-2fa').addEventListener('click', async () => {
      state.error = '';
      try {
        const data = await api('login_2fa', { body: { code: document.getElementById('login-otp').value } });
        state.login2fa = false;
        state.user = data.user;
        state.csrf = data.csrf || state.csrf;
        state.flash = 'Welcome';
        go('#/');
      } catch (e) {
        state.error = e.message;
        render();
      }
    });
    return;
  }
  app.innerHTML = `<div class="wrap">${topbar()}${banner()}
    <div class="panel stack" style="max-width:420px;margin:0 auto">
      <h1>Login</h1>
      <label>Username <input id="login-user" autocomplete="username"></label>
      <label>Password <input id="login-pass" type="password" autocomplete="current-password"></label>
      <button type="button" class="primary" id="login-go">Sign in</button>
    </div></div>`;
  bindChrome();
  document.getElementById('login-go').addEventListener('click', async () => {
    state.error = '';
    try {
      const data = await api('login', {
        body: {
          username: document.getElementById('login-user').value,
          password: document.getElementById('login-pass').value
        }
      });
      state.csrf = data.csrf || state.csrf;
      if (data.need_2fa) {
        state.login2fa = true;
        state.flash = data.message || 'Enter authenticator code';
        render();
        return;
      }
      state.user = data.user;
      state.flash = 'Welcome';
      go('#/');
    } catch (e) {
      state.error = e.message;
      render();
    }
  });
}

async function renderSettings() {
  if (!state.user) { go('#/login'); return; }
  let settings = state.settings;
  let ht = { exists: false, writable: false };
  try { settings = await api('settings_get'); state.settings = Object.assign({}, state.settings, settings); applyTheme(); }
  catch (e) { state.error = e.message; }
  try { ht = await api('htaccess_status'); } catch (e) {}

  const totpOn = settings.totp_enabled === '1' || settings.totp_enabled === 1 || settings.totp_enabled === true;
  app.innerHTML = `<div class="wrap">${topbar()}${banner()}
    <div class="panel stack">
      <div class="kicker">System</div>
      <h1>Settings</h1>
      <label>Site title <input id="s-title" value="${esc(settings.site_title || '')}"></label>
      <label>Homepage intro <textarea id="s-intro" rows="3" placeholder="Short line under the journal title">${esc(settings.site_intro || '')}</textarea></label>
      <label>Theme
        <select id="s-theme">
          <option value="system" ${settings.theme==='system'||!settings.theme?'selected':''}>Follow system</option>
          <option value="light" ${settings.theme==='light'?'selected':''}>Light</option>
          <option value="dark" ${settings.theme==='dark'?'selected':''}>Dark</option>
        </select>
      </label>
      <label>Posts per page <input id="s-ppp" type="number" min="1" max="100" value="${esc(settings.posts_per_page || '10')}"></label>
      <p class="muted">Public RSS feed: <a href="?action=rss" target="_blank" rel="noopener">?action=rss</a></p>
      <div class="row"><button type="button" class="primary" id="s-save">Save settings</button>
        <button type="button" data-go="#/file">Edit self file</button>
        <button type="button" data-go="#/audit">Audit log</button></div>
      <hr class="rule">
      <div class="kicker">Security</div>
      <h1 style="font-size:1.6rem">Password</h1>
      <label>Current password <input id="p-cur" type="password"></label>
      <label>New password <input id="p-new" type="password"></label>
      <label>Confirm <input id="p-new2" type="password"></label>
      <div class="row"><button type="button" id="p-save">Update password</button></div>
      <h1 style="font-size:1.6rem;margin-top:1.5rem">Two-factor authentication</h1>
      <p class="muted">Status: <strong>${totpOn ? 'Enabled' : 'Disabled'}</strong>. Failed logins lock for 15 minutes after repeated attempts.</p>
      <div id="totp-panel" class="stack">
        ${totpOn
          ? `<label>Authenticator code <input id="totp-code" inputmode="numeric" autocomplete="one-time-code" maxlength="8"></label>
             <label>Current password <input id="totp-pass" type="password"></label>
             <div class="row"><button type="button" class="btn-danger" id="totp-disable">Disable 2FA</button></div>`
          : `<div class="row"><button type="button" class="primary" id="totp-setup">Set up 2FA</button></div>
             <div id="totp-setup-box" class="hidden stack">
               <p class="muted">Add this secret to your authenticator app, then confirm with a code.</p>
               <label>Secret <input id="totp-secret" readonly></label>
               <p class="muted" id="totp-otpauth" style="word-break:break-all;font-size:.8rem"></p>
               <label>Confirm code <input id="totp-enable-code" inputmode="numeric" maxlength="8"></label>
               <div class="row"><button type="button" class="primary" id="totp-enable">Enable 2FA</button></div>
             </div>`}
      </div>
      <hr class="rule">
      <div class="kicker">Data</div>
      <h1 style="font-size:1.6rem">Backup / Restore</h1>
      <p class="muted">Export a JSON backup of articles, settings, users, and recent audit logs. Restore replaces content.</p>
      <div class="row">
        <button type="button" class="primary" id="bk-export">Download backup</button>
        <button type="button" id="bk-restore">Restore backup</button>
        <input id="bk-file" type="file" accept="application/json,.json" class="hidden">
      </div>
      <label class="checkbox" style="margin-top:.5rem"><input type="checkbox" id="bk-users"> Also restore users from backup</label>
      <hr class="rule">
      <div class="kicker">Danger zone</div>
      <h1 style="font-size:1.6rem">Clear data</h1>
      <p class="muted">Clear content without uninstalling. Type <strong>CLEAR</strong> to confirm.</p>
      <div class="row">
        <button type="button" class="btn-danger" id="clr-content">Clear articles</button>
        <button type="button" class="btn-danger" id="clr-audit">Clear audit</button>
        <button type="button" class="btn-danger" id="clr-all">Clear all</button>
      </div>
      <hr class="rule">
      <div class="kicker">Apache</div>
      <h1 style="font-size:1.6rem">.htaccess</h1>
      <p class="muted">Rules live inside <strong>index.php</strong> (<code>HTACCESS_TEMPLATE</code>) and are written out to disk for Apache.
        Protects <strong>*.sqlite</strong> / backup / temp. Status: ${ht.exists ? 'present on disk' : 'missing on disk'}${ht.writable ? '' : ' (not writable)'}</p>
      <label>Embedded template (read-only preview)
        <textarea class="code" readonly rows="12">${esc(ht.preview || '')}</textarea>
      </label>
      <div class="row">
        <button type="button" class="primary" id="ht-write">${ht.exists ? 'Rewrite .htaccess from index.php' : 'Write .htaccess from index.php'}</button>
      </div>
    </div></div>`;
  bindChrome();
  document.getElementById('s-save').addEventListener('click', async () => {
    state.error = '';
    try {
      const data = await api('settings_save', {
        body: {
          site_title: document.getElementById('s-title').value,
          site_intro: document.getElementById('s-intro').value,
          theme: document.getElementById('s-theme').value,
          posts_per_page: document.getElementById('s-ppp').value
        }
      });
      state.settings = Object.assign({}, state.settings, data);
      applyTheme();
      state.flash = 'Settings saved';
      render();
    } catch (e) { state.error = e.message; render(); }
  });
  const totpSetupBtn = document.getElementById('totp-setup');
  if (totpSetupBtn) {
    totpSetupBtn.addEventListener('click', async () => {
      try {
        const data = await api('totp_setup', { body: {} });
        const box = document.getElementById('totp-setup-box');
        box.classList.remove('hidden');
        document.getElementById('totp-secret').value = data.secret || '';
        document.getElementById('totp-otpauth').textContent = data.otpauth || '';
      } catch (e) {
        await uiAlert(e.message, { title: '2FA setup failed' });
      }
    });
  }
  const totpEnableBtn = document.getElementById('totp-enable');
  if (totpEnableBtn) {
    totpEnableBtn.addEventListener('click', async () => {
      try {
        await api('totp_enable', { body: { code: document.getElementById('totp-enable-code').value } });
        state.flash = '2FA enabled';
        render();
      } catch (e) {
        await uiAlert(e.message, { title: 'Could not enable 2FA' });
      }
    });
  }
  const totpDisableBtn = document.getElementById('totp-disable');
  if (totpDisableBtn) {
    totpDisableBtn.addEventListener('click', async () => {
      try {
        await api('totp_disable', {
          body: {
            code: document.getElementById('totp-code').value,
            password: document.getElementById('totp-pass').value
          }
        });
        state.flash = '2FA disabled';
        render();
      } catch (e) {
        await uiAlert(e.message, { title: 'Could not disable 2FA' });
      }
    });
  }
  document.getElementById('p-save').addEventListener('click', async () => {
    state.error = '';
    try {
      await api('password_change', {
        body: {
          current_password: document.getElementById('p-cur').value,
          new_password: document.getElementById('p-new').value,
          new_password_confirm: document.getElementById('p-new2').value
        }
      });
      state.flash = 'Password updated';
      render();
    } catch (e) { state.error = e.message; render(); }
  });
  document.getElementById('bk-export').addEventListener('click', async () => {
    try {
      const data = await api('backup_export');
      const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'onefile-backup-' + new Date().toISOString().slice(0,10) + '.json';
      a.click();
      URL.revokeObjectURL(a.href);
      state.flash = 'Backup downloaded';
      await uiAlert('Backup file downloaded.', { title: 'Backup ready' });
    } catch (e) {
      await uiAlert(e.message, { title: 'Backup failed' });
    }
  });
  document.getElementById('bk-restore').addEventListener('click', () => document.getElementById('bk-file').click());
  document.getElementById('bk-file').addEventListener('change', async (ev) => {
    const file = ev.target.files && ev.target.files[0];
    if (!file) return;
    const ok = await uiConfirm('Restore will replace articles and settings from this backup.', {
      title: 'Restore backup',
      okText: 'Restore',
      danger: true
    });
    if (!ok) { ev.target.value = ''; return; }
    try {
      const text = await file.text();
      const backup = JSON.parse(text);
      backup.replace_users = document.getElementById('bk-users').checked;
      await api('backup_restore', { body: { backup } });
      state.flash = 'Backup restored';
      await uiAlert('Restore completed.', { title: 'Done' });
      render();
    } catch (e) {
      await uiAlert(e.message, { title: 'Restore failed' });
    }
    ev.target.value = '';
  });
  const clearRun = async (mode, label) => {
    const typed = await uiPrompt('Type CLEAR to ' + label + '.', {
      title: 'Danger zone',
      placeholder: 'CLEAR',
      okText: 'Clear',
      danger: true
    });
    if (typed === null) return;
    if (typed !== 'CLEAR') {
      await uiAlert('Confirmation text did not match CLEAR.', { title: 'Cancelled' });
      return;
    }
    try {
      await api('data_clear', { body: { mode, confirm: 'CLEAR' } });
      state.flash = 'Cleared: ' + mode;
      await uiAlert('Clear completed (' + mode + ').', { title: 'Done' });
      render();
    } catch (e) {
      await uiAlert(e.message, { title: 'Clear failed' });
    }
  };
  document.getElementById('clr-content').addEventListener('click', () => clearRun('content', 'delete all articles'));
  document.getElementById('clr-audit').addEventListener('click', () => clearRun('audit', 'wipe audit logs'));
  document.getElementById('clr-all').addEventListener('click', () => clearRun('all', 'clear articles, audit, and reset settings'));
  document.getElementById('ht-write').addEventListener('click', async () => {
    const overwrite = !!ht.exists;
    if (overwrite) {
      const ok = await uiConfirm('Overwrite the existing .htaccess with the starter rules?', {
        title: 'Write .htaccess',
        okText: 'Overwrite'
      });
      if (!ok) return;
    }
    try {
      const res = await api('htaccess_write', { body: { overwrite } });
      state.flash = res.message || 'htaccess written';
      await uiAlert(res.message || 'Written.', { title: '.htaccess' });
      render();
    } catch (e) {
      await uiAlert(e.message, { title: 'Write failed' });
    }
  });
}

async function renderMedia() {
  if (!state.user) { go('#/login'); return; }
  let items = [];
  let allowed = [];
  try {
    const data = await api('upload_list');
    items = data.items || [];
    allowed = data.allowed || [];
  } catch (e) {
    state.error = e.message;
  }
  const cards = items.map((f) => `
    <div class="media-card">
      ${f.is_image ? `<img src="${esc(f.url)}" alt="">` : `<div class="meta" style="height:90px;display:flex;align-items:center;justify-content:center;border:1px solid var(--line)">.${esc(f.ext)}</div>`}
      <div class="name">${esc(f.name)}</div>
      <div class="meta">${Math.round((f.size || 0) / 1024)} KB · ${esc(f.url)}</div>
      <div class="media-actions">
        <button type="button" data-copy="${esc(f.url)}">Copy URL</button>
        <button type="button" class="btn-danger" data-del-media="${esc(f.name)}">Delete</button>
      </div>
    </div>`).join('') || `<p class="muted">No uploads yet.</p>`;
  app.innerHTML = `<div class="wrap">${topbar()}${banner()}
    <div class="panel stack">
      <div class="kicker">Library</div>
      <h1>Media</h1>
      <p class="muted">Allowed: ${esc(allowed.join(', ') || 'jpg png gif webp pdf txt md zip')} · max 5MB. Uploads folder is protected against PHP execution.</p>
      <div class="row">
        <input type="file" id="media-upload" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.md,.zip,image/*">
        <button type="button" class="primary" id="media-upload-btn">Upload</button>
      </div>
      <div class="media-grid">${cards}</div>
    </div></div>`;
  bindChrome();
  document.getElementById('media-upload-btn').addEventListener('click', async () => {
    const input = document.getElementById('media-upload');
    const file = input.files && input.files[0];
    if (!file) {
      await uiAlert('Choose a file first.', { title: 'Upload' });
      return;
    }
    try {
      await apiUpload(file);
      state.flash = 'Uploaded';
      render();
    } catch (e) {
      await uiAlert(e.message, { title: 'Upload failed' });
    }
  });
  app.querySelectorAll('[data-copy]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const url = btn.getAttribute('data-copy');
      try {
        await navigator.clipboard.writeText(url);
        state.flash = 'URL copied';
      } catch (e) {
        await uiPrompt('Copy this URL', { title: 'File URL', defaultValue: url, okText: 'Close' });
      }
    });
  });
  app.querySelectorAll('[data-del-media]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const name = btn.getAttribute('data-del-media');
      const ok = await uiConfirm('Delete ' + name + ' permanently?', {
        title: 'Delete file',
        okText: 'Delete',
        danger: true
      });
      if (!ok) return;
      try {
        await api('upload_delete', { body: { name } });
        state.flash = 'Deleted';
        render();
      } catch (e) {
        await uiAlert(e.message, { title: 'Delete failed' });
      }
    });
  });
}

async function renderAudit() {
  if (!state.user) { go('#/login'); return; }
  let page = 1;
  const hash = location.hash || '';
  const m = hash.match(/page=(\d+)/);
  if (m) page = parseInt(m[1], 10);
  let data = { items: [], page: 1, total_pages: 1, total: 0 };
  try {
    data = await api('audit_list', { query: '&page=' + page });
  } catch (e) {
    state.error = e.message;
  }
  const rows = (data.items || []).map((row) => `<tr>
    <td>${esc(row.created_at || '')}</td>
    <td>${esc(row.username || '—')}</td>
    <td class="audit-action">${esc(row.action || '')}</td>
    <td class="audit-detail">${esc(row.detail || '')}</td>
    <td>${esc(row.ip || '')}</td>
  </tr>`).join('') || `<tr><td colspan="5" class="muted">No audit events yet.</td></tr>`;
  app.innerHTML = `<div class="wrap">${topbar()}${banner()}
    <div class="panel stack">
      <div class="kicker">History</div>
      <h1>Audit log</h1>
      <p class="muted">${data.total || 0} events · page ${data.page || 1} / ${data.total_pages || 1}</p>
      <div style="overflow:auto">
        <table class="audit-table">
          <thead><tr><th>When</th><th>User</th><th>Action</th><th>Detail</th><th>IP</th></tr></thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
      <div class="row">
        <button type="button" id="aud-prev" ${(data.page||1)<=1?'disabled':''}>Previous</button>
        <button type="button" id="aud-next" ${(data.page||1)>=(data.total_pages||1)?'disabled':''}>Next</button>
        <button type="button" data-go="#/settings">Back</button>
      </div>
    </div></div>`;
  bindChrome();
  const prev = document.getElementById('aud-prev');
  const next = document.getElementById('aud-next');
  if (prev) prev.addEventListener('click', () => { location.hash = '#/audit?page=' + Math.max(1, (data.page||1) - 1); });
  if (next) next.addEventListener('click', () => { location.hash = '#/audit?page=' + ((data.page||1) + 1); });
}

async function renderFile() {
  if (!state.user) { go('#/login'); return; }
  let meta = { path: '', size: 0, mtime: 0, writable: false };
  let content = state.fileDraft != null ? state.fileDraft : '';
  try {
    const data = await api('file_self_get');
    meta = data;
    if (state.fileDraft == null) content = data.content || '';
  } catch (e) {
    state.error = e.message;
  }
  const mtime = meta.mtime ? new Date(meta.mtime * 1000).toISOString() : '—';
  app.innerHTML = `<div class="wrap">${topbar()}${banner()}
    <div class="panel stack">
      <div class="kicker">Introspection</div>
      <h1>Self file</h1>
      <p class="muted">Read and write this single <strong>index.php</strong>. Syntax is checked with php -l before replace.</p>
      <div class="meta-line">
        <span>${esc(meta.basename || 'index.php')}</span>
        <span>${meta.size || 0} bytes</span>
        <span>${esc(mtime)}</span>
        <span>${meta.writable ? 'Writable' : 'Read-only'}</span>
      </div>
      <label>Source<textarea id="self-src" class="code" spellcheck="false">${esc(content)}</textarea></label>
      <div class="row">
        <button type="button" class="primary" id="self-save" ${meta.writable ? '' : 'disabled'}>Write file</button>
        <button type="button" id="self-reload">Reload</button>
        <button type="button" data-go="#/settings">Back</button>
      </div>
    </div></div>`;
  bindChrome();
  document.getElementById('self-reload').addEventListener('click', () => {
    state.fileDraft = null;
    state.error = '';
    state.flash = '';
    render();
  });
  const saveBtn = document.getElementById('self-save');
  if (saveBtn && !saveBtn.disabled) {
    saveBtn.addEventListener('click', async () => {
      const ok = await uiConfirm('Write changes to index.php? A syntax error can break the app.', {
        title: 'Write self file',
        okText: 'Write file',
        danger: true
      });
      if (!ok) return;
      const src = document.getElementById('self-src').value;
      state.fileDraft = src;
      state.error = '';
      try {
        const result = await api('file_self_put', { body: { content: src } });
        state.fileDraft = null;
        state.flash = (result && result.message) ? result.message : 'File written';
        await uiAlert('File written successfully. Reloading…', { title: 'Saved' });
        location.reload();
      } catch (e) {
        state.error = e.message;
        await uiAlert(e.message, { title: 'Write failed' });
        render();
      }
    });
  }
}

function bindChrome() {
  app.querySelectorAll('[data-go]').forEach(el => {
    el.addEventListener('click', () => go(el.getAttribute('data-go')));
  });
  const importBtn = document.getElementById('btn-import');
  if (importBtn) importBtn.addEventListener('click', () => openImportDialog());
  const importDash = document.getElementById('btn-import-dash');
  if (importDash) importDash.addEventListener('click', () => openImportDialog());
  const logout = document.getElementById('btn-logout');
  if (logout) {
    logout.addEventListener('click', async () => {
      const ok = await uiConfirm('Sign out of the admin session?', {
        title: 'Logout',
        okText: 'Logout'
      });
      if (!ok) return;
      try { await api('logout', { body: {} }); } catch (e) {}
      state.user = null;
      state.flash = 'Logged out';
      go('#/');
      render();
    });
  }
  app.querySelectorAll('[data-del]').forEach(el => {
    el.addEventListener('click', async () => {
      const ok = await uiConfirm('Delete this article permanently?', {
        title: 'Delete article',
        okText: 'Delete',
        danger: true
      });
      if (!ok) return;
      try {
        await api('article_delete', { body: { id: parseInt(el.getAttribute('data-del'), 10) } });
        state.flash = 'Deleted';
        render();
      } catch (e) {
        state.error = e.message;
        await uiAlert(e.message, { title: 'Delete failed' });
        render();
      }
    });
  });
}

async function render() {
  state.error = state.error || '';
  if (!state.installed) {
    await renderInstall();
    return;
  }
  const route = parseRoute();
  try {
    if (route.name === 'login') await renderLogin();
    else if (route.name === 'dashboard') await renderDashboard();
    else if (route.name === 'settings') await renderSettings();
    else if (route.name === 'audit') await renderAudit();
    else if (route.name === 'media') await renderMedia();
    else if (route.name === 'file') await renderFile();
    else if (route.name === 'edit') await renderEdit(route.params.id);
    else if (route.name === 'view') await renderView(route.params.id);
    else await renderHome();
  } catch (e) {
    state.error = e.message;
    app.innerHTML = `<div class="wrap">${topbar()}${banner()}<div class="panel"><p>Something went wrong.</p></div></div>`;
    bindChrome();
  }
  // clear flash after paint once
  setTimeout(() => { state.flash = ''; }, 0);
}

window.addEventListener('hashchange', () => { state.error = ''; render(); });

(async function boot() {
  if (!state.installed) {
    await loadInstallChecks();
    return;
  }
  try {
    await ensureSession();
  } catch (e) {
    state.error = e.message;
  }
  await render();
})();
</script>
</body>
</html>
