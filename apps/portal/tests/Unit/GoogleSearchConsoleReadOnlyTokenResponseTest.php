<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\SeoReview\GoogleSearchConsoleAuthorizationUrl;
use App\Domain\SeoReview\GoogleSearchConsoleCallbackParser;
use App\Domain\SeoReview\GoogleSearchConsoleReadOnlyTokenResponse;
use PHPUnit\Framework\TestCase;

final class GoogleSearchConsoleReadOnlyTokenResponseTest extends TestCase
{
    private static function good(): array
    {
        return [
            'access_token' => str_repeat('X', 42),
            'token_type' => 'Bearer',
            'scope' => GoogleSearchConsoleAuthorizationUrl::SCOPE_READONLY,
            'expires_in' => 3599,
        ];
    }

    public function test_accepts_only_strict_synthetic_readonly_response_shape(): void
    {
        $review = new GoogleSearchConsoleReadOnlyTokenResponse();
        $valid = self::good();
        self::assertTrue($review->hasStrictReadOnlyShape($valid));
        self::assertTrue($review->hasStrictReadOnlyShape([
            ...$valid,
            'refresh_token' => str_repeat('R', 48),
        ]));

        self::assertFalse(GoogleSearchConsoleReadOnlyTokenResponse::authorizesGoogleAccess());
        self::assertFalse(GoogleSearchConsoleReadOnlyTokenResponse::authorizesTokenStorage());
    }

    public function test_rejects_wider_scopes_unexpected_fields_and_expired_tokens(): void
    {
        $review = new GoogleSearchConsoleReadOnlyTokenResponse();
        $good = self::good();

        foreach ([
            [...$good, 'scope' => 'https://www.googleapis.com/auth/webmasters'],
            [...$good, 'scope' => 'https://www.googleapis.com/auth/drive.readonly'],
            [...$good, 'scope' => $good['scope'].' https://www.googleapis.com/auth/webmasters'],
            [...$good, 'scope' => ''],
            [...$good, 'scope' => null],
            [...$good, 'token_type' => 'bearer'],
            [...$good, 'token_type' => 'MAC'],
            [...$good, 'expires_in' => 0],
            [...$good, 'expires_in' => 59],
            [...$good, 'expires_in' => 3601],
            [...$good, 'expires_in' => '3599'],
            [...$good, 'expires_in' => 3599.0],
            [...$good, 'id_token' => str_repeat('I', 24)],
            [...$good, 'error' => 'invalid_grant'],
            [...$good, 'extra_scope' => 'secret'],
            [...$good, 'refresh_token' => ''],
            [...$good, 'refresh_token' => 'short'],
            [...$good, 'access_token' => str_repeat('X', 15)],
            [...$good, 'access_token' => str_repeat('X', 8193)],
            [...$good, 'access_token' => "ABC\n".str_repeat('X', 32)],
            [...$good, 'access_token' => 'Bearer '.str_repeat('X', 32)],
            array_diff_key($good, ['scope' => true]),
            array_diff_key($good, ['access_token' => true]),
            array_diff_key($good, ['expires_in' => true]),
            array_diff_key($good, ['token_type' => true]),
            null, false, 'not-a-token-response', [], ['value', 'another'],
        ] as $invalid) {
            self::assertFalse(
                $review->hasStrictReadOnlyShape($invalid),
                'A token form or scope must never be implicitly widened.',
            );
        }
    }

    public function test_shape_review_does_not_turn_callback_into_authorized_google_access(): void
    {
        $state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $urlBuilder = new GoogleSearchConsoleAuthorizationUrl();
        $callback = new GoogleSearchConsoleCallbackParser();
        $review = new GoogleSearchConsoleReadOnlyTokenResponse();

        $url = $urlBuilder->build(
            '123456789-synthetic.apps.googleusercontent.com',
            GoogleSearchConsoleAuthorizationUrl::REDIRECT_URI,
            $state,
            $challenge,
        );
        self::assertIsString($url);
        self::assertSame(
            '4/synthetic-code-example',
            $callback->extractCodeSyntaxOnly(
                'state='.$state.'&code=4%2Fsynthetic-code-example',
                $state,
            ),
        );
        self::assertTrue($review->hasStrictReadOnlyShape(self::good()));

        self::assertFalse(GoogleSearchConsoleAuthorizationUrl::authorizesOperationalOAuth());
        self::assertFalse(GoogleSearchConsoleCallbackParser::authorizesTokenExchange());
        self::assertFalse(GoogleSearchConsoleReadOnlyTokenResponse::authorizesGoogleAccess());
        self::assertFalse(GoogleSearchConsoleReadOnlyTokenResponse::authorizesTokenStorage());
    }
}
