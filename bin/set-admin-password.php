<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require dirname(__DIR__) . '/src/admin_auth.php';

fwrite(STDOUT, "Новый пароль администратора (не менее 12 символов): ");
$password = rtrim((string)fgets(STDIN), "\r\n");
fwrite(STDOUT, "Повторите пароль: ");
$confirmation = rtrim((string)fgets(STDIN), "\r\n");
if (!hash_equals($password, $confirmation)) {
    fwrite(STDERR, "Пароли не совпадают.\n");
    exit(1);
}
try {
    saveAdminPassword($password);
    fwrite(STDOUT, "Пароль сохранён (хеш в storage/admin-password.hash).\n");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
