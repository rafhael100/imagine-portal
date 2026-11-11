<?php

declare(strict_types=1);

namespace App\Domain\SeoReview;

/**
 * GSC-F118: pure in-memory shape check for a FUTURE backend OAuth response.
 *
 * Never interpret this as proof that Google issued the response. That requires
 * a trusted HTTPS token endpoint, verified state/PKCE, tenant authorization,
 * confidential credentials and a separately approved token vault.
 *
 * The function deliberately returns only a boolean, never tokens or a DTO
 * containing credentials. No logging, persistence or network call exists.
 */
final class GoogleSearchConsoleReadOnlyTokenResponse
{
    private const ALLOWED = [
        'access_token', 'token_type', 'scope', 'expires_in', 'refresh_token',
    ];

    private const REQUIRED = [
        'access_token', 'token_type', 'scope', 'expires_in',
    ];

    public function hasStrictReadOnlyShape(#[\SensitiveParameter] mixed $response): bool
    {
        if (!is_array($response) || array_is_list($response)) {
            return false;
        }

        $keys = array_keys($response);
        foreach ($keys as $key) {
            if (!is_string($key) || !in_array($key, self::ALLOWED, true)) {
                return false;
            }
        }
        foreach (self::REQUIRED as $required) {
            if (!array_key_exists($required, $response)) {
                return false;
            }
        }

        if ($response['token_type'] !== 'Bearer'
            || $response['scope'] !== GoogleSearchConsoleAuthorizationUrl::SCOPE_READONLY
            || !is_int($response['expires_in'])
            || $response['expires_in'] < 60
            || $response['expires_in'] > 3600
            || !self::opaqueToken($response['access_token'])) {
            return false;
        }

        if (array_key_exists('refresh_token', $response)
            && !self::opaqueToken($response['refresh_token'])) {
            return false;
        }

        return true;
    }

    /**
     * A passing response shape does not authorize storage or external calls.
     */
    public static function authorizesGoogleAccess(): bool
    {
        return false;
    }

    public static function authorizesTokenStorage(): bool
    {
        return false;
    }

    private static function opaqueToken(mixed $value): bool
    {
        return is_string($value)
            && strlen($value) >= 16
            && strlen($value) <= 8192
            && preg_match('/\A[\x21-\x7e]+\z/D', $value) === 1;
    }
}
