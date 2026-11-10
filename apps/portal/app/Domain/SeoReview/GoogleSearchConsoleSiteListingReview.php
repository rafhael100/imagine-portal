<?php

declare(strict_types=1);

namespace App\Domain\SeoReview;

/**
 * GSC-F121: pure, fail-closed review of the Google Search Console sites.list
 * RESPONSE SHAPE. It does not establish that the response came from Google.
 *
 * A future trusted backend must obtain sites.list over authenticated HTTPS
 * after Portal session/MFA/tenant authorization, and bind the exact siteUrl
 * to the organization's persisted property fingerprint and current grants.
 */
final class GoogleSearchConsoleSiteListingReview
{
    private const VALID_PERMISSION_LEVELS = [
        'siteOwner',
        'siteFullUser',
        'siteRestrictedUser',
        'siteUnverifiedUser',
    ];

    private const READONLY_CONNECT_CANDIDATES = [
        'siteOwner',
        'siteFullUser',
    ];

    /**
     * A true result is ONLY a well-formed candidate for future review.
     * Never treat it as authenticated Google or Portal authority.
     */
    public function containsExactFullAccessCandidate(
        #[\SensitiveParameter] mixed $sitesResponse,
        ?string $expectedSiteUrl,
    ): bool {
        $expected = GoogleSearchConsolePropertyIdentifier::exactSiteUrl($expectedSiteUrl);

        if ($expected === null
            || !is_array($sitesResponse)
            || array_is_list($sitesResponse)
            || array_keys($sitesResponse) !== ['siteEntry']
            || !is_array($sitesResponse['siteEntry'])
            || !array_is_list($sitesResponse['siteEntry'])
            || $sitesResponse['siteEntry'] === []
            || count($sitesResponse['siteEntry']) > 1024) {
            return false;
        }

        $seen = [];
        $matched = false;

        foreach ($sitesResponse['siteEntry'] as $entry) {
            if (!is_array($entry)
                || array_is_list($entry)
                || count($entry) !== 2
                || !array_key_exists('siteUrl', $entry)
                || !array_key_exists('permissionLevel', $entry)
                || !is_string($entry['permissionLevel'])
                || !in_array($entry['permissionLevel'], self::VALID_PERMISSION_LEVELS, true)) {
                return false;
            }

            $site = GoogleSearchConsolePropertyIdentifier::exactSiteUrl($entry['siteUrl']);
            if ($site === null || isset($seen[$site])) {
                // Duplicate siteUrl entries with conflicting permissionLevel
                // are ambiguous even when one appears to be siteOwner.
                return false;
            }
            $seen[$site] = true;

            if (hash_equals($expected, $site)) {
                $matched = in_array(
                    $entry['permissionLevel'],
                    self::READONLY_CONNECT_CANDIDATES,
                    true,
                );
            }
        }

        return $matched;
    }

    public static function authorizesGoogleAccess(): bool
    {
        return false;
    }

    public static function verifiesGoogleOwnership(): bool
    {
        return false;
    }
}
