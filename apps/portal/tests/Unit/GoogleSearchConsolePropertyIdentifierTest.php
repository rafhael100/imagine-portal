<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\SeoReview\GoogleSearchConsolePropertyIdentifier as SiteUrl;
use PHPUnit\Framework\TestCase;

final class GoogleSearchConsolePropertyIdentifierTest extends TestCase
{
    public function test_exact_domain_and_url_prefix_site_url_formats_are_distinct(): void
    {
        $sites = [
            'sc-domain:imaginecomunicacao.com.br',
            'sc-domain:www.imaginecomunicacao.com.br',
            'https://www.imaginecomunicacao.com.br/',
            'https://imaginecomunicacao.com.br/',
            'http://www.imaginecomunicacao.com.br/',
            'https://example.com/blog/',
            'https://example.com/blog',
            'https://example.com/blog/a-b_1.html',
            'https://sub.example.co.uk/',
            'https://xn--bcher-kva.example/',
        ];
        foreach ($sites as $siteUrl) {
            self::assertSame($siteUrl, SiteUrl::exactSiteUrl($siteUrl), $siteUrl);
            self::assertSame(hash('sha256', $siteUrl), SiteUrl::fingerprint($siteUrl));
        }
        self::assertCount(count($sites), array_unique(array_map(
            static fn (string $url): string => SiteUrl::fingerprint($url),
            $sites,
        )));
        self::assertFalse(SiteUrl::authorizesPropertyAccess());
    }

    public function test_url_prefix_is_not_interchangeable_with_domain_property(): void
    {
        $domain = 'sc-domain:example.com';
        $www = 'https://www.example.com/';
        $root = 'https://example.com/';
        $path = 'https://example.com/blog/';

        self::assertNotSame(SiteUrl::fingerprint($domain), SiteUrl::fingerprint($www));
        self::assertNotSame(SiteUrl::fingerprint($domain), SiteUrl::fingerprint($root));
        self::assertNotSame(SiteUrl::fingerprint($www), SiteUrl::fingerprint($root));
        self::assertNotSame(SiteUrl::fingerprint($root), SiteUrl::fingerprint($path));
        self::assertNotSame(
            SiteUrl::fingerprint('https://example.com/Blog/'),
            SiteUrl::fingerprint('https://example.com/blog/'),
        );
    }

    public function test_malformed_domain_url_and_ambiguous_host_are_rejected(): void
    {
        foreach ([
            null, false, 123, [], '',
            'sc-domain:',
            'sc-domain:EXAMPLE.com',
            'sc-domain:example.com/',
            'sc-domain:www.example.com.',
            'sc-domain:com',
            'sc-domain:example',
            'sc-domain:example..com',
            'sc-domain:-example.com',
            'sc-domain:example-.com',
            'sc-domain:127.0.0.1',
            'sc-domain:example.com%2Fevil',
            'sc-domain:example.com?x=1',
            'SC-DOMAIN:example.com',
            'https://example.com',
            'https://EXAMPLE.com/',
            'HTTPS://example.com/',
            'ftp://example.com/',
            '//example.com/',
            'https://user:secret@example.com/',
            'https://example.com:443/',
            'https://example.com?x=1/',
            'https://example.com/#fragment',
            'https://example.com/foo?next=bad',
            'https://example.com/foo%2fbar/',
            'https://example.com//evil/',
            'https://example.com/a/../b/',
            'https://example.com/a/./b/',
            'https://example.com\@evil.invalid/',
            'https://example.com/evil\path/',
            'https://127.0.0.1/',
            'https://[::1]/',
            'https://localhost/',
            'https://example_com/',
            "https://example.com/\r\n",
            'https://example.com/lo gin/',
            'https://exämple.com/',
            str_repeat('a', 2049),
        ] as $invalid) {
            self::assertNull(SiteUrl::exactSiteUrl($invalid), is_scalar($invalid) ? (string) $invalid : get_debug_type($invalid));
            self::assertNull(SiteUrl::fingerprint($invalid));
        }
    }

    public function test_property_identifier_never_establishes_tenant_or_google_authority(): void
    {
        $candidate = 'sc-domain:imaginecomunicacao.com.br';
        self::assertSame(hash('sha256', $candidate), SiteUrl::fingerprint($candidate));
        self::assertFalse(SiteUrl::authorizesPropertyAccess());
    }
}
