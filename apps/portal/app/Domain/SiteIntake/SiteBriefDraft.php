<?php

declare(strict_types=1);

namespace App\Domain\SiteIntake;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServicePortfolio\PortfolioProduct;

/**
 * F42: immutable, normalized SAMPLE Sites brief.
 * This contains NO authenticated identity, consent or payment instruction.
 * Never serialize as a real customer record or publish to an external app.
 */
final readonly class SiteBriefDraft
{
    public function __construct(
        public string $reference,
        public OrganizationId $organizationId,
        public PortfolioProduct $product,
        public string $objective,
        public string $audience,
        public string $callToAction,
        public int $revision,
    ) {
        if (preg_match('/\ADEMO-SITE-REQ-[A-Z0-9-]{1,48}\z/D',$reference)!==1
            || !in_array($organizationId->value,['org-a','org-b'],true)
            || !in_array($product,[PortfolioProduct::Onepage,PortfolioProduct::LandingPage],true)
            || $revision<0 || $revision>100000) {
            throw new \InvalidArgumentException('F42 only accepts a synthetic Sites briefing draft.');
        }
    }

    /** @return array{reference:string,product:string,objective:string,audience:string,call_to_action:string,revision:int} */
    public function illustration(): array
    {
        return [
            'reference'=>$this->reference,
            'product'=>$this->product->label(),
            'objective'=>$this->objective,
            'audience'=>$this->audience,
            'call_to_action'=>$this->callToAction,
            'revision'=>$this->revision,
        ];
    }

    public function canSubmitToPortal(): bool
    {
        return false;
    }

    public function canPublishSite(): bool
    {
        return false;
    }

    public function canChargeOrContract(): bool
    {
        return false;
    }
}
