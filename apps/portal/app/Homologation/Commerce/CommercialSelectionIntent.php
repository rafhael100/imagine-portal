<?php
declare(strict_types=1);
namespace App\Homologation\Commerce;
use Illuminate\Contracts\Session\Session;
use InvalidArgumentException;
use Throwable;
/** Informational enums only. F426 parsing contract; no acceptance or authority. */
final class CommercialSelectionIntent {
 public const KEY='preview.commercial_selection';
 public const HEADERS=['Cache-Control'=>'no-store, private','Referrer-Policy'=>'no-referrer','X-Robots-Tag'=>'noindex, nofollow'];
 /** Public catalog presentation only; no customer lookup or combined bill. */
 public static function viewData(Session $session):array {
  $intent=self::current($session);$channels=[];
  if($intent!==null)foreach(explode(',',$intent['channels']) as $channel)if($channel!=='')
   $channels[$channel]=self::quote(['site'=>'none','channels'=>$channel,'tier'=>$intent['tier']]);
  return ['commercialIntent'=>$intent,'commercialQuote'=>$intent===null?null:self::quote($intent),'commercialChannels'=>$channels];
 }
 /** Decode only this endpoint's three fields, before TrimStrings/null conversion.
  * Never retain the raw query, duplicate fields, structures or client amounts.
  */
 public static function replaceQuery(Session $session,string $query):void {
  $session->forget([self::KEY,'preview.intent']);
  $pairs=explode('&',$query);
  if(strlen($query)>1024||count($pairs)!==3)throw new InvalidArgumentException('INVALID_COMMERCIAL_INTENT');
  $input=[];
  foreach($pairs as $pair){
   if(!str_contains($pair,'=')||preg_match('/%(?![0-9a-fA-F]{2})/',$pair))throw new InvalidArgumentException('INVALID_COMMERCIAL_INTENT');
   [$key,$value]=array_map('urldecode',explode('=',$pair,2));
   if(!in_array($key,['site','channels','tier'],true)||array_key_exists($key,$input))throw new InvalidArgumentException('INVALID_COMMERCIAL_INTENT');
   $input[$key]=$value;
  }
  self::replace($session,$input);
 }
 public static function parse(array $input):array {
  $keys=array_keys($input);sort($keys);
  if($keys!==['channels','site','tier'])throw new InvalidArgumentException('INVALID_COMMERCIAL_INTENT');
  foreach($input as $v)if(!is_string($v)||strlen($v)>40||preg_match('/[\x00-\x20\x7f]/',$v))throw new InvalidArgumentException('INVALID_COMMERCIAL_INTENT');
  if(!in_array($input['site'],['none','onepage','landing-pages'],true)
   ||!in_array($input['channels'],['','google_ads','meta_ads','google_ads,meta_ads'],true)
   ||!in_array($input['tier'],['minimum','recommended'],true)
   ||($input['site']==='none'&&$input['channels']===''))throw new InvalidArgumentException('INVALID_COMMERCIAL_INTENT');
  return ['site'=>$input['site'],'channels'=>$input['channels'],'tier'=>$input['tier']];
 }
 public static function quote(array $input):array {
  $v=self::parse($input);
  return (new CompositeQuotePreview())->quote(['site'=>$v['site'],'channels'=>$v['channels']===''?[]:explode(',',$v['channels']),'tier'=>$v['tier']]);
 }
 /** Clear the old intent BEFORE validation, so rejected replacement cannot resurrect it. */
 public static function replace(Session $session,array $input):void {
  $session->forget([self::KEY,'preview.intent']);
  $v=self::parse($input);self::quote($v);$session->put(self::KEY,$v);
 }
 public static function current(Session $session):?array {
  $v=$session->get(self::KEY);if($v===null)return null;
  try{if(!is_array($v))throw new InvalidArgumentException();$v=self::parse($v);self::quote($v);return $v;}
  catch(Throwable){$session->forget(self::KEY);return null;}
 }
 /** Only invoke after successful rotation, never logout/failed authentication. */
 public static function restore(Session $session,?array $intent):void {
  if($intent!==null)self::replace($session,$intent);
 }
}
