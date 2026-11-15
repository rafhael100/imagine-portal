<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F127: synthetic trusted-public-key registry, separate from the signed DTO.
 *
 * This does not provision KMS, issue real keys, authenticate a session or
 * authorize deployment. Apply only to disposable PG18 test infrastructure
 * until production identity and key-governance gates have been approved.
 */
return new class extends Migration {
    public function up():void
    {
        if(DB::getDriverName()!=='pgsql')
            throw new RuntimeException('SEO F127 key registry requires PostgreSQL');

        Schema::create('portal_seo_build_signing_keys',function(Blueprint $t):void {
            $t->string('tenant_ref',80);
            $t->string('signer_ref',80);
            $t->char('public_key_fingerprint',64);
            $t->char('public_key_base64',44);
            $t->string('key_status',12);
            $t->unsignedBigInteger('not_before');
            $t->unsignedBigInteger('expires_at');
            $t->unsignedBigInteger('revoked_at')->nullable();
            $t->primary([
                'tenant_ref','signer_ref','public_key_fingerprint'
            ],'seo_build_key_tenant_signer_pk');
            $t->index([
                'tenant_ref','signer_ref','key_status','expires_at'
            ],'seo_build_key_active_idx');
        });

        DB::statement("ALTER TABLE portal_seo_build_signing_keys
            ADD CONSTRAINT seo_build_key_integrity CHECK (
                tenant_ref ~ '^[a-z0-9][a-z0-9_-]{1,79}$'
                AND signer_ref ~ '^[a-z0-9][a-z0-9_-]{1,79}$'
                AND public_key_fingerprint ~ '^[a-f0-9]{64}$'
                AND public_key_base64 ~ '^[A-Za-z0-9+/]{43}=$'
                AND key_status IN ('active','revoked')
                AND not_before>=1700000000 AND not_before<=4102444800
                AND expires_at>not_before AND expires_at<=4102444800
                AND ((key_status='active' AND revoked_at IS NULL)
                     OR (key_status='revoked' AND
                         revoked_at IS NOT NULL AND revoked_at>=not_before))
            )");
        DB::statement('ALTER TABLE portal_seo_build_signing_keys
            ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE portal_seo_build_signing_keys
            FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY seo_build_keys_tenant_rls
            ON portal_seo_build_signing_keys FOR ALL
            USING (tenant_ref=current_setting('app.tenant_ref',true))
            WITH CHECK (tenant_ref=current_setting('app.tenant_ref',true))");
    }

    public function down():void
    {
        if(DB::getDriverName()!=='pgsql')
            throw new RuntimeException('SEO F127 key rollback requires PostgreSQL');
        Schema::dropIfExists('portal_seo_build_signing_keys');
    }
};
