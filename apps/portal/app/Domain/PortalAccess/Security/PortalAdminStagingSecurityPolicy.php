<?php
declare(strict_types=1);

namespace App\Domain\PortalAccess\Security;

/**
 * Fail-closed STATIC configuration preflight, not proof of live TLS, DB
 * restoration, MFA issuer, tenant identity, AppSec or deployment approval.
 */
final class PortalAdminStagingSecurityPolicy
{
    public static function issues(string $environment):array
    {
        $issues=[];
        if($environment!=='staging')
            $issues[]='STAGING_ENV_REQUIRED';

        if(config('portal_admin.enabled')!==true)
            $issues[]='ADMIN_FEATURE_NOT_ENABLED_FOR_STAGING_REVIEW';
        if(config('app.debug')!==false)
            $issues[]='APP_DEBUG_MUST_BE_FALSE';

        $url=config('app.url');
        if(!is_string($url) ||
           filter_var($url,FILTER_VALIDATE_URL)===false ||
           parse_url($url,PHP_URL_SCHEME)!=='https' ||
           !is_string(parse_url($url,PHP_URL_HOST)))
            $issues[]='HTTPS_APP_URL_REQUIRED';

        $key=config('app.key');
        if(!is_string($key) ||
           !str_starts_with($key,'base64:') ||
           strlen($key)<50)
            $issues[]='ENCRYPTION_KEY_REQUIRED';

        if(config('database.default')!=='pgsql')
            $issues[]='PORTAL_POSTGRES_REQUIRED';
        if(config('cache.default')!=='database')
            $issues[]='SHARED_RATE_LIMIT_DATABASE_CACHE_REQUIRED';
        if(config('session.driver')!=='database')
            $issues[]='PERSISTENT_DATABASE_SESSION_REQUIRED';
        if(config('session.encrypt')!==true)
            $issues[]='ENCRYPTED_SESSION_PAYLOAD_REQUIRED';
        if(config('session.secure')!==true)
            $issues[]='HTTPS_SECURE_SESSION_COOKIE_REQUIRED';
        if(config('session.http_only')!==true)
            $issues[]='HTTP_ONLY_COOKIE_REQUIRED';
        if(!in_array(config('session.same_site'),['strict','lax'],true))
            $issues[]='SAMESITE_LAX_OR_STRICT_REQUIRED';

        return $issues;
    }

    public static function authorizesCustomerDataAccess():bool{return false;}
    public static function authorizesProductionDeployment():bool{return false;}
}
