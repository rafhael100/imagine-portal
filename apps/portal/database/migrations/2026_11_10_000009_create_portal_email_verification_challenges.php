<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PORTAL-F115: closed Portal-only email proof challenge schema.
 *
 * A verifier challenge is not proof of password/MFA or business membership.
 * No policies, app grants, email delivery, registration, or HTTP route.
 * Applying this to persistent data requires independent DBA/AppSec approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || !Schema::hasTable('portal_identities')) {
            throw new RuntimeException('F115 requires closed F66 PostgreSQL identity foundation.');
        }

        Schema::create('portal_email_verification_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('token_sha256', 64)->unique();
            $table->foreignUuid('identity_id')
                ->constrained('portal_identities')->restrictOnDelete();
            // Email snapshot digest lets verification reject a changed address.
            // Unkeyed email SHA-256 is re-identifiable, so this is security-only
            // database material, NOT anonymous analytics or an export.
            $table->char('email_sha256', 64);
            $table->unsignedBigInteger('identity_revision');
            $table->string('status', 16)->default('pending');
            $table->timestampTz('issued_at', 6);
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('consumed_at', 6)->nullable();
            $table->timestampTz('revoked_at', 6)->nullable();
            $table->timestampsTz();
            $table->index(
                ['identity_id', 'status', 'expires_at'],
                'portal_email_verify_identity_state_idx'
            );
        });

        foreach ([
            "ALTER TABLE portal_email_verification_challenges ADD CONSTRAINT portal_email_verify_token_ck CHECK (token_sha256 ~ '^[a-f0-9]{64}$')",
            "ALTER TABLE portal_email_verification_challenges ADD CONSTRAINT portal_email_verify_email_ck CHECK (email_sha256 ~ '^[a-f0-9]{64}$')",
            "ALTER TABLE portal_email_verification_challenges ADD CONSTRAINT portal_email_verify_revision_ck CHECK (identity_revision > 0)",
            "ALTER TABLE portal_email_verification_challenges ADD CONSTRAINT portal_email_verify_ttl_ck CHECK (expires_at > issued_at AND expires_at <= issued_at + interval '30 minutes')",
            "ALTER TABLE portal_email_verification_challenges ADD CONSTRAINT portal_email_verify_state_ck CHECK (
              (status='pending' AND consumed_at IS NULL AND revoked_at IS NULL)
              OR (status='consumed' AND consumed_at IS NOT NULL AND revoked_at IS NULL)
              OR (status='revoked' AND revoked_at IS NOT NULL AND consumed_at IS NULL))",
            "ALTER TABLE portal_email_verification_challenges ADD CONSTRAINT portal_email_verify_event_ck CHECK (
              (consumed_at IS NULL OR (consumed_at >= issued_at AND consumed_at < expires_at))
              AND (revoked_at IS NULL OR revoked_at >= issued_at))",
        ] as $sql) {
            DB::statement($sql);
        }

        // Revocation must be explicit before reissue. Expiry alone does not
        // release the partial unique index, preventing parallel live tokens.
        DB::statement(
            "CREATE UNIQUE INDEX portal_email_verify_one_pending_per_identity_idx
             ON portal_email_verification_challenges (identity_id)
             WHERE status='pending'"
        );

        DB::statement('ALTER TABLE portal_email_verification_challenges ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE portal_email_verification_challenges FORCE ROW LEVEL SECURITY');
        DB::statement('REVOKE ALL ON TABLE portal_email_verification_challenges FROM PUBLIC');
        // Deliberately no RLS policies and no application grants.
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('F115 rollback requires PostgreSQL.');
        }

        Schema::dropIfExists('portal_email_verification_challenges');
    }
};
