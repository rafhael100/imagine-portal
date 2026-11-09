<?php

declare(strict_types=1);

namespace App\Domain\SiteIntake;

use InvalidArgumentException;

/**
 * F48: a canonical METADATA-ONLY bridge contract for Sites Engine design.
 *
 * This is neither an externally transmitted message nor an operational
 * approval. Never treat these caller-supplied DEMO values as authenticated
 * identity, signatures, privacy validation, or permission to publish.
 */
final readonly class SiteEngineHandoffPreviewEnvelope
{
    public function __construct(
        public string $organization,
        public string $siteReference,
        public string $product,
        public int $briefRevision,
        public int $contentRevision,
        public string $contentSha256,
        public string $reviewCommandReference,
        public int $reviewRevision,
    ) {
        if (!in_array($organization,['org-a','org-b'],true)
            || preg_match('/\ADEMO-SITE-REQ-[A-Z0-9-]{1,48}\z/D',$siteReference)!==1
            || !in_array($product,['sites_onepage','sites_landing_page'],true)
            || $briefRevision<0 || $briefRevision>100000
            || $contentRevision<1 || $contentRevision>100000
            || preg_match('/\A[a-f0-9]{64}\z/D',$contentSha256)!==1
            || preg_match('/\ADEMO-REVIEW-CMD-[A-Z0-9-]{1,48}\z/D',
                $reviewCommandReference)!==1
            || $reviewRevision!==1) {
            throw new InvalidArgumentException('F48 handoff only accepts immutable DEMO metadata.');
        }
    }

    /** @return array<string,string|int|bool> */
    public function fields(): array
    {
        // Fixed ordered keys define the deterministic v1 encoding. No
        // customer name, phone, email, document, artwork or text included.
        return [
            'schema'=>'imagine.portal.sites-handoff-preview.v1',
            'kind'=>'site_editorial_consistency_preview',
            'source'=>'imagine-portal',
            'target'=>'imagine-sites-engine',
            'organization_demo'=>$this->organization,
            'site_reference_demo'=>$this->siteReference,
            'product'=>$this->product,
            'brief_revision'=>$this->briefRevision,
            'content_revision'=>$this->contentRevision,
            'content_sha256_demo'=>$this->contentSha256,
            'review_command_demo'=>$this->reviewCommandReference,
            'review_revision'=>$this->reviewRevision,
            'review_status'=>'approved_demo',
            'authenticated_customer_approval'=>false,
            'external_delivery_enabled'=>false,
            'publishing_enabled'=>false,
        ];
    }

    public function canonicalJson(): string
    {
        return json_encode(
            $this->fields(),
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
        );
    }

    public function fingerprint(): string
    {
        return hash('sha256',$this->canonicalJson());
    }

    public function maySendToSitesEngine(): bool
    {
        return false;
    }

    public function mayPublishSite(): bool
    {
        return false;
    }

    public function isVerifiedClientConsent(): bool
    {
        return false;
    }
}
