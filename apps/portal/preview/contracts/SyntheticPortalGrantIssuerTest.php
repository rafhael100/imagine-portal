<?php

declare(strict_types=1);

require_once __DIR__.'/SyntheticS2SProtocol.php';
require_once __DIR__.'/SyntheticPortalGrantIssuer.php';

use Imagine\Protocol\Lab\SyntheticPortalGrantIssuer;
use Imagine\Protocol\Lab\SyntheticS2SProtocol;

$fixture = getenv('PERF_SNAPSHOT_FIXTURE');
if (!is_string($fixture) || !is_file($fixture)) {
    throw new RuntimeException('Missing offline synthetic fixture.');
}
$bytes = file_get_contents($fixture);
if (!is_string($bytes) || strlen($bytes) > 32768) {
    throw new RuntimeException('Invalid snapshot fixture.');
}
$sha = hash('sha256', $bytes);
$key = random_bytes(32);
$now = 1800000000;
$issuer = new SyntheticPortalGrantIssuer($key, $sha);
$protocol = new SyntheticS2SProtocol();

$issue = static fn (
    string $actor = 'actor-demo-owner-a',
    string $org = 'org-demo-a',
    string $tenant = 'perf-demo-a',
    string $service = 'svc-demo-a',
    string $request = 'req-demo-a1',
    string $nonce = 'nonce-demo-a1',
    int $issued = 1799999940,
    int $expires = 1800000060
): array|string => $issuer->issueForLab(
    $actor, $org, $tenant, $service, $request, $nonce, $issued, $expires
);
$verify = static fn (
    SyntheticS2SProtocol $v,
    mixed $envelope,
    string $org = 'org-demo-a',
    string $tenant = 'perf-demo-a',
    string $service = 'svc-demo-a',
    int $revision = 3,
    bool $active = true
): string => $v->verifyForLab(
    $envelope, $key, $org, $tenant, $service, $sha, $revision, $now, $active
);
$n = 0;
$check = static function (string $name, mixed $got, mixed $want) use (&$n): void {
    if ($got !== $want) {
        throw new RuntimeException("FAIL $name: ".var_export($got, true).' expected '.var_export($want, true));
    }
    ++$n;
    echo "PASS $n $name\n";
};

$a = $issue();
$check('01 owner A issues envelope, not a live authorization', is_array($a), true);
$check('02 issuer binds A organization', $a['organization_ref'], 'org-demo-a');
$check('03 issuer binds A tenant', $a['tenant_ref'], 'perf-demo-a');
$check('04 issuer binds A service', $a['service_ref'], 'svc-demo-a');
$check('05 issuer binds mocked grant revision', $a['grant_revision'], 3);
$check('06 issuer signs exact offline fixture digest', $a['payload_sha256'], $sha);
$check('07 Performance protocol accepts first synthetic envelope', $verify($protocol, $a), 'accepted_synthetic_only');
$check('08 Performance protocol detects exact replay', $verify($protocol, $a), 'mock_replay_denied');
$check('09 unknown actor is refused', $issue(actor: 'actor-demo-intruder'), 'mock_actor_denied');
$check('10 viewer cannot emit a signed envelope', $issue(actor: 'actor-demo-reader-a'), 'mock_scope_denied');
$check('11 A owner cannot issue tenant B claim', $issue(tenant: 'perf-demo-b'), 'mock_binding_denied');
$check('12 A owner cannot claim organization B', $issue(org: 'org-demo-b'), 'mock_binding_denied');
$check('13 A owner cannot claim B service', $issue(service: 'svc-demo-b'), 'mock_binding_denied');
$check('14 unknown service rejected', $issue(service: 'svc-demo-absent'), 'mock_binding_denied');
$b = $issue('actor-demo-owner-b', 'org-demo-b', 'perf-demo-b', 'svc-demo-b', 'req-demo-b1', 'nonce-demo-b1');
$check('15 B issuer can sign own synthetic service', is_array($b), true);
$check('16 Performance protocol accepts B when bound to B', $verify(
    new SyntheticS2SProtocol(), $b, 'org-demo-b', 'perf-demo-b', 'svc-demo-b', 1
), 'accepted_synthetic_only');
$check('17 B signed message cannot pass A binding', $verify(new SyntheticS2SProtocol(), $b), 'mock_binding_denied');

$tampered = $a;
$tampered['tenant_ref'] = 'perf-demo-b';
$check('18 altered signed tenant rejected', $verify(new SyntheticS2SProtocol(), $tampered), 'invalid_lab_signature');
$check('19 expired signed event rejected', $verify(new SyntheticS2SProtocol(),
    $issue(request: 'req-demo-expired', nonce: 'nonce-demo-expired', issued: $now - 60, expires: $now)
), 'mock_message_expired');
$check('20 mock revoke is explicit', $issuer->revokeForLab('svc-demo-a'), 'revoked_demo_only');
$check('21 revocation increments grant revision', $issuer->grantRevisionForLab('svc-demo-a'), 4);
$check('22 revoked issuer refuses new envelope', $issue(request: 'req-demo-a2', nonce: 'nonce-demo-a2'), 'mock_grant_revoked');
$check('23 previously signed envelope cannot bypass revoked fixture', $verify(
    new SyntheticS2SProtocol(), $a, active: false
), 'mock_grant_revoked');
$check('24 duplicate revoke records a denial', $issuer->revokeForLab('svc-demo-a'), 'already_revoked_demo');
$check('25 unrelated B still issues', is_array($issue(
    'actor-demo-owner-b', 'org-demo-b', 'perf-demo-b', 'svc-demo-b', 'req-demo-b2', 'nonce-demo-b2'
)), true);
$check('26 unknown grant returns not found', $issuer->grantRevisionForLab('svc-demo-absent'), null);
$check('27 no cookies, PII or money fields', array_key_exists('email', $a) || array_key_exists('payment_state', $a)
    || array_key_exists('session_cookie', $a), false);
$check('28 demo binding is never active service', array_key_exists('contract_active', $a), false);
try {
    new SyntheticPortalGrantIssuer('weak', $sha);
    throw new RuntimeException('Weak key accepted by issuer');
} catch (InvalidArgumentException $e) {
    $check('29 issuer requires ephemeral strong test key', $e->getMessage(), 'Lab issuer requires ephemeral key and fixture digest.');
}
echo "PASS $n Portal synthetic issuer/Performance verifier checks\n";
