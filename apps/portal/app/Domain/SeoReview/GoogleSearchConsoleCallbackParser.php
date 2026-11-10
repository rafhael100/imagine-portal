<?php

declare(strict_types=1);

namespace App\Domain\SeoReview;

/**
 * GSC-F117: syntax-only parser for the raw Google OAuth callback query.
 *
 * Never use parse_str() for untrusted OAuth input: duplicate keys and
 * bracketed PHP field names can be silently overwritten or normalized.
 *
 * The caller MUST use a trusted, one-time, tenant/session-bound state
 * ledger before interpreting any returned code. A successful parse is
 * NEVER an authenticated session or a permission to exchange a token.
 */
final class GoogleSearchConsoleCallbackParser
{
    private const OPTIONAL_FIELDS = ['scope', 'authuser', 'prompt'];

    /**
     * Extract the opaque code only for a FUTURE trusted backend.
     *
     * This does not burn an OAuth state, authorize Google, verify an OAuth
     * origin, perform a network request, or call a token endpoint.
     */
    public function extractCodeSyntaxOnly(
        ?string $rawQueryString,
        ?string $issuedState,
    ): ?string {
        if ($rawQueryString === null
            || $rawQueryString === ''
            || strlen($rawQueryString) > 8192
            || str_contains($rawQueryString, '#')
            || str_contains($rawQueryString, ';')
            || !self::isCanonicalState($issuedState)) {
            return null;
        }

        $pairs = explode('&', $rawQueryString);
        if (count($pairs) < 2 || count($pairs) > 6) {
            return null;
        }

        $fields = [];

        foreach ($pairs as $pair) {
            $separator = strpos($pair, '=');
            if ($separator === false || $separator === 0) {
                return null;
            }

            $key = substr($pair, 0, $separator);
            $rawValue = substr($pair, $separator + 1);
            if (!in_array($key, [
                'code', 'state', 'error', 'error_description',
                ...self::OPTIONAL_FIELDS,
            ], true)
                || array_key_exists($key, $fields)
                || $rawValue === ''
                || strlen($rawValue) > 4096
                || preg_match('/%(?![0-9A-Fa-f]{2})/', $rawValue) === 1) {
                return null;
            }

            $value = rawurldecode($rawValue);
            if ($value === ''
                || strlen($value) > 4096
                || preg_match('/[\x00-\x20\x7f]/', $value) === 1
                || str_contains($value, '%')) {
                return null;
            }

            $fields[$key] = $value;
        }

        $state = $fields['state'] ?? null;
        if (!is_string($state)
            || !self::isCanonicalState($state)
            || !hash_equals($issuedState, $state)
            || array_key_exists('error', $fields)
            || array_key_exists('error_description', $fields)) {
            return null;
        }

        if (array_key_exists('scope', $fields)
            && $fields['scope'] !== GoogleSearchConsoleAuthorizationUrl::SCOPE_READONLY) {
            return null;
        }
        if (array_key_exists('authuser', $fields)
            && preg_match('/\A[0-9]{1,3}\z/D', $fields['authuser']) !== 1) {
            return null;
        }
        if (array_key_exists('prompt', $fields)
            && !in_array($fields['prompt'], ['consent', 'select_account', 'none'], true)) {
            return null;
        }

        $code = $fields['code'] ?? null;
        return is_string($code)
            && strlen($code) >= 8
            && strlen($code) <= 4096
            && preg_match('/\A[A-Za-z0-9._~+\/=\-]{8,4096}\z/D', $code) === 1
            ? $code : null;
    }

    public static function authorizesTokenExchange(): bool
    {
        return false;
    }

    private static function isCanonicalState(?string $state): bool
    {
        if ($state === null
            || strlen($state) !== 43
            || preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $state) !== 1) {
            return false;
        }
        $raw = base64_decode(strtr($state, '-_', '+/').'=', true);

        return is_string($raw)
            && strlen($raw) === 32
            && rtrim(strtr(base64_encode($raw), '+/', '-_'), '=') === $state;
    }
}
