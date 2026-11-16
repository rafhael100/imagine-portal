<?php
declare(strict_types=1);
namespace App\Support;
use RuntimeException;
final class PrivateStagingSecret {
 public static function read(mixed $path,mixed $fallback=null):?string {
  if($path===null||$path==='')return is_string($fallback)?$fallback:null;
  if(!is_string($path)||!preg_match('/\A\/run\/secrets\/[a-z][a-z0-9_]{1,80}\z/D',$path)||is_link($path)||!is_file($path)||!is_readable($path))throw new RuntimeException('Private staging material unavailable');
  $s=lstat($path);if(!$s||!in_array($s['mode']&0777,[0400,0600],true)||$s['size']<16||$s['size']>4096)throw new RuntimeException('Private staging material denied');
  $v=rtrim(file_get_contents($path),"\r\n");if(strlen($v)<16||strpbrk($v,"\r\n\0")!==false)throw new RuntimeException('Private staging material denied');return $v;
 }
}
