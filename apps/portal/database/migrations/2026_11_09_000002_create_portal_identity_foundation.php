<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F66: production-shaped Portal-only identity/tenant schema.
 *
 * CRITICAL: there are NO application RLS policies or runtime auth routes yet.
 * Every table is FORCE RLS with no policies; ordinary application roles cannot
 * read/write any row even when SQL GRANTs are added. This is a safe baseline,
 * NOT a login, credential issuer, migration approval or production deployment.
 *
 * Only a separately-reviewed security migration may introduce policies and
 * roles backed by verified first-party Portal identity, MFA and revocation.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Portal identity foundation requires PostgreSQL.');
        }

        Schema::create('portal_identities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email_normalized', 254)->unique();
            $table->string('password_hash', 255)->nullable();
            $table->string('status', 16)->default('invited');
            $table->timestampTz('email_verified_at')->nullable();
            $table->text('mfa_secret_ciphertext')->nullable();
            $table->timestampTz('mfa_confirmed_at')->nullable();
            $table->unsignedBigInteger('security_revision')->default(1);
            $table->timestampsTz();
        });

        Schema::create('portal_organizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference', 128)->unique();
            $table->string('display_name', 160);
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('security_revision')->default(1);
            $table->timestampsTz();
        });

        Schema::create('portal_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('identity_id')->constrained('portal_identities')->restrictOnDelete();
            $table->foreignUuid('organization_id')->constrained('portal_organizations')->restrictOnDelete();
            $table->string('role', 16);
            $table->string('status', 16)->default('invited');
            $table->unsignedBigInteger('membership_revision')->default(1);
            $table->timestampsTz();
            $table->unique(['identity_id', 'organization_id'], 'portal_member_identity_org_uq');
        });

        // Permissions are explicit: role labels alone never grant rights.
        Schema::create('portal_membership_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('membership_id')->constrained('portal_memberships')->restrictOnDelete();
            $table->string('permission_code', 48);
            $table->timestampsTz();
            $table->unique(['membership_id', 'permission_code'], 'portal_member_grant_uq');
        });

        Schema::create('portal_auth_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // A high-entropy opaque bearer NEVER belongs in this table.
            $table->char('session_sha256', 64)->unique();
            $table->uuid('identity_id');
            $table->uuid('organization_id');
            $table->string('status', 16)->default('pending_mfa');
            $table->unsignedBigInteger('identity_revision');
            $table->unsignedBigInteger('membership_revision');
            $table->timestampTz('mfa_verified_at')->nullable();
            $table->timestampTz('issued_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['identity_id', 'organization_id'], 'portal_auth_session_membership_fk')
                ->references(['identity_id', 'organization_id'])
                ->on('portal_memberships')
                ->restrictOnDelete();
        });

        $constraints = [
            "ALTER TABLE portal_identities ADD CONSTRAINT portal_identity_status_ck CHECK (status IN ('invited','active','suspended','revoked'))",
            "ALTER TABLE portal_identities ADD CONSTRAINT portal_identity_revision_ck CHECK (security_revision > 0)",
            "ALTER TABLE portal_identities ADD CONSTRAINT portal_identity_email_ck CHECK (email_normalized = lower(email_normalized) AND position('@' IN email_normalized) > 1)",
            "ALTER TABLE portal_identities ADD CONSTRAINT portal_identity_password_ck CHECK (password_hash IS NULL OR password_hash LIKE '\$argon2id\$%')",
            "ALTER TABLE portal_organizations ADD CONSTRAINT portal_org_status_ck CHECK (status IN ('active','suspended','closed'))",
            "ALTER TABLE portal_organizations ADD CONSTRAINT portal_org_revision_ck CHECK (security_revision > 0)",
            "ALTER TABLE portal_organizations ADD CONSTRAINT portal_org_reference_ck CHECK (reference ~ '^[a-z0-9][a-z0-9._:-]{0,127}$')",
            "ALTER TABLE portal_memberships ADD CONSTRAINT portal_membership_role_ck CHECK (role IN ('owner','manager','member'))",
            "ALTER TABLE portal_memberships ADD CONSTRAINT portal_membership_status_ck CHECK (status IN ('invited','active','suspended','revoked'))",
            "ALTER TABLE portal_memberships ADD CONSTRAINT portal_membership_revision_ck CHECK (membership_revision > 0)",
            "ALTER TABLE portal_membership_grants ADD CONSTRAINT portal_grant_permission_ck CHECK (permission_code IN ('services.view','services.manage','billing.view','members.manage'))",
            "ALTER TABLE portal_auth_sessions ADD CONSTRAINT portal_session_hash_ck CHECK (session_sha256 ~ '^[a-f0-9]{64}$')",
            "ALTER TABLE portal_auth_sessions ADD CONSTRAINT portal_session_status_ck CHECK (status IN ('pending_mfa','active','revoked'))",
            "ALTER TABLE portal_auth_sessions ADD CONSTRAINT portal_session_revision_ck CHECK (identity_revision > 0 AND membership_revision > 0)",
            "ALTER TABLE portal_auth_sessions ADD CONSTRAINT portal_session_clock_ck CHECK (expires_at > issued_at)",
            "ALTER TABLE portal_auth_sessions ADD CONSTRAINT portal_session_mfa_ck CHECK (
                (status='pending_mfa' AND mfa_verified_at IS NULL AND revoked_at IS NULL)
                OR (status='active' AND mfa_verified_at IS NOT NULL AND revoked_at IS NULL)
                OR (status='revoked' AND revoked_at IS NOT NULL))",
        ];
        foreach ($constraints as $sql) {
            DB::statement($sql);
        }

        // Deliberately create NO RLS policies, even for application readers.
        // Merely granting SELECT/UPDATE later must not expose a tenant row.
        foreach ([
            'portal_identities',
            'portal_organizations',
            'portal_memberships',
            'portal_membership_grants',
            'portal_auth_sessions',
        ] as $table) {
            DB::statement('ALTER TABLE "' . $table . '" ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE "' . $table . '" FORCE ROW LEVEL SECURITY');
            DB::statement('REVOKE ALL ON TABLE "' . $table . '" FROM PUBLIC');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Portal identity rollback requires PostgreSQL.');
        }

        Schema::dropIfExists('portal_auth_sessions');
        Schema::dropIfExists('portal_membership_grants');
        Schema::dropIfExists('portal_memberships');
        Schema::dropIfExists('portal_organizations');
        Schema::dropIfExists('portal_identities');
    }
};
