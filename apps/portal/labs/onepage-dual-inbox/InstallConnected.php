<?php
declare(strict_types=1);
namespace PrivateSharedIngress;
use PDO;use Closure;use RuntimeException;use Throwable;
use PrivateDualDb\DualDbGate;
use PrivateInboxSemantic\Compare;
/** Private connected installer. Uses expectations emitted in the supervisor's sealed review. */
final class InstallConnected {
 private static function need(bool $v):void{if(!$v)throw new RuntimeException('SHARED_CONNECTED_INSTALL_REFUSED');}
 private static function norm(mixed $v):mixed{if(!is_array($v))return $v;if(!array_is_list($v))ksort($v);return array_map(self::norm(...),$v);}
 public static function run(array $ctx,array $q,array $fragments,string $catalogSql,string $deltaSql,string $f392):void{
  self::need(array_keys($q)===['model','finance','shared','recheck']);
  self::need(($q['recheck']??null) instanceof Closure&&($ctx['recheck']??null) instanceof Closure);
  $m=$q['model'];$finance=$q['finance'];$permit=$q['shared'];
  self::need($permit===['phase'=>'AFTER_FINANCE_TO_SHARED','files'=>SharedCatalog::PINS]);
  $expected=SharedCatalog::expected($fragments);
  $p=$ctx['inbox'];self::need($p instanceof PDO&&!$p->inTransaction()&&$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql');
  $binding=static function()use($ctx,$q,$p):void{($ctx['recheck'])();self::need(($q['recheck'])()===$p);};
  $facts=static function(array $expected)use($p):void{
   $r=$p->query(DualDbGate::SQL)->fetch(PDO::FETCH_ASSOC);
   self::need(is_array($r)&&in_array($r['fresh']??null,[true,'t',1,'1'],true)&&self::norm(json_decode($r['facts'],true,64,JSON_THROW_ON_ERROR))===self::norm($expected));
  };
  $base=array_replace($m['baseline'],$m['phaseAnchors']['POST_BINDING']);
  foreach([['onepage_finance_lab_owner',false,false,false,false,false,false,false],['onepage_finance_lab_verifier',true,false,false,false,false,false,false]] as $role)$base['roles'][]=$role;
  $binding();$facts($finance['AFTER_FINANCE']['inbox']);
  Compare::matches('F392',$f392,$base,json_decode($p->query($catalogSql)->fetchColumn(),true,64,JSON_THROW_ON_ERROR));
  $binding();self::need($p->beginTransaction());
  try{
   $p->exec("SET LOCAL lock_timeout='2s'; SET LOCAL statement_timeout='3s'");
   foreach($fragments as $sql)self::need($p->exec($sql)!==false);
   $facts($finance['SHARED_INSTALLED']['inbox']);
   SharedCatalog::matches($expected,json_decode($p->query($deltaSql)->fetchColumn(),true,64,JSON_THROW_ON_ERROR));
   Compare::matches('F392',$f392,SharedCatalog::baseline($base),json_decode($p->query(SharedCatalog::projection($catalogSql))->fetchColumn(),true,64,JSON_THROW_ON_ERROR));
   $binding();self::need($p->commit());
  }catch(Throwable){if($p->inTransaction())$p->rollBack();throw new RuntimeException('SHARED_CONNECTED_COMMIT_UNCONFIRMED');}
 }
}
