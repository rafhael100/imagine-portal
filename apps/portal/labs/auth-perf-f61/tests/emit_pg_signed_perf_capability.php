<?php

declare(strict_types=1);

// F09: one-time disposable PostgreSQL authority -> Ed25519 test envelope.
// No private key persisted; no real login, customers, providers or traffic.
$root=dirname(__DIR__,3);
foreach([
    'PortalPerfAuthorityFacts.php',
    'PortalPerfSqlAuthorityResolver.php',
    'SyntheticCapabilityIssuer.php',
    'PortalSqlBackedCapabilityIssuer.php',
] as $file){
    require_once $root.'/app/Labs/AuthPerfF61/'.$file;
}
use App\Labs\AuthPerfF61\PortalPerfSqlAuthorityResolver;
use App\Labs\AuthPerfF61\PortalSqlBackedCapabilityIssuer;

if (PHP_SAPI!=='cli'
    || getenv('APP_ENV')!=='testing'
    || getenv('PORTAL_PERF_PG_AUTH_LAB')!=='1'
    || getenv('PORTAL_PERF_SIGNED_LAB')!=='1'
    || getenv('PORTAL_PERF_SQL_ISSUER_LAB')!=='1'
    || getenv('PGHOST')!=='127.0.0.1'
    || getenv('PGPORT')!=='5432'
    || getenv('PGDATABASE')!=='portal_perf_auth_lab'
    || count($argv)!==3
    || !ctype_digit($argv[2])) {
    fwrite(STDERR,"F09 signing requires disposable CLI PG lab\n");
    exit(2);
}
$publicKeyPath=$argv[1];
$now=(int)$argv[2];
if ($now<=0 || $now>2147483627
    || !str_starts_with($publicKeyPath,sys_get_temp_dir().'/')
    || is_link($publicKeyPath)) {
    fwrite(STDERR,"Refusing non-temporary signing output\n");
    exit(2);
}
umask(0077);
try {
    $db=new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=portal_perf_auth_lab;connect_timeout=3',
        'portal_f08_reader','ci-f08-read-only',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES=>false]
    );
    $issuer=new PortalSqlBackedCapabilityIssuer(
        new PortalPerfSqlAuthorityResolver($db)
    );
    $pair=sodium_crypto_sign_keypair();
    $secret=sodium_crypto_sign_secretkey($pair);
    $public=sodium_crypto_sign_publickey($pair);
    $packet=$issuer->issue(
        'portal-f08-opaque-session-test-aaaaaaaaaaaaaaaaaaa',
        'svc-demo-a',
        $secret,
        $now
    );
    sodium_memzero($secret);
    sodium_memzero($pair);
    if (!is_string($packet)
        || file_put_contents($publicKeyPath,bin2hex($public),LOCK_EX)!==64) {
        throw new RuntimeException('Refused signed claim or failed temporary key.');
    }
    fwrite(STDOUT,$packet."\n");
} catch(Throwable) {
    fwrite(STDERR,"F09 issue failed closed\n");
    exit(1);
}
