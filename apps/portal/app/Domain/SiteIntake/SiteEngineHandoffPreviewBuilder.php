<?php

declare(strict_types=1);

namespace App\Domain\SiteIntake;

/**
 * F48: validates the internal demo editorial event, then prepares only
 * canonical metadata for a future cross-repository contract discussion.
 *
 * The source is in-memory and caller-controlled. No HTTP, queue, key,
 * actual identity, approved publishing artifact, integration or delivery.
 */
final class SiteEngineHandoffPreviewBuilder
{
    public function prepare(
        SiteContentCandidate $candidate,
        DemoSiteReviewJournal $journal,
    ): SiteEngineHandoffPreviewResult {
        if ($journal->candidate->fingerprint!==$candidate->fingerprint
            || !$journal->candidate->brief->organizationId->equals(
                $candidate->brief->organizationId
            )
            || $journal->candidate->brief->reference!==$candidate->brief->reference
            || $journal->candidate->brief->revision!==$candidate->brief->revision
            || $journal->candidate->contentRevision!==$candidate->contentRevision) {
            return SiteEngineHandoffPreviewResult::blocked('content_mismatch');
        }

        $events=$journal->syntheticHistory();
        if ($events===[]) {
            return SiteEngineHandoffPreviewResult::blocked('no_demo_review');
        }
        if (count($events)!==1 || $journal->currentReviewRevision()!==1) {
            return SiteEngineHandoffPreviewResult::blocked('ambiguous_history');
        }
        if ($journal->currentSimulatedStatus()!=='approved_demo') {
            return SiteEngineHandoffPreviewResult::blocked('not_approved_demo');
        }
        $event=$events[0];
        if (($event['content_sha256'] ?? null)!==$candidate->fingerprint
            || ($event['reference'] ?? null)!==$candidate->brief->reference
            || ($event['organization'] ?? null)!==$candidate->brief->organizationId->value
            || ($event['brief_revision'] ?? null)!==$candidate->brief->revision
            || ($event['content_revision'] ?? null)!==$candidate->contentRevision
            || ($event['decision'] ?? null)!=='approve'
            || ($event['review_revision'] ?? null)!==1
            || !is_string($event['command'] ?? null)) {
            return SiteEngineHandoffPreviewResult::blocked('content_mismatch');
        }

        return new SiteEngineHandoffPreviewResult(
            'preview_ready',
            new SiteEngineHandoffPreviewEnvelope(
                $candidate->brief->organizationId->value,
                $candidate->brief->reference,
                $candidate->brief->product->value,
                $candidate->brief->revision,
                $candidate->contentRevision,
                $candidate->fingerprint,
                $event['command'],
                1,
            ),
        );
    }
}
