<?php
/** @var int $commentMovieId */
/** @var string $commentKind */
require_once __DIR__ . '/community.php';
$commentRows = [];
$commentError = '';
$commentPage = max(1, min(10000, (int)($_GET['comments_page'] ?? 1)));
$commentPages = 1;
try {
    $commentTotal = countApprovedComments($commentMovieId, $commentKind);
    $commentPages = max(1, (int)ceil($commentTotal / 30));
    $commentPage = min($commentPages, $commentPage);
    $commentRows = approvedComments($commentMovieId, $commentKind, $commentPage);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $commentError = 'Комментарии пока недоступны. Администратору нужно проверить права на папку storage/.';
}
?>
<section class="comments" id="comments" aria-labelledby="comments-title">
    <div class="section-head"><h2 id="comments-title">Обсуждение</h2><span>Комментарии проходят модерацию</span></div>
    <?php if ($flash = takeCommentFlash()): ?>
        <p class="comment-message" role="status"><?=e($flash)?></p>
    <?php endif; ?>
    <?php if ($commentError): ?>
        <p class="notice"><?=e($commentError)?></p>
    <?php else: ?>
        <?php if (!$commentRows): ?>
            <p class="comment-empty">Пока нет опубликованных комментариев. Начните обсуждение!</p>
        <?php endif; ?>
        <div class="comment-list">
            <?php foreach ($commentRows as $comment): ?>
                <article class="comment-item">
                    <div class="comment-top"><strong><?=e($comment['author'])?></strong><time><?=e(commentDate($comment['created_at']))?></time></div>
                    <p><?=nl2br(e($comment['body']), false)?></p>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if ($commentPages > 1): ?>
            <nav class="comment-pagination" aria-label="Страницы комментариев">
                <?php if ($commentPage > 1): ?><a href="?comments_page=<?=$commentPage-1?>#comments">← Более новые</a><?php endif; ?>
                <span>Страница <?=$commentPage?> из <?=$commentPages?></span>
                <?php if ($commentPage < $commentPages): ?><a href="?comments_page=<?=$commentPage+1?>#comments">Более старые →</a><?php endif; ?>
            </nav>
        <?php endif; ?>
        <form class="comment-form" action="/comment.php" method="post">
            <h3>Оставить комментарий</h3>
            <p>Ваш отзыв появится после проверки модератором.</p>
            <input type="hidden" name="csrf" value="<?=e(csrfToken())?>">
            <input type="hidden" name="movie_id" value="<?=$commentMovieId?>">
            <input type="hidden" name="kind" value="<?=e($commentKind)?>">
            <div class="honeypot" aria-hidden="true"><label>Сайт <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
            <label>Ваше имя <input name="author" maxlength="60" minlength="2" required autocomplete="name" placeholder="Как к вам обращаться?"></label>
            <label>Комментарий <textarea name="body" maxlength="4000" minlength="5" rows="5" required placeholder="Поделитесь мнением об истории…"></textarea></label>
            <button type="submit">Отправить на модерацию</button>
        </form>
    <?php endif; ?>
</section>
