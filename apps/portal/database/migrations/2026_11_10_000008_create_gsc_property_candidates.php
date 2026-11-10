<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GSC-F123: tenant-scoped, non-authoritative Search Console candidates.
 *
 * There is NO Google proof, token, credential, authorization policy or RLS
 * bypass here. Candidate rows MUST NOT be considered evidence that Google
 * granted a tenant any access to its property. Future audited workflows
 * need first-party session/MFA, ownership proof and active explicit grants.
 */
return new class extends Migration
{
    private const TABLE = 'portal_gsc_property_candidates';

    public function up(): void
    {
        $this->requireFoundation();

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                ->constrained('portal_organizations')->restrictOnDelete();
            $table->uuid('created_by_identity_id');
            $table->string('site_url', 2048);
            $table->char('site_fingerprint_sha256', 64);
            $table->string('site_type', 16);
            $table->string('status', 16)->default('candidate');
            $table->unsignedBigInteger('security_revision')->default(1);
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['organization_id', 'site_fingerprint_sha256'],
                'portal_gsc_candidate_org_fingerprint_uq',
            );
            $table->index(['organization_id', 'status'], 'portal_gsc_candidate_org_status_idx');
        });

        // The founder must already be a Portal member of EXACTLY this
        // organization. This relational link does not prove active MFA
        // or Search Console ownership.
        DB::statement(
            'ALTER TABLE public.portal_gsc_property_candidates '
            .'ADD CONSTRAINT portal_gsc_candidate_founder_member_fk '
            .'FOREIGN KEY (created_by_identity_id, organization_id) '
            .'REFERENCES public.portal_memberships (identity_id, organization_id) '
            .'ON DELETE RESTRICT'
        );

        foreach ([
            "ALTER TABLE public.portal_gsc_property_candidates "
                ."ADD CONSTRAINT portal_gsc_candidate_digest_ck "
                ."CHECK (site_fingerprint_sha256 ~ '^[a-f0-9]{64}$')",
            "ALTER TABLE public.portal_gsc_property_candidates "
                ."ADD CONSTRAINT portal_gsc_candidate_exact_digest_ck "
                ."CHECK (site_fingerprint_sha256 = "
                ."encode(sha256(convert_to(site_url, 'UTF8')), 'hex'))",
            "ALTER TABLE public.portal_gsc_property_candidates "
                ."ADD CONSTRAINT portal_gsc_candidate_url_chars_ck "
                ."CHECK (site_url !~ '[[:cntrl:][:space:]]' "
                ."AND site_url NOT LIKE '%?%' AND site_url NOT LIKE '%#%' "
                ."AND position(chr(92) in site_url)=0 AND position(chr(37) in site_url)=0)",
            "ALTER TABLE public.portal_gsc_property_candidates "
                ."ADD CONSTRAINT portal_gsc_candidate_type_ck "
                ."CHECK (site_type IN ('domain','url_prefix'))",
            "ALTER TABLE public.portal_gsc_property_candidates "
                ."ADD CONSTRAINT portal_gsc_candidate_site_type_ck "
                ."CHECK ((site_type='domain' AND site_url LIKE 'sc-domain:%') "
                ."OR (site_type='url_prefix' AND "
                ."(site_url LIKE 'https://%' OR site_url LIKE 'http://%')))",
            "ALTER TABLE public.portal_gsc_property_candidates "
                ."ADD CONSTRAINT portal_gsc_candidate_status_ck "
                ."CHECK (status IN ('candidate','disabled','revoked'))",
            "ALTER TABLE public.portal_gsc_property_candidates "
                ."ADD CONSTRAINT portal_gsc_candidate_revocation_ck "
                ."CHECK ((status='revoked' AND revoked_at IS NOT NULL) "
                ."OR (status<>'revoked' AND revoked_at IS NULL))",
            "ALTER TABLE public.portal_gsc_property_candidates "
                ."ADD CONSTRAINT portal_gsc_candidate_revision_ck "
                ."CHECK (security_revision > 0)",
        ] as $constraint) {
            DB::statement($constraint);
        }

        // No application policies and no app role grants.
        DB::statement('ALTER TABLE public.portal_gsc_property_candidates ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE public.portal_gsc_property_candidates FORCE ROW LEVEL SECURITY');
        DB::statement('REVOKE ALL ON public.portal_gsc_property_candidates FROM PUBLIC');
    }

    public function down(): void
    {
        $this->requirePostgres();
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        // Destruction of any property reference requires independent
        // removal with an audit trail; the migration never erases rows.
        if (DB::table(self::TABLE)->exists()) {
            throw new RuntimeException(
                'GSC-F123 rollback denied: property candidate records require audited cleanup.'
            );
        }

        Schema::dropIfExists(self::TABLE);
    }

    private function requireFoundation(): void
    {
        $this->requirePostgres();
        if (!Schema::hasTable('portal_organizations')
            || !Schema::hasTable('portal_identities')
            || !Schema::hasTable('portal_memberships')) {
            throw new RuntimeException('GSC-F123 requires the Portal identity/organization foundation.');
        }
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('GSC-F123 requires PostgreSQL.');
        }
    }
};
