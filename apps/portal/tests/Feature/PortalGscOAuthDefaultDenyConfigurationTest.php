<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\SeoReview\GoogleSearchConsoleAuthorizationUrl;
use App\Domain\SeoReview\GoogleSearchConsoleCallbackParser;
use App\Domain\SeoReview\GoogleSearchConsoleReadOnlyTokenResponse;
use Tests\TestCase;

/**
 * F119 only proves current application bootstrap is disabled by default.
 * It cannot certify the security of a future OAuth backend.
 */
final class PortalGscOAuthDefaultDenyConfigurationTest extends TestCase
{
    public function test_no_part_of_google_oauth_can_start_from_current_laravel_config(): void
    {
        foreach ([
            'authorization_flow_enabled',
            'callback_enabled',
            'token_exchange_enabled',
            'token_storage_enabled',
            'provider_property_lookup_enabled',
        ] as $key) {
            self::assertFalse(
                config('google_search_console.'.$key),
                'No operational OAuth component may be enabled by default: '.$key,
            );
        }

        self::assertSame(
            'HOLD_TRUSTED_PORTAL_IAM_GOOGLE_CLIENT_VAULT_AND_INDEPENDENT_REVIEW',
            config('google_search_console.release_gate'),
        );
        self::assertNull(config('google_search_console.client_id'));
        foreach ([
            'credential_source', 'token_vault', 'portal_session_source',
            'tenant_grant_source', 'exact_property_authority',
        ] as $key) {
            self::assertSame('unconfigured', config('google_search_console.'.$key));
        }
    }

    public function test_fixed_provider_and_redirect_scope_match_application_contracts(): void
    {
        self::assertSame(
            GoogleSearchConsoleAuthorizationUrl::AUTHORIZATION_ENDPOINT,
            config('google_search_console.oauth_authorization_endpoint'),
        );
        self::assertSame(
            'https://oauth2.googleapis.com/token',
            config('google_search_console.oauth_token_endpoint'),
        );
        self::assertSame(
            GoogleSearchConsoleAuthorizationUrl::REDIRECT_URI,
            config('google_search_console.redirect_uri'),
        );
        self::assertSame(
            GoogleSearchConsoleAuthorizationUrl::SCOPE_READONLY,
            config('google_search_console.scope'),
        );
        self::assertSame('S256', config('google_search_console.pkce_method'));
    }

    public function test_client_environment_cannot_override_hardcoded_release_hold(): void
    {
        $source = file_get_contents(base_path('config/google_search_console.php'));
        self::assertIsString($source);
        self::assertStringNotContainsString('env(', $source);

        // User-defined flags are not consulted by this configuration.
        $previous = getenv('GSC_OAUTH_ENABLED');
        try {
            putenv('GSC_OAUTH_ENABLED=1');
            $config = require base_path('config/google_search_console.php');
            self::assertFalse($config['authorization_flow_enabled']);
            self::assertFalse($config['callback_enabled']);
            self::assertFalse($config['token_exchange_enabled']);
        } finally {
            if ($previous === false) {
                putenv('GSC_OAUTH_ENABLED');
            } else {
                putenv('GSC_OAUTH_ENABLED='.$previous);
            }
        }
    }

    public function test_even_valid_syntax_contracts_do_not_enable_any_oauth_endpoint(): void
    {
        self::assertFalse(GoogleSearchConsoleAuthorizationUrl::authorizesOperationalOAuth());
        self::assertFalse(GoogleSearchConsoleCallbackParser::authorizesTokenExchange());
        self::assertFalse(GoogleSearchConsoleReadOnlyTokenResponse::authorizesGoogleAccess());
        self::assertFalse(GoogleSearchConsoleReadOnlyTokenResponse::authorizesTokenStorage());

        self::assertSame(['up'], array_map(
            static fn ($route): string => $route->uri(),
            iterator_to_array($this->app['router']->getRoutes()),
        ));
        foreach ([
            '/oauth/google/start',
            '/oauth/google/callback',
            '/api/portal/seo/gsc/connect',
            '/api/portal/seo/gsc/properties',
        ] as $path) {
            $this->get($path)->assertNotFound();
            $this->post($path)->assertNotFound();
        }
    }
}
