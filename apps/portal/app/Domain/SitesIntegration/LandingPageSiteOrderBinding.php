<?php
declare(strict_types=1);
namespace App\Domain\SitesIntegration;
use InvalidArgumentException;

/** Immutable custody projection, NOT principal authentication or briefing approval. */
final readonly class LandingPageSiteOrderBinding
{
    public function __construct(
        public string $tenantRef, public string $orderRef, public string $siteRef,
        public string $contractRef, public int $contractRevision, public string $productCode,
    ) {
        foreach ([$tenantRef,$orderRef,$contractRef] as $ref) {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$ref)!==1) {
                throw new InvalidArgumentException('Invalid binding reference.');
            }
        }
        if (preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D',$siteRef)!==1 ||
            $contractRevision<1 || $contractRevision>9007199254740991 ||
            $productCode!=='landing_page') {
            throw new InvalidArgumentException('Invalid Landing Page binding.');
        }
    }
}
