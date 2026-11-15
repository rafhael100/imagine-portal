<?php
declare(strict_types=1);

namespace App\Labs\PerformanceV7Pg18;

use App\Domain\Performance\PortalPerformanceV7AgreementPlanLabIssuer;
use InvalidArgumentException;
use RuntimeException;

/**
 * CI-only V7 bridge: create an in-memory signed review, derive a STABLE
 * commercial fingerprint and return it ONLY if independently seeded SQL
 * Portal session+MFA+services.manage+agreement authority passes.
 *
 * Does not bridge actual Portal browser login or accepted legal contract.
 * No usable external capability, HTTP endpoint, charge, Ads or CRM write.
 */
final class PortalV7SqlGatedSignedPlanReviewLab
{
    private const PLAN_DOMAIN="IMAGINE-V7-ACCEPTANCE-STABLE-PLAN-V1\n";

    public function __construct(private readonly PortalV7SqlAgreementAuthorityLab $authority) {}

    /** @return array<string,mixed> */
    public function prepare(
        #[\SensitiveParameter] string $opaqueSession,
        string $expectedTenant,
        string $expectedAgreement,
        string $expectedTermsSha256,
        int $expectedApprovalRevision,
        array $activation,
        array $originalReview,
        array $eligibleChannels,
        array $scope,
        array $plannedByChannelCents,
        string $nowUtc,
        string $keyId,
        string $nonce,
        int $issuedAt,
        int $expiresAt,
        #[\SensitiveParameter] string $activationKey,
        #[\SensitiveParameter] string $planKey
    ): array {
        if (PHP_SAPI!=='cli'
            || getenv('APP_ENV')!=='testing'
            || getenv('PORTAL_PERF_V7_PG18_IDENTITY_LAB')!=='1'
            || !preg_match('/\A[a-f0-9]{64}\z/D', $expectedTermsSha256)
            || !is_string($scope['tenant_id']??null)
            || !hash_equals($expectedTenant,$scope['tenant_id'])
            || !is_string($scope['agreement_id']??null)
            || !hash_equals($expectedAgreement,$scope['agreement_id'])
            || !is_string($scope['period']??null)) {
            throw new RuntimeException('Portal V7 real-database-shaped preflight is disabled.');
        }
        // The lower-level signer is intentionally kept NO-GO; it signs a
        // review, not an executable order. It is not an authenticated issuer.
        $signed=PortalPerformanceV7AgreementPlanLabIssuer::issue(
            $activation,$originalReview,$eligibleChannels,$scope,
            $plannedByChannelCents,$nowUtc,$keyId,$nonce,
            $issuedAt,$expiresAt,$activationKey,$planKey
        );
        $payload=$signed['payload'];
        // Acceptance covers the stable commercial facts, not short HMAC TTL,
        // rotating key ID or one-use nonce. Performance uses EXACT same
        // canonical hash domain; tests pin parity across repos.
        unset($payload['nonce'],$payload['issued_at'],
            $payload['expires_at'],$payload['key_id']);
        $fingerprint=hash('sha256',self::PLAN_DOMAIN.json_encode(
            self::canonical($payload),
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE
        ));
        if (!$this->authority->permits(
            $opaqueSession,$expectedTenant,$expectedAgreement,
            $scope['period'],$fingerprint,$expectedTermsSha256,
            $expectedApprovalRevision
        )) {
            throw new RuntimeException('Portal V7 SQL session/consent review denied.');
        }
        return [
            'signed_plan_review'=>$signed,
            'stable_plan_sha256'=>$fingerprint,
            'synthetic_pg18_session_mfa_agreement_checked'=>true,
            'state'=>'portal_v7_sql_verified_ci_review_only',
            'portal_iam_authenticated'=>false,
            'real_customer_acceptance_proven'=>false,
            'real_customer_consent_proven'=>false,
            'billable'=>false,
            'can_authorize_http'=>false,
            'can_charge'=>false,
            'can_activate'=>false,
            'ads_write_enabled'=>false,
            'crm_write_enabled'=>false,
        ];
    }

    /** @param array<string,mixed> $map @return array<string,mixed> */
    private static function canonical(array $map):array
    {
        if(array_is_list($map)) {
            return array_map(static fn(mixed $value):mixed=>
                is_array($value)?self::canonical($value):$value,$map);
        }
        ksort($map,SORT_STRING);
        foreach($map as $name=>$value) {
            if(!is_string($name))throw new InvalidArgumentException('Invalid contract fingerprint key.');
            if(is_array($value))$map[$name]=self::canonical($value);
            elseif(!is_int($value)&&!is_string($value)&&!is_bool($value)) {
                throw new InvalidArgumentException('Unexpected commercial fingerprint field.');
            }
        }
        return $map;
    }
}
