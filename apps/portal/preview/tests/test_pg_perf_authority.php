<?php

declare(strict_types=1);

require_once dirname(__DIR__,2).'/app/Domain/PerformanceBridge/PortalPerfAuthorityFacts.php';
require_once dirname(__DIR__,2).'/app/Domain/PerformanceBridge/PortalPerfSqlAuthorityResolver.php';

use App\Domain\PerformanceBridge\PortalPerfSqlAuthorityResolver;
use App\Domain\PerformanceBridge\PortalPerfAuthorityFacts;

if (PHP_SAPI!=='cli'
    || getenv('APP_ENV')!=='testing'
    || getenv('PORTAL_PERF_PG_AUTH_LAB')!=='1'
    || getenv('PGHOST')!=='127.0.0.1'
    || getenv('PGPORT')!=='5432'
    || getenv('PGDATABASE')!=='portal_perf_auth_lab') {
    throw new RuntimeException('Portal F08 requires isolated CI PostgreSQL.');
}
$connect=static fn(string $user,string $pass):PDO=>new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_perf_auth_lab;connect_timeout=3',
    $user,$pass,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false
    ]
);
$admin=$connect('postgres','ci-f08-db-only');
$reader=$connect('portal_f08_reader','ci-f08-read-only');
$resolver=new PortalPerfSqlAuthorityResolver($reader);
$now=1800000000;
$tokenA='portal-f08-opaque-session-test-aaaaaaaaaaaaaaaaaaa';
$tokenB='portal-f08-opaque-session-test-bbbbbbbbbbbbbbbbbbb';
$hashA=hash('sha256',$tokenA);
$hashB=hash('sha256',$tokenB);
$n=0;
$check=static function(string $name,mixed $actual,mixed $want) use(&$n):void {
    if($actual!==$want){
        throw new RuntimeException('FAIL PORTAL F08 '.$name.' actual '
            .var_export($actual,true).' expected '.var_export($want,true));
    }
    ++$n;
    echo "PASS PORTAL F08 $n $name\n";
};
$one=static fn(string $sql):mixed=>$admin->query($sql)->fetchColumn();
$deny=static fn(string $label,?PortalPerfAuthorityFacts $facts):void=>
    $check($label,$facts,null);
$read=static fn(string $bearer=$tokenA,string $service='svc-demo-a',?int $clock=null)=>
    $resolver->resolve($bearer,$service,$clock??$now);

$insertSession=$admin->prepare(
    "INSERT INTO portal_perf_authority_sessions (
       id,session_hash,session_ref,actor_ref,organization_ref,status,
       mfa_verified_at,issued_at,expires_at,
       account_revision,session_account_revision,created_at,updated_at
     ) VALUES (
       gen_random_uuid(),?,?,?,?,'active',
       to_timestamp(?),to_timestamp(?),to_timestamp(?),4,4,
       clock_timestamp(),clock_timestamp()
     )"
);
$insertSession->execute([
    $hashA,'session-demo-a','actor-demo-a','org-demo-a',
    $now-50,$now-100,$now+1800
]);
$insertSession->execute([
    $hashB,'session-demo-b','actor-demo-b','org-demo-b',
    $now-50,$now-100,$now+1800
]);
$insertMembership=$admin->prepare(
    "INSERT INTO portal_perf_authority_memberships (
       id,actor_ref,organization_ref,status,permission,membership_revision,
       created_at,updated_at
     ) VALUES (gen_random_uuid(),?,?,'active','campaigns:read',2,
               clock_timestamp(),clock_timestamp())"
);
$insertMembership->execute(['actor-demo-a','org-demo-a']);
$insertMembership->execute(['actor-demo-b','org-demo-b']);
$insertService=$admin->prepare(
    "INSERT INTO portal_perf_authority_services (
      id,organization_ref,tenant_ref,service_ref,contract_state,
      service_revision,recovery_epoch,created_at,updated_at
     ) VALUES (gen_random_uuid(),?,?,?,'active_mock',3,5,
               clock_timestamp(),clock_timestamp())"
);
$insertService->execute(['org-demo-a','perf-demo-a','svc-demo-a']);
$insertService->execute(['org-demo-b','perf-demo-b','svc-demo-b']);

$check('01 session table FORCE RLS',
    $one("SELECT relrowsecurity AND relforcerowsecurity
          FROM pg_class WHERE oid='portal_perf_authority_sessions'::regclass"),true);
$check('02 membership table FORCE RLS',
    $one("SELECT relrowsecurity AND relforcerowsecurity
          FROM pg_class WHERE oid='portal_perf_authority_memberships'::regclass"),true);
$check('03 service table FORCE RLS',
    $one("SELECT relrowsecurity AND relforcerowsecurity
          FROM pg_class WHERE oid='portal_perf_authority_services'::regclass"),true);
$check('04 bearer session is never stored in cleartext',
    (int)$one("SELECT count(*) FROM portal_perf_authority_sessions
              WHERE session_hash='".$tokenA."'"),0);
$check('05 hashed session stored with SHA-256',
    (int)$one("SELECT count(*) FROM portal_perf_authority_sessions
              WHERE session_hash='".$hashA."'"),1);
$check('06 no session visible without scoped hash',
    (int)$reader->query('SELECT count(*) FROM portal_perf_authority_sessions')
        ->fetchColumn(),0);
$check('07 no membership visible without org scope',
    (int)$reader->query('SELECT count(*) FROM portal_perf_authority_memberships')
        ->fetchColumn(),0);
$check('08 no service visible without org scope',
    (int)$reader->query('SELECT count(*) FROM portal_perf_authority_services')
        ->fetchColumn(),0);

$facts=$read();
$check('09 real PostgreSQL queries return a typed authority object',
    $facts instanceof PortalPerfAuthorityFacts,true);
$check('10 organization inferred from hashed server session',
    $facts?->organizationRef,'org-demo-a');
$check('11 tenant taken from server service row',
    $facts?->tenantRef,'perf-demo-a');
$check('12 service reference scoped to A',
    $facts?->serviceRef,'svc-demo-a');
$check('13 actor from own session row',
    $facts?->actorRef,'actor-demo-a');
$check('14 session_ref from stored session row',
    $facts?->sessionRef,'session-demo-a');
$check('15 permission from membership not supplied in request',
    $facts?->permission,'campaigns:read');
$check('16 membership revision comes from PostgreSQL',
    $facts?->membershipRevision,2);
$check('17 service revision comes from PostgreSQL',
    $facts?->serviceRevision,3);
$check('18 session account revision comes from PostgreSQL',
    $facts?->sessionRevision,4);
$check('19 restore epoch is stored with service',
    $facts?->recoveryEpoch,5);
$check('20 reader observation bound to tested clock',
    $facts?->sourceObservedAt,$now);
$check('21 read-only transaction closes after success',
    $reader->inTransaction(),false);
$check('22 transaction local hash is removed after COMMIT',
    (int)$reader->query('SELECT count(*) FROM portal_perf_authority_sessions')
        ->fetchColumn(),0);
$check('23 transaction local org context is removed after COMMIT',
    (int)$reader->query('SELECT count(*) FROM portal_perf_authority_memberships')
        ->fetchColumn(),0);
$check('24 repeated read is deterministic',
    get_object_vars($read()),get_object_vars($facts));

$deny('25 missing session bearer fails closed',$read(''));
$deny('26 malformed bearer fails closed',$read('../../secret'));
$deny('27 unrecognized session bearer fails closed',
    $read('portal-f08-opaque-session-test-ccccccccccccccccccc'));
$deny('28 other organization session cannot reach A service',
    $read($tokenB));
$deny('29 client cannot select a foreign service',
    $read($tokenA,'svc-demo-b'));
$deny('30 unknown service fails closed',
    $read($tokenA,'svc-demo-unknown'));
$deny('31 expired session cannot resolve',$read($tokenA,'svc-demo-a',$now+3600));
$deny('32 session not yet issued cannot resolve',$read($tokenA,'svc-demo-a',$now-300));
$deny('33 session MFA too old rejects',$read($tokenA,'svc-demo-a',$now+600));
$deny('34 current session source cannot accept invalid now',
    $read($tokenA,'svc-demo-a',0));

$admin->exec(
    "UPDATE portal_perf_authority_sessions SET status='revoked'
     WHERE session_hash='{$hashA}'"
);
$deny('35 server-side session revocation immediately rejects',$read());
$admin->exec(
    "UPDATE portal_perf_authority_sessions SET status='active',
      session_account_revision=3 WHERE session_hash='{$hashA}'"
);
$deny('36 session/account rev mismatch denies',$read());
$admin->exec(
    "UPDATE portal_perf_authority_sessions SET session_account_revision=4,
      mfa_verified_at=NULL WHERE session_hash='{$hashA}'"
);
$deny('37 missing MFA proof in source rejects',$read());
$admin->exec(
    "UPDATE portal_perf_authority_sessions SET
      mfa_verified_at=to_timestamp(".($now-50).")
     WHERE session_hash='{$hashA}'"
);
$check('38 restored session+MFA mock permits lab read',$read() instanceof PortalPerfAuthorityFacts,true);

$admin->exec(
    "UPDATE portal_perf_authority_memberships SET status='revoked'
     WHERE organization_ref='org-demo-a'"
);
$deny('39 membership revoke denies even valid session',$read());
$admin->exec(
    "UPDATE portal_perf_authority_memberships SET status='active',membership_revision=3
     WHERE organization_ref='org-demo-a'"
);
$check('40 updated membership revision is observed from source',
    $read()?->membershipRevision,3);
$admin->exec(
    "UPDATE portal_perf_authority_services SET contract_state='pending_mock'
     WHERE organization_ref='org-demo-a'"
);
$deny('41 pending mock contract blocks read',$read());
$admin->exec(
    "UPDATE portal_perf_authority_services SET contract_state='revoked_mock'
     WHERE organization_ref='org-demo-a'"
);
$deny('42 revoked mock contract blocks read',$read());
$admin->exec(
    "UPDATE portal_perf_authority_services SET contract_state='active_mock',
        service_revision=4,recovery_epoch=6 WHERE organization_ref='org-demo-a'"
);
$check('43 source service revision 4 is observed',$read()?->serviceRevision,4);
$check('44 source recovery epoch 6 is observed',$read()?->recoveryEpoch,6);

$admin->exec('REVOKE SELECT ON portal_perf_authority_memberships FROM portal_f08_reader');
$deny('45 lost membership SELECT fails closed',$read());
$check('46 no open transaction after SQL privilege error',$reader->inTransaction(),false);
$admin->exec('GRANT SELECT ON portal_perf_authority_memberships TO portal_f08_reader');
$check('47 restore role SELECT returns mock facts',$read() instanceof PortalPerfAuthorityFacts,true);
$check('48 reader cannot modify sessions',
    (bool)$one("SELECT has_table_privilege('portal_f08_reader',
       'portal_perf_authority_sessions','UPDATE')"),false);
$check('49 reader cannot modify memberships',
    (bool)$one("SELECT has_table_privilege('portal_f08_reader',
       'portal_perf_authority_memberships','UPDATE')"),false);
$check('50 reader cannot modify service contracts',
    (bool)$one("SELECT has_table_privilege('portal_f08_reader',
       'portal_perf_authority_services','UPDATE')"),false);
$check('51 reader cannot create sessions',
    (bool)$one("SELECT has_table_privilege('portal_f08_reader',
       'portal_perf_authority_sessions','INSERT')"),false);
$check('52 role is not PostgreSQL superuser',
    $one("SELECT rolsuper FROM pg_roles WHERE rolname='portal_f08_reader'"),false);
$check('53 admin PG role is rejected as operational authority reader',
    (new PortalPerfSqlAuthorityResolver($admin))->resolve($tokenA,'svc-demo-a',$now),null);
putenv('PORTAL_PERF_PG_AUTH_LAB=0');
$check('54 disabling lab flag revokes entire resolver',$read(),null);
putenv('PORTAL_PERF_PG_AUTH_LAB=1');
putenv('APP_ENV=production');
$check('55 production environment always denies',$read(),null);
putenv('APP_ENV=testing');
$check('56 restored testing flag allows only synthetic source',
    $read() instanceof PortalPerfAuthorityFacts,true);

$check('57 no email or customer personal data returned',
    str_contains(json_encode($read(),JSON_THROW_ON_ERROR),'@'),false);
$check('58 no raw bearer token serialized into facts',
    str_contains(json_encode($read(),JSON_THROW_ON_ERROR),$tokenA),false);
$check('59 no real contract status is implied',
    (string)$one("SELECT contract_state FROM portal_perf_authority_services
        WHERE organization_ref='org-demo-a'"),'active_mock');
$check('60 source session table has only two fictional sessions',
    (int)$one('SELECT count(*) FROM portal_perf_authority_sessions'),2);
echo "PASS PORTAL F08 $n PostgreSQL role/authority/revocation tests\n";
