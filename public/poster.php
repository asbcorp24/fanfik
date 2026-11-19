<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
/**
 * Fetch each poster once, store it on this host and serve it locally thereafter.
 * Requires ext-curl. Never accept arbitrary URLs from HTTP requests.
 */
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if(!$id){http_response_code(404);exit;}
try{$m=movie($id);}catch(Throwable $e){error_log($e->getMessage());http_response_code(503);exit;}
if(!$m){http_response_code(404);exit;}
$url=urlPoster((string)($m['poster_url']??''));
if(!$url){http_response_code(404);exit;}
$hash=hash('sha256',$url);
$dir=__DIR__.'/assets/posters';
$base=$dir.'/'.$id.'-'.$hash;
$types=['jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif'];
function servePoster(string $path,string $mime):never{
 header('Content-Type: '.$mime);
 header('Cache-Control: public, max-age=31536000, immutable');
 header('X-Content-Type-Options: nosniff');
 header('Content-Length: '.filesize($path));
 readfile($path);exit;
}
foreach($types as $ext=>$mime){if(is_file($base.'.'.$ext)){servePoster($base.'.'.$ext,$mime);}}
if(!extension_loaded('curl')){http_response_code(503);exit;}
$host=parse_url($url,PHP_URL_HOST);
if(!$host || !preg_match('/^[a-z0-9.-]+$/i',$host)){http_response_code(404);exit;}
// Resolve once, pin the address in curl and reject private / reserved destinations.
$ips=gethostbynamel($host)?:[];
$public=array_values(array_filter($ips,static fn($ip)=>filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)!==false));
if(count($public)!==count($ips) || !$public){http_response_code(403);exit;}
$scheme=strtolower((string)parse_url($url,PHP_URL_SCHEME));
$port=$scheme==='https'?443:80;
$body='';$tooLarge=false;
$ch=curl_init($url);
curl_setopt_array($ch,[
 CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>12,
 CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
 CURLOPT_USERAGENT=>'FanfikPosterCache/1.0',CURLOPT_SSL_VERIFYPEER=>true,
 CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_RESOLVE=>[$host.':'.$port.':'.$public[0]],
 CURLOPT_WRITEFUNCTION=>static function($ch,$chunk)use(&$body,&$tooLarge){
  if(strlen($body)+strlen($chunk)>5242880){$tooLarge=true;return 0;}
  $body.=$chunk;return strlen($chunk);
 }
]);
$ok=curl_exec($ch);
$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
curl_close($ch);
if(!$ok || $tooLarge || $status!==200 || $body===''){http_response_code(502);exit;}
$info=@getimagesizefromstring($body);
$mime=$info['mime']??'';
$ext=array_search($mime,$types,true);
if($ext===false || ($info[0]??0)>5000 || ($info[1]??0)>7500){http_response_code(415);exit;}
if(!is_dir($dir) && !mkdir($dir,0755,true) && !is_dir($dir)){http_response_code(503);exit;}
$tmp=tempnam($dir,'.poster-');
if(!$tmp){http_response_code(503);exit;}
if(file_put_contents($tmp,$body,LOCK_EX)===false){@unlink($tmp);http_response_code(503);exit;}
$target=$base.'.'.$ext;
if(!is_file($target) && !rename($tmp,$target)){@unlink($tmp);http_response_code(503);exit;}
@unlink($tmp);
servePoster($target,$mime);
