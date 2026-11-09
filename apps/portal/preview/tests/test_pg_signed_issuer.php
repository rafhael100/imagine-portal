<?php

declare(strict_types=1);

// F09 test runs AFTER F08 seeded/attacked/recovered a disposable PostgreSQL DB.
$dir=dirname(__DIR__,2).'/app/Domain/PerformanceBridge';
foreach([
    'PortalPerfAuthorityFacts.php',
    'PortalPerfSqlAuthorityResolver.php',
    'SyntheticCapabilityIssuer.php',
    'PortalSqlBackedCapabilityIssuer.php',
] as $file){
    require_once $dir.'/'.$file;
}
use App\Domain\PerformanceBridge\PortalPerfSqlAuthorityResolver;
use App\Domain\PerformanceBridge\PortalSqlBackedCapabilityIssuer;

if(PHP_SAPI!=='cli'
    || getenv('APP_ENV')!=='testing'
    || getenv('PORTAL_PERF_PG_AUTH_LAB')!=='1'
    || getenv('PORTAL_PERF_SIGNED_LAB')!=='1'
    || getenv('PORTAL_PERF_SQL_ISSUER_LAB')!=='1'
    || getenv('PGHOST')!=='127.0.0.1'
    || getenv('PGDATABASE')!=='portal_perf_auth_lab'){
    throw new RuntimeException('F09 issuer tests require CI-only PostgreSQL.');
}
$connect=static fn(string $user,string $pass):PDO=>new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_perf_auth_lab;connect_timeout=3',
    $user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false]
);
$admin=$connect('postgres','ci-f08-db-only');
$reader=$connect('portal_f08_reader','ci-f08-read-only');
$issuer=new PortalSqlBackedCapabilityIssuer(
    new PortalPerfSqlAuthorityResolver($reader)
);
$badIssuer=new PortalSqlBackedCapabilityIssuer(
    new PortalPerfSqlAuthorityResolver($admin)
);
$token='portal-f08-opaque-session-test-aaaaaaaaaaaaaaaaaaa';
$hash=hash('sha256',$token);
$now=1800000000;
$pair=sodium_crypto_sign_keypair();
$secret=sodium_crypto_sign_secretkey($pair);
$public=sodium_crypto_sign_publickey($pair);
$n=0;
$check=static function(string $label,mixed $actual,mixed $expected) use(&$n):void{
    if($actual!==$expected){
        throw new RuntimeException(
            'FAIL F09 '.$label.' actual '.var_export($actual,true)
            .' expected '.var_export($expected,true)
        );
    }
    ++$n;
    echo "PASS F09 $n $label\n";
};
$sign=static fn(?string $bearer=null,string $service='svc-demo-a',?int $clock=null)=>
    $issuer->issue($bearer??$token,$service,$secret,$clock??$now);
$decode=static fn(string $b64):string=>
    (string)base64_decode(strtr($b64,'-_','+/'),true);
$packet=$sign();
$check('01 PG-backed issuer produces a string only after DB authority check',
    is_string($packet),true);
if(!is_string($packet)){
    throw new RuntimeException('F09 cannot inspect missing envelope.');
}
$env=json_decode($packet,true,16,JSON_THROW_ON_ERROR);
$claims=json_decode($decode($env['payload']),true,32,JSON_THROW_ON_ERROR);
$check('02 envelope identifies compatible Portal crypto version',
    $env['version'],'portal-capability-envelope.v1');
$check('03 key identifier pinned to ephemeral CI issuer',
    $env['key_id'],'ephemeral-lab-key-1');
$check('04 Ed25519 proof verifies with public key from generated pair',
    sodium_crypto_sign_verify_detached(
        $decode($env['signature']),
        "IMAGINE-PORTAL-CAPABILITY-SYNTHETIC-V1\n".$decode($env['payload']),
        $public
    ),true);
$check('05 actor sourced from session row',
    $claims['actor_ref'],'actor-demo-a');
$check('06 organization sourced from session row',
    $claims['organization_ref'],'org-demo-a');
$check('07 tenant sourced from server service row',
    $claims['performance_tenant_ref'],'perf-demo-a');
$check('08 service sourced from server row',
    $claims['portal_service_ref'],'svc-demo-a');
$check('09 session reference from hashed bearer DB row',
    $claims['session_ref'],'session-demo-a');
$check('10 permission from server membership',
    $claims['permission'],'campaigns:read');
$check('11 membership revision reflects F08 changed database row',
    $claims['membership_revision'],3);
$check('12 service revision reflects F08 changed database row',
    $claims['service_revision'],4);
$check('13 session revision reflects DB current row',
    $claims['session_revision'],4);
$check('14 recovery epoch reflects DB current service row',
    $claims['recovery_epoch'],6);
$check('15 display resource is explicitly fictitious',
    $claims['resource_ref'],'demo-cam-a1');
$check('16 service state does not pretend to be real contract',
    $claims['service_state'],'active_mock');
$check('17 MFA comes only after DB observed mock MFA',
    $claims['session_mfa_verified'],true);
$check('18 signed capability lasts 20 seconds only',
    $claims['expires_at']-$claims['issued_at'],20);
$check('19 no raw bearer token in signed claim',
    str_contains($decode($env['payload']),$token),false);
$check('20 no email/phone/provider credentials in payload',
    str_contains($decode($env['payload']),'@')
    ||str_contains($decode($env['payload']),'access_token')
    ||str_contains($decode($env['payload']),'phone'),false);
$check('21 every emission creates a new nonce',
    $claims['nonce']!==json_decode(
        $decode(json_decode((string)$sign(),true,16,JSON_THROW_ON_ERROR)['payload']),
        true,32,JSON_THROW_ON_ERROR
    )['nonce'],true);
$check('22 wrong bearer cannot generate a claim',
    $sign('portal-f08-opaque-session-test-ccccccccccccccccccc'),null);
$check('23 no bearer cannot generate a claim',
    $sign(''),null);
$check('24 another organization session cannot sign for service A',
    $sign('portal-f08-opaque-session-test-bbbbbbbbbbbbbbbbbbb'),null);
$check('25 caller cannot select service B',
    $sign($token,'svc-demo-b'),null);
$check('26 DB superuser is rejected as an authority reader',
    $badIssuer->issue($token,'svc-demo-a',$secret,$now),null);
$check('27 invalid Ed25519 secret cannot sign',
    $issuer->issue($token,'svc-demo-a','bad-key',$now),null);
$check('28 expired session at future clock cannot sign',
    $sign($token,'svc-demo-a',$now+3600),null);

$admin->exec("UPDATE portal_perf_authority_sessions
    SET status='revoked' WHERE session_hash='{$hash}'");
$check('29 revoking server-side session prevents issuance',
    $sign(),null);
$admin->exec("UPDATE portal_perf_authority_sessions
    SET status='active',mfa_verified_at=NULL WHERE session_hash='{$hash}'");
$check('30 missing MFA evidence prevents issuance',
    $sign(),null);
$admin->exec("UPDATE portal_perf_authority_sessions
    SET mfa_verified_at=to_timestamp(".($now-50).")
    WHERE session_hash='{$hash}'");
$check('31 restoring MFA row restores ability to issue',
    is_string($sign()),true);
$admin->exec("UPDATE portal_perf_authority_memberships
    SET status='revoked' WHERE organization_ref='org-demo-a'");
$check('32 revoking membership prevents issuance',
    $sign(),null);
$admin->exec("UPDATE portal_perf_authority_memberships
    SET status='active',membership_revision=4
    WHERE organization_ref='org-demo-a'");
$check('33 current membership revision is signed after restoration',
    json_decode($decode(json_decode((string)$sign(),true,16,JSON_THROW_ON_ERROR)['payload']),
        true,32,JSON_THROW_ON_ERROR)['membership_revision'],4);
$admin->exec("UPDATE portal_perf_authority_services
    SET contract_state='pending_mock' WHERE organization_ref='org-demo-a'");
$check('34 pending mock contract prevents issuance',
    $sign(),null);
$admin->exec("UPDATE portal_perf_authority_services
    SET contract_state='revoked_mock' WHERE organization_ref='org-demo-a'");
$check('35 revoked mock contract prevents issuance',
    $sign(),null);
$admin->exec("UPDATE portal_perf_authority_services
    SET contract_state='active_mock',service_revision=5,recovery_epoch=7
    WHERE organization_ref='org-demo-a'");
$check('36 service new revision and epoch come from DB, not caller',
    (static function()use($sign,$decode):array{
        $x=json_decode((string)$sign(),true,16,JSON_THROW_ON_ERROR);
        $c=json_decode($decode($x['payload']),true,32,JSON_THROW_ON_ERROR);
        return [$c['service_revision'],$c['recovery_epoch']];
    })(),[5,7]);
$admin->exec('REVOKE SELECT ON portal_perf_authority_services FROM portal_f08_reader');
$check('37 source SELECT outage prevents signing',
    $sign(),null);
$admin->exec('GRANT SELECT ON portal_perf_authority_services TO portal_f08_reader');
$check('38 after restoring SQL privilege, signer can issue again',
    is_string($sign()),true);

putenv('PORTAL_PERF_SQL_ISSUER_LAB=0');
$check('39 disabled issuer flag overrides valid DB facts and private key',
    $sign(),null);
putenv('PORTAL_PERF_SQL_ISSUER_LAB=1');
putenv('APP_ENV=production');
$check('40 production app environment never emits a capability',
    $sign(),null);
putenv('APP_ENV=testing');
$check('41 no open DB transaction after issuance/denial',
    $reader->inTransaction(),false);
$check('42 zero cleartext session bearer values stored',
    (int)$admin->query(
       "SELECT count(*) FROM portal_perf_authority_sessions
        WHERE session_hash='".$token."'"
    )->fetchColumn(),0);
$check('43 current status remains active_mock only',
    $admin->query("SELECT contract_state FROM portal_perf_authority_services
       WHERE organization_ref='org-demo-a'")->fetchColumn(),'active_mock');
$check('44 current membership status read from server',
    $admin->query("SELECT status FROM portal_perf_authority_memberships
       WHERE organization_ref='org-demo-a'")->fetchColumn(),'active');
$check('45 no consumer operations or Ads dispatch from issuer',
    is_file(dirname(__DIR__,2).'/routes/api.php'),false);
sodium_memzero($secret);
sodium_memzero($pair);
echo "PASS F09 $n PostgreSQL-driven Ed25519 issuer and revocation checks\n";
