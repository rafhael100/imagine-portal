<?php

declare(strict_types=1);

// Fixture ephemeral interop CLI: explicit argv target folder, no keys exported.
require_once dirname(__DIR__, 2).'/app/Domain/CommercialPublishing/PublicCatalogSigner.php';
require_once dirname(__DIR__, 2).'/app/Domain/CommercialPublishing/PublicationApprovalGate.php';
require_once dirname(__DIR__, 2).'/app/Domain/CommercialPublishing/ApprovedPublicCatalogIssuer.php';
// Fixtures CLI do laboratorio carregam somente classes internas App sem
// depender de instalacao, scripts ou credenciais de terceiros.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $relative = substr($class, 4);
    if (!preg_match('/\A[A-Za-z0-9_\\\\]+\z/D', $relative)) {
        throw new \RuntimeException('Invalid internal class path');
    }
    $path = dirname(__DIR__, 2).'/app/'.str_replace('\\', '/', $relative).'.php';
    if (is_file($path)) {
        require_once $path;
    }
});


use App\Domain\CommercialPublishing\TenantScopedPublicCatalogIssuer;
use App\Domain\Authorization\Permission;
use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\OrganizationId;
use App\Domain\Organizations\OrganizationMembership;
use App\Domain\Organizations\OrganizationRole;
use App\Domain\Organizations\MembershipStatus;

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
$org = new OrganizationId('tenant_imagine_01');
$member = static fn (string $actor, Permission $permission): OrganizationMembership =>
    new OrganizationMembership(
        new PortalUserId($actor), $org, OrganizationRole::Member,
        MembershipStatus::Active, [$permission]
    );
$envelope = TenantScopedPublicCatalogIssuer::issue(
    $snapshot, $request, $review, $dispatch, $org,
    $member('editor_demo_01', Permission::CommercialEditCatalog),
    $member('reviewer_demo_01', Permission::CommercialApproveCatalog),
    $member('publisher_demo_01', Permission::CommercialReservePublish),
    'portal_contract_01', $secret
);
// ASN.1 SubjectPublicKeyInfo for RFC8410 Ed25519 + raw 32-byte public key.
$der = hex2bin('302a300506032b6570032100').$pubRaw;
$pem = "-----BEGIN PUBLIC KEY-----\n".
    chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
file_put_contents($folder.'/commercial-envelope.json', json_encode($envelope, JSON_THROW_ON_ERROR));
file_put_contents($folder.'/commercial-public.pem', $pem);
echo "Created signed *synthetic* public catalog and public key for parity test.\n";
