<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SEO F111: REVIEW-ONLY durable identity and property-mapping schema.
 *
 * This migration does not create authenticated users, sign in customers or
 * provision real Google access. Run only in disposable PostgreSQL CI until
 * AppSec/DBA approves issuer, RLS role, MFA, lifecycle and restore procedure.
 */
return new class extends Migration {
    private const TABLES = [
        'portal_seo_review_sessions' => 'seo_session_tenant_rls',
        'portal_seo_review_memberships' => 'seo_membership_tenant_rls',
        'portal_seo_review_resources' => 'seo_resource_tenant_rls',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql')
            throw new RuntimeException('SEO F111 review requires PostgreSQL');

        Schema::create('portal_seo_review_sessions', function (Blueprint $t): void {
            $t->string('tenant_ref',64);
            $t->char('session_digest',64);
            $t->string('actor_ref',64);
            $t->unsignedBigInteger('issued_at');
            $t->unsignedBigInteger('expires_at');
            $t->unsignedBigInteger('mfa_verified_at')->nullable();
            $t->unsignedBigInteger('revoked_at')->nullable();
            $t->unsignedInteger('account_revision');
            $t->unsignedInteger('session_revision');
            $t->primary(['tenant_ref','session_digest'],'seo_session_tenant_digest_pk');
            $t->index(['tenant_ref','actor_ref'],'seo_session_actor_idx');
        });

        DB::statement("ALTER TABLE portal_seo_review_sessions
            ADD CONSTRAINT seo_session_digest_hex
            CHECK (session_digest ~ '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE portal_seo_review_sessions
            ADD CONSTRAINT seo_session_timing
            CHECK (expires_at>issued_at AND expires_at-issued_at<=86400
            AND (mfa_verified_at IS NULL OR
                 (mfa_verified_at>=issued_at AND mfa_verified_at<=expires_at))
            AND (revoked_at IS NULL OR revoked_at>=issued_at)
            AND account_revision>0 AND session_revision>0)');

        Schema::create('portal_seo_review_memberships',function(Blueprint $t):void {
            $t->string('tenant_ref',64);
            $t->string('actor_ref',64);
            $t->string('status',16);
            $t->string('permission',32);
            $t->unsignedBigInteger('checked_at');
            $t->unsignedBigInteger('revoked_at')->nullable();
            $t->primary(['tenant_ref','actor_ref'],'seo_membership_tenant_actor_pk');
        });
        DB::statement("ALTER TABLE portal_seo_review_memberships
            ADD CONSTRAINT seo_membership_status_check
            CHECK (status IN ('active','revoked') AND
                   permission='seo.view' AND
                   (status='active' OR revoked_at IS NOT NULL))");

        Schema::create('portal_seo_review_resources',function(Blueprint $t):void {
            $t->string('tenant_ref',64);
            $t->char('resource_digest',64);
            $t->char('property_fingerprint',64);
            $t->string('status',16);
            $t->unsignedBigInteger('updated_at');
            $t->primary(['tenant_ref','resource_digest'],'seo_resource_tenant_digest_pk');
        });
        DB::statement("ALTER TABLE portal_seo_review_resources
            ADD CONSTRAINT seo_resource_hash_check
            CHECK (resource_digest ~ '^[0-9a-f]{64}$'
            AND property_fingerprint ~ '^[0-9a-f]{64}$'
            AND status IN ('active','revoked'))");

        foreach (self::TABLES as $table=>$policy) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY {$policy} ON {$table}
                FOR ALL USING
                (tenant_ref = current_setting('app.tenant_ref', true))
                WITH CHECK
                (tenant_ref = current_setting('app.tenant_ref', true))");
        }
    }

    public function down():void
    {
        if (DB::getDriverName() !== 'pgsql')
            throw new RuntimeException('SEO F111 review rollback requires PostgreSQL');
        foreach (array_reverse(array_keys(self::TABLES)) as $table)
            Schema::dropIfExists($table);
    }
};
