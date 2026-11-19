<?php
declare(strict_types=1);
const TYPES = [
 'plot'=>['Сюжет фильма','plot_ru','plot'],
 'ending'=>['Чем закончился фильм','ending_ru','ending'],
 'novel'=>['Литературная версия','novel_ru',null],
 'erotic'=>['Романтика 18+','novel_erotic',null],
 'horror'=>['Ужасы','novel_horror',null],
 'fantasy'=>['Фэнтези','novel_fantasy',null],
 'comedy'=>['Комедия','novel_comedy',null],
 'detective'=>['Детектив','novel_detective',null],
 'thriller'=>['Триллер','novel_thriller',null]
];
function db(): PDO {
 static $pdo;
 if($pdo instanceof PDO) return $pdo;
 $path = getenv('FANFIK_DB_PATH') ?: dirname(__DIR__).'/storage/spoilers.db';
 if(!is_file($path) || !is_readable($path)) throw new RuntimeException('База данных не найдена или недоступна. Проверьте FANFIK_DB_PATH.');
 $pdo = new PDO('sqlite:'.realpath($path), null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT=>3, PDO::ATTR_PERSISTENT=>false, PDO::ATTR_EMULATE_PREPARES=>false]);
 return $pdo;
}
function e(mixed $s):string{return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function linkMovie(int $id):string{return '/movie/'.$id;}
function title(array $movie):string{
 $original=trim((string)($movie['title']??''));
 $translated=trim((string)($movie['title_ru']??''));
 // Bad machine translations sometimes contain the whole synopsis instead of a title.
 // Keep the source title without modifying the database.
 if($translated==='' || mb_strlen($translated)>110 || preg_match('/[.!?]\\s+.{20,}/u',$translated))return $original;
 return $translated;
}
function catalogTitle(array $movie):string{
 $name=title($movie);
 return mb_strlen($name)>90?mb_substr($name,0,89).'…':$name;
}
function excerpt(string $s,int $limit=180):string{
 $s=trim(preg_replace('/\s+/u',' ',strip_tags($s))??'');
 return mb_strlen($s)>$limit?mb_substr($s,0,$limit).'…':$s;
}
function contentOf(array $m,string $kind):string{
 $t=TYPES[$kind]??null;
 if(!$t) return '';
 $value=trim((string)($m[$t[1]]??''));
 return $value!==''?$value:($t[2]?trim((string)($m[$t[2]]??'')):'');
}
function movie(int $id):?array{
 $stmt=db()->prepare('SELECT * FROM movies WHERE id=:id');
 $stmt->execute([':id'=>$id]);
 return $stmt->fetch()?:null;
}
function urlPoster(string $s):string{
 $s=trim($s);
 if(!$s || !filter_var($s,FILTER_VALIDATE_URL))return '';
 $p=parse_url($s);
 if(!in_array(strtolower($p['scheme']??''),['https','http'],true))return '';
 return $s;
}
function catalog(string $query,string $year,string $genre,string $kind,int $page):array{
 $w=[];$args=[];
 if($query!==''){ $w[]="(title LIKE :q OR title_ru LIKE :q2)";$args[':q']='%'.$query.'%';$args[':q2']='%'.$query.'%';}
 if($year!==''){ $w[]='year = :year';$args[':year']=(int)$year; }
 if($genre!==''){ $w[]='genres LIKE :genre';$args[':genre']='%'.$genre.'%';}
 if($kind!=='' && isset(TYPES[$kind])){
   $t=TYPES[$kind]; $cols=[$t[1]];
   if($t[2])$cols[]=$t[2];
   $w[]='('.implode(' OR ',array_map(fn($c)=>"length(trim(coalesce($c,'')))>0",$cols)).')';
 }
 $where=$w?' WHERE '.implode(' AND ',$w):'';
 $total=db()->prepare('SELECT count(*) FROM movies'.$where);$total->execute($args);
 $count=(int)$total->fetchColumn();$pages=max(1,(int)ceil($count/24));$page=min($page,$pages);
 $stmt=db()->prepare('SELECT id,title,title_ru,year,genres,poster_url,plot,plot_ru,ending,ending_ru,novel_ru FROM movies'.$where.' ORDER BY COALESCE(NULLIF(title_ru,\'\'),title) COLLATE NOCASE ASC LIMIT 24 OFFSET :offset');
 foreach($args as $k=>$v)$stmt->bindValue($k,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
 $stmt->bindValue(':offset',($page-1)*24,PDO::PARAM_INT);$stmt->execute();
 return [$stmt->fetchAll(),$count,$page,$pages];
}
function allGenres():array{
 $genres=[];
 foreach(db()->query("SELECT genres FROM movies WHERE genres IS NOT NULL AND genres<>''") as $row){
  foreach(preg_split('/[,;|]/u',(string)$row['genres']) as $g){
   $g=trim($g); if($g!=='')$genres[mb_strtolower($g)]=$g;
  }
 }
 natcasesort($genres);return array_values($genres);
}
function renderText(string $s):string{
 $parts=preg_split('/\R\s*\R/u',trim($s))?:[];
 return implode("\n",array_map(fn($p)=>'<p>'.nl2br(e(trim($p)),false).'</p>',$parts));
}
