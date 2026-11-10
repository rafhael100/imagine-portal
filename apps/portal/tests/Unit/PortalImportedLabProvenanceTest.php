<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * F63: preserve reviewed source provenance for copied, test-only security
 * artifacts. Git blob SHA-1 is a content identity check, not an identity,
 * signature, approval, or trustworthy remote-repository attestation.
 */
final class PortalImportedLabProvenanceTest extends TestCase
{
    /** @return list<array{path:string,blob:string,importNamespace?:string,originNamespace?:string}> */
    private static function reviewedSources(): array
    {
        return [
            [
                'path' => 'labs/sites-intake-f55/create-project.schema.snapshot.json',
                'blob' => '042f4ba31776b486ced757e9624bc42757c46e74',
            ],
            [
                'path' => 'labs/release-f60/C04-OPERATIONAL-GATES.json',
                'blob' => 'd5af6bf60666c121e970f3546ad44695bdbc2ed2',
            ],
            [
                'path' => 'labs/release-f60/src/c04-release-gates.mjs',
                'blob' => 'c1f4c0ff3162da2951c821aef352cf0c7e99b7df',
            ],
            [
                'path' => 'labs/release-f60/test/c04-release-gates.test.mjs',
                'blob' => 'd31002ea9059aac9cbf14870fa27912c5ff09aad',
            ],
            [
                'path' => 'labs/release-f60/wrangler.jsonc',
                'blob' => '928cfdaf2867660e240ff3875f5ef170f401403e',
            ],
            [
                'path' => 'app/Labs/AuthPerfF61/PortalPerfAuthorityFacts.php',
                'blob' => 'a26214a277f038fca92ea4247d299a1e92388029',
                'importNamespace' => 'namespace App\Labs\AuthPerfF61;',
                'originNamespace' => 'namespace App\Domain\PerformanceBridge;',
            ],
            [
                'path' => 'app/Labs/AuthPerfF61/PortalPerfSqlAuthorityResolver.php',
                'blob' => 'f4fb1d441a4470cac2a2eafbfe668bdf6f10518d',
                'importNamespace' => 'namespace App\Labs\AuthPerfF61;',
                'originNamespace' => 'namespace App\Domain\PerformanceBridge;',
            ],
            [
                'path' => 'app/Labs/AuthPerfF61/SyntheticCapabilityIssuer.php',
                'blob' => 'c1263b86376bb50af6f33339a97cae30c07934a9',
                'importNamespace' => 'namespace App\Labs\AuthPerfF61;',
                'originNamespace' => 'namespace App\Domain\PerformanceBridge;',
            ],
            [
                'path' => 'app/Labs/AuthPerfF61/PortalSqlBackedCapabilityIssuer.php',
                'blob' => '9bc6b1fbef98accecc8e4540959fe6158d8e1ce1',
                'importNamespace' => 'namespace App\Labs\AuthPerfF61;',
                'originNamespace' => 'namespace App\Domain\PerformanceBridge;',
            ],
            [
                'path' => 'labs/performance-verifier-f62/SignedPortalCapabilityGate.php',
                'blob' => 'e54ed6d3d044013b00a7ec30d8d405ae64d15751',
                'importNamespace' => 'namespace App\Labs\PerformanceVerifierF62;',
                'originNamespace' => 'namespace App\Performance\Security;',
            ],
            [
                'path' => 'labs/performance-verifier-f62/SyntheticCurrentAuthority.php',
                'blob' => 'ffbd4b2dc81194320e9c9e0fece6fe9e8bf68ce0',
                'importNamespace' => 'namespace App\Labs\PerformanceVerifierF62;',
                'originNamespace' => 'namespace App\Performance\Security;',
            ],
        ];
    }

    public function test_all_eleven_reviewed_cross_branch_artifacts_have_pinned_git_blob_identity(): void
    {
        $sources = self::reviewedSources();
        self::assertCount(11, $sources);

        foreach ($sources as $source) {
            $path = dirname(__DIR__, 2) . '/' . $source['path'];
            self::assertFileExists($path, $source['path']);
            $contents = file_get_contents($path);
            self::assertIsString($contents, $source['path']);

            if (isset($source['importNamespace'], $source['originNamespace'])) {
                $count = 0;
                $contents = str_replace(
                    $source['importNamespace'],
                    $source['originNamespace'],
                    $contents,
                    $count,
                );
                self::assertSame(1, $count, 'Unexpected namespace conversion: ' . $source['path']);
            }

            $blob = hash('sha1', 'blob ' . strlen($contents) . "\0" . $contents);
            self::assertSame($source['blob'], $blob, 'Imported lab artifact drift: ' . $source['path']);
        }
    }

    public function test_mutating_even_one_byte_of_pinned_source_changes_git_blob_identity(): void
    {
        $path = dirname(__DIR__, 2) . '/labs/release-f60/C04-OPERATIONAL-GATES.json';
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        $mutated = $contents . ' ';
        self::assertNotSame(
            hash('sha1', 'blob ' . strlen($contents) . "\0" . $contents),
            hash('sha1', 'blob ' . strlen($mutated) . "\0" . $mutated),
        );
    }
}
