<?php
declare(strict_types=1);

namespace App\Domain\Performance;

use InvalidArgumentException;
use RuntimeException;

/**
 * P0 V7 — signed PLAN/AGREEMENT review, CI ONLY.
 *
 * Requires the real Portal F419 signed activation/PSP review to pass its own
 * independent order/CRM verification BEFORE issuing a separate-domain HMAC
 * for tenant/agreement/period, exact channel media plan and monthly floor.
 *
 * This is deliberately NOT a real customer contract, acceptance evidence,
 * S2S identity proof, provider spend proof, invoice or executable order.
 */
final class PortalPerformanceV7AgreementPlanLabIssuer
{
    private const TYPE='imagine.portal.performance.v7-plan-agreement-review';
    private const POLICY='performance-postpaid-170-per-channel-2026-11-14';
    private const DOMAIN="IMAGINE-PORTAL-PERFORMANCE-V7-PLAN-AGREEMENT-LAB\n";
    private const MEDIA_FLOORS=[
        'chatgpt_ads'=>120000,
        'google_ads'=>100000,
        'meta_ads'=>70000,
    ];
    private const MAX_MEDIA_CENTS=100000000;

    /**
     * @param array<string,mixed> $activationEnvelope authentic F419 signed review
     * @param array<string,mixed> $originalReview known Portal PSP+CRM review
     * @param array<string,bool> $serverEligibility server-owned channel eligibility
     * @param array{tenant_id:string,agreement_id:string,period:string,organization_ref:string,order_ref:string,policy_revision:int} $scope
     * @param array<string,int> $plannedCents exact selected-channel media plan
     * @return array{payload:array<string,mixed>,mac:string}
     */
    public static function issue(
        array $activationEnvelope,
        array $originalReview,
        array $serverEligibility,
        array $scope,
        array $plannedCents,
        string $reviewNowUtc,
        string $keyId,
        string $nonce,
        int $issuedAt,
        int $expiresAt,
        #[\SensitiveParameter] string $activationReviewKey,
        #[\SensitiveParameter] string $agreementKey
    ): array {
        if (getenv('APP_ENV')!=='testing' ||
            getenv('PORTAL_PERF_V7_PLAN_AGREEMENT_LAB')!=='1') {
            throw new RuntimeException('Portal V7 signed plan lab disabled.');
        }
        self::exact($scope,[
            'tenant_id','agreement_id','period','organization_ref',
            'order_ref','policy_revision',
        ]);
        if (!is_string($scope['tenant_id']) ||
            !preg_match('/\Atenant_[A-Za-z0-9_-]{8,64}\z/D',$scope['tenant_id']) ||
            !is_string($scope['agreement_id']) ||
            !preg_match('/\Aagreement_[A-Za-z0-9_-]{8,64}\z/D',$scope['agreement_id']) ||
            !is_string($scope['period']) ||
            !preg_match('/\A[0-9]{4}-(0[1-9]|1[0-2])\z/D',$scope['period']) ||
            !is_string($scope['organization_ref']) ||
            !is_string($scope['order_ref']) ||
            !is_int($scope['policy_revision']) ||
            !is_string($keyId) ||
            !preg_match('/\Ak_[A-Za-z0-9_-]{4,64}\z/D',$keyId) ||
            !is_string($nonce) ||
            !preg_match('/\A[A-Za-z0-9_-]{16,96}\z/D',$nonce) ||
            strlen($agreementKey)<32 || strlen($agreementKey)>128 ||
            strlen($activationReviewKey)<32 || strlen($activationReviewKey)>128 ||
            hash_equals($activationReviewKey,$agreementKey)) {
            throw new InvalidArgumentException('Invalid independent plan signing context.');
        }
        if (!PerformanceChannelReviewEnvelope::verify(
            $activationEnvelope,$originalReview,$serverEligibility,
            $scope['organization_ref'],$scope['order_ref'],
            $scope['policy_revision'],$reviewNowUtc,$activationReviewKey
        )) {
            throw new InvalidArgumentException('Portal F419 activation and CRM review must verify first.');
        }
        $channels=$activationEnvelope['payload']['channels'];
        if (!is_array($channels) || !array_is_list($channels) ||
            count($channels)<1 || count($channels)>3) {
            throw new InvalidArgumentException('Unknown eligible channel selection.');
        }
        foreach($channels as $channel){
            if(!is_string($channel) || !array_key_exists($channel,self::MEDIA_FLOORS) ||
               ($serverEligibility[$channel]??null)!==true) {
                throw new InvalidArgumentException('Ineligible channel cannot be bound.');
            }
        }
        $expected=$channels;
        sort($expected,SORT_STRING);
        if ($expected!==$channels || count(array_unique($channels))!==count($channels)) {
            throw new InvalidArgumentException('Uncanonical channel selection.');
        }
        if (array_keys($plannedCents)!==$expected) {
            // Caller must submit already canonical channel→plan mapping, not a
            // loose array which might silently omit a contracted channel.
            throw new InvalidArgumentException('Plan must exactly match signed channels.');
        }
        $estimated=0;
        $sum=0;
        foreach($plannedCents as $channel=>$cents){
            if(!is_int($cents) || $cents<self::MEDIA_FLOORS[$channel] ||
               $cents>self::MAX_MEDIA_CENTS) {
                throw new InvalidArgumentException('Invalid channel media plan or floor.');
            }
            $sum+=$cents;
            $estimated+=max(17000,intdiv($cents*2000+5000,10000));
        }
        $epoch=\DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z',$reviewNowUtc,new \DateTimeZone('UTC')
        );
        if (!$epoch || $epoch->format('Y-m-d\TH:i:s\Z')!==$reviewNowUtc ||
            $issuedAt<=0 || $issuedAt>$epoch->getTimestamp()+30 ||
            $issuedAt<$epoch->getTimestamp()-30 ||
            $expiresAt<=$epoch->getTimestamp() ||
            $expiresAt<=$issuedAt ||
            $expiresAt-$issuedAt>300) {
            throw new InvalidArgumentException('V7 signing time invalid or grant too long.');
        }

        $payload=[
            'version'=>'1',
            'type'=>self::TYPE,
            'issuer'=>'imagine-portal-ci',
            'audience'=>'imagine-performance-ai.v7-plan-lab',
            'tenant_id'=>$scope['tenant_id'],
            'agreement_id'=>$scope['agreement_id'],
            'billing_period'=>$scope['period'],
            'organization_ref'=>$scope['organization_ref'],
            'order_ref'=>$scope['order_ref'],
            'policy_revision'=>$scope['policy_revision'],
            'channels'=>$channels,
            'planned_media_by_channel_cents'=>$plannedCents,
            'planned_media_total_cents'=>$sum,
            'activation_cents'=>$activationEnvelope['payload']['activation_cents'],
            'activation_review_mac'=>$activationEnvelope['mac'],
            'postpaid_policy_version'=>self::POLICY,
            'management_basis_points'=>2000,
            'minimum_management_fee_per_channel_cents'=>17000,
            'estimated_management_fee_cents'=>$estimated,
            'minimum_scope'=>'per_active_channel_full_month',
            'partial_first_month_rule'=>'finance_and_contract_review_required',
            'media_paid_directly_to_platforms'=>true,
            'customer_acceptance_proven'=>false,
            'portal_iam_authenticated'=>false,
            'provider_spend_verified'=>false,
            'billable'=>false,
            'can_charge'=>false,
            'can_activate'=>false,
            'ads_write_enabled'=>false,
            'key_id'=>$keyId,
            'nonce'=>$nonce,
            'issued_at'=>$issuedAt,
            'expires_at'=>$expiresAt,
        ];
        $bytes=self::DOMAIN.json_encode(
            self::canonical($payload),
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE
        );
        if(strlen($bytes)>8192)throw new InvalidArgumentException('Oversized V7 plan review.');
        return ['payload'=>$payload,'mac'=>hash_hmac('sha256',$bytes,$agreementKey)];
    }

    /** @param array<string,mixed> $value @param list<string> $allowed */
    private static function exact(array $value,array $allowed):void
    {
        $keys=array_keys($value);
        sort($keys,SORT_STRING);
        sort($allowed,SORT_STRING);
        if($keys!==$allowed)throw new InvalidArgumentException('Unexpected V7 plan scope fields.');
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private static function canonical(array $value):array
    {
        if(array_is_list($value)) {
            return array_map(static fn(mixed $x):mixed=>
                is_array($x)?self::canonical($x):$x,$value);
        }
        ksort($value,SORT_STRING);
        foreach($value as $key=>$x){
            if(!is_string($key))throw new InvalidArgumentException('Invalid plan key.');
            if(is_array($x))$value[$key]=self::canonical($x);
            elseif(!is_int($x)&&!is_string($x)&&!is_bool($x)) {
                throw new InvalidArgumentException('Unsupported V7 plan value.');
            }
        }
        return $value;
    }
}
