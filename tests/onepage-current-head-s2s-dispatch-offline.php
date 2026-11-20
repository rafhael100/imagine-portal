<?php

declare(strict_types=1);

// Exercise the complete candidate request -> service witness -> current head
// composer -> independent Sites response parser. ALL sources are synthetic;
// there is no HTTP controller, authenticated peer, official approval or DB.
use App\Domain\SitesIntegration\OnepageCurrentHeadS2sDispatchCandidate;
use App\Domain\SitesIntegration\SiteContractSnapshot;
use App\Integration\PortalOnepageHeadRequestCandidate;

ob_start();
require __DIR__.'/onepage-current-head-s2s-contract-offline.php';
$baseline = ob_get_clean();
if (!str_contains($baseline, 'PORTAL ONEPAGE CURRENT HEAD CONTRACT PASS:')) {
    throw new RuntimeException('Missing pinned response contract regression');
}
require_once __DIR__.'/../apps/portal/app/Domain/SitesIntegration/OnepageCurrentHeadS2sRequestCandidate.php';
require_once __DIR__.'/../apps/portal/app/Domain/SitesIntegration/OnepageCurrentHeadS2sDispatchCandidate.php';
require_once __DIR__.'/../.pinned-sites/apps/sites/app/Integration/PortalOnepageHeadRequestCandidate.php';

$checks = 0;
$ok = static function(bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('CURRENT HEAD DISPATCH FAILURE: '.$label);
    ++$checks;
};
$denied = static function(callable $action, string $label) use ($ok): void {
    ob_start();
    $failed = false;
    try {
        $action();
    } catch (RuntimeException $error) {
        $failed = $error->getMessage() === 'PORTAL_ONEPAGE_CURRENT_HEAD_DISPATCH_DENIED'
            && $error->getPrevious() === null;
    }
    $output = ob_get_clean();
    $ok($failed && $output === '', $label.' denied with no disclosure');
};

$wire = PortalOnepageHeadRequestCandidate::encode($tenant, $order, $site);
$dispatcher = new OnepageCurrentHeadS2sDispatchCandidate($resolve);
$default = new OnepageCurrentHeadS2sDispatchCandidate();

$reset();
$expected = $call();
$reset();
$response = $dispatcher->dispatch($wire, 'test-only-authorized-s2s');
$ok(hash_equals($expected, $response), 'canonical Sites bytes produce exact independent Portal head response');
$ok(\App\Integration\PortalOnepageHeadMtlsHttpSource::parseCandidateResponse(
    $response, $tenant, $order, $site
) === json_decode($expected, true, 16, JSON_THROW_ON_ERROR),
    'Sites head transport parser consumes produced bytes');
$ok($service->calls === 2 && $contracts->calls === 2 &&
    $current->calls === 2 && $exact->calls === 2 && $approval->calls === 2,
    'each candidate authority observed before and after export');

$reset();
$denied(static fn() => $default->dispatch($wire, 'test-only-authorized-s2s'), 'default-deny composer');
$reset();
$denied(static fn() => $dispatcher->dispatch($wire, ''), 'missing out-of-band peer');
$denied(static fn() => $dispatcher->dispatch($wire, 'not a service'), 'malformed out-of-band peer');
$ok($service->calls === 0 && $contracts->calls === 0 &&
    $current->calls === 0 && $exact->calls === 0,
    'malformed peer never reads even a service witness');

foreach ([
    ['empty', ''],
    ['not JSON', '{'],
    ['added authority', substr($wire,0,-1).',"canPublish":true}'],
    ['repeated tenant', substr($wire,0,-1).',"tenantRef":"tenant-head-lab-B"}'],
    ['trailing whitespace', $wire."\n"],
    ['other product', str_replace('"productCode":"onepage"', '"productCode":"landing_page"', $wire)],
    ['other schema', str_replace('current-head.fetch.v1', 'current-head.fetch.v2', $wire)],
    ['reordered keys', '{"tenantRef":"'.$tenant.'","schemaVersion":"sites.onepage-current-head.fetch.v1","orderRef":"'.$order.'","siteRef":"'.$site.'","productCode":"onepage"}'],
] as [$label, $bad]) {
    $reset();
    $denied(static fn() => $dispatcher->dispatch($bad,'test-only-authorized-s2s'), $label);
    $ok($service->calls === 0 && $contracts->calls === 0 &&
        $current->calls === 0 && $exact->calls === 0,
        $label.' rejected before private source reads');
}

$reset();
$denied(static fn() => $dispatcher->dispatch($wire, 'wrong-service'), 'untrusted but well-formed peer');
$ok($service->calls === 1 && $contracts->calls === 0 && $current->calls === 0,
    'untrusted service never reaches contract/head sources');

$reset();
$foreign = PortalOnepageHeadRequestCandidate::encode('tenant-head-lab-B', $order, $site);
$denied(static fn() => $dispatcher->dispatch($foreign,'test-only-authorized-s2s'), 'foreign tenant');
$ok($service->calls === 1 && $contracts->calls === 0 && $current->calls === 0,
    'foreign selector is not tenant authority');

$reset();
$approval->revokeSecond = true;
$denied(static fn() => $dispatcher->dispatch($wire,'test-only-authorized-s2s'),
    'revocation between witness checks');
$reset();
$contracts->next = new SiteContractSnapshot($tenant,$order,'contract-head-lab',5,'onepage');
$denied(static fn() => $dispatcher->dispatch($wire,'test-only-authorized-s2s'),
    'contract revision changed between reads');
$reset();
$ok($dispatcher->dispatch($wire, 'test-only-authorized-s2s') === $expected,
    'idempotent replay after denials when sources are unchanged');

echo 'PORTAL ONEPAGE HEAD DISPATCH PASS: '.$checks.
    " checks; synthetic readers, no mTLS peer attestation, official approval, controller or release.\n";
