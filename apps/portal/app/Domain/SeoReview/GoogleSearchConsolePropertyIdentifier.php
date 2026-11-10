<?php

declare(strict_types=1);

namespace App\Domain\SeoReview;

/**
 * GSC-F120: fail-closed syntax boundary for Search Console property IDs.
 *
 * The Search Console API uses siteUrl to identify BOTH URL-prefix properties
 * and sc-domain properties. Never collapse a domain property into a URL
 * prefix, or change scheme, host, path, or case while checking authorization.
 *
 * This is not a Google-verification or a tenant authorization service.
 * Accept only siteUrl from a future trusted Google API response, never a
 * customer-provided URL as evidence of permission.
 */
final class GoogleSearchConsolePropertyIdentifier
{
    private const DOMAIN_PREFIX = 'sc-domain:';

    public static function exactSiteUrl(mixed $candidate): ?string
    {
        if (!is_string($candidate)
            || $candidate === ''
            || strlen($candidate) > 2048
            || preg_match('/[^\x21-\x7e]/', $candidate) === 1
            || str_contains($candidate, '\\')
            || str_contains($candidate, '%')
            || str_contains($candidate, '?')
            || str_contains($candidate, '#')) {
            return null;
        }

        if (str_starts_with($candidate, self::DOMAIN_PREFIX)) {
            $host = substr($candidate, strlen(self::DOMAIN_PREFIX));

            return self::validHost($host) ? $candidate : null;
        }

        if (!str_starts_with($candidate, 'https://')
            && !str_starts_with($candidate, 'http://')) {
            return null;
        }

        $parts = parse_url($candidate);
        if (!is_array($parts)
            || count(array_diff(array_keys($parts), ['scheme', 'host', 'path'])) > 0
            || !isset($parts['scheme'], $parts['host'], $parts['path'])
            || !in_array($parts['scheme'], ['https', 'http'], true)
            || !self::validHost($parts['host'])
            || $parts['path'] === ''
            || $parts['path'][0] !== '/'
            || preg_match('/\A\/[A-Za-z0-9._~\/-]*\z/D', $parts['path']) !== 1
            || str_contains($parts['path'], '//')) {
            return null;
        }

        foreach (explode('/', $parts['path']) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $candidate;
    }

    /**
     * F110-F114 PostgreSQL ledger stores SHA-256 of this exact siteUrl.
     * A fingerprint NEVER proves Google ownership or a Portal membership.
     */
    public static function fingerprint(mixed $candidate): ?string
    {
        $exact = self::exactSiteUrl($candidate);

        return $exact === null ? null : hash('sha256', $exact);
    }

    public static function authorizesPropertyAccess(): bool
    {
        return false;
    }

    private static function validHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253 || strtolower($host) !== $host) {
            return false;
        }
        $labels = explode('.', $host);
        if (count($labels) < 2) {
            return false;
        }
        foreach ($labels as $label) {
            if (strlen($label) < 1
                || strlen($label) > 63
                || preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $label) !== 1) {
                return false;
            }
        }
        $tld = $labels[count($labels) - 1];

        // Reject numeric/IP literals and ambiguous single-letter TLDs.
        return strlen($tld) >= 2
            && preg_match('/[a-z]/', $tld) === 1
            && filter_var($host, FILTER_VALIDATE_IP) === false;
    }
}
