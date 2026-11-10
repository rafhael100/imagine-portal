<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PortalAuthSession;
use App\Models\PortalIdentity;
use App\Models\PortalMembership;
use App\Models\PortalMembershipGrant;
use App\Models\PortalOrganization;
use Tests\TestCase;

/**
 * F67 verifies persistence mapping ONLY. Eloquent models do not authenticate
 * the holder of a session hash or verify revocation, permissions or MFA.
 */
final class PortalIdentityModelsTest extends TestCase
{
    public function test_models_bind_only_to_first_party_portal_tables(): void
    {
        // Explicit comparisons avoid accidental use of CRM model/provider.
        self::assertSame('portal_identities', (new PortalIdentity())->getTable());
        self::assertSame('portal_organizations', (new PortalOrganization())->getTable());
        self::assertSame('portal_memberships', (new PortalMembership())->getTable());
        self::assertSame('portal_membership_grants', (new PortalMembershipGrant())->getTable());
        self::assertSame('portal_auth_sessions', (new PortalAuthSession())->getTable());

        foreach ([
            new PortalIdentity(),
            new PortalOrganization(),
            new PortalMembership(),
            new PortalMembershipGrant(),
            new PortalAuthSession(),
        ] as $model) {
            self::assertFalse($model->getIncrementing());
            self::assertSame('string', $model->getKeyType());
            self::assertSame(['*'], $model->getGuarded());
            self::assertSame([], $model->getFillable());
        }
    }

    public function test_hidden_password_and_mfa_fields_cannot_leak_into_serialized_identity(): void
    {
        $identity = (new PortalIdentity())->forceFill([
            'id' => '11111111-1111-4111-8111-111111111111',
            'email_normalized' => 'f67@example.invalid',
            'password_hash' => '$argon2id$not-a-real-hash',
            'mfa_secret_ciphertext' => 'SYNTHETIC-CIPHERTEXT-NOT-REAL',
            'status' => 'active',
        ]);

        self::assertSame('password_hash', $identity->getAuthPasswordName());
        self::assertSame('$argon2id$not-a-real-hash', $identity->getAuthPassword());

        $array = $identity->toArray();
        $json = $identity->toJson();
        self::assertArrayNotHasKey('password_hash', $array);
        self::assertArrayNotHasKey('mfa_secret_ciphertext', $array);
        self::assertStringNotContainsString('not-a-real-hash', $json);
        self::assertStringNotContainsString('SYNTHETIC-CIPHERTEXT', $json);
    }

    public function test_session_hash_is_not_serialized(): void
    {
        $session = (new PortalAuthSession())->forceFill([
            'id' => '66666666-6666-4666-8666-666666666666',
            'session_sha256' => str_repeat('a', 64),
            'status' => 'pending_mfa',
        ]);
        self::assertArrayNotHasKey('session_sha256', $session->toArray());
        self::assertStringNotContainsString(str_repeat('a', 64), $session->toJson());
    }

    public function test_model_relationships_use_only_portal_foreign_keys(): void
    {
        $membership = new PortalMembership();
        self::assertSame('identity_id', $membership->identity()->getForeignKeyName());
        self::assertSame('organization_id', $membership->organization()->getForeignKeyName());
        self::assertSame('membership_id', $membership->grants()->getForeignKeyName());
        self::assertSame('identity_id', (new PortalIdentity())->memberships()->getForeignKeyName());
        self::assertSame('organization_id', (new PortalOrganization())->memberships()->getForeignKeyName());
        self::assertSame('membership_id', (new PortalMembershipGrant())->membership()->getForeignKeyName());
        self::assertSame('identity_id', (new PortalAuthSession())->identity()->getForeignKeyName());
        self::assertSame('organization_id', (new PortalAuthSession())->organization()->getForeignKeyName());
    }

    public function test_no_login_or_customer_data_routes_are_enabled_by_models(): void
    {
        foreach (['/login','/register','/mfa','/portal','/api/portal/session'] as $uri) {
            $this->get($uri)->assertNotFound();
        }
        self::assertFalse(method_exists(new PortalAuthSession(), 'authorizeCustomer'));
        self::assertFalse(method_exists(new PortalMembership(), 'authorizeCustomer'));
    }
}
