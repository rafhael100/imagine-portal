<?php

declare(strict_types=1);

// F117 standalone PHP 8.3/8.4 CLI; intentionally no Laravel bootstrap,
// database, network, credentials, customer data, environment or dependencies.
require __DIR__ . '/PortalCommercialMetadataInspector.php';

use Imagine\Portal\Labs\CommercialIntakeF117\PortalCommercialMetadataInspector as Inspector;

$checks = 0;
function check(bool $passed, string $description): void
{
    global $checks;
    ++$checks;
    if (!$passed) {
        throw new RuntimeException('F117 failed: ' . $description);
    }
}

$envelope = [
    'protocol' => 'imagine.lead-intake',
    'schema_version' => 1,
    'organization_reference' => '11111111-1111-4111-8111-111111111111',
    'binding_reference' => '22222222-2222-4222-8222-222222222222',
    'submission_reference' => '33333333-3333-4333-8333-333333333333',
    'site_reference' => '44444444-4444-4444-8444-444444444444',
    'form_reference' => '55555555-5555-4555-8555-555555555555',
    'destination_reference' => '66666666-6666-4666-8666-666666666666',
];
$binding = [
    'organization_reference' => $envelope['organization_reference'],
    'binding_reference' => $envelope['binding_reference'],
    'site_reference' => $envelope['site_reference'],
    'form_reference' => $envelope['form_reference'],
    'destination_reference' => $envelope['destination_reference'],
];
$flags = [
    'canAcceptVisitorData',
    'tenantAuthorized',
    'canCreateLead',
    'canQueueOutbox',
    'canDeliverToCrm',
    'crmReceiptProven',
    'canAcknowledgeVisitor',
    'productionAuthorized',
];

function assess(array $input, array $binding, string $expected): void
{
    global $flags, $envelope, $checks;

    $assessment = Inspector::inspect($input, $binding);
    check($assessment['reasonCode'] === $expected, 'reason ' . $expected);
    check($assessment['evidenceClass'] === 'SYNTHETIC_STRUCTURE_ONLY', 'evidence only');
    check($assessment['schema'] === Inspector::SCHEMA, 'version');
    check(array_keys($assessment) === array_merge(
        ['schema', 'reasonCode', 'evidenceClass'], $flags
    ), 'no IDs, secrets or PII in assessment');
    foreach ($flags as $flag) {
        check($assessment[$flag] === false, $flag . ' always false');
    }
    $encoded = json_encode($assessment, JSON_THROW_ON_ERROR);
    foreach ($envelope as $key => $value) {
        if (str_ends_with($key, '_reference')) {
            check(!str_contains($encoded, (string)$value), 'no UUID returned');
        }
    }
}

assess($envelope, $binding, 'STRUCTURE_MATCH_UNTRUSTED');
$upper = $envelope;
foreach (array_keys($binding) as $field) {
    $upper[$field] = strtoupper($upper[$field]);
}
assess($upper, $binding, 'STRUCTURE_MATCH_UNTRUSTED');
$retry = $envelope;
$retry['submission_reference'] = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
assess($retry, $binding, 'STRUCTURE_MATCH_UNTRUSTED');
// Distinct submission is not automatically authorized: no storage/side effect.
assess($retry, $binding, 'STRUCTURE_MATCH_UNTRUSTED');

foreach (array_keys($binding) as $field) {
    $forged = $envelope;
    $forged[$field] = '77777777-7777-4777-8777-777777777777';
    assess($forged, $binding, 'PINNED_BINDING_CONFLICT');
}
foreach (array_keys($binding) as $field) {
    $badBinding = $binding;
    $badBinding[$field] = '77777777-7777-4777-8777-777777777777';
    assess($envelope, $badBinding, 'PINNED_BINDING_CONFLICT');
}
foreach (array_keys($envelope) as $field) {
    $missing = $envelope;
    unset($missing[$field]);
    assess($missing, $binding, 'ENVELOPE_STRUCTURE_INVALID');
}
foreach (['name', 'email', 'phone', 'message', 'consent', 'role', 'mobile', 'trusted_service'] as $field) {
    $extra = $envelope;
    $extra[$field] = 'not valid for metadata';
    assess($extra, $binding, 'ENVELOPE_STRUCTURE_INVALID');
}
$wrong = $envelope;
$wrong['protocol'] = 'other.protocol';
assess($wrong, $binding, 'PROTOCOL_UNSUPPORTED');
foreach ([0, 2, '1', true, null] as $version) {
    $wrong = $envelope;
    $wrong['schema_version'] = $version;
    assess($wrong, $binding, 'VERSION_UNSUPPORTED');
}
foreach (array_slice(array_keys($envelope), 2) as $field) {
    foreach (['invalid', '00000000-0000-0000-0000-000000000000', 123, null] as $candidate) {
        $wrong = $envelope;
        $wrong[$field] = $candidate;
        assess($wrong, $binding, 'REFERENCE_FORMAT_INVALID');
    }
}
$extraBinding = $binding;
$extraBinding['client_supplied_permission'] = 'admin';
assess($envelope, $extraBinding, 'FIXTURE_BINDING_INVALID');
$missingBinding = $binding;
unset($missingBinding['organization_reference']);
assess($envelope, $missingBinding, 'FIXTURE_BINDING_INVALID');
$invalidBinding = $binding;
$invalidBinding['site_reference'] = null;
assess($envelope, $invalidBinding, 'FIXTURE_BINDING_INVALID');

// JSON fixtures must have the *same* metadata layout as Comercial F78.
$decoded = json_decode(json_encode($envelope, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
check(array_keys($decoded) === array_keys($envelope), 'F78 exact eight-key transport fixture');
assess($decoded, $binding, 'STRUCTURE_MATCH_UNTRUSTED');

echo 'F117 PASS: ' . $checks . " synthetic PHP assertions. No route, lead, tenant authority or CRM receipt.\n";
