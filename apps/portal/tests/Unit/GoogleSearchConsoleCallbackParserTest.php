<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\SeoReview\GoogleSearchConsoleAuthorizationUrl;
use App\Domain\SeoReview\GoogleSearchConsoleCallbackParser;
use PHPUnit\Framework\TestCase;

/**
 * Security assertions only. No HTTP routes, provider tokens, Google network,
 * trusted Portal session or OAuth token exchange is created here.
 */
final class GoogleSearchConsoleCallbackParserTest extends TestCase
{
    private static function state(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function test_valid_raw_callback_is_parsed_but_never_authorized(): void
    {
        $parser = new GoogleSearchConsoleCallbackParser();
        $state = self::state();
        $code = '4/synthetic_code-ABC+xyz';

        $query = http_build_query([
            'state' => $state,
            'code' => $code,
            'scope' => GoogleSearchConsoleAuthorizationUrl::SCOPE_READONLY,
            'authuser' => '0',
            'prompt' => 'consent',
        ], '', '&', PHP_QUERY_RFC3986);

        self::assertSame($code, $parser->extractCodeSyntaxOnly($query, $state));
        self::assertFalse(GoogleSearchConsoleCallbackParser::authorizesTokenExchange());
    }

    public function test_minimal_callback_succeeds_only_for_exact_one_time_state_value(): void
    {
        $parser = new GoogleSearchConsoleCallbackParser();
        $state = self::state();
        $raw = 'code=4%2Fsynthetic-code-123&state='.$state;
        self::assertSame('4/synthetic-code-123', $parser->extractCodeSyntaxOnly($raw, $state));
        self::assertNull($parser->extractCodeSyntaxOnly($raw, self::state()));
        self::assertNull($parser->extractCodeSyntaxOnly($raw, str_repeat('A', 43)));
        self::assertNull($parser->extractCodeSyntaxOnly($raw, ''));
        self::assertNull($parser->extractCodeSyntaxOnly($raw, null));
    }

    public function test_duplicate_bracketed_and_ambiguous_callback_fields_are_rejected(): void
    {
        $parser = new GoogleSearchConsoleCallbackParser();
        $state = self::state();

        foreach ([
            'state='.$state.'&state='.$state.'&code=synthetic-code-123',
            'code=synthetic-code-123&code=synthetic-code-456&state='.$state,
            'state%5B%5D='.$state.'&code=synthetic-code-123',
            'state[]='.$state.'&code=synthetic-code-123',
            'state='.$state.'&code[]=synthetic-code-123',
            'state='.$state.'&c.ode=synthetic-code-123',
            'state='.$state.'&code=synthetic-code-123&state%00=abc',
            'state='.$state.'&code=synthetic-code-123&unexpected=abc',
            'state='.$state.'&code=synthetic-code-123&scope=readonly&scope=readonly',
            'state='.$state.'&code=synthetic-code-123&error=access_denied',
            'state='.$state.'&error=access_denied',
            'state='.$state.'&error_description=no',
            'state='.$state.'&code=synthetic-code-123&error_description=bad',
            'state='.$state.'&code=synthetic-code-123&',
            'state='.$state.'&code=synthetic-code-123;scope=another',
        ] as $invalid) {
            self::assertNull($parser->extractCodeSyntaxOnly($invalid, $state), $invalid);
        }
    }

    public function test_invalid_scope_account_and_prompt_are_rejected(): void
    {
        $parser = new GoogleSearchConsoleCallbackParser();
        $state = self::state();
        $base = 'state='.$state.'&code=synthetic-code-123';

        foreach ([
            '&scope=https%3A%2F%2Fwww.googleapis.com%2Fauth%2Fwebmasters',
            '&scope=https%3A%2F%2Fwww.googleapis.com%2Fauth%2Fdrive.readonly',
            '&scope='.rawurlencode(GoogleSearchConsoleAuthorizationUrl::SCOPE_READONLY.' https://www.googleapis.com/auth/drive.readonly'),
            '&authuser=-1',
            '&authuser=9999',
            '&authuser=1%0A',
            '&prompt=unexpected',
            '&prompt=consent%20none',
        ] as $invalid) {
            self::assertNull($parser->extractCodeSyntaxOnly($base.$invalid, $state), $invalid);
        }
    }

    public function test_malformed_encoding_codes_controls_fragments_and_queries_are_rejected(): void
    {
        $parser = new GoogleSearchConsoleCallbackParser();
        $state = self::state();

        foreach ([
            null,
            '',
            '?state='.$state.'&code=synthetic-code-123',
            'state='.$state.'&code=short',
            'state='.$state.'&code=synthetic-code-123#fragment',
            'state='.$state.'&code=synthetic%GGcode123',
            'state='.$state.'&code=synthetic%252Fcode123',
            'state='.$state.'&code=synthetic%00code123',
            'state='.$state.'&code=synthetic%0Acode123',
            'state='.$state.'&code=synthetic%20code123',
            'state='.$state.'&code='.str_repeat('X', 4097),
            str_repeat('X', 8193),
            'state='.str_repeat('A', 42).'B&code=synthetic-code-123',
            'state='.$state.'&code=synthetic-code-123&scope='.rawurlencode(
                GoogleSearchConsoleAuthorizationUrl::SCOPE_READONLY,
            ).'&authuser=0&prompt=consent&extra=1',
        ] as $invalid) {
            self::assertNull($parser->extractCodeSyntaxOnly($invalid, $state));
        }
    }
}
