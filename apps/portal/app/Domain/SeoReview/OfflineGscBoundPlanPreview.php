<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

/**
 * Synthetic, CLI-only reader for SEO GSC bound-plan v1 (F40/F42).
 * Never authenticates Google, grants a tenant, dispatches HTTP or publishes.
 */
final readonly class OfflineGscBoundPlanPreview
{
    private function __construct(
        public string $tenantRef,
        public string $propertyFingerprint,
        public bool $exactPropertyListed,
        public bool $declaredReadPermissionEligible,
    ) {}

    private static function keys(mixed $value, array $expected): bool
    {
        if (!is_array($value) || array_is_list($value)) return false;
        $keys=array_keys($value);
        sort($keys);
        sort($expected);
        return $keys===$expected;
    }

    public static function fromDto(mixed $dto): ?self
    {
        if (PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||
            getenv('PORTAL_SEO_GSC_LAB')!=='1') return null;
        if (!self::keys($dto,[
            'contractVersion','tenantRef','propertyFingerprint',
            'exactPropertyListed','declaredReadPermissionEligible',
            'scopeIntegrityCheckedOffline','oauthVerified','tenantGrantVerified',
            'productionReadAuthorized','networkPerformed','authorization',
        ])) return null;
        // Pure synthetic scope, never a customer tenant or real GSC property.
        if ($dto['contractVersion']!=='seo-gsc-offline-bound-plan-v1'||
            $dto['tenantRef']!=='imagine-demo'||
            $dto['propertyFingerprint']!==str_repeat('a',64)||
            !is_bool($dto['exactPropertyListed'])||
            !is_bool($dto['declaredReadPermissionEligible'])||
            ($dto['declaredReadPermissionEligible'] && !$dto['exactPropertyListed'])||
            $dto['scopeIntegrityCheckedOffline']!==true||
            $dto['oauthVerified']!==false||
            $dto['tenantGrantVerified']!==false||
            $dto['productionReadAuthorized']!==false||
            $dto['networkPerformed']!==false||
            $dto['authorization']!=='HOLD_EXTERNAL_BACKEND_VERIFICATION')
            return null;
        return new self(
            $dto['tenantRef'],$dto['propertyFingerprint'],
            $dto['exactPropertyListed'],$dto['declaredReadPermissionEligible'],
        );
    }

    public function canReadGoogle(): bool {return false;}
    public function hasVerifiedTenantGrant(): bool {return false;}
    public function canDisplayToCustomers(): bool {return false;}
    public function canPublish(): bool {return false;}
    public function canDispatch(): bool {return false;}
}
