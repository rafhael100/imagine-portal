<?php

declare(strict_types=1);

namespace App\Domain\SeoReview;

/**
 * GSC-F116 — pure Google OAuth authorization URL builder.
 *
 * This component never authenticates a Portal user, checks MFA, issues PKCE
 * challenges, validates a property or grants Google access. A trusted future
 * backend must supply an issued, one-time state and server-created PKCE S256
 * challenge after checking session/tenant permissions. No HTTP route, OAuth
 * token exchange or provider connection is enabled by this class.
 */
final class GoogleSearchConsoleAuthorizationUrl
{
    public const AUTHORIZATION_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const REDIRECT_URI = 'https://portal.imaginecomunicacao.com.br/oauth/google/callback';
    public const SCOPE_READONLY = 'https://www.googleapis.com/auth/webmasters.readonly';

    public function build(
        ?string $clientId,
        ?string $redirectUri,
        ?string $issuedState,
        ?string $pkceS256Challenge,
    ): ?string {
        if (!self::validClientId($clientId)
            || $redirectUri !== self::REDIRECT_URI
            || !self::isCanonical256BitToken($issuedState)
            || !self::isCanonical256BitToken($pkceS256Challenge)
            || hash_equals($issuedState, $pkceS256Challenge)) {
            return null;
        }

        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'response_type' => 'code',
            'scope' => self::SCOPE_READONLY,
            'state' => $issuedState,
            'code_challenge' => $pkceS256Challenge,
            'code_challenge_method' => 'S256',
            'access_type' => 'offline',
            // Do not silently aggregate previously granted wider scopes.
            'include_granted_scopes' => 'false',
        ], '', '&', PHP_QUERY_RFC3986);

        // URL assembly is not a consent grant or a browser redirect.
        return self::AUTHORIZATION_ENDPOINT.'?'.$query;
    }

    public static function authorizesOperationalOAuth(): bool
    {
        return false;
    }

    private static function validClientId(?string $clientId): bool
    {
        if ($clientId === null || strlen($clientId) > 255) {
            return false;
        }

        return preg_match(
            '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,199}\.apps\.googleusercontent\.com\z/D',
            $clientId,
        ) === 1;
    }

    private static function isCanonical256BitToken(?string $token): bool
    {
        if ($token === null
            || strlen($token) !== 43
            || preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $token) !== 1) {
            return false;
        }

        $bytes = base64_decode(strtr($token, '-_', '+/').'=', true);

        return is_string($bytes)
            && strlen($bytes) === 32
            && rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=') === $token;
    }
}
