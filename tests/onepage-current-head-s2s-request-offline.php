<?php

declare(strict_types=1);

// Unbound request contract. No official Portal controller, certificate,
// authenticated service, billing, live approval or network request.
require_once __DIR__.'/../apps/portal/app/Domain/SitesIntegration/OnepageCurrentHeadS2sRequestCandidate.php';

use App\Domain\SitesIntegration\OnepageCurrentHeadS2sRequestCandidate as Request;

$checks = 0;
$ok = static function (bool $valid, string $label) use (&$checks): void {
    if (!$valid) {
        throw new RuntimeException('PORTAL CURRENT HEAD REQUEST TEST FAILURE: '.$label);
    }
    ++$checks;
};
$deny = static function (string $body, string $label) use ($ok): void {
    ob_start();
    $denied = false;
    try {
        Request::decode($body);
    } catch (RuntimeException $error) {
        $denied = $error->getMessage() === 'PORTAL_ONEPAGE_CURRENT_HEAD_REQUEST_DENIED' &&
            $error->getPrevious() === null;
    }
    $output = ob_get_clean();
    $ok($denied && $output === '', $label.' denied without disclosure');
};

$wire = [
    'schemaVersion' => 'sites.onepage-current-head.fetch.v1',
    'tenantRef' => 'tenant-lab-A',
    'orderRef' => 'order-lab-A',
    'siteRef' => '11111111-1111-4111-8111-111111111111',
    'productCode' => 'onepage',
];
$json = static fn(array $body): string =>
    json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

$bytes = $json($wire);
$selector = ['tenantRef' => 'tenant-lab-A', 'orderRef' => 'order-lab-A',
    'siteRef' => '11111111-1111-4111-8111-111111111111'];
$ok(Request::decode($bytes) === $selector, 'exact Sites wire request parsed as selectors only');
$ok(Request::decode($bytes) === $selector, 'stable replay is read-only parsing, not authorization');
$ok(!array_key_exists('authenticatedServiceRef', Request::decode($bytes)),
    'mTLS identity cannot be supplied or inferred through the body');

foreach ([
    ['schemaVersion', 'sites.onepage-current-head.fetch.v2'],
    ['productCode', 'landing_page'],
    ['productCode', 'ONEPAGE'],
    ['tenantRef', 'tenant lab A'],
    ['tenantRef', ' tenant-lab-A'],
    ['tenantRef', ''],
    ['orderRef', 'order/lab'],
    ['orderRef', str_repeat('a',129)],
    ['siteRef', strtoupper($wire['siteRef'])],
    ['siteRef', '11111111-1111-1111-1111-111111111111/other'],
    ['siteRef', null],
    ['tenantRef', 42],
    ['orderRef', ['order-lab-A']],
    ['productCode', true],
] as [$field,$value]) {
    $bad = $wire;
    $bad[$field] = $value;
    $deny($json($bad), 'reject altered '.$field);
}
foreach (array_keys($wire) as $field) {
    $bad = $wire;
    unset($bad[$field]);
    $deny($json($bad), 'missing '.$field);
}
foreach (['canPublish'=>true, 'authenticatedServiceRef'=>'admin',
    'approvalAuthenticated'=>true, 'sourceState'=>'approved',
    'tenantOverride'=>'tenant-lab-B'] as $field=>$value) {
    $deny($json(array_merge($wire, [$field=>$value])), 'injected '.$field);
}
$deny($json(array_reverse($wire, true)), 'reordered keys not a canonical Sites request');
$deny($bytes." ", 'trailing whitespace');
$deny("\n".$bytes, 'leading whitespace');
$deny("\xEF\xBB\xBF".$bytes, 'UTF-8 BOM');
$deny(str_replace('"tenantRef":"tenant-lab-A"',
    '"tenantRef":"tenant-lab-A","tenantRef":"tenant-lab-B"', $bytes),
    'duplicate tenant property');
$deny(str_replace('"productCode":"onepage"',
    '"productCode":"onepage","canPublish":true', $bytes),
    'authority cannot be injected as another property');
$deny(str_replace('"tenantRef":"tenant-lab-A"',
    '"tenantRef":"tenant-\u006cab-A"', $bytes),
    'alternate Unicode property value encoding');
$deny(str_replace('"tenantRef":"tenant-lab-A"',
    '"\u0074enantRef":"tenant-lab-A"', $bytes),
    'alternate escaped property name');
$deny(str_replace('"productCode":"onepage"',
    '"productCode": "onepage"', $bytes),
    'noncanonical separator whitespace');
$deny(str_repeat(' ', 1025), 'oversized envelope');
$deny('null', 'null body');
$deny('[]', 'array body');
$deny('{}', 'missing selectors');
$deny('', 'empty body');
$deny("{\"schemaVersion\":\"sites.onepage-current-head.fetch.v1\",", 'truncated body');
$deny($bytes."\xFF", 'invalid UTF-8');
$ok(Request::decode($bytes) === $selector, 'valid request remains valid after denials');

echo 'PORTAL ONEPAGE CURRENT HEAD REQUEST PASS: '.$checks.
    " checks; no mTLS identity, official briefing, HTTP route or authority.\n";
