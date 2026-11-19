<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** Reader comments live outside spoilers.db so the scraper can keep updating it. */
function communityDb(): PDO {
    static $connection = null;
    if ($connection instanceof PDO) return $connection;

    $path = getenv('FANFIK_COMMUNITY_DB_PATH') ?: dirname(__DIR__) . '/storage/community.sqlite';
    $connection = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $connection->exec('PRAGMA busy_timeout = 5000');
    $connection->exec("CREATE TABLE IF NOT EXISTS comments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        movie_id INTEGER NOT NULL,
        kind TEXT NOT NULL,
        author TEXT NOT NULL,
        body TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected')),
        ip_hash TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $connection->exec('CREATE INDEX IF NOT EXISTS comments_thread ON comments(movie_id, kind, status, id)');
    $connection->exec('CREATE INDEX IF NOT EXISTS comments_rate ON comments(ip_hash, created_at)');
    $connection->exec("CREATE TABLE IF NOT EXISTS login_failures (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip_hash TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $connection->exec('CREATE INDEX IF NOT EXISTS failures_rate ON login_failures(ip_hash, created_at)');
    $connection->exec('CREATE TABLE IF NOT EXISTS app_settings (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
    $stmt = $connection->prepare('INSERT OR IGNORE INTO app_settings(name,value) VALUES (?,?)');
    $stmt->execute(['ip_key', bin2hex(random_bytes(32))]);
    return $connection;
}

function startSiteSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    session_name('fanfik_session');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

function csrfToken(): string {
    startSiteSession();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function verifyCsrf(): void {
    startSiteSession();
    if (!hash_equals(csrfToken(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        throw new RuntimeException('Сессия истекла. Обновите страницу и попробуйте ещё раз.');
    }
}

function ipFingerprint(): string {
    $key = (string)communityDb()->query("SELECT value FROM app_settings WHERE name = 'ip_key'")->fetchColumn();
    return hash_hmac('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''), $key);
}

function commentTarget(int $movieId, string $kind): bool {
    if ($movieId === 0 && $kind === 'site') return true;
    if ($movieId < 1) return false;
    $m = movie($movieId);
    if (!$m) return false;
    return $kind === 'movie' || (isset(TYPES[$kind]) && contentOf($m, $kind) !== '');
}

function commentLink(int $movieId, string $kind): string {
    if ($kind === 'site') return '/#comments';
    if ($kind === 'movie') return '/movie/' . $movieId . '#comments';
    return '/read/' . $movieId . '/' . rawurlencode($kind) . '#comments';
}

function commentContext(int $movieId, string $kind): string {
    if ($kind === 'site') return 'Обсуждение сайта';
    return $kind === 'movie' ? 'Фильм №' . $movieId : (TYPES[$kind][0] ?? 'История') . ' · №' . $movieId;
}

function countApprovedComments(int $movieId, string $kind): int {
    $stmt = communityDb()->prepare("SELECT count(*) FROM comments WHERE movie_id = ? AND kind = ? AND status = 'approved'");
    $stmt->execute([$movieId, $kind]);
    return (int)$stmt->fetchColumn();
}

function approvedComments(int $movieId, string $kind, int $page = 1): array {
    $stmt = communityDb()->prepare("SELECT author, body, created_at FROM comments
        WHERE movie_id = :movie_id AND kind = :kind AND status = 'approved'
        ORDER BY id DESC LIMIT 30 OFFSET :offset");
    $stmt->bindValue(':movie_id', $movieId, PDO::PARAM_INT);
    $stmt->bindValue(':kind', $kind);
    $stmt->bindValue(':offset', (max(1, $page) - 1) * 30, PDO::PARAM_INT);
    $stmt->execute();
    return array_reverse($stmt->fetchAll());
}

function commentDate(string $value): string {
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i');
    } catch (Throwable $exception) {
        return $value;
    }
}

function commentFlash(string $message): void {
    startSiteSession();
    $_SESSION['comment_flash'] = $message;
}

function takeCommentFlash(): string {
    startSiteSession();
    $message = (string)($_SESSION['comment_flash'] ?? '');
    unset($_SESSION['comment_flash']);
    return $message;
}

function addReaderComment(int $movieId, string $kind, string $author, string $body): void {
    if (!commentTarget($movieId, $kind)) throw new InvalidArgumentException('Эта страница не найдена.');
    if (mb_strlen($author) < 2 || mb_strlen($author) > 60) {
        throw new InvalidArgumentException('Имя должно содержать от 2 до 60 символов.');
    }
    if (mb_strlen($body) < 5 || mb_strlen($body) > 4000) {
        throw new InvalidArgumentException('Комментарий должен содержать от 5 до 4000 символов.');
    }
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $author . $body)) {
        throw new InvalidArgumentException('Удалите управляющие символы из текста.');
    }

    $db = communityDb();
    $fingerprint = ipFingerprint();
    // Serialize count-and-insert to prevent concurrent requests bypassing the rate limit.
    $db->exec('BEGIN IMMEDIATE');
    try {
        $stmt = $db->prepare("SELECT count(*) FROM comments WHERE ip_hash = ? AND created_at >= datetime('now','-1 hour')");
        $stmt->execute([$fingerprint]);
        if ((int)$stmt->fetchColumn() >= 5) throw new RuntimeException('Лимит: пять комментариев в час. Попробуйте позже.');
        $stmt = $db->prepare("SELECT count(*) FROM comments WHERE ip_hash = ? AND created_at >= datetime('now','-1 minute')");
        $stmt->execute([$fingerprint]);
        if ((int)$stmt->fetchColumn() > 0) throw new RuntimeException('Подождите минуту перед следующим комментарием.');
        $stmt = $db->prepare('INSERT INTO comments(movie_id,kind,author,body,ip_hash) VALUES (?,?,?,?,?)');
        $stmt->execute([$movieId, $kind, $author, $body, $fingerprint]);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        throw $exception;
    }
}
