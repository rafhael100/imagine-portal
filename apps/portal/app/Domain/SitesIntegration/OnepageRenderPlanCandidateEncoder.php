<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use RuntimeException;
use stdClass;
use Throwable;

/**
 * Pure Portal-side plan composer for the Sites Onepage *candidate* renderer.
 *
 * The editorial fields MUST be supplied separately. Nothing derives approved
 * marketing copy from a client's rich briefing, calls an AI or treats an
 * expected SHA-256 as proof that the HTML was rendered by Sites.
 */
final class OnepageRenderPlanCandidateEncoder
{
    private const MAX_BYTES = 32768;
    private const TEXT_LIMITS = [
        'brand' => 120,
        'headline' => 180,
        'description' => 600,
        'callToAction' => 80,
    ];

    /**
     * @param array<string,mixed> $snapshotPair Only a candidate exporter result,
     *        not official authorization.
     * @param array{operationId:string,idempotencyKey:string,releaseRef:string,
     *        renderInputRef:string} $selection
     * @param array{sha256:string,sizeBytes:int} $expectedArtifact
     * @return array{planBytes:string,editorialBytes:string,planSha256:string,
     *       editorialSha256:string,authorityVerified:false,clientAccepted:false,
     *       canPublish:false,canAcceptRealOrders:false}
     */
    public static function build(
        array $snapshotPair,
        string $editorialBytes,
        array $selection,
        array $expectedArtifact,
    ): array {
        try {
            self::keys($snapshotPair, [
                'manifestBytes', 'sourceBytes', 'manifestSha256',
                'payloadSha256', 'authorityVerified', 'clientAccepted',
                'canPublish', 'canAcceptRealOrders',
            ]);
            foreach (['authorityVerified', 'clientAccepted', 'canPublish', 'canAcceptRealOrders'] as $key) {
                if ($snapshotPair[$key] !== false) throw new RuntimeException();
            }
            $manifestBytes = $snapshotPair['manifestBytes'];
            $sourceBytes = $snapshotPair['sourceBytes'];
            if (!is_string($manifestBytes) || !is_string($sourceBytes) ||
                strlen($manifestBytes) < 1 || strlen($manifestBytes) > 32768 ||
                strlen($sourceBytes) < 1 || strlen($sourceBytes) > 150000 ||
                !is_string($snapshotPair['manifestSha256']) ||
                !is_string($snapshotPair['payloadSha256']) ||
                !self::digest($snapshotPair['manifestSha256']) ||
                !self::digest($snapshotPair['payloadSha256']) ||
                !hash_equals($snapshotPair['manifestSha256'], hash('sha256', $manifestBytes)) ||
                !hash_equals($snapshotPair['payloadSha256'], hash('sha256', $sourceBytes))) {
                throw new RuntimeException();
            }
            $manifest = json_decode($manifestBytes, false, 8, JSON_THROW_ON_ERROR);
            self::rejectDuplicates($manifestBytes);
            if (!$manifest instanceof stdClass) throw new RuntimeException();
            self::keys(get_object_vars($manifest), [
                'schemaVersion', 'operationId', 'idempotencyKey', 'tenantRef',
                'siteRef', 'orderRef', 'contractRef', 'contractRevision',
                'productCode', 'sourceProfile', 'sourceVersion', 'sourceState',
                'snapshotRef', 'expectedBriefRevision', 'briefRevision',
                'payload', 'materials',
            ]);
            if ($manifest->schemaVersion !== 'sites.brief-snapshot.candidate.v0.1' ||
                $manifest->sourceProfile !== 'sites.onepage-brief-payload.candidate.v0.1' ||
                $manifest->productCode !== 'onepage' || $manifest->sourceState !== 'approved' ||
                $manifest->materials !== [] ||
                !is_string($manifest->tenantRef) || !self::reference($manifest->tenantRef) ||
                !is_string($manifest->siteRef) || !self::uuid($manifest->siteRef) ||
                !is_string($manifest->snapshotRef) || !self::uuid($manifest->snapshotRef) ||
                !is_int($manifest->briefRevision) || $manifest->briefRevision < 1 ||
                $manifest->briefRevision > 2147483647) throw new RuntimeException();
            if (!$manifest->payload instanceof stdClass) throw new RuntimeException();
            self::keys(get_object_vars($manifest->payload), [
                'payloadRef', 'mediaType', 'sizeBytes', 'sha256',
            ]);
            // Reject a re-hashed but internally inconsistent snapshot here.
            // The pinned Sites BriefSnapshotInput would deny this later; the
            // Portal candidate must not emit a plan for such an input.
            foreach (['operationId', 'idempotencyKey', 'orderRef', 'contractRef'] as $key) {
                if (!is_string($manifest->{$key}) || !self::reference($manifest->{$key})) {
                    throw new RuntimeException();
                }
            }
            if (!is_int($manifest->contractRevision) ||
                $manifest->contractRevision < 1 || $manifest->contractRevision > 2147483647 ||
                !is_int($manifest->sourceVersion) ||
                $manifest->sourceVersion < 0 || $manifest->sourceVersion > 9007199254740991 ||
                !is_int($manifest->expectedBriefRevision) ||
                $manifest->expectedBriefRevision < 0 || $manifest->expectedBriefRevision > 2147483646 ||
                $manifest->briefRevision !== $manifest->expectedBriefRevision + 1 ||
                !is_string($manifest->payload->payloadRef) ||
                !self::reference($manifest->payload->payloadRef) ||
                $manifest->payload->mediaType !== 'application/json' ||
                $manifest->payload->sizeBytes !== strlen($sourceBytes) ||
                $manifest->payload->sha256 !== $snapshotPair['payloadSha256']) {
                throw new RuntimeException();
            }
            // The pair is promised to come from our local candidate exporter,
            // not from an arbitrary re-hashed JSON object. Rebuild it with the
            // same source bytes to validate the entire rich-body profile and
            // reject non-canonical/repackaged manifests before plan generation.
            // This verifies structure ONLY; it is not origin authentication.
            $reconstructed = OnepageBriefSnapshotCandidateEncoder::build(
                new SiteContractSnapshot(
                    $manifest->tenantRef, $manifest->orderRef,
                    $manifest->contractRef, $manifest->contractRevision, 'onepage',
                ),
                [
                    'siteRef' => $manifest->siteRef,
                    'snapshotRef' => $manifest->snapshotRef,
                    'operationId' => $manifest->operationId,
                    'idempotencyKey' => $manifest->idempotencyKey,
                    'sourceVersion' => $manifest->sourceVersion,
                    'expectedBriefRevision' => $manifest->expectedBriefRevision,
                    'payloadRef' => $manifest->payload->payloadRef,
                ],
                $sourceBytes,
            );
            if (!hash_equals($manifestBytes, $reconstructed['manifestBytes'])) {
                throw new RuntimeException();
            }
            self::keys($selection, [
                'operationId', 'idempotencyKey', 'releaseRef', 'renderInputRef',
            ]);
            foreach ($selection as $ref) {
                if (!is_string($ref) || !self::reference($ref)) throw new RuntimeException();
            }
            self::keys($expectedArtifact, ['sha256', 'sizeBytes']);
            if (!is_string($expectedArtifact['sha256']) ||
                !self::digest($expectedArtifact['sha256']) ||
                !is_int($expectedArtifact['sizeBytes']) ||
                $expectedArtifact['sizeBytes'] < 1 || $expectedArtifact['sizeBytes'] > 150000) {
                throw new RuntimeException();
            }
            self::editorial($editorialBytes);
            // This descriptor selects editorial bytes but does not claim their
            // author, approval, or permission to deploy. The *Sites renderer*
            // verifies expectedArtifact against independently generated HTML.
            $plan = [
                'schemaVersion' => 'sites.brief-render-plan.candidate.v0.1',
                'operationId' => $selection['operationId'],
                'idempotencyKey' => $selection['idempotencyKey'],
                'tenantRef' => $manifest->tenantRef,
                'siteRef' => $manifest->siteRef,
                'releaseRef' => $selection['releaseRef'],
                'snapshot' => [
                    'snapshotRef' => $manifest->snapshotRef,
                    'briefRevision' => $manifest->briefRevision,
                    'manifestSha256' => $snapshotPair['manifestSha256'],
                ],
                'renderInput' => [
                    'payloadRef' => $selection['renderInputRef'],
                    'mediaType' => 'application/json',
                    'sizeBytes' => strlen($editorialBytes),
                    'sha256' => hash('sha256', $editorialBytes),
                ],
                'expectedArtifact' => [
                    'sha256' => $expectedArtifact['sha256'],
                    'sizeBytes' => $expectedArtifact['sizeBytes'],
                ],
            ];
            $planBytes = json_encode(
                $plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            )."\n";
            if (strlen($planBytes) > self::MAX_BYTES) throw new RuntimeException();
            return [
                'planBytes' => $planBytes,
                'editorialBytes' => $editorialBytes,
                'planSha256' => hash('sha256', $planBytes),
                'editorialSha256' => hash('sha256', $editorialBytes),
                'authorityVerified' => false,
                'clientAccepted' => false,
                'canPublish' => false,
                'canAcceptRealOrders' => false,
            ];
        } catch (Throwable) {
            // Never echo client content, plan selectors or internal parser causes.
            throw new RuntimeException('ONEPAGE_RENDER_PLAN_CANDIDATE_DENIED');
        }
    }

    private static function editorial(string $raw): void
    {
        if ($raw === '' || strlen($raw) > self::MAX_BYTES || str_contains($raw, "\0") ||
            !mb_check_encoding($raw, 'UTF-8')) throw new RuntimeException();
        $brief = json_decode($raw, false, 8, JSON_THROW_ON_ERROR);
        self::rejectDuplicates($raw);
        if (!$brief instanceof stdClass) throw new RuntimeException();
        self::keys(get_object_vars($brief), [
            'productCode', 'brand', 'headline', 'description', 'callToAction', 'benefits',
        ]);
        if ($brief->productCode !== 'onepage') throw new RuntimeException();
        foreach (self::TEXT_LIMITS as $field => $limit) {
            self::text($brief->{$field}, $limit);
        }
        if (!is_array($brief->benefits) ||
            count($brief->benefits) < 1 || count($brief->benefits) > 8) throw new RuntimeException();
        foreach ($brief->benefits as $benefit) self::text($benefit, 180);
    }

    private static function text(mixed $value, int $limit): void
    {
        if (!is_string($value) || str_contains($value, "\0") ||
            !mb_check_encoding($value, 'UTF-8') ||
            mb_strlen($value, 'UTF-8') > $limit ||
            trim($value) === '' || preg_match('/\A\p{White_Space}*\z/u', $value) === 1) {
            throw new RuntimeException();
        }
    }

    private static function keys(array $fields, array $expected): void
    {
        if (count($fields) !== count($expected) ||
            array_diff($expected, array_keys($fields))) throw new RuntimeException();
    }

    private static function reference(string $v): bool
    {
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $v) === 1;
    }

    private static function digest(string $v): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/D', $v) === 1;
    }

    private static function uuid(string $v): bool
    {
        return preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D', $v) === 1;
    }

    private static function rejectDuplicates(string $json): void
    {
        $objects = [];
        $length = strlen($json);
        for ($i = 0; $i < $length; ++$i) {
            $char = $json[$i];
            if ($char === '{') $objects[] = [];
            elseif ($char === '[') $objects[] = null;
            elseif ($char === '}' || $char === ']') array_pop($objects);
            elseif ($char === '"') {
                $start = $i;
                for (++$i; $i < $length; ++$i) {
                    if ($json[$i] === '\\') { ++$i; continue; }
                    if ($json[$i] === '"') break;
                }
                $next = $i + 1;
                while ($next < $length && str_contains(" \t\r\n", $json[$next])) ++$next;
                if ($next < $length && $json[$next] === ':') {
                    $key = json_decode(substr($json, $start, $i - $start + 1), true, 2, JSON_THROW_ON_ERROR);
                    $level = count($objects) - 1;
                    if ($level < 0 || !is_array($objects[$level]) || isset($objects[$level][$key])) {
                        throw new RuntimeException();
                    }
                    $objects[$level][$key] = true;
                }
            }
        }
    }
}
