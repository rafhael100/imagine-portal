<?php
declare(strict_types=1);

/**
 * F132: first real Portal admin login foundation, disabled by default.
 *
 * Enabling the feature is NOT approval to expose customer data. Real tenants,
 * MFA enrollment, session storage, TLS and security reviews need staging.
 */
return [
    'enabled'=>env('PORTAL_ADMIN_AUTH_ENABLED',false),
    'provision_enabled'=>env('PORTAL_ADMIN_PROVISION_ENABLED',false),
    'pending_seconds'=>300,
    'mfa_verified_seconds'=>3600,
    'max_password_attempts'=>5,
    'max_mfa_attempts'=>5,
];
