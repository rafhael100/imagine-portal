<?php

declare(strict_types=1);

require_once __DIR__.'/../../app/Labs/SitesF401/PortalSitesCrmSignedBindingCommandLab.php';

use App\Labs\SitesF401\PortalSitesCrmSignedBindingCommandLab as Emitter;

$now=1790000000;
$claim=[
    'protocol'=>'imagine-s2s-f400-ci-v1',
    'sourceService'=>'imagine-portal',
    'audienceService'=>'imagine-crm',
    'keyId'=>'lab-portal-crm-provision-a',
    'action'=>'provision',
    'organizationReference'=>'a1111111-1111-4111-8111-111111111111',
    'orderReference'=>'order-fictional-f399-01',
    'bindingReference'=>'23232323-2222-4222-8222-555555555551',
    'expectedOrderVersion'=>1,
    'nonce'=>'41414141-4141-4141-8141-414141414141',
    'issuedAt'=>$now-10,
    'expiresAt'=>$now+40,
];
$count=0;
$assert=static function (bool $okay) use (&$count): void {
    ++$count;
    if (!$okay) {
        throw new LogicException('F401 Portal standalone contract assertion '.$count.' failed');
    }
};
$denied=static function (array $bad) use ($now,$assert): void {
    try {
        Emitter::sign($bad,$now);
        $assert(false);
    } catch (LogicException $e) {
        $assert(true);
    }
};

$signed=Emitter::sign($claim,$now);
$assert(count($signed)===count($claim)+1);
$assert(strlen($signed['signature'])===64);
$assert(hash_equals(
    hash_hmac(
        'sha256',
        Emitter::canonical($claim),
        'FAKE-PUBLIC-CI-ONLY-PORTAL-CRM-PROVISION-A-2026-NOT-A-SECRET',
    ),
    $signed['signature'],
));
$assert(Emitter::sign($claim,$now)===$signed);

foreach ([
    ['sourceService'=>'imagine-sites'],
    ['audienceService'=>'imagine-sites'],
    ['keyId'=>'unknown-ci-key'],
    ['action'=>'publish'],
    ['expectedOrderVersion'=>0],
    ['issuedAt'=>$now+31],
    ['expiresAt'=>$now+301],
    ['expiresAt'=>$now-1],
    ['organizationReference'=>'malformed-org'],
    ['orderReference'=>'unsafe'."\n".'header'],
    ['extra'=>'not-on-wire'],
] as $bad) {
    $denied(array_replace($claim,$bad));
}

$revoke=array_replace($claim,[
    'keyId'=>'lab-portal-crm-revoke-c',
    'action'=>'revoke',
    'nonce'=>'41414141-4141-4141-8141-414141414142',
]);
$assert(Emitter::sign($revoke,$now)['signature']!==$signed['signature']);
$rotated=array_replace($claim,[
    'keyId'=>'lab-portal-crm-provision-b',
    'nonce'=>'41414141-4141-4141-8141-414141414143',
]);
$assert(Emitter::sign($rotated,$now)['signature']!==$signed['signature']);

echo 'F401 portal original CI emitter PASS ('.$count.' assertions); no API, real keys, PSP, consent, activation or deploy.'.PHP_EOL;
