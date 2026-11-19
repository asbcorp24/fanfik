<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/community.php';
startCommunitySession();
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
$hash=getenv('FANFIK_ADMIN_PASSWORD_HASH')?:'';
$hashFile=dirname(__DIR__).'/storage/admin-password.hash';
if(!$hash&&is_file($hashFile))$hash=trim((string)file_get_contents($hashFile));
$plainFile=dirname(__DIR__).'/storage/admin-password.txt';
$plain=is_file($plainFile)&&is_readable($plainFile)?trim((string)file_get_contents($plainFile)):'';
$configured=($hash!==''&&password_get_info($hash)['algo']!==null)||$plain!=='';
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verifyCsrf();
 $action=(string)($_POST['action']??'');
 if($action==='logout'){
  unset($_SESSION['admin']);session_regenerate_id(true);
  header('Location: /admin.php',true,303);exit;
 }
 if($action==='login'){
  $now=time();
  if(!$configured){$error='Пароль администратора ещё не настроен.';}
  elseif((int)($_SESSION['login_block_until']??0)>$now){$error='Слишком много попыток. Попробуйте позднее.';}
  elseif(($plain!==''&&hash_equals($plain,(string)($_POST['password']??'')))||($plain===''&&$hash!==''&&password_verify((string)($_POST['password']??''),$hash))){
   session_regenerate_id(true);$_SESSION['admin']=true;$_SESSION['login_fails']=0;
   header('Location: /admin.php',true,303);exit;
  }else{
   $_SESSION['login_fails']=(int)($_SESSION['login_fails']??0)+1;
   if($_SESSION['login_fails']>=5){$_SESSION['login_block_until']=$now+900;$_SESSION['login_fails']=0;}
   $error='Неверный пароль.';
  }
 }elseif(!empty($_SESSION['admin'])&&in_array($action,['approve','reject','delete'],true)){
  $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  if(!$id){http_response_code(400);exit('Неверный ID');}
  try{
   $pdo=communityDb();
   if($action==='delete'){$s=$pdo->prepare('DELETE FROM comments WHERE id=?');$s->execute([$id]);}
   else{$s=$pdo->prepare('UPDATE comments SET status=? WHERE id=?');$s->execute([$action==='approve'?'approved':'rejected',$id]);}
   header('Location: /admin.php?status='.rawurlencode((string)($_POST['return_status']??'pending')),true,303);exit;
  }catch(Throwable $e){error_log($e->getMessage());$error='Не удалось изменить комментарий.';}
 }
}
$logged=!empty($_SESSION['admin']);
$status=(string)($_GET['status']??'pending');
if(!in_array($status,['pending','approved','rejected'],true))$status='pending';
$stats=['movies'=>0,'pending'=>0,'approved'=>0,'rejected'=>0];$comments=[];$recentMovies=[];
if($logged){
 try{
  $stats['movies']=(int)db()->query('SELECT count(*) FROM movies')->fetchColumn();
  $recentMovies=db()->query('SELECT id,title,title_ru,year,translate_status,novel_status FROM movies ORDER BY id DESC LIMIT 20')->fetchAll();
  $pdo=communityDb();
  foreach(['pending','approved','rejected'] as $s){$q=$pdo->prepare('SELECT count(*) FROM comments WHERE status=?');$q->execute([$s]);$stats[$s]=(int)$q->fetchColumn();}
  $q=$pdo->prepare('SELECT * FROM comments WHERE status=? ORDER BY id DESC LIMIT 100');$q->execute([$status]);$comments=$q->fetchAll();
 }catch(Throwable $e){error_log($e->getMessage());$error='Не удалось прочитать статистику или комментарии.';}
}
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Админка — Фанфик</title><link rel="stylesheet" href="/assets/site.css"><link rel="stylesheet" href="/assets/mobile.css"></head><body class="admin-page">
<header class="topbar"><div class="wrap head"><a class="logo" href="/">✦ Фанфик</a><nav><a href="/">На сайт</a><?php if($logged):?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrfToken())?>"><input type="hidden" name="action" value="logout"><button class="filter-btn">Выйти</button></form><?php endif;?></nav></div></header>
<main class="wrap admin"><h1>Панель управления</h1>
<?php if($error):?><p class="notice"><?=e($error)?></p><?php endif;?>
<?php if(!$configured):?><p class="notice">Создайте текстовый файл <code>storage/admin-password.txt</code> и впишите в него пароль одной строкой. Файл должен находиться вне публичного каталога сайта.</p>
<?php elseif(!$logged):?><form method="post" class="comment-form login"><input type="hidden" name="csrf" value="<?=e(csrfToken())?>"><input type="hidden" name="action" value="login"><label>Пароль администратора<input type="password" name="password" required autocomplete="current-password"></label><button class="filter-btn">Войти</button></form>
<?php else:?>
<div class="admin-stats"><div>Фильмов <strong><?=$stats['movies']?></strong></div><div>Ожидают <strong><?=$stats['pending']?></strong></div><div>Опубликовано <strong><?=$stats['approved']?></strong></div><div>Отклонено <strong><?=$stats['rejected']?></strong></div></div>
<h2>Модерация комментариев</h2><nav class="admin-tabs" aria-label="Статус комментариев"><a href="/admin.php?status=pending" <?=$status==='pending'?'aria-current="page"':''?>>Ожидают (<?=$stats['pending']?>)</a><a href="/admin.php?status=approved" <?=$status==='approved'?'aria-current="page"':''?>>Опубликованы (<?=$stats['approved']?>)</a><a href="/admin.php?status=rejected" <?=$status==='rejected'?'aria-current="page"':''?>>Отклонены (<?=$stats['rejected']?>)</a></nav>
<?php if(!$comments):?><p class="comments-hint">В этом разделе пока нет комментариев.</p><?php endif;?>
<?php foreach($comments as $c):?>
<article class="comment"><div><strong><?=e($c['author'])?></strong><time><?=e($c['created_at'])?></time></div><p><?=nl2br(e($c['body']),false)?></p><p class="comments-hint">Раздел: <?=e($c['scope'])?> · фильм #<?=(int)$c['movie_id']?> · <?=e($c['story_type'])?></p>
<div class="admin-actions">
<?php foreach(['approve'=>'Одобрить','reject'=>'Отклонить','delete'=>'Удалить'] as $action=>$label):?>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrfToken())?>"><input type="hidden" name="action" value="<?=$action?>"><input type="hidden" name="id" value="<?=(int)$c['id']?>"><input type="hidden" name="return_status" value="<?=e($status)?>"><button class="filter-btn" <?=$action==='delete'?'onclick="return confirm(\'Удалить комментарий?\')"':''?>><?=$label?></button></form>
<?php endforeach;?></div></article>
<?php endforeach;?>
<h2>Последние фильмы в базе</h2><p class="comments-hint">Просмотр записей из spoilers.db. Исходная база не изменяется.</p><div class="admin-movies"><?php foreach($recentMovies as $film):?><a href="/movie/<?=(int)$film['id']?>"><strong><?=e(title($film))?></strong><span><?=e($film['year'])?> · перевод: <?=e($film['translate_status'])?> · роман: <?=e($film['novel_status'])?></span></a><?php endforeach;?></div>
<?php endif;?></main></body></html>
