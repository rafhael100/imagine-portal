<?php
declare(strict_types=1);

/**
 * Explicit operator-only read-only connectivity check against Asaas Sandbox.
 * This script never executes from an HTTP route or GitHub Actions.
 *
 * APP_ENV=staging ASAAS_SANDBOX_READONLY_PROBE=1 php \
 *   apps/portal/ops/psp/asaas-sandbox-readonly-preflight.php \
 *   --probe --credential-file=/private/path/to/sandbox-key
 */
use App\Homologation\Commerce\AsaasSandboxReadinessProbe;

if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='staging' ||
    getenv('ASAAS_SANDBOX_READONLY_PROBE')!=='1' ||
    getenv('GITHUB_ACTIONS')==='true' ||
    !in_array('--probe',$argv,true)) {
    fwrite(STDERR,"NO-GO: explicit staging CLI only; never CI, HTTP or production.\n");
    exit(2);
}
$files=array_values(array_filter($argv,static fn(string $arg):bool =>
    str_starts_with($arg,'--credential-file=')));
if (count($files)!==1 || count($argv)!==3) {
    fwrite(STDERR,"NO-GO: exactly one protected --credential-file is required.\n");
    exit(2);
}
$path=substr($files[0],strlen('--credential-file='));
$stat=$path!==''?@lstat($path):false;
$uid=function_exists('posix_geteuid')?posix_geteuid():null;
if ($stat===false || @is_link($path) || !is_file($path) ||
    $uid===null || $stat['uid']!==$uid ||
    !in_array($stat['mode']&0777,[0400,0600],true) ||
    $stat['size']<32 || $stat['size']>1024) {
    fwrite(STDERR,"NO-GO: secret file must be owned by operator, regular, private and not a symlink.\n");
    exit(2);
}
$key=@file_get_contents($path);
if (!is_string($key)) {
    fwrite(STDERR,"NO-GO: secret unreadable.\n");
    exit(2);
}
$key=rtrim($key,"\r\n");
require dirname(__DIR__,2).'/app/Homologation/Commerce/AsaasSandboxReadinessProbe.php';
try {
    $ok=(new AsaasSandboxReadinessProbe())->inspect($key);
} catch (\Throwable) {
    $ok=false;
}
unset($key);
if (!$ok) {
    fwrite(STDERR,"NO-GO: Sandbox GET not verified; no changes made.\n");
    exit(1);
}
echo "PASS: Asaas Sandbox authenticated read-only GET; no charge, payment confirmation, customer details, configuration changes or deployment.\n";
echo "NO-GO: this does not approve webhook, real identity, legal contracts, Sites provisioning or production.\n";
