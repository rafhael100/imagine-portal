<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

/**
 * Read-only DTO bridge for SEO Engine F50. A lab fixture is not an identity,
 * OAuth, Google property grant, consent record, or an operational data source.
 * No provider I/O, token storage, service binding, route, or DB access.
 */
final readonly class OfflineGscWorkflowPreview
{
    private function __construct(
        public string $status,
        public ?array $summary,
        public ?array $pagination,
    ) {}

    private static function shape(mixed $value, array $expected): bool
    {
        if (!is_array($value)) return false;
        $found=array_keys($value);
        sort($found);
        sort($expected);
        return $found===$expected;
    }

    private static function countValue(mixed $value, int $max): bool
    {
        return is_int($value) && $value>=0 && $value<=$max;
    }

    public static function fromDto(mixed $dto): ?self
    {
        // Deliberately impossible to activate on a web request or in production.
        if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing'
            || getenv('PORTAL_GSC_WORKFLOW_LAB')!=='1') return null;

        if (!self::shape($dto,[
            'contractVersion','tenantRef','propertyFingerprint','sourceType',
            'status','pagination','summary','oauthVerified',
            'backendTenantGrantVerified','propertyConsentVerified',
            'customerDisplayAuthorized','productionReadAuthorized',
            'networkPerformed','releaseStatus',
        ]) || $dto['contractVersion']!=='seo-gsc-offline-workflow-v1'
            || $dto['sourceType']!=='operator_supplied_not_verified'
            || $dto['tenantRef']!=='imagine-demo'
            || $dto['propertyFingerprint']!==hash('sha256','sc-domain:example.com')
            || $dto['releaseStatus']!=='HOLD_EXTERNAL_BACKEND_VERIFICATION') return null;

        foreach ([
            'oauthVerified','backendTenantGrantVerified','propertyConsentVerified',
            'customerDisplayAuthorized','productionReadAuthorized','networkPerformed',
        ] as $flag) {
            if ($dto[$flag]!==false) return null;
        }

        $status=$dto['status'];
        if ($status==='DECLARED_PROPERTY_NOT_ELIGIBLE') {
            if ($dto['pagination']!==null || $dto['summary']!==null) return null;
            return new self($status,null,null);
        }
        if ($status!=='OFFLINE_REVIEW_ONLY') return null;

        $pagination=$dto['pagination'];
        if (!self::shape($pagination,[
            'pagesReceived','rowsReceived','mayHaveMore','sourceMayBeTruncated',
        ]) || !self::countValue($pagination['pagesReceived'],4)
            || $pagination['pagesReceived']<1
            || !self::countValue($pagination['rowsReceived'],50000)
            || !is_bool($pagination['mayHaveMore'])
            || $pagination['sourceMayBeTruncated']!==true) return null;

        $summary=$dto['summary'];
        if (!self::shape($summary,[
            'inputRows','redactedRows','analyzedRows','clicks','impressions',
        ]) || !self::countValue($summary['inputRows'],50000)
            || !self::countValue($summary['redactedRows'],50000)
            || !self::countValue($summary['analyzedRows'],50000)
            || !self::countValue($summary['clicks'],1000000000)
            || !self::countValue($summary['impressions'],1000000000)
            || $summary['clicks']>$summary['impressions']
            || $summary['redactedRows']+$summary['analyzedRows']!==$summary['inputRows']
            || $pagination['rowsReceived']!==$summary['inputRows']) return null;

        // A caller-controlled but well-shaped report remains unauthenticated.
        return new self($status,$summary,$pagination);
    }

    public function isCustomerDisplayAuthorized(): bool { return false; }
    public function canQueryGoogle(): bool { return false; }
    public function hasVerifiedTenantGrant(): bool { return false; }
    public function canStoreTokens(): bool { return false; }
    public function canPublish(): bool { return false; }
}
