<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PORTAL-F105: closed Portal-only invitation TABLE, not an invitation flow.
 *
 * PostgreSQL FORCE RLS, zero allow policies and zero application grants.
 * Never deploy without a trusted issuer, email verification, MFA, atomic
 * redemption, revocation, abuse throttling and independent DBA/AppSec review.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || !Schema::hasTable('portal_memberships')) {
            throw new RuntimeException('F105 requires the isolated PostgreSQL Portal identity foundation.');
        }

        Schema::create('portal_access_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('token_sha256', 64)->unique();
            $table->uuid('identity_id');
            $table->uuid('organization_id');
            $table->unsignedBigInteger('identity_revision');
            $table->unsignedBigInteger('membership_revision');
            $table->unsignedBigInteger('organization_revision');
            $table->string('status', 16)->default('pending');
            $table->timestampTz('issued_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->foreign(
                ['identity_id', 'organization_id'],
                'portal_invitation_member_fk'
            )->references(['identity_id', 'organization_id'])
              ->on('portal_memberships')
              ->restrictOnDelete();

            $table->index(
                ['organization_id', 'status', 'expires_at'],
                'portal_invitation_org_state_expiry_idx'
            );
        });

        foreach ([
            "ALTER TABLE portal_access_invitations ADD CONSTRAINT portal_invitation_digest_ck CHECK (token_sha256 ~ '^[a-f0-9]{64}$')",
            "ALTER TABLE portal_access_invitations ADD CONSTRAINT portal_invitation_revisions_ck CHECK (identity_revision > 0 AND membership_revision > 0 AND organization_revision > 0)",
            "ALTER TABLE portal_access_invitations ADD CONSTRAINT portal_invitation_time_ck CHECK (expires_at > issued_at AND expires_at <= issued_at + interval '72 hours')",
            "ALTER TABLE portal_access_invitations ADD CONSTRAINT portal_invitation_state_ck CHECK (
                (status='pending' AND consumed_at IS NULL AND revoked_at IS NULL)
                OR (status='consumed' AND consumed_at IS NOT NULL AND revoked_at IS NULL)
                OR (status='revoked' AND revoked_at IS NOT NULL AND consumed_at IS NULL))",
            "ALTER TABLE portal_access_invitations ADD CONSTRAINT portal_invitation_event_clock_ck CHECK (
                (consumed_at IS NULL OR (consumed_at >= issued_at AND consumed_at <= expires_at))
                AND (revoked_at IS NULL OR revoked_at >= issued_at))",
        ] as $sql) {
            DB::statement($sql);
        }

        // Prevent duplicate outstanding invitations for one Portal membership.
        // Expired but not revoked pending invitations must be explicitly
        // invalidated before reissue; a clock alone does not change SQL status.
        DB::statement(
            "CREATE UNIQUE INDEX portal_invitation_one_pending_per_member_idx
             ON portal_access_invitations (identity_id, organization_id)
             WHERE status='pending'"
        );

        DB::statement('ALTER TABLE portal_access_invitations ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE portal_access_invitations FORCE ROW LEVEL SECURITY');
        DB::statement('REVOKE ALL ON TABLE portal_access_invitations FROM PUBLIC');
        // Intentionally NO RLS allow policies. No writer or reader exists yet.
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('F105 invitation rollback requires PostgreSQL.');
        }
        Schema::dropIfExists('portal_access_invitations');
    }
};
