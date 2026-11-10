<?php

declare(strict_types=1);

namespace App\Domain\SiteIntake;

/**
 * Offline drift check for the *candidate* Imagine Sites CreateProject v1
 * schema. Not an API client, authenticator, signature or publication gate.
 *
 * The pinned Git blob hash identifies a reviewed synthetic schema snapshot;
 * an exact match is necessary for updating the Portal's local lab, but never
 * sufficient to authorize production use or to attest to a remote issuer.
 */
final class SiteEngineSchemaDriftGate
{
    public const PINNED_GIT_BLOB_SHA1 = '042f4ba31776b486ced757e9624bc42757c46e74';

    /** @return array{matched:bool,reason:string} */
    public function compare(string $upstreamPath, string $portalSnapshotPath): array
    {
        $upstream = $this->readBoundedFile($upstreamPath);
        $snapshot = $this->readBoundedFile($portalSnapshotPath);

        if ($upstream === null || $snapshot === null) {
            return ['matched' => false, 'reason' => 'file_unavailable_or_oversize'];
        }

        // Unlike sha1(file), Git hashes the blob header plus exact contents.
        if ($this->gitBlobSha1($snapshot) !== self::PINNED_GIT_BLOB_SHA1) {
            return ['matched' => false, 'reason' => 'portal_snapshot_not_pinned'];
        }
        if (!hash_equals($snapshot, $upstream)) {
            return ['matched' => false, 'reason' => 'upstream_schema_drift'];
        }
        return ['matched' => true, 'reason' => 'pinned_schema_match_only'];
    }

    private function readBoundedFile(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $size = filesize($path);
        if (!is_int($size) || $size === 0 || $size > 65536) {
            return null;
        }
        $data = file_get_contents($path);
        return is_string($data) && strlen($data) === $size ? $data : null;
    }

    private function gitBlobSha1(string $content): string
    {
        return sha1('blob ' . strlen($content) . "\0" . $content);
    }

    public static function authorizesIntegration(): bool { return false; }
    public static function verifiesRemoteOrigin(): bool { return false; }
}
