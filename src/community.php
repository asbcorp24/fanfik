<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';

function communityDb(): PDO {
 static $pdo=null;
 if($pdo instanceof PDO)return $pdo;
 $file=dirname(__DIR__).'/storage/community.db';
 $pdo=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_TIMEOUT=>5]);
 $pdo->exec('PRAGMA busy_timeout=5000');
 $pdo->exec("CREATE TABLE IF NOT EXISTS comments (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 scope TEXT NOT NULL CHECK(scope IN ('site','movie','story')),
 movie_id INTEGER NOT NULL DEFAULT 0,
 story_type TEXT NOT NULL DEFAULT '',
 author TEXT NOT NULL,
 body TEXT NOT NULL,
 status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected')),
 ip_hash TEXT NOT NULL,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
 )");
 $pdo->exec('CREATE INDEX IF NOT EXISTS comments_target ON comments(scope,movie_id,story_type,status,id)');
 $pdo->exec('CREATE INDEX IF NOT EXISTS comments_ip ON comments(ip_hash,created_at)');
 return $pdo;
}
function startCommunitySession():void{
 if(session_status()===PHP_SESSION_ACTIVE)return;
 if(!headers_sent()){
  ini_set('session.use_strict_mode','1');
  ini_set('session.cookie_httponly','1');
  ini_set('session.cookie_samesite','Lax');
  session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','samesite'=>'Lax','path'=>'/']);
 }
 session_start();
}
function csrfToken():string{
 startCommunitySession();
 if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
 return $_SESSION['csrf'];
}
function verifyCsrf():void{
 startCommunitySession();
 if(!is_string($_POST['csrf']??null)||!hash_equals(csrfToken(),$_POST['csrf'])){http_response_code(403);exit('Неверный защитный токен. Обновите страницу.');}
}
function commentTarget(string $scope,int $movieId,string $type):bool{
 if($scope==='site')return $movieId===0&&$type==='';
 if($movieId<1)return false;
 if($scope==='movie')return $type===''&&movie($movieId)!==null;
 if($scope==='story'&&isset(TYPES[$type])){
  $m=movie($movieId);return $m!==null&&contentOf($m,$type)!=='';
 }
 return false;
}
function commentUrl(string $scope,int $id,string $type):string{
 if($scope==='site')return '/#comments';
 if($scope==='movie')return '/movie/'.$id.'#comments';
 return '/read/'.$id.'/'.$type.'#comments';
}
function commentsFor(string $scope,int $id,string $type):array{
 $s=communityDb()->prepare("SELECT author,body,created_at FROM comments WHERE scope=? AND movie_id=? AND story_type=? AND status='approved' ORDER BY id DESC LIMIT 100");
 $s->execute([$scope,$id,$type]);return $s->fetchAll();
}
function commentsWidget(string $scope,int $id=0,string $type=''):void{
 $comments=[];$error=false;
 try{$comments=commentsFor($scope,$id,$type);}catch(Throwable $e){error_log($e->getMessage());$error=true;}
 echo '<section class="comments" id="comments"><h2>Комментарии</h2><p class="comments-hint">Обсудите фильм или историю. Новые сообщения появляются после проверки модератором.</p>';
 if(isset($_GET['comment']))echo '<p class="notice">'.(($_GET['comment']==='sent')?'Комментарий отправлен на модерацию.':'Не удалось отправить комментарий.').'</p>';
 if($error){echo '<p class="notice">Комментарии временно недоступны.</p></section>';return;}
 echo '<form class="comment-form" action="/comment.php" method="post"><input type="hidden" name="csrf" value="'.e(csrfToken()).'"><input type="hidden" name="scope" value="'.e($scope).'"><input type="hidden" name="movie_id" value="'.$id.'"><input type="hidden" name="story_type" value="'.e($type).'"><label>Ваше имя<input name="author" maxlength="60" required placeholder="Как к вам обращаться"></label><label>Комментарий<textarea name="body" maxlength="3000" minlength="3" rows="5" required placeholder="Ваши впечатления…"></textarea></label><div class="trap" aria-hidden="true"><label>Сайт<input name="website" tabindex="-1" autocomplete="off"></label></div><button class="filter-btn" type="submit">Отправить на модерацию</button></form>';
 if(!$comments)echo '<p class="comments-hint">Пока комментариев нет. Станьте первым!</p>';
 foreach($comments as $c)echo '<article class="comment"><div><strong>'.e($c['author']).'</strong><time>'.e($c['created_at']).'</time></div><p>'.nl2br(e($c['body']),false).'</p></article>';
 echo '</section>';
}
