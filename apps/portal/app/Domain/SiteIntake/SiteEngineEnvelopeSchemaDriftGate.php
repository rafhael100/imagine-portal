<?php

declare(strict_types=1);

namespace App\Domain\SiteIntake;

/**
 * F311: offline, byte-exact review of an Imagine Sites signed-envelope
 * CANDIDATE schema. No signature verification, key management, replay fence,
 * resource ownership proof, request dispatch or HTTP authorization.
 */
final class SiteEngineEnvelopeSchemaDriftGate
{
    public const PINNED_GIT_BLOB_SHA1 = 'c02af853186fde48f76a6c8a1039ae10502ca953';

    /** @return array{matched:bool,reason:string} */
    public function compare(string $upstreamPath, string $portalSnapshotPath): array
    {
        $upstream = self::read($upstreamPath);
        $snapshot = self::read($portalSnapshotPath);
        if ($upstream === null || $snapshot === null) {
            return ['matched'=>false,'reason'=>'file_unavailable_or_oversize'];
        }
        if (sha1('blob '.strlen($snapshot)."\0".$snapshot) !== self::PINNED_GIT_BLOB_SHA1) {
            return ['matched'=>false,'reason'=>'portal_snapshot_not_pinned'];
        }
        if (!hash_equals($snapshot, $upstream)) {
            return ['matched'=>false,'reason'=>'upstream_envelope_schema_drift'];
        }
        return ['matched'=>true,'reason'=>'pinned_candidate_envelope_only'];
    }

    private static function read(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) return null;
        $size = filesize($path);
        if (!is_int($size) || $size < 1 || $size > 65536) return null;
        $bytes = file_get_contents($path);
        return is_string($bytes) && strlen($bytes) === $size ? $bytes : null;
    }

    public static function verifiesRemoteIssuer(): bool { return false; }
    public static function mayReceiveServiceOwnership(): bool { return false; }
    public static function authorizesSitesRequest(): bool { return false; }
}
