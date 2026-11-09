<?php

declare(strict_types=1);

// CI-only cross-repository test bridge. Never writes private key to disk.
require_once dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Domain\PerformanceBridge\SyntheticCapabilityIssuer;

if (PHP_SAPI!=='cli'
    || getenv('APP_ENV')!=='testing'
    || getenv('PORTAL_PERF_SIGNED_LAB')!=='1'
    || count($argv)!==3
    || !ctype_digit($argv[2])
    || !extension_loaded('sodium')) {
    fwrite(STDERR,"Refusing PORTAL-AUTH-PERF-07 outside explicit CLI lab\n");
    exit(2);
}
$publicKeyPath=$argv[1];
$time=(int)$argv[2];
if ($time<1 || $time>2147483627
    || !str_starts_with($publicKeyPath,sys_get_temp_dir().'/')
    || is_link($publicKeyPath)) {
    fwrite(STDERR,"Invalid lab output path or clock\n");
    exit(2);
}
umask(0077);
try {
    $pair=sodium_crypto_sign_keypair();
    $secret=sodium_crypto_sign_secretkey($pair);
    $public=sodium_crypto_sign_publickey($pair);
    $packet=SyntheticCapabilityIssuer::issueSynthetic($secret,$time);
    sodium_memzero($secret);
    sodium_memzero($pair);
    if (!is_string($packet)
        || file_put_contents($publicKeyPath,bin2hex($public),LOCK_EX)!==64) {
        throw new RuntimeException('Failed to emit synthetic envelope and temporary public key.');
    }
    fwrite(STDOUT,$packet."\n");
} catch(Throwable) {
    fwrite(STDERR,"Synthetic Portal issuance failed closed\n");
    exit(1);
}
