<?php

declare(strict_types=1);

namespace App\Domain\SiteIntake;

use JsonException;

/**
 * F49: deterministic, strict "consumer" rehearsal for F48 v1 bytes.
 *
 * Only exactly the canonical JSON generated from an independently supplied
 * local DEMO candidate+journal is accepted as a MATCHING PREVIEW.
 * This is not a signature, trusted identity, replay protection, or a
 * transport adapter. No external Sites Engine is contacted.
 */
final readonly class SiteEngineHandoffDemoContractValidator
{
    private const MAX_BYTES=1024;

    public function __construct(
        private SiteEngineHandoffPreviewBuilder $builder
            = new SiteEngineHandoffPreviewBuilder(),
    ) {
    }

    public function inspect(
        string $incomingJson,
        SiteContentCandidate $expectedCandidate,
        DemoSiteReviewJournal $localReview,
    ): SiteEngineHandoffDemoValidationResult {
        if (strlen($incomingJson)>self::MAX_BYTES || $incomingJson==='') {
            return SiteEngineHandoffDemoValidationResult::denied('payload_too_large');
        }

        try {
            $decoded=json_decode(
                $incomingJson,
                true,
                8,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            return SiteEngineHandoffDemoValidationResult::denied('malformed_json');
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            return SiteEngineHandoffDemoValidationResult::denied('malformed_json');
        }

        $preview=$this->builder->prepare($expectedCandidate,$localReview);
        if (!$preview->hasPreviewEnvelope() || $preview->envelope===null) {
            return SiteEngineHandoffDemoValidationResult::denied('local_review_unavailable');
        }

        // Byte-exact canonical encoding means whitespace, reordered
        // properties, duplicate keys, unexpected fields, non-boolean
        // flags, stale SHA/revisions, wrong org/product and appended JSON
        // are rejected. PHP's normal json_decode alone would NOT detect
        // duplicate keys; the byte comparison is therefore required.
        $canonical=$preview->envelope->canonicalJson();
        if (!hash_equals($canonical,$incomingJson)
            || $decoded!==$preview->envelope->fields()) {
            return SiteEngineHandoffDemoValidationResult::denied('noncanonical_or_mismatch');
        }

        return new SiteEngineHandoffDemoValidationResult(
            'matching_demo_preview',
            $preview->envelope->fingerprint(),
        );
    }
}
