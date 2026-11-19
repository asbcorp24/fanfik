<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/community.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$movieId = filter_var($_POST['movie_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$kind = (string)($_POST['kind'] ?? '');
if ($movieId === false || !($kind === 'site' || $kind === 'movie' || isset(TYPES[$kind]))) {
    http_response_code(400);
    exit('Недопустимый раздел комментариев.');
}

try {
    verifyCsrf();
    if (trim((string)($_POST['website'] ?? '')) === '') {
        addReaderComment((int)$movieId, $kind, trim((string)($_POST['author'] ?? '')),
            trim((string)($_POST['body'] ?? '')));
    }
    commentFlash('Спасибо! Комментарий отправлен на модерацию.');
} catch (Throwable $exception) {
    error_log($exception instanceof PDOException ? $exception->getMessage() : 'Comment validation failed');
    commentFlash($exception instanceof PDOException ? 'Не удалось сохранить комментарий. Попробуйте позже.' : $exception->getMessage());
}

header('Location: ' . commentLink((int)$movieId, $kind), true, 303);
exit;
