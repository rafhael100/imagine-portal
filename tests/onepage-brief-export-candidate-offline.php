<?php

declare(strict_types=1);

// No Portal boot, database, session, payment, HTTP, JSON normalization,
// shell invocation or output of a private source is involved in this test.
require __DIR__.'/../apps/portal/app/Domain/SitesIntegration/SiteContractSnapshot.php';
require __DIR__.'/../apps/portal/app/Domain/SitesIntegration/OnepageBriefSnapshotCandidateEncoder.php';

use App\Domain\SitesIntegration\OnepageBriefSnapshotCandidateEncoder as Exporter;
use App\Domain\SitesIntegration\SiteContractSnapshot;

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Onepage candidate export failed: '.$label);
    ++$checks;
};
$deny = static function (callable $action, string $label) use ($check): void {
    try {
        $action();
    } catch (RuntimeException $error) {
        $check($error->getMessage() === 'ONEPAGE_BRIEF_EXPORT_CANDIDATE_DENIED' &&
            $error->getPrevious() === null, $label);
        return;
    }
    $check(false, $label.' was accepted');
};
$contract = new SiteContractSnapshot(
    'synthetic-portal-org', 'synthetic-order-001', 'synthetic-contract-001', 1, 'onepage',
);
$selection = [
    'siteRef' => '11111111-1111-4111-8111-111111111111',
    'snapshotRef' => '22222222-2222-4222-8222-222222222222',
    'operationId' => 'synthetic-operation-001',
    'idempotencyKey' => 'synthetic-brief-key-001',
    'sourceVersion' => 12,
    'expectedBriefRevision' => 0,
    'payloadRef' => 'synthetic-body-001',
];
$source = [
    'company_name' => 'Oficina Exemplo — apenas laboratório',
    'company_intro' => "Atendimento ilustrativo com a regra de quebra\nentre linhas.",
    'services' => 'Conteúdo de serviços de demonstração.',
    'current_site' => '',
    'contact_site' => 'https://contato.example.invalid/',
    'domain' => 'exemplo.invalid',
    'email' => 'contato@exemplo.invalid',
    'phone' => '',
    'address' => '',
    'whatsapp' => '',
    'notes' => 'Não é dado de cliente.',
    'ai_support' => false,
    'logo_unavailable' => true,
    'manual_unavailable' => true,
];
$encode = static fn(array $x): string => json_encode(
    $x, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
)."\n";
$raw = $encode($source);
$actual = Exporter::build($contract, $selection, $raw);
$replay = Exporter::build($contract, $selection, $raw);
$check($actual === $replay, 'same original bytes and explicit selection reproduce exact manifest');
$check($actual['sourceBytes'] === $raw && strlen($actual['sourceBytes']) === strlen($raw),
    'raw UTF-8 bytes, newline and JSON key order are retained');
$check($actual['payloadSha256'] === hash('sha256', $raw) &&
    $actual['manifestSha256'] === hash('sha256', $actual['manifestBytes']),
    'both SHA-256 digests refer to original, distinct full byte streams');
$check(str_ends_with($actual['manifestBytes'], "\n"), 'generated manifest has a stable trailing LF');
foreach (['authorityVerified', 'clientAccepted', 'canPublish', 'canAcceptRealOrders'] as $key) {
    $check($actual[$key] === false, $key.' cannot be promoted by a candidate export');
}
$manifest = json_decode($actual['manifestBytes'], true, 8, JSON_THROW_ON_ERROR);
$check(is_array($manifest) && count($manifest) === 17, 'strict snapshot manifest has seventeen fields');
$check($manifest['schemaVersion'] === 'sites.brief-snapshot.candidate.v0.1' &&
    $manifest['sourceProfile'] === 'sites.onepage-brief-payload.candidate.v0.1' &&
    $manifest['sourceState'] === 'approved' &&
    $manifest['productCode'] === 'onepage' && $manifest['materials'] === [],
    'candidate only, with explicit declared state and no material capabilities');
$check($manifest['siteRef'] === $selection['siteRef'] &&
    $manifest['snapshotRef'] === $selection['snapshotRef'] &&
    $manifest['tenantRef'] === $contract->tenantRef &&
    $manifest['orderRef'] === $contract->orderRef &&
    $manifest['contractRef'] === $contract->contractRef &&
    $manifest['contractRevision'] === $contract->contractRevision,
    'all independent project, contract and snapshot identifiers are retained');
$check($manifest['briefRevision'] === 1 && $manifest['expectedBriefRevision'] === 0 &&
    $manifest['sourceVersion'] === 12, 'source and Sites immutable revision sequences remain distinct');
$check($manifest['payload'] === [
    'payloadRef' => 'synthetic-body-001',
    'mediaType' => 'application/json',
    'sizeBytes' => strlen($raw),
    'sha256' => hash('sha256', $raw),
], 'descriptor binds full payload bytes without a path or URL');

$alternative = Exporter::build($contract, array_replace($selection, ['sourceVersion' => 13,
    'snapshotRef' => '33333333-3333-4333-8333-333333333333']), $raw);
$check($alternative['manifestSha256'] !== $actual['manifestSha256'] &&
    $alternative['payloadSha256'] === $actual['payloadSha256'],
    'new snapshot and source counter cannot overwrite original or change identical source bytes');
$reencoded = json_encode($source, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$another = Exporter::build($contract, $selection, $reencoded);
$check($another['payloadSha256'] !== $actual['payloadSha256'] &&
    $another['manifestSha256'] !== $actual['manifestSha256'],
    'removing LF changes both bound SHA digests even for logically equivalent JSON');

$badFields = [
    'missing company' => ['company_name' => "\u{00A0}\u{2003}"],
    'empty services' => ['services' => " \n  "],
    'missing presentation' => ['company_intro' => ''],
    'missing contact' => ['email' => '', 'phone' => '', 'whatsapp' => ''],
    'wrong boolean' => ['ai_support' => 'false'],
    'material present' => ['logo_unavailable' => false],
    'manual present' => ['manual_unavailable' => false],
    'invalid domain' => ['domain' => 'bad domain'],
    'invalid email' => ['email' => 'not-email'],
    'userinfo in URL' => ['contact_site' => 'https://user:pass@contato.example.invalid/'],
    'unicode hostname' => ['contact_site' => 'https://exémplo.invalid/'],
    'abbreviated IPv4' => ['current_site' => 'http://127.1/'],
    'backslash URL' => ['contact_site' => 'https://contato.example.invalid\\path'],
    'oversized brand UTF16' => ['company_name' => str_repeat("🧪", 81)],
    'invalid NUL' => ['notes' => "nulo\0aqui"],
];
foreach ($badFields as $label => $changes) {
    $deny(static fn() => Exporter::build($contract, $selection, $encode(
        array_replace($source, $changes),
    )), $label);
}
$withExtra = array_replace($source, ['canPublish' => true]);
$deny(static fn() => Exporter::build($contract, $selection, $encode($withExtra)),
    'extra authority field');
$without = $source;
unset($without['company_name']);
$deny(static fn() => Exporter::build($contract, $selection, $encode($without)),
    'missing body field');
$deny(static fn() => Exporter::build($contract, $selection,
    '{"company_name":"a","company_name":"b"}'),
    'duplicate JSON key');
$deny(static fn() => Exporter::build($contract, $selection, '{"email":"a\ud800"}'),
    'unpaired surrogate');
$deny(static fn() => Exporter::build($contract, $selection, "\xFF"),
    'invalid UTF-8');
$deny(static fn() => Exporter::build($contract, $selection,
    str_repeat(' ', Exporter::SOURCE_LIMIT + 1)),
    'oversized source byte limit');
$deny(static fn() => Exporter::build(
    new SiteContractSnapshot('synthetic-portal-org', 'synthetic-order-001',
        'synthetic-contract-001', 1, 'landing_page'), $selection, $raw,
), 'landing_page is not an authorized Onepage profile');

$selectionNegatives = [
    'wrong UUID' => ['siteRef' => 'not-a-uuid'],
    'uppercase UUID' => ['snapshotRef' => 'AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA'],
    'negative sourceVersion' => ['sourceVersion' => -1],
    'string sourceVersion' => ['sourceVersion' => '12'],
    'overflow sourceVersion' => ['sourceVersion' => 9007199254740992],
    'negative revision' => ['expectedBriefRevision' => -1],
    'string revision' => ['expectedBriefRevision' => '1'],
    'beyond integer successor' => ['expectedBriefRevision' => 2147483647],
    'operation with path' => ['operationId' => '../outside'],
    'idempotency with space' => ['idempotencyKey' => 'not allowed'],
    'payload URL' => ['payloadRef' => 'https://invalid.example/path'],
    'extra source authority' => ['canPublish' => true],
];
foreach ($selectionNegatives as $label => $changes) {
    $deny(static fn() => Exporter::build($contract, array_replace($selection, $changes), $raw),
        $label);
}
$withoutSelection = $selection;
unset($withoutSelection['siteRef']);
$deny(static fn() => Exporter::build($contract, $withoutSelection, $raw),
    'missing selection field');
$limit = array_replace($selection, ['sourceVersion' => 9007199254740991,
    'expectedBriefRevision' => 2147483646]);
$atLimit = Exporter::build($contract, $limit, $raw);
$check(json_decode($atLimit['manifestBytes'], true, 8, JSON_THROW_ON_ERROR)['briefRevision']
    === 2147483647, 'last supported successor is represented without integer overflow');

echo "PORTAL ONEPAGE BRIEF CANDIDATE OFFLINE PASS: {$checks} checks; no authenticated export or transport.\n";
