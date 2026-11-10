<?php

declare(strict_types=1);

namespace App\Labs\AuthPerfF61;

use JsonException;
use SodiumException;

/**
 * PORTAL-AUTH-PERF-07: Ed25519 cryptographic envelope for a FICTIONAL issuer.
 *
 * No trusted Portal session/membership/contract authority exists yet. This
 * signer is intentionally restricted to a disposable PHP CLI test environment,
 * uses an ephemeral private key supplied in memory, and does not provide a
 * production capability, SSO token, customer identity or Ads authorization.
 *
 * Never register it in a ServiceProvider, API route or login controller.
 */
final class SyntheticCapabilityIssuer
{
    public const ISSUER = 'imagine.portal';
    public const AUDIENCE = 'imagine.performance-ai';
    public const PROFILE = 'portal.capability.synthetic.v1';
    public const KEY_ID = 'ephemeral-lab-key-1';
    public const DOMAIN_SEPARATOR = "IMAGINE-PORTAL-CAPABILITY-SYNTHETIC-V1\n";

    /** @return array<string,mixed>|null */
    public static function claims(int $issuedAt): ?array
    {
        if (!self::labEnabled() || $issuedAt <= 0 || $issuedAt > 2147483647) {
            return null;
        }
        return [
            'version'=>1,
            'issuer'=>self::ISSUER,
            'audience'=>self::AUDIENCE,
            'profile'=>self::PROFILE,
            'organization_ref'=>'org-demo-a',
            'performance_tenant_ref'=>'perf-demo-a',
            'portal_service_ref'=>'svc-demo-a',
            'resource_ref'=>'demo-cam-a1',
            'actor_ref'=>'actor-demo-a',
            'session_ref'=>'session-demo-a',
            'permission'=>'campaigns:read',
            'session_mfa_verified'=>true,
            'membership_revision'=>2,
            'service_revision'=>3,
            'session_revision'=>4,
            'recovery_epoch'=>5,
            'service_state'=>'active_mock',
            'issued_at'=>$issuedAt,
            'expires_at'=>$issuedAt+20,
            // A cryptographically random 128-bit nonce; the receiver still
            // needs durable replay storage and an independent current fence.
            'nonce'=>bin2hex(random_bytes(16)),
        ];
    }

    public static function issueSynthetic(string $secretKey, int $issuedAt): ?string
    {
        if (!self::labEnabled()
            || strlen($secretKey)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            return null;
        }
        $claims=self::claims($issuedAt);
        if ($claims===null) {
            return null;
        }

        try {
            $payload=json_encode(
                $claims,
                JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR
            );
            if (!is_string($payload)) {
                return null;
            }
            $sig=sodium_crypto_sign_detached(
                self::DOMAIN_SEPARATOR.$payload,
                $secretKey
            );
            return json_encode([
                'version'=>'portal-capability-envelope.v1',
                'key_id'=>self::KEY_ID,
                'payload'=>self::base64url($payload),
                'signature'=>self::base64url($sig),
            ],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        } catch (SodiumException|JsonException) {
            return null;
        }
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');
    }

    private static function labEnabled(): bool
    {
        return PHP_SAPI==='cli'
            && getenv('APP_ENV')==='testing'
            && getenv('PORTAL_PERF_SIGNED_LAB')==='1';
    }
}
