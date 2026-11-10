<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\SiteIntake\SiteEngineSchemaDriftGate;
use PHPUnit\Framework\TestCase;

final class SiteEngineSchemaDriftGateTest extends TestCase
{
    private function snapshotPath(): string
    {
        return dirname(__DIR__, 2) . '/labs/sites-intake-f55/create-project.schema.snapshot.json';
    }

    /** @return array{matched:bool,reason:string} */
    private function inspect(string $upstreamPath, ?string $snapshot = null): array
    {
        return (new SiteEngineSchemaDriftGate())->compare($upstreamPath, $snapshot ?? $this->snapshotPath());
    }

    private function temporaryFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'portal-f57-');
        self::assertIsString($path);
        file_put_contents($path, $content);
        return $path;
    }

    public function test_reviewed_snapshot_matches_an_exact_local_sites_copy(): void
    {
        $path = $this->snapshotPath();
        self::assertSame(
            ['matched' => true, 'reason' => 'pinned_schema_match_only'],
            $this->inspect($path),
        );
    }

    public function test_upstream_change_fails_closed_even_if_only_whitespace_changes(): void
    {
        $bytes = file_get_contents($this->snapshotPath());
        self::assertIsString($bytes);
        $temp = $this->temporaryFile($bytes . "\n");
        try {
            self::assertSame('upstream_schema_drift', $this->inspect($temp)['reason']);
        } finally {
            unlink($temp);
        }
    }

    public function test_mutated_portal_snapshot_is_rejected_even_when_upstream_matches_it(): void
    {
        $bytes = file_get_contents($this->snapshotPath());
        self::assertIsString($bytes);
        $mutated = str_replace('"onepage"', '"onepage_changed"', $bytes);
        self::assertNotSame($bytes, $mutated);
        $temp = $this->temporaryFile($mutated);
        try {
            self::assertSame('portal_snapshot_not_pinned', $this->inspect($temp, $temp)['reason']);
        } finally {
            unlink($temp);
        }
    }

    public function test_missing_empty_and_oversized_upstream_files_are_rejected(): void
    {
        self::assertSame('file_unavailable_or_oversize', $this->inspect('/definitely/missing/portal-f57-schema.json')['reason']);
        $empty = $this->temporaryFile('');
        $oversized = $this->temporaryFile(str_repeat('x', 65537));
        try {
            self::assertSame('file_unavailable_or_oversize', $this->inspect($empty)['reason']);
            self::assertSame('file_unavailable_or_oversize', $this->inspect($oversized)['reason']);
        } finally {
            unlink($empty);
            unlink($oversized);
        }
    }

    public function test_no_matching_schema_grants_integration_or_authenticity(): void
    {
        self::assertFalse(SiteEngineSchemaDriftGate::authorizesIntegration());
        self::assertFalse(SiteEngineSchemaDriftGate::verifiesRemoteOrigin());
        self::assertFalse(
            $this->inspect($this->snapshotPath())['matched'] &&
            SiteEngineSchemaDriftGate::authorizesIntegration(),
        );
    }
}
