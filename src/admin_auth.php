<?php
declare(strict_types=1);
require_once __DIR__ . '/community.php';

function adminPasswordFile(): string {
    return dirname(__DIR__) . '/storage/admin-password.hash';
}

function adminPasswordHash(): string {
    $path = adminPasswordFile();
    return is_file($path) ? trim((string)file_get_contents($path)) : '';
}

function saveAdminPassword(string $password): void {
    if (mb_strlen($password) < 12 || mb_strlen($password) > 200) {
        throw new InvalidArgumentException('Пароль должен содержать от 12 до 200 символов.');
    }
    $path = adminPasswordFile();
    $temporary = tempnam(dirname($path), '.admin-');
    if ($temporary === false) throw new RuntimeException('Не удалось создать файл пароля в storage/.');
    try {
        if (file_put_contents($temporary, password_hash($password, PASSWORD_DEFAULT) . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Не удалось записать пароль.');
        }
        chmod($temporary, 0600);
        if (!rename($temporary, $path)) throw new RuntimeException('Не удалось заменить файл пароля.');
    } finally {
        if (is_file($temporary)) unlink($temporary);
    }
}

function adminIsLoggedIn(): bool {
    startSiteSession();
    $hash = adminPasswordHash();
    if ($hash === '' || empty($_SESSION['admin_proof'])) return false;
    return hash_equals(hash_hmac('sha256', session_id(), $hash), $_SESSION['admin_proof']);
}

function loginAdmin(string $password): bool {
    $hash = adminPasswordHash();
    if ($hash === '') return false;
    $connection = communityDb();
    $fingerprint = ipFingerprint();
    $stmt = $connection->prepare("SELECT count(*) FROM login_failures WHERE ip_hash = ? AND created_at >= datetime('now','-15 minutes')");
    $stmt->execute([$fingerprint]);
    if ((int)$stmt->fetchColumn() >= 5) throw new RuntimeException('Слишком много попыток входа. Подождите 15 минут.');
    if (!password_verify($password, $hash)) {
        $stmt = $connection->prepare('INSERT INTO login_failures(ip_hash) VALUES (?)');
        $stmt->execute([$fingerprint]);
        return false;
    }
    $stmt = $connection->prepare('DELETE FROM login_failures WHERE ip_hash = ?');
    $stmt->execute([$fingerprint]);
    startSiteSession();
    session_regenerate_id(true);
    $_SESSION['admin_proof'] = hash_hmac('sha256', session_id(), $hash);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return true;
}

function logoutAdmin(): void {
    startSiteSession();
    unset($_SESSION['admin_proof']);
    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/** A separate, explicit credential is required for first-time web setup. */
function setupAdmin(string $setupToken, string $newPassword): void {
    if (adminPasswordHash() !== '') throw new RuntimeException('Администратор уже настроен.');
    $expected = (string)getenv('FANFIK_SETUP_TOKEN');
    if ($expected === '' || strlen($expected) < 24 || !hash_equals($expected, $setupToken)) {
        throw new RuntimeException('Неверный ключ первоначальной настройки.');
    }
    saveAdminPassword($newPassword);
}
