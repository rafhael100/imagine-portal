<?php
declare(strict_types=1);
namespace PrivateAtomicIngressFixture;
use Closure;
use PDO;
use PrivateDualDb\DualDbGate;
use RuntimeException;

/** Attachment to a fresh #966-format scope, before the mutually exclusive F391 installer. */
final class Fixture
{
    public const DB='portal_asaas_ci';
    public const PINS=[
      'pre.sql'=>'56601e585c9431c971336fc3b5ccac90f0f531d787f7ebd12e58a9e2bcfbc379',
      'atomic-capture.candidate.sql'=>'69252f1e50e1a20a2f1aa1d9e9d9985de3adab63742f7d27419035387a713775',
      'post.sql'=>'8545612528bd0fdbac3a01de62f7675ec71ad04a7d047a431e9ec6f6cfefb999',
      'AtomicInboxPort.php'=>'615152bfc015b04c39fe23a0109eba51c8f6ba0bb6c24f68d0e6fb63c448e612',
    ];
    private static function need(bool $ok):void {if(!$ok)throw new RuntimeException('ATOMIC_FIXTURE_REFUSED');}
    public static function inputs(array $bytes):array
    {
        self::need(array_keys($bytes)===array_keys(self::PINS));
        foreach(self::PINS as $name=>$hash) self::need(is_string($bytes[$name])&&hash_equals($hash,hash('sha256',$bytes[$name])));
        return [$bytes['pre.sql'],$bytes['atomic-capture.candidate.sql'],$bytes['post.sql']];
    }
    /**
     * $owned is a harness-only repeat of the #966 independent supervisor attestation.
     * It must bind PID, namespaces, unique loopback listener, directory and PG control ID;
     * neither caller request, nor this installer may fabricate the witness.
     * $exact is the Laravel PDO returned by the already bound released DualDbGate.
     */
    public static function install(DualDbGate $gate,PDO $exact,Closure $owned,array $bytes,bool $enabled=false):void
    {
        self::need($enabled&&getenv('PORTAL_NATIVE_ORDER_TEST')==='DISPOSABLE_PG18'
            &&getenv('PORTAL_NATIVE_ORDER_TEST_PORT')==='55491');
        $fragments=self::inputs($bytes);
        self::need($owned()===true&&$gate->beforeDdl()===$exact&&!$exact->inTransaction());
        $q=$exact->query(<<<'SQL'
SELECT CASE WHEN current_database()='portal_asaas_ci'
 AND current_user='portal_native_test_bootstrap' AND session_user=current_user
 AND host(inet_server_addr())='127.0.0.1' AND host(inet_client_addr())='127.0.0.1'
 AND inet_server_port()=55491 AND current_setting('unix_socket_directories')=''
 AND current_setting('listen_addresses')='127.0.0.1'
 AND NOT EXISTS (SELECT FROM pg_roles WHERE rolname LIKE 'onepage_ingress_%')
 THEN 1 ELSE 0 END
SQL);
        self::need((string)$q->fetchColumn()==='1'&&$q->fetchColumn()===false);
        $q->closeCursor();
        self::need($owned()===true&&$gate->beforeDdl()===$exact&&$exact->beginTransaction());
        try {
            $exact->exec("SET LOCAL lock_timeout='2s'; SET LOCAL statement_timeout='3s'");
            foreach($fragments as $sql)self::need($exact->exec($sql)!==false);
            self::verifyCatalog($exact);
            self::need($owned()===true&&$exact->commit());
        } catch (\Throwable) {
            try {if($exact->inTransaction())$exact->rollBack();} catch(\Throwable) {}
            throw new RuntimeException('ATOMIC_FIXTURE_COMMIT_UNCONFIRMED');
        }
    }
    public static function verifyCatalog(PDO $exact):void
    {
        $r=$exact->query(self::AUDIT);
        self::need((string)$r->fetchColumn()==='1'&&$r->fetchColumn()===false);
        $r->closeCursor();
    }
    private const AUDIT=<<<'SQL'
WITH
 names AS (SELECT unnest(ARRAY['onepage_ingress_capture','onepage_ingress_revoker']) AS u),
 f AS (SELECT p.*,p.oid AS foid FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='onepage_ingress_lab'),
 t AS (SELECT c.* FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='onepage_ingress_lab' AND c.relkind='r')
SELECT CASE WHEN (SELECT count(*)=2 AND bool_and(prosecdef AND proowner='onepage_ingress_owner'::regrole
 AND proconfig=ARRAY['search_path=pg_catalog, pg_temp']
 AND NOT EXISTS(SELECT FROM aclexplode(COALESCE(proacl,acldefault('f',proowner))) WHERE grantee=0 AND privilege_type='EXECUTE')) FROM f)
 AND (SELECT count(*)=2 AND bool_and(relrowsecurity AND relforcerowsecurity AND relowner='portal_native_test_bootstrap'::regrole) FROM t)
 AND (SELECT count(*)=4 AND count(*) FILTER(WHERE NOT polpermissive)=2 FROM pg_policy WHERE polrelid IN(SELECT oid FROM t))
 AND NOT EXISTS (
 SELECT FROM pg_roles rr WHERE rolname IN ('onepage_ingress_owner','onepage_ingress_capture','onepage_ingress_revoker')
 AND (rolcanlogin<>(rolname<>'onepage_ingress_owner') OR rolsuper OR rolinherit OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls
 OR EXISTS(SELECT FROM pg_auth_members WHERE roleid=rr.oid OR member=rr.oid)))
 AND NOT EXISTS (SELECT FROM names CROSS JOIN t
  WHERE has_table_privilege(u,t.oid,'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER,MAINTAIN')
  OR has_any_column_privilege(u,t.oid,'SELECT,INSERT,UPDATE,REFERENCES'))
 AND NOT EXISTS(SELECT FROM pg_database d CROSS JOIN LATERAL aclexplode(COALESCE(d.datacl,acldefault('d',d.datdba))) a
  WHERE d.datname=current_database() AND a.grantee=0)
 AND NOT EXISTS(SELECT FROM pg_namespace n CROSS JOIN LATERAL aclexplode(COALESCE(n.nspacl,acldefault('n',n.nspowner))) a
  WHERE n.nspname IN ('public','onepage_ingress_lab') AND a.grantee=0)
 AND NOT EXISTS(SELECT FROM names WHERE NOT has_database_privilege(u,current_database(),'CONNECT')
  OR has_database_privilege(u,current_database(),'CREATE,TEMPORARY')
  OR has_schema_privilege(u,'onepage_ingress_lab','CREATE') OR has_schema_privilege(u,'public','CREATE'))
 AND NOT EXISTS(SELECT FROM names CROSS JOIN f
  WHERE (proname IN ('capture','revoke')) IS NOT TRUE OR
    has_function_privilege(u,f.foid,'EXECUTE')<>(u=CASE proname WHEN 'capture' THEN 'onepage_ingress_capture' ELSE 'onepage_ingress_revoker' END))
 THEN 1 ELSE 0 END
SQL;
}
