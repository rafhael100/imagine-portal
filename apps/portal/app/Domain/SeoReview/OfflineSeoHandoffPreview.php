<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

/**
 * Test-only consumer for the SEO Engine handoff v1.
 * Cannot authenticate, authorize, publish or create HTTP routes.
 */
final readonly class OfflineSeoHandoffPreview
{
    private const ACTIONS = [
        'review_indexation','repair_canonical','fix_sitemap','review_robots',
        'repair_internal_links','improve_internal_navigation',
        'review_page_metadata','review_page_structure','review_structured_data',
        'review_other_technical_findings','review_impression_drop',
        'review_click_drop','review_ctr_drop','review_average_position',
        'review_new_search_theme',
    ];
    private const REQUIREMENTS = [
        'A trusted Portal backend must independently authenticate the user and verify the tenant grant.',
        'Google Search Console property access and consent must be proven before customer display.',
        'This offline envelope is neither a bearer token nor evidence of a verified data source.',
    ];

    private function __construct(
        public string $tenantRef,
        public array $technical,
        public ?array $organic,
        public array $suggestedReviews,
    ) {}

    private static function keys(mixed $value, array $expected): bool
    {
        if (!is_array($value) || array_is_list($value)) return false;
        $keys=array_keys($value);
        sort($keys); sort($expected);
        return $keys===$expected;
    }

    private static function bounded(mixed $v, int $max=1000000): bool
    {
        return is_int($v) && $v>=0 && $v<=$max;
    }

    private static function metric(mixed $raw): bool
    {
        return self::keys($raw,['clicks','impressions'])
            && self::bounded($raw['clicks']) && self::bounded($raw['impressions'])
            && $raw['clicks']<=$raw['impressions'];
    }

    private static function delta(int $before,int $after): ?float
    {
        return $before>0 ? round(($after-$before)/$before,3) : null;
    }

    public static function fromDto(mixed $dto): ?self
    {
        if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing'
            || getenv('PORTAL_SEO_HANDOFF_LAB')!=='1') return null;
        if (!self::keys($dto,[
            'contractVersion','origin','scope','releaseStatus',
            'customerDisplayAuthorized','propertyAuthorizationVerified',
            'backendTenantGrantVerified','publicationAuthorized',
            'technical','organic','suggestedReviews','requirements',
        ]) || $dto['contractVersion']!=='seo-portal-review-handoff-v1'
            || $dto['origin']!=='offline_unverified_not_for_customer_display'
            || $dto['releaseStatus']!=='HOLD_EXTERNAL_AUTHORIZATION'
            || $dto['customerDisplayAuthorized']!==false
            || $dto['propertyAuthorizationVerified']!==false
            || $dto['backendTenantGrantVerified']!==false
            || $dto['publicationAuthorized']!==false
            || $dto['requirements']!==self::REQUIREMENTS) return null;
        $scope=$dto['scope'];
        // This bridge deliberately accepts ONLY a wholly artificial fixture.
        if (!self::keys($scope,['tenantRef','propertyFingerprint','sourceRevision'])
            || $scope['tenantRef']!=='imagine-demo'
            || $scope['propertyFingerprint']!==str_repeat('a',64)
            || $scope['sourceRevision']!==str_repeat('b',40)) return null;
        $technical=$dto['technical'];
        if (!self::keys($technical,['status','pages','errors','warnings'])
            || !self::bounded($technical['pages'],20000)
            || !self::bounded($technical['errors'])
            || !self::bounded($technical['warnings'])) return null;
        $status=$technical['errors']>0?'review_required':
            ($technical['warnings']>0?'attention':'no_findings_in_supplied_audit');
        if ($technical['status']!==$status) return null;
        $organic=$dto['organic'];
        if ($organic!==null) {
            if (!self::keys($organic,[
                'days','previous','current','clicksChangeFraction','impressionsChangeFraction',
            ]) || !self::bounded($organic['days'],92) || $organic['days']<1
                || !self::metric($organic['previous'])
                || !self::metric($organic['current'])
                || $organic['clicksChangeFraction']!==self::delta(
                    $organic['previous']['clicks'],$organic['current']['clicks'])
                || $organic['impressionsChangeFraction']!==self::delta(
                    $organic['previous']['impressions'],$organic['current']['impressions'])
            ) return null;
        }
        $reviews=$dto['suggestedReviews'];
        if (!is_array($reviews) || !array_is_list($reviews) || count($reviews)>32)
            return null;
        $seen=[];
        foreach($reviews as $item){
            if (!self::keys($item,['code','priority','count'])
                || !is_string($item['code'])
                || !in_array($item['code'],self::ACTIONS,true)
                || !in_array($item['priority'],['critical','high','medium','low'],true)
                || !self::bounded($item['count']) || $item['count']<1
                || isset($seen[$item['code']])) return null;
            $seen[$item['code']]=true;
        }
        return new self($scope['tenantRef'],$technical,$organic,$reviews);
    }

    public function isCustomerDisplayAuthorized(): bool {return false;}
    public function hasVerifiedTenantGrant(): bool {return false;}
    public function hasVerifiedGoogleProperty(): bool {return false;}
    public function canPublish(): bool {return false;}
    public function canDispatch(): bool {return false;}
}
