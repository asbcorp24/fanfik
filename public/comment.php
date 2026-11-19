<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/community.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
verifyCsrf();
$scope=(string)($_POST['scope']??'');
$id=filter_var($_POST['movie_id']??0,FILTER_VALIDATE_INT);
$id=$id===false?-1:$id;
$type=(string)($_POST['story_type']??'');
try{if(!commentTarget($scope,$id,$type))throw new InvalidArgumentException('Invalid target');}
catch(Throwable $e){http_response_code(400);exit('Неверная страница комментариев');}
$redirect=commentUrl($scope,$id,$type);
if(trim((string)($_POST['website']??''))!==''){header('Location: '.$redirect, true,303);exit;}
$author=trim((string)($_POST['author']??''));
$body=trim((string)($_POST['body']??''));
if(mb_strlen($author)<2||mb_strlen($author)>60||mb_strlen($body)<3||mb_strlen($body)>3000){http_response_code(422);exit('Имя или комментарий не соответствует ограничениям длины.');}
startCommunitySession();
if((int)($_SESSION['comment_last']??0)>time()-45){http_response_code(429);exit('Подождите 45 секунд перед следующим комментарием.');}
try{
 $pdo=communityDb();
 $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
 $secret=getenv('FANFIK_COMMENT_SALT')?:'fanfik-local-comment-rate';
 $ipHash=hash_hmac('sha256',$ip,$secret);
 $stmt=$pdo->prepare("SELECT count(*) FROM comments WHERE ip_hash=? AND created_at >= datetime('now','-1 hour')");
 $stmt->execute([$ipHash]);
 if((int)$stmt->fetchColumn()>=6){http_response_code(429);exit('Превышен лимит комментариев за час.');}
 $stmt=$pdo->prepare("INSERT INTO comments(scope,movie_id,story_type,author,body,status,ip_hash) VALUES(?,?,?,?,?,'pending',?)");
 $stmt->execute([$scope,$id,$type,$author,$body,$ipHash]);
 $_SESSION['comment_last']=time();
 header('Location: '.str_replace('#comments','?comment=sent#comments',$redirect),true,303);
}catch(Throwable $e){error_log($e->getMessage());http_response_code(503);exit('Не удалось сохранить комментарий.');}
