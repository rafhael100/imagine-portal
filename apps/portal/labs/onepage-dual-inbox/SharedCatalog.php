<?php
declare(strict_types=1);
namespace PrivateSharedIngress;
use RuntimeException;
/** Exact expected values from reviewed SQL, not catalog observations. */
final class SharedCatalog {
 const ACCOUNT='1fcc491f-96d9-4efe-8ec5-b8b09de750dd';
 const OWNER='onepage_ingress_owner';
 const BOOT='portal_native_test_bootstrap';
 const CAP='onepage_ingress_capture';
 const REV='onepage_ingress_revoker';
 const SC='onepage_ingress_lab';
 const PINS=['pre.sql'=>'06c1b2041d08c95d55e6f3b4e83690251d12eb53c6b55d6af6da7e08bece1078','functions.sql'=>'4ba6386d076331a97966966cfd07f1e948d774180811a084d93b94e1bf197162','post.sql'=>'6f15b0abb1d254a63ecfb0529314d1e507b3128f64139e199924d880cb4611ac'];
 private static function need(bool $v):void {if(!$v)throw new RuntimeException('SHARED_CATALOG_REFUSED');}
 public static function expected(array $f):array {
  foreach(self::PINS as $n=>$h)self::need(isset($f[$n])&&hash('sha256',$f[$n])===$h);
  $s=self::SC;$o=self::OWNER;$b=self::BOOT;$a=self::ACCOUNT;
  $account="account_ref = '$a'::text";$env="$account AND environment = 'sandbox'::text";
  $uuid="account_ref = '$a'::uuid";$assigned="CURRENT_USER = '$o'::name AND $uuid";
  preg_match_all('/AS \$\$(.*?)\$\$;/s',$f['functions.sql'],$hits);self::need(count($hits[1])===2);
  $tables=['admissions'=>['account_ref','order_id','payment_id','revision','environment','active'],'inbox'=>['account_ref','event_id','event_type','payment_id','order_id','revision','payload_sha256','received_at']];
  $cols=[];
  foreach($tables as $t=>$names)foreach($names as $i=>$n)$cols[]=[$t,$i+1,$n,match($n){'active'=>'boolean','received_at'=>'timestamp with time zone',default=>'text'},true,'','',$n==='received_at'?'clock_timestamp()':null];
  $acl=[];
  $add=static function($kind,$target,$col,$role,$owner,$rights)use(&$acl){foreach($rights as $right)$acl[]=[$kind,$target,$col,$role,$owner,$right,false];};
  $add('schema',$s,null,$b,$b,['CREATE','USAGE']);
  foreach([$o,self::CAP,self::REV] as $r)$add('schema',$s,null,$r,$b,['USAGE']);
  foreach(array_keys($tables) as $t)$add('table',"$s.$t",null,$b,$b,['SELECT','INSERT','UPDATE','DELETE','TRUNCATE','REFERENCES','TRIGGER','MAINTAIN']);
  $add('table',"$s.admissions",null,$o,$b,['SELECT']);
  $add('column',"$s.admissions",'active',$o,$b,['UPDATE']);
  $add('table',"$s.inbox",null,$o,$b,['SELECT','INSERT']);
  $add('schema','portal_psp',null,$o,'postgres',['USAGE']);
  $add('table','portal_psp.asaas_inbox',null,$o,$b,['SELECT','INSERT']);
  foreach([self::CAP,self::REV] as $r)$add('database','portal_asaas_ci',null,$r,$b,['CONNECT']);
  foreach(['capture_shared'=>self::CAP,'revoke'=>self::REV] as $fn=>$r){
   $add('function',$fn,null,$o,$o,['EXECUTE']);
   $add('function',$fn,null,$r,$o,['EXECUTE']);
  }
  $nn=[];foreach($tables as $t=>$names)foreach($names as $n)$nn[]=[$t,null,'n',[$n],null,true,true,false,false,true,0];
  return [
   'schemas'=>[[$s,$b]],
   'relations'=>array_merge(array_map(fn($t)=>[$t,'r',$b,true,true,'p',null,false],array_keys($tables)),array_map(fn($t)=>[$t,'i',$b,false,false,'p',null,false],['admissions_pkey','admissions_account_ref_payment_id_key','inbox_pkey'])),
   'columns'=>$cols,
   'constraints'=>array_merge($nn,[['admissions','admissions_pkey','p',['account_ref','order_id'],null,true,true,false,false,true,0],['admissions','admissions_account_ref_payment_id_key','u',['account_ref','payment_id'],null,true,true,false,false,true,0],['admissions','admissions_environment_check','c',['environment'],"environment = 'sandbox'::text",true,true,false,false,true,0],['inbox','inbox_pkey','p',['account_ref','event_id'],null,true,true,false,false,true,0]]),
   'indexes'=>[['admissions','admissions_pkey',true,true,true,true,true,true,false,'btree',['account_ref','order_id'],null,null,2,2,true],['admissions','admissions_account_ref_payment_id_key',true,false,true,true,true,true,false,'btree',['account_ref','payment_id'],null,null,2,2,true],['inbox','inbox_pkey',true,true,true,true,true,true,false,'btree',['account_ref','event_id'],null,null,2,2,true]],
   'policies'=>[
     ["$s.admissions",'admissions_owner','*',true,[$o],'true','true'],
     ["$s.admissions",'admissions_exact','*',false,[$o],$env,$env],
     ["$s.inbox",'inbox_owner','*',true,[$o],'true','true'],
     ["$s.inbox",'inbox_exact','*',false,[$o],$account,$account],
     ['portal_psp.asaas_inbox','onepage_shared_select','r',true,[$o],$assigned,null],
     ['portal_psp.asaas_inbox','onepage_shared_insert','a',true,[$o],null,$assigned],
     ['portal_psp.asaas_inbox','onepage_shared_account','*',false,[$o],$uuid,$uuid],
   ],
   'functions'=>[
    ['capture_shared','a text, o text, p text, r text, env text, e text, k text, ep text, h text','text','plpgsql',$o,true,'v','u',false,false,['search_path=pg_catalog, pg_temp'],hash('sha256',$hits[1][0])],
    ['revoke','a text, o text, p text, r text','boolean','plpgsql',$o,true,'v','u',false,false,['search_path=pg_catalog, pg_temp'],hash('sha256',$hits[1][1])],
   ],
   'acl'=>$acl,'forbidden'=>[0,0,0,0,0,0,0],
  ];
 }
 /** Projection only after exact shared-delta audit. Upstream query bytes pinned. */
 public static function projection(string $source):string {
  self::need(hash('sha256',$source)==='808aa9b6f8273a81787b798f6a03b58f2eace9ec2d480af3da356fcfb9bd7734');
  $edit=[
   "WHERE nspname !~ '^pg_' AND nspname<>'information_schema'"=>"WHERE nspname !~ '^pg_' AND nspname NOT IN ('information_schema','onepage_ingress_lab')",
   "FROM pg_catalog.pg_policy p JOIN rel r ON r.oid=p.polrelid)"=>"FROM pg_catalog.pg_policy p JOIN rel r ON r.oid=p.polrelid WHERE NOT (r.nspname='portal_psp' AND r.relname='asaas_inbox' AND p.polname IN ('onepage_shared_select','onepage_shared_insert','onepage_shared_account')))",
   "privilege_type,is_grantable)),'[]') FROM acl)"=>"privilege_type,is_grantable)),'[]') FROM acl WHERE NOT ((kind='schema' AND object='portal_psp' AND pg_catalog.pg_get_userbyid(grantee)='onepage_ingress_owner' AND privilege_type='USAGE') OR (kind='relation' AND object='portal_psp.asaas_inbox' AND pg_catalog.pg_get_userbyid(grantee)='onepage_ingress_owner' AND privilege_type IN ('SELECT','INSERT')) OR (kind='database' AND object='portal_asaas_ci' AND pg_catalog.pg_get_userbyid(grantee) IN ('onepage_ingress_capture','onepage_ingress_revoker') AND privilege_type='CONNECT')))",
   "rolcreaterole,rolreplication,rolbypassrls)),'[]') FROM pg_catalog.pg_roles)"=>"rolcreaterole,rolreplication,rolbypassrls)),'[]') FROM pg_catalog.pg_roles WHERE rolname NOT IN ('onepage_ingress_capture','onepage_ingress_owner','onepage_ingress_revoker'))",
  ];
  foreach($edit as $before=>$after){self::need(substr_count($source,$before)===1);$source=str_replace($before,$after,$source);}
  return $source;
 }
 /** Only removals are the three independently modeled PUBLIC rows. */
 public static function baseline(array $old):array {
  $revoked=[['schema','public',null,'PUBLIC','pg_database_owner','USAGE',false]];
  foreach(['CONNECT','TEMPORARY'] as $priv)$revoked[]=['database','portal_asaas_ci',null,'PUBLIC',self::BOOT,$priv,false];
  foreach($revoked as $row){
   $keys=array_keys(array_filter($old['acl'],fn($v)=>$v===$row));
   self::need(count($keys)===1);unset($old['acl'][$keys[0]]);
  }
  $old['acl']=array_values($old['acl']);return $old;
 }
 /** The exact four-case rehearsal only. Installation still requires empty tables. */
 public static function expectedAfterCases(array $fragments):array {
  $expected=self::expected($fragments);
  $expected['forbidden'][5]=8;  // two admissions per case
  $expected['forbidden'][6]=12; // target, alias, neighbor per case
  return $expected;
 }
 /** Check queue cardinality before adapting the projection to F392's empty fixture. */
 public static function afterCasesProjection(array $observed):array {
  self::need(($observed['rows']??null)===12);
  $observed['rows']=0;
  return $observed;
 }
 // Identical policy AST for a closed expression grammar: atoms are exact and
 // optional parentheses may wrap an atom or the whole two-atom conjunction.
 private static function expression(mixed $v):mixed {
  if($v===null)return null;
  self::need(is_string($v));
  $v=trim($v);
  $strip=static fn(string $x):string=>str_starts_with($x,'(')&&str_ends_with($x,')')?trim(substr($x,1,-1)):$x;
  // PostgreSQL pretty deparse has either no parentheses or a single outer pair.
  $candidate=$strip($v);
  foreach([$v,$candidate] as $text){
   $parts=preg_split('/[ ]+AND[ ]+/i',$text);
   self::need(is_array($parts)&&count($parts)<=2);
   $atoms=[];
   foreach($parts as $part){
    $part=$strip(trim($part));
    if($part==='true'){$atoms[]='true';continue;}
    if(!preg_match("/^(account_ref|environment|current_user) = ('[a-z0-9_-]+')::(text|name|uuid)$/iD",$part,$m))continue 2;
    $atoms[]=strtolower($m[1]).' = '.$m[2].'::'.strtolower($m[3]);
   }
   return implode(' AND ',$atoms);
  }
  self::need(false);
  return null;
 }
 public static function matches(array $expected,array $observed):void {
  ksort($expected);ksort($observed);self::need(array_keys($expected)===array_keys($observed));
  foreach($expected as $k=>$rows){
   self::need(is_array($observed[$k])&&array_is_list($observed[$k]));
   $actual=$observed[$k];
   if($k==='constraints'){
    $seen=[];
    foreach($actual as &$row){
     self::need(is_array($row)&&count($row)===11&&is_string($row[0])&&is_string($row[1])&&preg_match('/^[a-z0-9_]+$/D',$row[1])===1);
     $key=$row[0].'.'.$row[1];self::need(!isset($seen[$key]));$seen[$key]=true;
     // PG18 stores a named constraint for NOT NULL. The pinned F392 checker
     // intentionally identifies generated names by exact relation/column.
     if($row[2]==='n')$row[1]=null;
     $row[4]=self::expression($row[4]);
    }unset($row);
   }
   if($k==='policies'){
    foreach($actual as &$row){
     self::need(is_array($row)&&count($row)===7);
     $row[5]=self::expression($row[5]);$row[6]=self::expression($row[6]);
    }unset($row);
    foreach($rows as &$row){$row[5]=self::expression($row[5]);$row[6]=self::expression($row[6]);}unset($row);
   }
   if($k!=='forbidden'){
    $encode=static fn($row)=>json_encode($row,JSON_THROW_ON_ERROR);
    $rows=array_map($encode,$rows);$actual=array_map($encode,$actual);sort($rows,SORT_STRING);sort($actual,SORT_STRING);
   }
   self::need($rows===$actual);
  }
 }
}
