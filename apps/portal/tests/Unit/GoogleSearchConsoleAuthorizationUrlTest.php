<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\SeoReview\GoogleSearchConsoleAuthorizationUrl;
use PHPUnit\Framework\TestCase;

final class GoogleSearchConsoleAuthorizationUrlTest extends TestCase
{
    private const CLIENT_ID = '123456789-synthetic.apps.googleusercontent.com';

    private static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function test_google_authorization_url_has_only_explicit_readonly_parameters(): void
    {
        $state = self::token();
        $challenge = self::token();
        $factory = new GoogleSearchConsoleAuthorizationUrl();

        $url = $factory->build(
            self::CLIENT_ID,
            GoogleSearchConsoleAuthorizationUrl::REDIRECT_URI,
            $state,
            $challenge,
        );

        self::assertIsString($url);
        self::assertSame(
            'https://accounts.google.com/o/oauth2/v2/auth',
            strtok($url, '?'),
        );
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertSame([
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => GoogleSearchConsoleAuthorizationUrl::REDIRECT_URI,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'access_type' => 'offline',
            'include_granted_scopes' => 'false',
        ], $query);

        foreach (['client_secret', 'code_verifier', 'access_token', 'refresh_token',
            'id_token', 'login_hint', 'hd', 'prompt', 'openid'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $query, $forbidden);
        }

        self::assertFalse(GoogleSearchConsoleAuthorizationUrl::authorizesOperationalOAuth());
    }

    public function test_redirect_uri_is_exact_and_never_accepts_open_redirects(): void
    {
        $state = self::token();
        $challenge = self::token();
        $factory = new GoogleSearchConsoleAuthorizationUrl();

        foreach ([
            '',
            'http://portal.imaginecomunicacao.com.br/oauth/google/callback',
            'https://portal.imaginecomunicacao.com.br/oauth/google/callback/',
            'https://portal.imaginecomunicacao.com.br/oauth/google/callback?next=https://evil.invalid',
            'https://portal.imaginecomunicacao.com.br/oauth/google/callback#fragment',
            'https://portal.imaginecomunicacao.com.br.evil.invalid/oauth/google/callback',
            'https://evil.invalid/oauth/google/callback',
            'https://localhost/oauth/google/callback',
        ] as $badCallback) {
            self::assertNull($factory->build(
                self::CLIENT_ID, $badCallback, $state, $challenge,
            ), $badCallback);
        }
    }

    public function test_invalid_client_id_and_injection_attempts_fail_closed(): void
    {
        $state = self::token();
        $challenge = self::token();
        $factory = new GoogleSearchConsoleAuthorizationUrl();

        foreach ([
            null,
            '',
            'synthetic-client',
            'https://accounts.google.com',
            'https://evil.invalid?client_id=123.apps.googleusercontent.com',
            'foo.apps.googleusercontent.com?scope=webmasters',
            'foo.apps.googleusercontent.com#fragment',
            "foo.apps.googleusercontent.com\r\nX-Test: injected",
            'foo.apps.googleusercontent.com/',
            str_repeat('x', 257).'.apps.googleusercontent.com',
        ] as $invalid) {
            self::assertNull($factory->build(
                $invalid, GoogleSearchConsoleAuthorizationUrl::REDIRECT_URI,
                $state, $challenge,
            ));
        }
    }

    public function test_tokens_must_be_canonical_256_bit_base64url_and_distinct(): void
    {
        $factory = new GoogleSearchConsoleAuthorizationUrl();
        $validState = self::token();
        $validChallenge = self::token();

        foreach ([
            null,
            '',
            str_repeat('A', 42),
            str_repeat('A', 44),
            str_repeat('!', 43),
            str_repeat('A', 42).'B', // noncanonical spare bits
            base64_encode(random_bytes(32)), // padding disallowed
            'https://malicious.invalid/redirect',
        ] as $badState) {
            self::assertNull($factory->build(
                self::CLIENT_ID, GoogleSearchConsoleAuthorizationUrl::REDIRECT_URI,
                $badState, $validChallenge,
            ));
            self::assertNull($factory->build(
                self::CLIENT_ID, GoogleSearchConsoleAuthorizationUrl::REDIRECT_URI,
                $validState, $badState,
            ));
        }

        self::assertNull($factory->build(
            self::CLIENT_ID, GoogleSearchConsoleAuthorizationUrl::REDIRECT_URI,
            $validState, $validState,
        ));
    }
}
