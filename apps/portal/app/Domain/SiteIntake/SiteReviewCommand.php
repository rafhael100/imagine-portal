<?php

declare(strict_types=1);

namespace App\Domain\SiteIntake;

use App\Domain\Organizations\OrganizationId;

/**
 * F43 review command: all attributes are caller-provided LAB inputs.
 * An actor string is NOT authentication, membership or consent evidence.
 */
final readonly class SiteReviewCommand
{
    public function __construct(
        public string $commandId,
        public OrganizationId $organization,
        public string $siteReference,
        public string $contentFingerprint,
        public string $actor,
        public int $expectedReviewRevision,
        public SiteReviewDecision $decision,
        public int $occurredAt,
    ) {
        if (preg_match('/\ADEMO-REVIEW-CMD-[A-Z0-9-]{1,48}\z/D',$commandId)!==1
            || preg_match('/\ADEMO-SITE-REQ-[A-Z0-9-]{1,48}\z/D',$siteReference)!==1
            || preg_match('/\A[a-f0-9]{64}\z/D',$contentFingerprint)!==1
            || !in_array($actor,['DEMO-REVIEWER-A','DEMO-REVIEWER-B'],true)
            || $expectedReviewRevision<0 || $expectedReviewRevision>100000
            || $occurredAt<1800000000 || $occurredAt>1800003600) {
            throw new \InvalidArgumentException('Invalid synthetic Sites review command.');
        }
    }

    public function fingerprint(): string
    {
        return hash('sha256',json_encode([
            'command'=>$this->commandId,
            'organization'=>$this->organization->value,
            'reference'=>$this->siteReference,
            'content'=>$this->contentFingerprint,
            'actor'=>$this->actor,
            'revision'=>$this->expectedReviewRevision,
            'decision'=>$this->decision->value,
            'occurred_at'=>$this->occurredAt,
        ],JSON_THROW_ON_ERROR));
    }
}
