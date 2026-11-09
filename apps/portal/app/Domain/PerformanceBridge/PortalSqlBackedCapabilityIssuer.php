<?php

declare(strict_types=1);

namespace App\Domain\PerformanceBridge;

use JsonException;
use SodiumException;

/**
 * PORTAL-AUTH-PERF-09 — source of claim values is the F08 PostgreSQL reader,
 * not a caller-provided actor, org, tenant, role, session or grant.
 *
 * IMPORTANT: The database contains only synthetic lab fixtures and
 * 'active_mock' contracts. A cryptographic signature does not turn a test
 * identity into an authenticated client. Not wired into routes/providers.
 */
final class PortalSqlBackedCapabilityIssuer
{
    public function __construct(private readonly PortalPerfSqlAuthorityResolver $source)
    {
    }

    public function issue(
        string $opaqueSessionBearer,
        string $requestedService,
        string $privateKey,
        int $nowEpoch,
    ): ?string {
        if (PHP_SAPI!=='cli'
            || getenv('APP_ENV')!=='testing'
            || getenv('PORTAL_PERF_SQL_ISSUER_LAB')!=='1'
            || getenv('PORTAL_PERF_SIGNED_LAB')!=='1'
            || getenv('PORTAL_PERF_PG_AUTH_LAB')!=='1'
            || !extension_loaded('sodium')
            || strlen($privateKey)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
            || $nowEpoch<=0 || $nowEpoch>2147483627
            || $requestedService!=='svc-demo-a') {
            return null;
        }

        $facts=$this->source->resolve(
            $opaqueSessionBearer,$requestedService,$nowEpoch
        );
        if ($facts===null
            || $facts->sourceObservedAt!==$nowEpoch
            || $facts->organizationRef!=='org-demo-a'
            || $facts->tenantRef!=='perf-demo-a'
            || $facts->serviceRef!=='svc-demo-a'
            || $facts->actorRef!=='actor-demo-a'
            || $facts->sessionRef!=='session-demo-a'
            || $facts->permission!=='campaigns:read'
            || $facts->membershipRevision<1
            || $facts->serviceRevision<1
            || $facts->sessionRevision<1
            || $facts->recoveryEpoch<1) {
            return null;
        }

        $claims=[
            'version'=>1,
            'issuer'=>SyntheticCapabilityIssuer::ISSUER,
            'audience'=>SyntheticCapabilityIssuer::AUDIENCE,
            'profile'=>SyntheticCapabilityIssuer::PROFILE,
            'organization_ref'=>$facts->organizationRef,
            'performance_tenant_ref'=>$facts->tenantRef,
            'portal_service_ref'=>$facts->serviceRef,
            // Campaign ownership has NOT been proven by this F08 schema.
            // Resource remains pinned to a harmless DEMO preview item.
            'resource_ref'=>'demo-cam-a1',
            'actor_ref'=>$facts->actorRef,
            'session_ref'=>$facts->sessionRef,
            'permission'=>$facts->permission,
            'session_mfa_verified'=>true, // established only by mock MFA row
            'membership_revision'=>$facts->membershipRevision,
            'service_revision'=>$facts->serviceRevision,
            'session_revision'=>$facts->sessionRevision,
            'recovery_epoch'=>$facts->recoveryEpoch,
            'service_state'=>'active_mock',
            'issued_at'=>$nowEpoch,
            'expires_at'=>$nowEpoch+20,
            'nonce'=>bin2hex(random_bytes(16)),
        ];
        try{
            $json=json_encode(
                $claims,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR
            );
            $signature=sodium_crypto_sign_detached(
                SyntheticCapabilityIssuer::DOMAIN_SEPARATOR.$json,
                $privateKey
            );
            return json_encode([
                'version'=>'portal-capability-envelope.v1',
                'key_id'=>SyntheticCapabilityIssuer::KEY_ID,
                'payload'=>self::b64url($json),
                'signature'=>self::b64url($signature),
            ],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        }catch(JsonException|SodiumException){
            return null;
        }
    }

    private static function b64url(string $bytes):string
    {
        return rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');
    }
}
