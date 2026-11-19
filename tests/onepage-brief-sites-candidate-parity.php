<?php

declare(strict_types=1);

// Paired, immutable GitHub checkout only. No live Portal/Sites DB/HTTP,
// no authentic approval, no customer data and no cross-repository mutations.
$root = dirname(__DIR__);
$sites = $root.'/.pinned-sites';
if (PHP_SAPI !== 'cli' || getenv('SITES_PINNED_CANDIDATE_CI') !== '1' ||
    !is_dir($sites) || is_link($sites)) {
    throw new RuntimeException('Pinned candidate Sites source required');
}
require $root.'/apps/portal/app/Domain/SitesIntegration/SiteContractSnapshot.php';
require $root.'/apps/portal/app/Domain/SitesIntegration/OnepageBriefSnapshotCandidateEncoder.php';
require $sites.'/src/Production/BriefCandidateJson.php';
require $sites.'/src/Production/BriefSnapshotInput.php';

use App\Domain\SitesIntegration\SiteContractSnapshot;
use App\Domain\SitesIntegration\OnepageBriefSnapshotCandidateEncoder as Exporter;
use Imagine\Sites\Production\BriefSnapshotInput;

$checks = 0;
$ok = static function (bool $condition,string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Paired candidate mismatch: '.$label);
    ++$checks;
};
$examples = $sites.'/contracts/portal-sites/candidates/brief-production-v1/examples';
$source = file_get_contents($examples.'/onepage-brief.synthetic.json');
$originalManifest = json_decode(
    (string)file_get_contents($examples.'/brief-snapshot.synthetic.json'), true, 8, JSON_THROW_ON_ERROR,
);
$ok(is_string($source) && $source !== '' && is_array($originalManifest),
    'pinned source example and selection are available');
$contract = new SiteContractSnapshot(
    $originalManifest['tenantRef'], $originalManifest['orderRef'],
    $originalManifest['contractRef'], $originalManifest['contractRevision'],
    $originalManifest['productCode'],
);
$selection = [
    'siteRef'=>$originalManifest['siteRef'],
    'snapshotRef'=>$originalManifest['snapshotRef'],
    'operationId'=>$originalManifest['operationId'],
    'idempotencyKey'=>$originalManifest['idempotencyKey'],
    'sourceVersion'=>$originalManifest['sourceVersion'],
    'expectedBriefRevision'=>$originalManifest['expectedBriefRevision'],
    'payloadRef'=>$originalManifest['payload']['payloadRef'],
];
$export = Exporter::build($contract, $selection, $source);
$siteInput = BriefSnapshotInput::fromJson($export['manifestBytes'], $export['sourceBytes']);
$ok($siteInput->snapshotJson === $export['manifestBytes'] &&
    $siteInput->sourceJson === $source, 'Sites accepts the Portal candidate without reserializing private bytes');
$ok($siteInput->manifestSha256 === $export['manifestSha256'] &&
    $siteInput->payloadSha256 === $export['payloadSha256'],
    'both sides agree on original JSON SHA digests');
$ok($siteInput->siteRef === $selection['siteRef'] &&
    $siteInput->snapshotRef === $selection['snapshotRef'] &&
    $siteInput->orderRef === $contract->orderRef &&
    $siteInput->contractRef === $contract->contractRef &&
    $siteInput->contractRevision === $contract->contractRevision,
    'identities and contract version cross the exact parser unchanged');
$ok($siteInput->briefRevision === $selection['expectedBriefRevision'] + 1 &&
    $siteInput->sourceVersion === $selection['sourceVersion'],
    'Sites parses the distinct source and snapshot version counters');
$ok($siteInput->productCode === 'onepage' &&
    $export['authorityVerified'] === false &&
    $export['clientAccepted'] === false &&
    $export['canPublish'] === false &&
    $export['canAcceptRealOrders'] === false,
    'Portal candidate never claims publication or customer acceptance');

$newSource = str_ends_with($source, "\n")
    ? substr($source, 0, -1)."\r\n"
    : $source."\r\n";
$ok($newSource !== $source,'synthetic whitespace alteration is observable');
$changed = Exporter::build($contract,
    array_replace($selection,[
        'snapshotRef'=>'33333333-3333-4333-8333-333333333333',
        'sourceVersion'=>13,'expectedBriefRevision'=>1,
    ]),$newSource);
$changedInput=BriefSnapshotInput::fromJson($changed['manifestBytes'],$changed['sourceBytes']);
$ok($changedInput->briefRevision===2 &&
    $changedInput->sourceVersion===13 &&
    $changedInput->payloadSha256!==$siteInput->payloadSha256,
    'second candidate binds new source bytes and explicit immutable revision');
$ok($siteInput->sourceJson===$source &&
    $changedInput->sourceJson===$newSource,
    'new export cannot rewrite old snapshot bytes in memory');
$ok($export===Exporter::build($contract,$selection,$source),
    'repeating the same source/selection produces byte-identical candidate files');

echo "PORTAL-SITES PINNED BRIEF CANDIDATE PARITY PASS: {$checks} checks; no authority or transport.\n";
