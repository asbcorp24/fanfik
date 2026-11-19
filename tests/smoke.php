<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

function check(bool $condition, string $description): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $description);
    echo "OK: $description\n";
}

$base = sys_get_temp_dir() . '/fanfik-smoke-' . bin2hex(random_bytes(5));
if (!mkdir($base, 0700)) throw new RuntimeException('Cannot create test directory');
$moviePath = $base . '/spoilers.db';
$communityPath = $base . '/community.sqlite';
putenv('FANFIK_DB_PATH=' . $moviePath);
putenv('FANFIK_COMMUNITY_DB_PATH=' . $communityPath);
$fixture = new PDO('sqlite:' . $moviePath);
$fixture->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$fixture->exec("CREATE TABLE movies (
 id INTEGER PRIMARY KEY, title TEXT NOT NULL, title_ru TEXT DEFAULT '', year INTEGER,
 poster_url TEXT DEFAULT '', plot TEXT DEFAULT '', plot_ru TEXT DEFAULT '', ending TEXT DEFAULT '', ending_ru TEXT DEFAULT '',
 novel_ru TEXT DEFAULT '', novel_erotic TEXT DEFAULT '', novel_horror TEXT DEFAULT '',
 novel_fantasy TEXT DEFAULT '', novel_comedy TEXT DEFAULT '', novel_detective TEXT DEFAULT '',
 novel_thriller TEXT DEFAULT ''
)");
$fixture->exec("INSERT INTO movies(id,title,plot_ru) VALUES (7,'Example','Сюжет примера')");

require dirname(__DIR__) . '/src/community.php';
try {
    check(commentTarget(0, 'site'), 'global comments allowed');
    check(commentTarget(7, 'movie'), 'movie comments allowed');
    check(commentTarget(7, 'plot'), 'available story comments allowed');
    check(!commentTarget(7, 'horror'), 'missing stories cannot be commented');
    check(!commentTarget(999, 'movie'), 'missing movies cannot be commented');
    $_SERVER['REMOTE_ADDR'] = '198.51.100.42';
    addReaderComment(7, 'plot', 'Читатель', 'Очень интересная история!');
    check(count(approvedComments(7, 'plot')) === 0, 'comment awaits moderation');
    check(count(approvedComments(7, 'movie')) === 0, 'threads are isolated');
    $db = communityDb();
    check((int)$db->query('SELECT count(*) FROM comments')->fetchColumn() === 1, 'comment persisted in separate database');
    $db->exec("UPDATE comments SET status = 'approved' WHERE movie_id = 7");
    check(count(approvedComments(7, 'plot')) === 1, 'approved comment visible');
    check(!str_contains(e('<script>alert(1)</script>'), '<script>'), 'HTML escaping');
    try {
        addReaderComment(7, 'plot', 'Читатель', 'Второй комментарий сразу');
        throw new RuntimeException('FAIL: rate limit did not apply');
    } catch (RuntimeException $exception) {
        check(str_contains($exception->getMessage(), 'минуту'), 'rate limiting');
    }
    echo "Smoke tests completed.\n";
} finally {
    $fixture = null;
    @unlink($moviePath);
    @unlink($communityPath);
    @rmdir($base);
}
