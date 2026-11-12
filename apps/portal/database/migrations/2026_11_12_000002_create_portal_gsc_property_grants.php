<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REVIEW ONLY. Does not provision or verify any real Google property grant.
 * Only a future authenticated Portal/Google service may write these records.
 */
return new class extends Migration
{
    public function up(): void
    {
        if(DB::getDriverName()!=='pgsql'){
            throw new RuntimeException('GSC grant ledger requires isolated PostgreSQL');
        }
        Schema::create('portal_gsc_property_grants',function(Blueprint $table):void{
            $table->string('tenant_ref',64);
            $table->string('actor_ref',64);
            $table->char('property_fingerprint',64);
            $table->string('scope',128);
            $table->string('grant_status',16);
            $table->unsignedBigInteger('verified_at');
            $table->unsignedBigInteger('expires_at');
            $table->unsignedBigInteger('revoked_at')->nullable();
            $table->primary(['tenant_ref','actor_ref','property_fingerprint'],
                'portal_gsc_grants_tenant_actor_property_pk');
            $table->index('expires_at','portal_gsc_grants_expires_idx');
        });
        DB::statement("ALTER TABLE portal_gsc_property_grants
            ADD CONSTRAINT portal_gsc_grants_property_hash
            CHECK (property_fingerprint ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE portal_gsc_property_grants
            ADD CONSTRAINT portal_gsc_grants_readonly_scope
            CHECK (scope='https://www.googleapis.com/auth/webmasters.readonly')");
        DB::statement("ALTER TABLE portal_gsc_property_grants
            ADD CONSTRAINT portal_gsc_grants_valid_status
            CHECK (grant_status IN ('active','revoked'))");
        DB::statement("ALTER TABLE portal_gsc_property_grants
            ADD CONSTRAINT portal_gsc_grants_time
            CHECK (expires_at >= verified_at AND
                (revoked_at IS NULL OR revoked_at>=verified_at))");
    }

    public function down():void
    {
        Schema::dropIfExists('portal_gsc_property_grants');
    }
};
