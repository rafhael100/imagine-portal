<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\SeoReview\GoogleSearchConsolePropertyIdentifier as SiteUrl;
use App\Domain\SeoReview\GoogleSearchConsoleSiteListingReview;
use PHPUnit\Framework\TestCase;

final class GoogleSearchConsoleSiteListingReviewTest extends TestCase
{
    private const DOMAIN = 'sc-domain:imaginecomunicacao.com.br';
    private const URL_PREFIX = 'https://www.imaginecomunicacao.com.br/';

    private function reviewer(): GoogleSearchConsoleSiteListingReview
    {
        return new GoogleSearchConsoleSiteListingReview();
    }

    public function test_owner_and_full_user_are_exact_candidates_only(): void
    {
        foreach (['siteOwner', 'siteFullUser'] as $level) {
            $fixture = ['siteEntry' => [
                ['siteUrl' => self::DOMAIN, 'permissionLevel' => $level],
                ['siteUrl' => self::URL_PREFIX, 'permissionLevel' => 'siteRestrictedUser'],
            ]];

            self::assertTrue($this->reviewer()->containsExactFullAccessCandidate(
                $fixture, self::DOMAIN,
            ), $level);
            self::assertFalse($this->reviewer()->containsExactFullAccessCandidate(
                $fixture, self::URL_PREFIX,
            ), 'A different property is not an owner grant.');
        }

        self::assertFalse(GoogleSearchConsoleSiteListingReview::authorizesGoogleAccess());
        self::assertFalse(GoogleSearchConsoleSiteListingReview::verifiesGoogleOwnership());
    }

    public function test_restricted_or_unverified_properties_never_qualify(): void
    {
        foreach (['siteRestrictedUser', 'siteUnverifiedUser'] as $level) {
            self::assertFalse($this->reviewer()->containsExactFullAccessCandidate(
                ['siteEntry' => [
                    ['siteUrl' => self::DOMAIN, 'permissionLevel' => $level],
                ]],
                self::DOMAIN,
            ), $level);
        }
    }

    public function test_domain_url_prefix_subdomain_scheme_and_path_remain_distinct(): void
    {
        $fixture = ['siteEntry' => [
            ['siteUrl' => self::DOMAIN, 'permissionLevel' => 'siteOwner'],
            ['siteUrl' => 'https://example.com/blog/', 'permissionLevel' => 'siteFullUser'],
        ]];

        foreach ([
            self::URL_PREFIX,
            'https://imaginecomunicacao.com.br/',
            'sc-domain:www.imaginecomunicacao.com.br',
            'http://example.com/blog/',
            'https://example.com/',
            'https://example.com/Blog/',
        ] as $other) {
            self::assertFalse(
                $this->reviewer()->containsExactFullAccessCandidate($fixture, $other),
                $other,
            );
        }
        self::assertTrue($this->reviewer()->containsExactFullAccessCandidate(
            $fixture, 'https://example.com/blog/',
        ));
        self::assertSame(
            hash('sha256', self::DOMAIN),
            SiteUrl::fingerprint(self::DOMAIN),
        );
    }

    public function test_duplicate_properties_missing_fields_and_unknown_levels_are_rejected(): void
    {
        $good = ['siteUrl' => self::DOMAIN, 'permissionLevel' => 'siteOwner'];
        $reviewer = $this->reviewer();

        foreach ([
            null,
            false,
            [],
            ['siteEntry' => []],
            ['siteEntry' => [$good], 'nextPageToken' => 'not-expected'],
            ['siteEntry' => $good],
            ['siteEntry' => [['siteUrl' => self::DOMAIN]]],
            ['siteEntry' => [['permissionLevel' => 'siteOwner']]],
            ['siteEntry' => [[...$good, 'access_token' => 'secret']]],
            ['siteEntry' => [['siteUrl' => self::DOMAIN, 'permissionLevel' => 'owner']]],
            ['siteEntry' => [['siteUrl' => self::DOMAIN, 'permissionLevel' => null]]],
            ['siteEntry' => [['siteUrl' => self::DOMAIN, 'permissionLevel' => true]]],
            ['siteEntry' => [['siteUrl' => self::DOMAIN, 'permissionLevel' => 'siteOwner'], [
                'siteUrl' => self::DOMAIN, 'permissionLevel' => 'siteRestrictedUser',
            ]]],
            ['siteEntry' => [['siteUrl' => 'sc-domain:EXAMPLE.com', 'permissionLevel' => 'siteOwner']]],
            ['siteEntry' => [null]],
            ['siteEntry' => [new \stdClass()]],
            ['siteEntry' => array_fill(0, 1025, $good)],
        ] as $invalid) {
            self::assertFalse($reviewer->containsExactFullAccessCandidate(
                $invalid, self::DOMAIN,
            ));
        }
    }

    public function test_untrusted_matching_response_is_never_authorized_on_its_own(): void
    {
        // This is caller-supplied data in a pure unit test; it proves
        // only a shape match, never Google's actual authorization.
        $forgedByCaller = ['siteEntry' => [[
            'siteUrl' => self::DOMAIN,
            'permissionLevel' => 'siteOwner',
        ]]];

        self::assertTrue($this->reviewer()->containsExactFullAccessCandidate(
            $forgedByCaller,
            self::DOMAIN,
        ));
        self::assertFalse(GoogleSearchConsoleSiteListingReview::verifiesGoogleOwnership());
        self::assertFalse(GoogleSearchConsoleSiteListingReview::authorizesGoogleAccess());
    }
}
