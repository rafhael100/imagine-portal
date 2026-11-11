<?php

declare(strict_types=1);

use App\Domain\SeoReview\GoogleSearchConsoleAuthorizationUrl;

/**
 * GSC-F119: explicit closed-by-default Google OAuth integration boundary.
 *
 * This configuration contains ZERO OAuth credentials or environment-driven
 * enable toggles. It is NOT wired into an HTTP route or token service.
 *
 * Enabling any one flag here would still NOT constitute a secure login,
 * tenant grant, provider verification or permission to deploy.
 */
return [
    'release_gate' => 'HOLD_TRUSTED_PORTAL_IAM_GOOGLE_CLIENT_VAULT_AND_INDEPENDENT_REVIEW',

    'authorization_flow_enabled' => false,
    'callback_enabled' => false,
    'token_exchange_enabled' => false,
    'token_storage_enabled' => false,
    'provider_property_lookup_enabled' => false,

    'client_id' => null,
    'credential_source' => 'unconfigured',
    'token_vault' => 'unconfigured',

    'oauth_authorization_endpoint' =>
        GoogleSearchConsoleAuthorizationUrl::AUTHORIZATION_ENDPOINT,
    'oauth_token_endpoint' => 'https://oauth2.googleapis.com/token',
    'redirect_uri' => GoogleSearchConsoleAuthorizationUrl::REDIRECT_URI,
    'scope' => GoogleSearchConsoleAuthorizationUrl::SCOPE_READONLY,
    'pkce_method' => 'S256',

    // The Portal and its connected clients must have separate login systems.
    'portal_session_source' => 'unconfigured',
    'tenant_grant_source' => 'unconfigured',
    'exact_property_authority' => 'unconfigured',
];
