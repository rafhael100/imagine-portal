<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F123: one-use nonce ledger for test-only signed SEO build evidence.
 *
 * The table is NOT an authentication or publication service. Do not apply to
 * production without a trusted signer, audited key lifecycle, RLS roles,
 * backup/restore test, retention policy and independent authorization.
 */
return new class extends Migration {
    public function up():void
    {
        if(DB::getDriverName()!=='pgsql')
            throw new RuntimeException('SEO F123 signed evidence requires PostgreSQL');

        Schema::create('portal_seo_build_evidence_nonces',function(Blueprint $t):void {
            $t->string('tenant_ref',80);
            $t->char('public_key_fingerprint',64);
            $t->char('nonce_digest',64);
            $t->string('run_ref',100);
            $t->char('engine_commit_sha',40);
            $t->unsignedBigInteger('received_at');
            $t->unsignedBigInteger('expires_at');
            $t->primary(
                ['tenant_ref','public_key_fingerprint','nonce_digest'],
                'seo_evidence_nonce_pk'
            );
            $t->index(['tenant_ref','expires_at'],'seo_evidence_tenant_expiry_idx');
        });
        DB::statement("ALTER TABLE portal_seo_build_evidence_nonces
            ADD CONSTRAINT seo_evidence_nonce_valid CHECK (
                tenant_ref ~ '^[a-z0-9][a-z0-9_-]{1,79}$'
                AND public_key_fingerprint ~ '^[a-f0-9]{64}$'
                AND nonce_digest ~ '^[a-f0-9]{64}$'
                AND run_ref ~ '^[a-zA-Z0-9:_-]{3,100}$'
                AND engine_commit_sha ~ '^[a-f0-9]{40}$'
                AND received_at>=1700000000
                AND received_at<=4102444800
                AND expires_at>received_at
                AND expires_at-received_at<=300
            )");
        DB::statement('ALTER TABLE portal_seo_build_evidence_nonces
            ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE portal_seo_build_evidence_nonces
            FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY seo_evidence_tenant_rls
            ON portal_seo_build_evidence_nonces
            FOR ALL
            USING (tenant_ref=current_setting('app.tenant_ref',true))
            WITH CHECK (tenant_ref=current_setting('app.tenant_ref',true))");
    }

    public function down():void
    {
        if(DB::getDriverName()!=='pgsql')
            throw new RuntimeException('SEO F123 rollback requires PostgreSQL');
        Schema::dropIfExists('portal_seo_build_evidence_nonces');
    }
};
