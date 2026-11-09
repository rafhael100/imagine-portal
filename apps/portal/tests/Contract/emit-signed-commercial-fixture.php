<?php

declare(strict_types=1);

// Fixture ephemeral interop CLI: explicit argv target folder, no keys exported.
require_once dirname(__DIR__, 2).'/app/Domain/CommercialPublishing/PublicCatalogSigner.php';

use App\Domain\CommercialPublishing\PublicCatalogSigner;

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
$envelope = PublicCatalogSigner::sign($snapshot, 'portal_contract_01', $secret);
// ASN.1 SubjectPublicKeyInfo for RFC8410 Ed25519 + raw 32-byte public key.
$der = hex2bin('302a300506032b6570032100').$pubRaw;
$pem = "-----BEGIN PUBLIC KEY-----\n".
    chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
file_put_contents($folder.'/commercial-envelope.json', json_encode($envelope, JSON_THROW_ON_ERROR));
file_put_contents($folder.'/commercial-public.pem', $pem);
echo "Created signed *synthetic* public catalog and public key for parity test.\n";
