<?php

declare(strict_types=1);

// Fixture ephemeral interop CLI: explicit argv target folder, no keys exported.
require_once dirname(__DIR__, 2).'/app/Domain/CommercialPublishing/PublicCatalogSigner.php';
require_once dirname(__DIR__, 2).'/app/Domain/CommercialPublishing/PublicationApprovalGate.php';
require_once dirname(__DIR__, 2).'/app/Domain/CommercialPublishing/ApprovedPublicCatalogIssuer.php';

use App\Domain\CommercialPublishing\ApprovedPublicCatalogIssuer;

$folder = $argv[1] ?? null;
if (!is_string($folder) || !is_dir($folder)) {
    fwrite(STDERR, "Usage: php emit-signed-commercial-fixture.php <existing temp dir>\n");
    exit(2);
}

$keypair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($keypair);
$pubRaw = sodium_crypto_sign_publickey($keypair);
$snapshot = [
    'schemaVersion' => '1.0',
    'releaseId' => 'release_20261109_parity',
    'publishedAt' => '2026-11-09T03:00:00Z',
    'items' => [[
        'id'=>'service_landing_1',
        'slug'=>'landing-pages',
        'title'=>'Landing Pages empresariais publicadas em teste',
        'description'=>'Metadados públicos de teste para verificar paridade criptográfica entre PHP e Astro no contrato de catálogo.',
        'path'=>'/servicos/landing-pages/',
        'status'=>'published',
        'version'=>2
    ]],
];
// Todos os atores, permissões e tempos são sintéticos: não são claims autenticadas.
$digest = hash('sha256', json_encode(
    $snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
));
$scope = [
    'requestId' => 'request_parity_20261109',
    'tenantId' => 'tenant_imagine_01',
    'releaseId' => $snapshot['releaseId'],
    'payloadSha256' => $digest,
];
$request = $scope + [
    'actorId' => 'editor_demo_01', 'roles' => ['editor'],
    'requestedAt' => '2026-11-09T02:55:00Z', 'status' => 'submitted'
];
$review = $scope + [
    'actorId' => 'reviewer_demo_01', 'roles' => ['content_approver'],
    'approvedAt' => '2026-11-09T02:57:00Z', 'decision' => 'approved'
];
$dispatch = $scope + [
    'actorId' => 'publisher_demo_01', 'roles' => ['publisher'],
    'dispatchedAt' => '2026-11-09T02:58:00Z', 'status' => 'pending'
];
$envelope = ApprovedPublicCatalogIssuer::issue(
    $snapshot, $request, $review, $dispatch, 'portal_contract_01', $secret
);
// ASN.1 SubjectPublicKeyInfo for RFC8410 Ed25519 + raw 32-byte public key.
$der = hex2bin('302a300506032b6570032100').$pubRaw;
$pem = "-----BEGIN PUBLIC KEY-----\n".
    chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
file_put_contents($folder.'/commercial-envelope.json', json_encode($envelope, JSON_THROW_ON_ERROR));
file_put_contents($folder.'/commercial-public.pem', $pem);
echo "Created signed *synthetic* public catalog and public key for parity test.\n";
