<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GSC-F125: vault locator DIGESTS only; never OAuth access/refresh tokens.
 *
 * No KMS key, token ciphertext, Google client secret, bearer or usable vault
 * locator is persisted. No binding can become active in this migration.
 * Security-reviewed vault/KMS integration must be built separately.
 */
return new class extends Migration
{
    private const TABLE = 'portal_gsc_vault_reference_candidates';

    public function up(): void
    {
        $this->requirePropertyRegistry();

        // The candidate ID already has a global PK; this additional
        // composite unique key makes tenant equality enforceable by FK.
        DB::statement(
            'ALTER TABLE public.portal_gsc_property_candidates '
            .'ADD CONSTRAINT portal_gsc_candidate_org_id_uq '
            .'UNIQUE (organization_id, id)'
        );

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('candidate_id');
            // This is a SHA-256 of a locator reference, NOT a credential.
            $table->char('vault_reference_sha256', 64);
            $table->string('key_version', 64);
            $table->string('scope', 120);
            $table->string('status', 20)->default('pending_review');
            $table->unsignedBigInteger('binding_revision')->default(1);
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['organization_id', 'candidate_id'],
                'portal_gsc_vault_org_candidate_uq',
            );
            $table->unique(
                ['organization_id', 'vault_reference_sha256'],
                'portal_gsc_vault_org_ref_uq',
            );
        });

        DB::statement(
            'ALTER TABLE public.portal_gsc_vault_reference_candidates '
            .'ADD CONSTRAINT portal_gsc_vault_candidate_tenant_fk '
            .'FOREIGN KEY (organization_id, candidate_id) '
            .'REFERENCES public.portal_gsc_property_candidates '
            .'(organization_id, id) ON DELETE RESTRICT'
        );

        foreach ([
            "ALTER TABLE public.portal_gsc_vault_reference_candidates "
                ."ADD CONSTRAINT portal_gsc_vault_ref_digest_ck "
                ."CHECK (vault_reference_sha256 ~ '^[a-f0-9]{64}$')",
            "ALTER TABLE public.portal_gsc_vault_reference_candidates "
                ."ADD CONSTRAINT portal_gsc_vault_key_version_ck "
                ."CHECK (key_version ~ '^[a-z0-9][a-z0-9._-]{0,63}$')",
            "ALTER TABLE public.portal_gsc_vault_reference_candidates "
                ."ADD CONSTRAINT portal_gsc_vault_scope_ck "
                ."CHECK (scope='https://www.googleapis.com/auth/webmasters.readonly')",
            "ALTER TABLE public.portal_gsc_vault_reference_candidates "
                ."ADD CONSTRAINT portal_gsc_vault_status_ck "
                ."CHECK (status IN ('pending_review','revoked'))",
            "ALTER TABLE public.portal_gsc_vault_reference_candidates "
                ."ADD CONSTRAINT portal_gsc_vault_revoked_ck "
                ."CHECK ((status='pending_review' AND revoked_at IS NULL) "
                ."OR (status='revoked' AND revoked_at IS NOT NULL))",
            "ALTER TABLE public.portal_gsc_vault_reference_candidates "
                ."ADD CONSTRAINT portal_gsc_vault_revision_ck "
                ."CHECK (binding_revision > 0)",
        ] as $constraint) {
            DB::statement($constraint);
        }

        // Default denial: no app role can read or write even metadata.
        DB::statement('ALTER TABLE public.portal_gsc_vault_reference_candidates ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE public.portal_gsc_vault_reference_candidates FORCE ROW LEVEL SECURITY');
        DB::statement('REVOKE ALL ON public.portal_gsc_vault_reference_candidates FROM PUBLIC');
    }

    public function down(): void
    {
        $this->requirePropertyRegistry();

        if (Schema::hasTable(self::TABLE)
            && DB::table(self::TABLE)->exists()) {
            throw new RuntimeException(
                'GSC-F125 rollback denied: vault-reference metadata needs audited removal.'
            );
        }

        Schema::dropIfExists(self::TABLE);
        DB::statement(
            'ALTER TABLE public.portal_gsc_property_candidates '
            .'DROP CONSTRAINT IF EXISTS portal_gsc_candidate_org_id_uq'
        );
    }

    private function requirePropertyRegistry(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || !Schema::hasTable('portal_gsc_property_candidates')) {
            throw new RuntimeException('GSC-F125 requires PostgreSQL and F123 candidates.');
        }
    }
};
