<?php
declare(strict_types=1);
namespace App\Domain\SitesIntegration;

use RuntimeException;
use Throwable;

/**
 * Composition boundary: verified peer + independent site/order custody + a
 * latest approved, unrevoked versioned briefing from a *separate* Portal source.
 * This is NOT an atomic snapshot or operational grant. No route/writer is bound.
 */
final readonly class LandingPageCurrentApprovedBriefGate
{
    public function __construct(
        private LandingPageCurrentHeadAdmissionCandidate $admission = new LandingPageCurrentHeadAdmissionCandidate(),
        private CurrentApprovedLandingPageBriefHeadSource $head = new DenyingCurrentApprovedLandingPageBriefHeadSource(),
    ) {}

    public function inspect(string $requestBytes, string $verifiedPeerServiceRef): ApprovedLandingPageBriefHead
    {
        try {
            $before = $this->admission->inspect($requestBytes, $verifiedPeerServiceRef);
            $first = $this->head->readCurrent($before['tenantRef'], $before['orderRef'], $before['siteRef']);
            if (!self::matches($first, $before)) throw new RuntimeException();
            // Recheck authority after reading content; observed revocations deny.
            // ABA or commits between reads still require a real transactional reader.
            $after = $this->admission->inspect($requestBytes, $verifiedPeerServiceRef);
            if ($after !== $before) throw new RuntimeException();
            $second = $this->head->readCurrent($after['tenantRef'], $after['orderRef'], $after['siteRef']);
            if (!self::matches($second, $after) ||
                $second != $first ||
                !hash_equals($first->sourceBytes(), $second->sourceBytes())) {
                throw new RuntimeException();
            }
            // Final custody check catches an observed revocation during the last head read.
            if ($this->admission->inspect($requestBytes, $verifiedPeerServiceRef) !== $before) {
                throw new RuntimeException();
            }
            return $second;
        } catch (Throwable) {
            throw new RuntimeException('PORTAL_LANDING_PAGE_APPROVED_BRIEF_DENIED');
        }
    }

    /** @param array<string, int|string> $admission */
    private static function matches(?ApprovedLandingPageBriefHead $head, array $admission): bool
    {
        return $head instanceof ApprovedLandingPageBriefHead &&
            $head->tenantRef === $admission['tenantRef'] &&
            $head->orderRef === $admission['orderRef'] &&
            $head->siteRef === $admission['siteRef'] &&
            $head->contractRef === $admission['contractRef'] &&
            $head->contractRevision === $admission['contractRevision'] &&
            $admission['productCode'] === 'landing_page';
    }
}
