<?php
declare(strict_types=1);
namespace PrivateDualDb;
use Closure;use RuntimeException;use Throwable;
final class InboxInstall {
 public const MARK="PASS INBOX_INSTALLED\n";
 public const PINS=['inbox.sql'=>'292b23f7416e34e9b6f6b8ebb9dff6d056426f4b','lease.sql'=>'03da8af90eca8733fb1842498fd4ac6b50fe8d7d'];
 public const CATALOG=<<<'SQL'
SELECT jsonb_build_object(
 'schemas',(SELECT jsonb_agg(jsonb_build_array(nspname,pg_get_userbyid(nspowner),nspacl::text) ORDER BY nspname) FROM pg_namespace WHERE nspname !~ '^pg_' AND nspname<>'information_schema'),
 'relations',(SELECT jsonb_agg(jsonb_build_array(n.nspname,c.relname,c.relkind,pg_get_userbyid(c.relowner),c.relrowsecurity,c.relforcerowsecurity,c.relacl::text) ORDER BY n.nspname,c.relname) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname !~ '^pg_' AND n.nspname<>'information_schema'),
 'columns',(SELECT jsonb_agg(jsonb_build_array(n.nspname,c.relname,a.attname,format_type(a.atttypid,a.atttypmod),a.attnotnull,pg_get_expr(d.adbin,d.adrelid)) ORDER BY n.nspname,c.relname,a.attnum) FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid JOIN pg_namespace n ON n.oid=c.relnamespace LEFT JOIN pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum WHERE a.attnum>0 AND NOT a.attisdropped AND n.nspname !~ '^pg_' AND n.nspname<>'information_schema'),
 'constraints',(SELECT jsonb_agg(jsonb_build_array(n.nspname,c.conname,pg_get_constraintdef(c.oid)) ORDER BY n.nspname,c.conname) FROM pg_constraint c JOIN pg_namespace n ON n.oid=c.connamespace WHERE n.nspname !~ '^pg_' AND n.nspname<>'information_schema'),
 'indexes',(SELECT jsonb_agg(jsonb_build_array(schemaname,indexname,indexdef) ORDER BY schemaname,indexname) FROM pg_indexes WHERE schemaname !~ '^pg_' AND schemaname<>'information_schema'),
 'policies',(SELECT jsonb_agg(to_jsonb(p) ORDER BY schemaname,tablename,policyname) FROM pg_policies p WHERE schemaname !~ '^pg_' AND schemaname<>'information_schema'),
 'functions',(SELECT jsonb_agg(jsonb_build_array(n.nspname,p.proname,pg_get_function_identity_arguments(p.oid),pg_get_userbyid(p.proowner),p.prosecdef,p.proconfig,p.proacl::text,pg_get_functiondef(p.oid)) ORDER BY n.nspname,p.proname,pg_get_function_identity_arguments(p.oid)) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname !~ '^pg_' AND n.nspname<>'information_schema'),
 'defaults',(SELECT jsonb_agg(jsonb_build_array(pg_get_userbyid(d.defaclrole),n.nspname,d.defaclobjtype,d.defaclacl::text) ORDER BY d.defaclrole,d.defaclnamespace,d.defaclobjtype) FROM pg_default_acl d LEFT JOIN pg_namespace n ON n.oid=d.defaclnamespace)
) AS catalog
SQL;
 private static function need(bool $v):void{if(!$v)throw new RuntimeException('INBOX_INSTALL_REFUSED');}
 private static function json(array $v):string{return "convert_from(decode('".bin2hex(json_encode($v,JSON_THROW_ON_ERROR))."','hex'),'UTF8')::jsonb";}
 public static function preamble(array $a):string{
  DualDbGate::validateAnchor($a);
  return "\\set ON_ERROR_STOP on\n\\unset dual_ok\nSELECT (fresh AND facts::jsonb = ".self::json($a['expected']).") AS dual_ok FROM (\n".DualDbGate::SQL."\n) verified \\gset\n\\if :dual_ok\n\\else\nSELECT 1/0 AS dual_gate_refused;\n\\endif\n";
 }
 private static function installed(array $before,array $expected,array $catalog):string{
  $stable=$before['expected'];foreach(['roles','memberships','empty'] as $k)unset($stable[$k]);
  foreach($stable as $k=>$v)self::need(array_key_exists($k,$expected)&&$expected[$k]===$v);
  self::need(($expected['empty']??null)===false&&is_array($expected['roles']??null)&&is_array($expected['memberships']??null));
  self::need(array_keys($catalog)===['schemas','relations','columns','constraints','indexes','policies','functions','defaults']);
  return "\\unset installed_ok\nSELECT (fresh AND facts::jsonb = ".self::json($expected)." AND pg_backend_pid() = :original_pid AND catalog = ".self::json($catalog).") AS installed_ok FROM (\n".DualDbGate::SQL."\n) identity CROSS JOIN (\n".self::CATALOG."\n) inventory \\gset\n";
 }
 public static function script(array $before,array $inbox,array $leased,array $catalogInbox,array $catalogLeased):string{
  DualDbGate::validateAnchor($before);self::need($before['phase']==='asaas_empty_owner_ready');
  return self::preamble($before).
   "\\if :dual_ok\nSELECT pg_backend_pid() AS original_pid \\gset\n\\ir inbox.sql\n".
   self::installed($before,$inbox,$catalogInbox)."\\if :installed_ok\n\\ir lease.sql\n".
   self::installed($before,$leased,$catalogLeased)."\\if :installed_ok\n\\echo PASS INBOX_INSTALLED\n".
   "\\else\nSELECT 1/0 AS lease_inventory_refused;\n\\endif\n".
   "\\else\nSELECT 1/0 AS inbox_inventory_refused;\n\\endif\n".
   "\\else\nSELECT 1/0 AS inbox_gate_refused;\n\\endif\n";
 }
 public static function pinned(array $files):void{
  self::need(array_keys($files)===array_keys(self::PINS));
  foreach(self::PINS as $name=>$pin){
   $s=$files[$name];self::need(is_string($s)&&sha1('blob '.strlen($s)."\0".$s)===$pin);
   // Originals own BEGIN/COMMIT; any failure requires whole-cluster disposal.
   self::need(!preg_match('/^\s*\\\\(?:c|connect|q|quit|i|ir|include|include_relative|!)(?:\s|$)/im',$s));
  }
 }
 public static function arguments(array $a):array{
  DualDbGate::validateAnchor($a);
  return ['-X','-w','-q','--host=127.0.0.1','--port='.$a['expected']['port'],'--dbname=portal_asaas_ci','--username=portal_native_test_bootstrap','--file=install.psql'];
 }
 public static function accepted(int $rc,string $out,bool $audit):bool{return $rc===0&&$out===self::MARK&&$audit;}
 // Trusted #962 supervisor adapters only. execute owns bounded capture, deadline,
 // same-session psql -X -w -q -f (never -1), frozen pinned files and closes inherited public FDs.
 // audit uses newly attested PDO and reviewed installed inventory. No raw output escapes.
 public static function run(array $files,array $before,array $inbox,array $leased,array $catalogInbox,array $catalogLeased,Closure $gate,Closure $execute,Closure $audit,Closure $cleanup):void{
  $ok=false;$clean=false;
  try{
   self::pinned($files);$script=self::script($before,$inbox,$leased,$catalogInbox,$catalogLeased);self::need($gate()===true);
   $r=$execute($files,$script);
   self::need(is_array($r)&&isset($r['rc'],$r['stdout'])&&is_int($r['rc'])&&is_string($r['stdout']));
   $ok=self::accepted($r['rc'],$r['stdout'],$r['rc']===0&&$r['stdout']===self::MARK&&$audit()===true);
  }catch(Throwable){$ok=false;}
  finally{try{$clean=$cleanup()===true;}catch(Throwable){$clean=false;}}
  self::need($ok&&$clean);
 }
}
