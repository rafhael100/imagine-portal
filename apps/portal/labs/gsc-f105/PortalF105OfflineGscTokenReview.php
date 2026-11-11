<?php

declare(strict_types=1);

/**
 * F105: offline, synthetic-only inspection of a future Google OAuth
 * token exchange response. Never stores or returns tokens and never grants
 * a Portal capability. A real verified backend caller is not implemented.
 */
final class PortalF105OfflineGscTokenReview
{
    private const READONLY_SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    public static function inspect(mixed $response): array
    {
        $hold = [
            'contractVersion' => 'portal-gsc-oauth-token-review-offline-v1',
            'readonlyScopeVerifiedOffline' => false,
            'tokenShapeReviewedOffline' => false,
            'productionReadAuthorized' => false,
            'tokensStored' => false,
            'networkPerformed' => false,
            'authorization' => 'HOLD_AUTHENTICATED_BACKEND_AND_TENANT_VERIFICATION',
        ];

        if (PHP_SAPI !== 'cli'
            || getenv('APP_ENV') !== 'testing'
            || getenv('PORTAL_F105_GSC_LAB') !== '1'
            || !is_array($response) || array_is_list($response)) {
            return $hold;
        }

        $required = ['access_token', 'expires_in', 'scope', 'token_type'];
        $allowed = [...$required, 'refresh_token'];
        $keys = array_keys($response);
        sort($keys);
        $sortedRequired = $required;
        sort($sortedRequired);
        $extra = array_diff($keys, $allowed);
        $missing = array_diff($required, $keys);
        if ($extra !== [] || $missing !== []) {
            return $hold;
        }
        if (!is_string($response['access_token'])
            || strlen($response['access_token']) < 16
            || strlen($response['access_token']) > 8192
            || preg_match('/\\A[\\x21-\\x7e]+\\z/D', $response['access_token']) !== 1
            || $response['token_type'] !== 'Bearer'
            || !is_int($response['expires_in'])
            || $response['expires_in'] < 60 || $response['expires_in'] > 3600
            || $response['scope'] !== self::READONLY_SCOPE) {
            return $hold;
        }
        if (array_key_exists('refresh_token', $response)
            && (!is_string($response['refresh_token'])
                || strlen($response['refresh_token']) < 16
                || strlen($response['refresh_token']) > 8192
                || preg_match('/\\A[\\x21-\\x7e]+\\z/D', $response['refresh_token']) !== 1)) {
            return $hold;
        }

        return [
            ...$hold,
            'readonlyScopeVerifiedOffline' => true,
            'tokenShapeReviewedOffline' => true,
        ];
    }

    public static function authorizesRealGoogleAccess(): bool
    {
        return false;
    }
}
