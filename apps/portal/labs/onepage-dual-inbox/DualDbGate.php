<?php
declare(strict_types=1);
namespace PrivateDualDb;
use Closure;use PDO;use RuntimeException;
final class DualDbGate {
 public const NAME='asaas_lab_bootstrap';
 public const SQL=<<<'SQL'
SELECT jsonb_build_object(
 'cluster',system_identifier::text,
 'started',extract(epoch FROM pg_postmaster_start_time())::text,
 'pid',split_part(pg_read_file('postmaster.pid'),chr(10),1),
 'data',current_setting('data_directory'),'port',inet_server_port()::text,
 'address',host(inet_server_addr()),'database',current_database(),
 'login',session_user,'actor',current_user,
 'major',(current_setting('server_version_num')::int/10000)::text,
 'listen',current_setting('listen_addresses'),'sockets',current_setting('unix_socket_directories'),
 'roles',(SELECT coalesce(jsonb_agg(jsonb_build_array(rolname,rolcanlogin,rolsuper,rolinherit,rolcreatedb,rolcreaterole,rolreplication,rolbypassrls) ORDER BY rolname),'[]'::jsonb) FROM pg_roles WHERE rolname !~ '^pg_'),
 'memberships',(SELECT coalesce(jsonb_agg(jsonb_build_array(a.rolname,b.rolname,m.admin_option,m.inherit_option,m.set_option) ORDER BY a.rolname,b.rolname),'[]'::jsonb) FROM pg_auth_members m JOIN pg_roles a ON a.oid=m.roleid JOIN pg_roles b ON b.oid=m.member),
 'databases',(SELECT jsonb_agg(datname ORDER BY datname) FROM pg_database),
 'empty',NOT EXISTS(SELECT FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname !~ '^pg_' AND n.nspname<>'information_schema')
   AND NOT EXISTS(SELECT FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname !~ '^pg_' AND n.nspname<>'information_schema')
   AND NOT EXISTS(SELECT FROM pg_namespace WHERE nspname !~ '^pg_' AND nspname NOT IN ('public','information_schema')),
 'postgres_safe',(SELECT NOT rolcanlogin AND NOT rolsuper AND NOT rolinherit AND NOT rolcreatedb AND NOT rolcreaterole AND NOT rolreplication AND NOT rolbypassrls
  AND NOT EXISTS(SELECT FROM pg_auth_members WHERE roleid=r.oid OR member=r.oid) FROM pg_roles r WHERE rolname='postgres')
)::text facts,
 (extract(epoch FROM clock_timestamp()-pg_postmaster_start_time()) BETWEEN 0 AND 299
  AND extract(epoch FROM clock_timestamp())-(system_identifier >> 32) BETWEEN 0 AND 299) fresh
FROM pg_control_system()
SQL;
 private PDO $pdo;private object $connection;private array $cfg;private mixed $native;private mixed $default;
 private function __construct(private array $anchor,private Closure $bootstrap) {}
 private static function need(bool $ok):void {if(!$ok)throw new RuntimeException('DUAL_DB_GATE_REFUSED');}
 private static function canonical(mixed $v):mixed {
  if(!is_array($v))return $v;if(!array_is_list($v))ksort($v,SORT_STRING);
  return array_map(self::canonical(...),$v);
 }
 public static function validateAnchor(array $a):void {
  self::need(in_array($a['phase']??null,['asaas_empty_before_owner','asaas_empty_owner_ready'],true));
  self::need(is_string($a['nonce']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$a['nonce'])===1);
  $e=$a['expected']??[];self::need(is_array($e));
  foreach(['cluster','pid','port'] as $k)self::need(is_string($e[$k]??null)&&preg_match('/\A[1-9][0-9]*\z/D',$e[$k])===1);
  self::need((int)$e['port']>=1024&&(int)$e['port']<=65535);
  self::need(is_string($e['started']??null)&&preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/D',$e['started'])===1);
  self::need(is_string($e['data']??null)&&preg_match('~\A/tmp/native-briefing-[a-zA-Z0-9_]+/data\z~D',$e['data'])===1);
  foreach(['database'=>'portal_asaas_ci','login'=>'portal_native_test_bootstrap','actor'=>'portal_native_test_bootstrap','major'=>'18','address'=>'127.0.0.1','listen'=>'127.0.0.1','sockets'=>'','empty'=>true] as $k=>$v)self::need(($e[$k]??null)===$v);
  self::need(($e['databases']??null)===['portal_asaas_ci','portal_native_orders_ci','postgres','template0','template1']);
  self::need(is_array($e['roles']??null)&&$e['roles']!==[]&&is_array($e['memberships']??null));
  self::need(array_key_exists('postgres_safe',$e)&&$e['postgres_safe']===($a['phase']==='asaas_empty_before_owner'?null:true));
 }
 // Trusted harness only: bootstrap must re-attest original topology/PDO/cluster and
 // return this SAME reviewed phase manifest plus process-local nonce. Not browser/env input.
 public static function bind(array $anchor,Closure $bootstrap):self {
  self::validateAnchor($anchor);$g=new self($anchor,$bootstrap);$g->recheckBootstrap();
  $g->native=config('database.connections.pgsql');$g->default=config('database.default');
  $g->cfg=['driver'=>'pgsql','host'=>'127.0.0.1','port'=>(int)$anchor['expected']['port'],
   'database'=>'portal_asaas_ci','username'=>'portal_native_test_bootstrap','password'=>'',
   'charset'=>'utf8','prefix'=>'','search_path'=>'public','sslmode'=>'disable'];
  config(['database.connections.'.self::NAME=>$g->cfg]);app('db')->purge(self::NAME);
  $g->connection=app('db')->connection(self::NAME);$g->pdo=$g->connection->getPdo();
  $g->beforeDdl();return $g;
 }
 private function binding():void {
  self::need(config('database.connections.pgsql')===$this->native&&config('database.default')===$this->default);
  self::need(config('database.connections.'.self::NAME)===$this->cfg);
  self::need(app('db')->connection(self::NAME)===$this->connection&&$this->connection->getPdo()===$this->pdo);
  foreach(['driver','host','port','database','username','password','url'] as $k)self::need(($this->connection->getConfig($k)??null)===($this->cfg[$k]??null));
 }
 private function recheckBootstrap():void {
  self::need(self::canonical(($this->bootstrap)())===self::canonical($this->anchor));
 }
 public function beforeDdl():PDO {
  $this->recheckBootstrap();
  $this->binding();
  self::need(!$this->pdo->inTransaction()&&$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql');
  self::need($this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION)&&$this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false));
  self::need($this->pdo->getAttribute(PDO::ATTR_ERRMODE)===PDO::ERRMODE_EXCEPTION&&$this->pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES)===false);
  $r=$this->pdo->query(self::SQL)->fetch(PDO::FETCH_ASSOC);
  self::need(is_array($r)&&in_array($r['fresh']??null,[true,'t',1,'1'],true));
  $actual=json_decode($r['facts'],true,64,JSON_THROW_ON_ERROR);
  self::need(self::canonical($actual)===self::canonical($this->anchor['expected']));
  $this->binding();$this->recheckBootstrap();return $this->pdo;
 }
 // Read-only preamble; same psql session must execute pinned originals afterwards.
 // Caller re-attests bootstrap immediately before spawn. No role or DDL here.
 public static function psqlPreamble(array $anchor):string {
  self::validateAnchor($anchor);
  $hex=bin2hex(json_encode($anchor['expected'],JSON_THROW_ON_ERROR));
  return "\\set ON_ERROR_STOP on\n\\unset dual_ok\nSELECT (fresh AND facts::jsonb = convert_from(decode('".$hex."','hex'),'UTF8')::jsonb) AS dual_ok FROM (\n".self::SQL."\n) verified \\gset\n\\if :dual_ok\n\\else\nSELECT 1/0 AS dual_gate_refused;\n\\endif\n";
 }
}
