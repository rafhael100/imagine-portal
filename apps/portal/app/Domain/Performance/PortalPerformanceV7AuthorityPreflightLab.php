<?php
declare(strict_types=1);

namespace App\Domain\Performance;

use App\Domain\Authorization\Permission;
use App\Domain\Organizations\OrganizationId;
use App\Domain\PortalAccess\Authority\PortalAuthorityBoundary;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/**
 * CI-only sequence: a Portal business-session/membership/resource boundary
 * must be a SYNTHETIC candidate for services.manage BEFORE issuing the
 * signed V7 plan review. All resolver interfaces can be faked; neither a
 * positive candidate nor this HMAC proves production IAM or customer assent.
 *
 * No route/controller/real session, payment or provider calls are enabled.
 */
final class PortalPerformanceV7AuthorityPreflightLab
{
    /**
     * @param array<string,mixed> $originalReview
     * @param array<string,mixed> $activationEnvelope
     * @param array<string,bool> $eligibility
     * @param array<string,mixed> $scope server-owned expected plan context
     * @param array<string,int> $mediaPlan
     * @return array<string,mixed>
     */
    public static function review(
        PortalAuthorityBoundary $authority,
        ?string $sessionReference,
        OrganizationId $selectedOrganization,
        string $expectedTenantId,
        string $expectedAgreementId,
        array $activationEnvelope,
        array $originalReview,
        array $eligibility,
        array $scope,
        array $mediaPlan,
        string $nowUtc,
        string $keyId,
        string $nonce,
        int $issuedAt,
        int $expiresAt,
        #[\SensitiveParameter] string $activationKey,
        #[\SensitiveParameter] string $planKey
    ):array {
        if(getenv('APP_ENV')!=='testing' ||
           getenv('PORTAL_PERF_V7_PLAN_AGREEMENT_LAB')!=='1' ||
           getenv('PORTAL_PERF_V7_AUTHORITY_PREFLIGHT_LAB')!=='1') {
            throw new RuntimeException('Portal V7 authority preflight disabled.');
        }
        $time=DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z',$nowUtc,new DateTimeZone('UTC')
        );
        if(!$time || $time->format('Y-m-d\TH:i:s\Z')!==$nowUtc ||
           !is_string($scope['organization_ref']??null) ||
           !hash_equals($selectedOrganization->value,$scope['organization_ref']) ||
           !is_string($scope['tenant_id']??null) ||
           !hash_equals($expectedTenantId,$scope['tenant_id']) ||
           !is_string($scope['agreement_id']??null) ||
           !hash_equals($expectedAgreementId,$scope['agreement_id'])) {
            throw new InvalidArgumentException('Independent Portal V7 organization and agreement scope mismatch.');
        }
        $verdict=$authority->inspect(
            $sessionReference,
            $selectedOrganization,
            $expectedAgreementId,
            Permission::ManageServices->value,
            $time->getTimestamp()
        );
        // This is solely a CI canary; canAuthorizeHttp() must ALWAYS be false.
        if(!$verdict->syntheticChecksPass || $verdict->canAuthorizeHttp()) {
            throw new RuntimeException('Portal V7 simulated business authority denied.');
        }
        $review=PortalPerformanceV7AgreementPlanLabIssuer::issue(
            $activationEnvelope,$originalReview,$eligibility,
            $scope,$mediaPlan,$nowUtc,$keyId,$nonce,
            $issuedAt,$expiresAt,$activationKey,$planKey
        );
        return [
            'signed_plan_review'=>$review,
            'state'=>'portal_v7_business_session_synthetic_candidate_review_only',
            'synthetic_business_session_and_membership_checked'=>true,
            'real_iam_authenticated'=>false,
            'real_customer_acceptance_proven'=>false,
            'customer_consent_verified'=>false,
            'finance_approved'=>false,
            'billable'=>false,
            'can_charge'=>false,
            'can_activate'=>false,
            'ads_write_enabled'=>false,
            'crm_write_enabled'=>false,
        ];
    }
}
