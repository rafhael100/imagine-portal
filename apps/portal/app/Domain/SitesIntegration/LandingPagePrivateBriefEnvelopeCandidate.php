<?php
declare(strict_types=1);
namespace App\Domain\SitesIntegration;

use RuntimeException;
use Throwable;

/**
 * Explicit private *candidate* transport envelope for an already-read head.
 * It does NOT authenticate a caller, certify source approval or configure HTTP.
 * Only a future authenticated, authorized private endpoint may call this encoder.
 */
final class LandingPagePrivateBriefEnvelopeCandidate
{
    public static function encode(ApprovedLandingPageBriefHead $head): string
    {
        try {
            // Order and field names are pinned for the independent Sites decoder.
            $value = [
                'schemaVersion' => 'portal.landing-approved-brief.handoff.candidate.v1',
                'tenantRef' => $head->tenantRef,
                'orderRef' => $head->orderRef,
                'siteRef' => $head->siteRef,
                'contractRef' => $head->contractRef,
                'contractRevision' => $head->contractRevision,
                'productCode' => 'landing_page',
                'sourceVersion' => $head->sourceVersion,
                'sourceSha256' => $head->sourceSha256,
                'approvalRef' => $head->approvalRef,
                'approvalEpoch' => $head->approvalEpoch,
                'sourceBytes' => $head->sourceBytes(),
            ];
            $bytes = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 400000) throw new RuntimeException();
            return $bytes;
        } catch (Throwable) {
            throw new RuntimeException('PORTAL_LANDING_PRIVATE_BRIEF_ENVELOPE_DENIED');
        }
    }
}
