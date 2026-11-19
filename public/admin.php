<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/admin_auth.php';

header('Cache-Control: no-store, private');
header('X-Frame-Options: DENY');
startSiteSession();
$error = '';
$success = '';

const EDITABLE_MOVIE_FIELDS = [
    'title' => ['Оригинальное название', 200],
    'title_ru' => ['Название на русском', 200],
    'year' => ['Год выпуска', 4],
    'genres' => ['Жанры', 400],
    'poster_url' => ['URL постера', 2048],
    'plot' => ['Сюжет (оригинал)', 500000],
    'plot_ru' => ['Сюжет (русский)', 500000],
    'ending' => ['Концовка (оригинал)', 500000],
    'ending_ru' => ['Концовка (русский)', 500000],
    'novel_ru' => ['Литературная версия', 500000],
    'novel_erotic' => ['Романтика 18+', 500000],
    'novel_horror' => ['Ужасы', 500000],
    'novel_fantasy' => ['Фэнтези', 500000],
    'novel_comedy' => ['Комедия', 500000],
    'novel_detective' => ['Детектив', 500000],
    'novel_thriller' => ['Триллер', 500000],
];

function safeAdminId(mixed $value): int {
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) throw new InvalidArgumentException('Недопустимый идентификатор.');
    return (int)$id;
}

function saveMovieEdits(int $id, mixed $values): void {
    if (!is_array($values)) throw new InvalidArgumentException('Данные формы не получены.');
    if (!movie($id)) throw new InvalidArgumentException('Фильм не найден.');
    $set = [];
    $bindings = [':id' => $id];
    foreach (EDITABLE_MOVIE_FIELDS as $field => [$label, $maxLength]) {
        if (!array_key_exists($field, $values) || !is_string($values[$field])) {
            throw new InvalidArgumentException('Не заполнено поле «' . $label . '».');
        }
        $value = trim($values[$field]);
        if (mb_strlen($value) > $maxLength) throw new InvalidArgumentException('Слишком длинное поле «' . $label . '».');
        if ($field === 'title' && $value === '') throw new InvalidArgumentException('Оригинальное название обязательно.');
        if ($field === 'year') {
            if ($value !== '' && (!ctype_digit($value) || (int)$value < 1880 || (int)$value > 2100)) {
                throw new InvalidArgumentException('Неверный год выпуска.');
            }
            $bindings[':' . $field] = $value === '' ? null : (int)$value;
        } else {
            if ($field === 'poster_url' && $value !== '' && urlPoster($value) === '') {
                throw new InvalidArgumentException('URL постера должен начинаться с http:// или https://.');
            }
            $bindings[':' . $field] = $value;
        }
        $set[] = $field . ' = :' . $field;
    }
    $statement = db()->prepare('UPDATE movies SET ' . implode(', ', $set) . ' WHERE id = :id');
    foreach ($bindings as $key => $value) {
        $statement->bindValue($key, $value, $value === null ? PDO::PARAM_NULL : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR));
    }
    $statement->execute();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        verifyCsrf();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'setup') {
            if ((string)($_POST['password'] ?? '') !== (string)($_POST['confirm'] ?? '')) {
                throw new InvalidArgumentException('Пароли не совпадают.');
            }
            setupAdmin((string)($_POST['setup_token'] ?? ''), (string)($_POST['password'] ?? ''));
            if (!loginAdmin((string)$_POST['password'])) throw new RuntimeException('Пароль создан, но вход не выполнен.');
            header('Location: /admin.php?setup=ok', true, 303);exit;
        }
        if ($action === 'login') {
            if (!loginAdmin((string)($_POST['password'] ?? ''))) throw new RuntimeException('Неверный пароль.');
            header('Location: /admin.php', true, 303);exit;
        }
        if (!adminIsLoggedIn()) throw new RuntimeException('Для этого действия нужно войти в админку.');
        if ($action === 'logout') {
            logoutAdmin();
            header('Location: /admin.php', true, 303);exit;
        }
        if ($action === 'moderate') {
            $id = safeAdminId($_POST['comment_id'] ?? null);
            $status = (string)($_POST['status'] ?? '');
            $connection = communityDb();
            if ($status === 'delete') {
                $stmt = $connection->prepare('DELETE FROM comments WHERE id = ?');
                $stmt->execute([$id]);
            } elseif (in_array($status, ['approved', 'rejected', 'pending'], true)) {
                $stmt = $connection->prepare('UPDATE comments SET status = ? WHERE id = ?');
                $stmt->execute([$status, $id]);
            } else throw new InvalidArgumentException('Неверное действие модерации.');
            header('Location: /admin.php?view=comments&status=' . rawurlencode((string)($_POST['return_status'] ?? 'pending')) . '&saved=1', true, 303);exit;
        }
        if ($action === 'save_movie') {
            $id = safeAdminId($_POST['movie_id'] ?? null);
            saveMovieEdits($id, $_POST['movie'] ?? null);
            header('Location: /admin.php?view=edit&id=' . $id . '&saved=1', true, 303);exit;
        }
        if ($action === 'change_password') {
            if (!password_verify((string)($_POST['current_password'] ?? ''), adminPasswordHash())) {
                throw new InvalidArgumentException('Текущий пароль неверен.');
            }
            if ((string)($_POST['new_password'] ?? '') !== (string)($_POST['confirm_password'] ?? '')) {
                throw new InvalidArgumentException('Новые пароли не совпадают.');
            }
            saveAdminPassword((string)$_POST['new_password']);
            logoutAdmin();
            header('Location: /admin.php?password=changed', true, 303);exit;
        }
        throw new InvalidArgumentException('Неизвестное действие.');
    } catch (Throwable $exception) {
        error_log($exception instanceof PDOException ? $exception->getMessage() : 'Admin request validation failed');
        $error = $exception instanceof PDOException ? 'Ошибка сохранения. Проверьте права на SQLite-файлы.' : $exception->getMessage();
    }
}

$loggedIn = adminIsLoggedIn();
$configured = adminPasswordHash() !== '';
$view = (string)($_GET['view'] ?? 'dashboard');
if (!in_array($view, ['dashboard', 'comments', 'movies', 'edit', 'settings'], true)) $view = 'dashboard';
$status = (string)($_GET['status'] ?? 'pending');
if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) $status = 'pending';
$currentMovie = null;
$movieRows = [];
$commentRows = [];
$totals = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
$movieCount = 0;
$page = max(1, min(10000, (int)($_GET['page'] ?? 1)));
$query = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
if ($loggedIn) {
    try {
        foreach (communityDb()->query('SELECT status, count(*) AS n FROM comments GROUP BY status') as $row) {
            $totals[$row['status']] = (int)$row['n'];
        }
        $movieCount = (int)db()->query('SELECT count(*) FROM movies')->fetchColumn();
        if ($view === 'edit') {
            $currentMovie = movie(safeAdminId($_GET['id'] ?? null));
            if (!$currentMovie) throw new RuntimeException('Фильм не найден.');
        }
        if ($view === 'movies') {
            $where = $query !== '' ? ' WHERE title LIKE :q OR title_ru LIKE :q' : '';
            $stmt = db()->prepare('SELECT id,title,title_ru,year,genres FROM movies' . $where . ' ORDER BY id DESC LIMIT 40 OFFSET :offset');
            if ($query !== '') $stmt->bindValue(':q', '%' . $query . '%');
            $stmt->bindValue(':offset', ($page - 1) * 40, PDO::PARAM_INT);
            $stmt->execute();
            $movieRows = $stmt->fetchAll();
        }
        if ($view === 'comments' || $view === 'dashboard') {
            $filter = $view === 'dashboard' ? 'pending' : $status;
            $where = $filter === 'all' ? '' : ' WHERE status = :status';
            $stmt = communityDb()->prepare('SELECT * FROM comments' . $where . ' ORDER BY id DESC LIMIT 50 OFFSET :offset');
            if ($filter !== 'all') $stmt->bindValue(':status', $filter);
            $stmt->bindValue(':offset', ($page - 1) * 50, PDO::PARAM_INT);
            $stmt->execute();
            $commentRows = $stmt->fetchAll();
        }
    } catch (Throwable $exception) {
        error_log($exception->getMessage());
        $error = 'Не удалось загрузить данные. Проверьте базу spoilers.db и папку storage/.';
    }
}
?><!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Админка — Фанфик</title><link rel="stylesheet" href="/assets/site.css"><link rel="stylesheet" href="/assets/comments.css"><link rel="stylesheet" href="/assets/admin.css"></head>
<body><header class="topbar"><div class="wrap head"><a class="logo" href="/"><span class="logo-mark">✦</span> Фанфик</a><nav><a href="/">На сайт</a><?php if ($loggedIn): ?><a href="/admin.php">Админка</a><?php endif; ?></nav></div></header>
<main class="wrap admin-shell">
    <?php if ($error): ?><p class="admin-alert" role="alert"><?=e($error)?></p><?php endif; ?>
    <?php if (!$configured): ?>
        <section class="admin-auth"><h1>Первоначальная настройка</h1><p>Задайте пароль администратора. Для защиты установки сначала установите на сервере переменную окружения <code>FANFIK_SETUP_TOKEN</code> (не менее 24 случайных символов). Альтернатива — команда <code>php bin/set-admin-password.php</code> в терминале.</p>
        <?php if (strlen((string)getenv('FANFIK_SETUP_TOKEN')) >= 24): ?>
            <form method="post" class="admin-form"><input type="hidden" name="csrf" value="<?=e(csrfToken())?>"><input type="hidden" name="action" value="setup"><label>Ключ настройки<input name="setup_token" type="password" required autocomplete="off"></label><label>Новый пароль (от 12 символов)<input name="password" type="password" minlength="12" required autocomplete="new-password"></label><label>Повторите пароль<input name="confirm" type="password" minlength="12" required autocomplete="new-password"></label><button>Создать администратора</button></form>
        <?php endif; ?></section>
    <?php elseif (!$loggedIn): ?>
        <section class="admin-auth"><div class="eyebrow">УПРАВЛЕНИЕ САЙТОМ</div><h1>Вход в админку</h1><?php if (isset($_GET['password'])): ?><p class="comment-message">Пароль изменён. Войдите заново.</p><?php endif; ?><form method="post" class="admin-form"><input type="hidden" name="csrf" value="<?=e(csrfToken())?>"><input type="hidden" name="action" value="login"><label>Пароль администратора<input name="password" type="password" required autocomplete="current-password" autofocus></label><button>Войти</button></form></section>
    <?php else: ?>
        <div class="admin-heading"><div><div class="eyebrow">ПАНЕЛЬ УПРАВЛЕНИЯ</div><h1>Админка</h1></div><form method="post"><input type="hidden" name="csrf" value="<?=e(csrfToken())?>"><input type="hidden" name="action" value="logout"><button class="admin-secondary">Выйти</button></form></div>
        <nav class="admin-nav" aria-label="Разделы админки"><a href="/admin.php" <?=$view==='dashboard'?'aria-current="page"':''?>>Обзор</a><a href="/admin.php?view=comments" <?=$view==='comments'?'aria-current="page"':''?>>Комментарии<?php if ($totals['pending']): ?> (<?=$totals['pending']?>)<?php endif; ?></a><a href="/admin.php?view=movies" <?=$view==='movies'||$view==='edit'?'aria-current="page"':''?>>Фильмы</a><a href="/admin.php?view=settings" <?=$view==='settings'?'aria-current="page"':''?>>Настройки</a></nav>
        <?php if (isset($_GET['saved']) || isset($_GET['setup'])): ?><p class="comment-message">Изменения сохранены.</p><?php endif; ?>
        <?php if ($view === 'dashboard'): ?>
            <div class="admin-stats"><div><span>Фильмов</span><strong><?=$movieCount?></strong></div><div><span>Ожидают проверки</span><strong><?=$totals['pending']?></strong></div><div><span>Опубликовано</span><strong><?=$totals['approved']?></strong></div><div><span>Отклонено</span><strong><?=$totals['rejected']?></strong></div></div>
            <h2>Ожидают модерации</h2>
        <?php endif; ?>
        <?php if ($view === 'comments'): ?>
            <h2>Комментарии</h2><div class="admin-tabs"><?php foreach (['pending'=>'На проверке', 'approved'=>'Опубликованные', 'rejected'=>'Отклонённые', 'all'=>'Все'] as $value=>$name): ?><a href="/admin.php?view=comments&status=<?=e($value)?>" <?=$status===$value?'aria-current="page"':''?>><?=e($name)?></a><?php endforeach; ?></div>
        <?php endif; ?>
        <?php if ($view === 'dashboard' || $view === 'comments'): ?>
            <?php if (!$commentRows): ?><div class="admin-empty">В этом разделе пока нет комментариев.</div><?php endif; ?>
            <div class="admin-comment-list"><?php foreach ($commentRows as $comment): ?>
                <article class="admin-comment"><div class="admin-comment-top"><div><strong><?=e($comment['author'])?></strong> · <?=e(commentDate($comment['created_at']))?> <span class="admin-pill"><?=e($comment['status'])?></span></div><a href="<?=e(commentLink((int)$comment['movie_id'], $comment['kind']))?>" target="_blank" rel="noopener"><?=e(commentContext((int)$comment['movie_id'], $comment['kind']))?></a></div><p><?=nl2br(e($comment['body']),false)?></p><div class="admin-actions">
                    <?php foreach (['approved'=>'Одобрить', 'rejected'=>'Отклонить', 'pending'=>'На проверку', 'delete'=>'Удалить'] as $state=>$label): ?>
                        <form method="post" <?php if ($state==='delete'): ?>onsubmit="return confirm('Удалить комментарий навсегда?')"<?php endif; ?>><input type="hidden" name="csrf" value="<?=e(csrfToken())?>"><input type="hidden" name="action" value="moderate"><input type="hidden" name="comment_id" value="<?=(int)$comment['id']?>"><input type="hidden" name="status" value="<?=e($state)?>"><input type="hidden" name="return_status" value="<?=e($status)?>"><button class="<?= $state==='delete'?'admin-danger':'admin-secondary' ?>"><?=e($label)?></button></form>
                    <?php endforeach; ?>
                </div></article>
            <?php endforeach; ?></div>
            <?php if (count($commentRows) === 50): ?><a class="admin-next" href="/admin.php?view=comments&status=<?=e($status)?>&page=<?=$page+1?>">Следующая страница →</a><?php endif; ?>
        <?php elseif ($view === 'movies'): ?>
            <h2>Редактор фильмов</h2><p class="admin-note">Изменения сохраняются непосредственно в spoilers.db. Перед массовыми правками сделайте резервную копию базы.</p>
            <form method="get" action="/admin.php" class="admin-search"><input type="hidden" name="view" value="movies"><input name="q" value="<?=e($query)?>" placeholder="Название фильма…"><button>Найти</button></form>
            <div class="admin-movie-list"><?php foreach ($movieRows as $row): ?><a href="/admin.php?view=edit&id=<?=(int)$row['id']?>"><span><strong><?=e(title($row))?></strong><small><?=e($row['title'])?> · <?=e($row['year']??'')?></small></span><span>Редактировать →</span></a><?php endforeach; ?></div>
            <?php if (!$movieRows): ?><p class="admin-empty">Фильмы не найдены.</p><?php endif; ?>
            <?php if (count($movieRows) === 40): ?><a class="admin-next" href="/admin.php?view=movies&q=<?=urlencode($query)?>&page=<?=$page+1?>">Следующая страница →</a><?php endif; ?>
        <?php elseif ($view === 'edit' && $currentMovie): ?>
            <a class="back" href="/admin.php?view=movies">← К списку фильмов</a><h2><?=e(title($currentMovie))?></h2><p class="admin-note">ID <?=(int)$currentMovie['id']?> · <a href="<?=linkMovie((int)$currentMovie['id'])?>" target="_blank" rel="noopener">Открыть на сайте</a>. Редактирование изменяет spoilers.db.</p>
            <form method="post" class="admin-edit-form"><input type="hidden" name="csrf" value="<?=e(csrfToken())?>"><input type="hidden" name="action" value="save_movie"><input type="hidden" name="movie_id" value="<?=(int)$currentMovie['id']?>">
                <?php foreach (EDITABLE_MOVIE_FIELDS as $field=>[$label,$limit]): ?>
                    <?php if ($limit <= 2048): ?><label><?=e($label)?><input name="movie[<?=e($field)?>]" value="<?=e($currentMovie[$field]??'')?>" maxlength="<?=$limit?>" <?=$field==='title'?'required':''?>></label>
                    <?php else: ?><label><?=e($label)?><textarea name="movie[<?=e($field)?>]" rows="8" maxlength="<?=$limit?>"><?=e($currentMovie[$field]??'')?></textarea></label><?php endif; ?>
                <?php endforeach; ?><button class="admin-save">Сохранить фильм</button>
            </form>
        <?php elseif ($view === 'settings'): ?>
            <section class="admin-auth admin-settings"><h2>Изменить пароль</h2><form method="post" class="admin-form"><input type="hidden" name="csrf" value="<?=e(csrfToken())?>"><input type="hidden" name="action" value="change_password"><label>Текущий пароль<input name="current_password" type="password" required autocomplete="current-password"></label><label>Новый пароль (от 12 символов)<input name="new_password" type="password" minlength="12" required autocomplete="new-password"></label><label>Повторите новый пароль<input name="confirm_password" type="password" minlength="12" required autocomplete="new-password"></label><button>Сменить пароль</button></form></section>
        <?php endif; ?>
    <?php endif; ?>
</main></body></html>
