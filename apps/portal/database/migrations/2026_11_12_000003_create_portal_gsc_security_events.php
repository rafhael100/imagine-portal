<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DRAFT: metadata-only GSC security audit, no secret/body or Google OAuth.
 * Must be DBA/LGPD reviewed before use outside disposable PostgreSQL CI.
 */
return new class extends Migration
{
    public function up():void
    {
        if (DB::getDriverName()!=='pgsql')
            throw new RuntimeException('GSC audit requires PostgreSQL');
        Schema::create('portal_gsc_security_events',function(Blueprint $table):void{
            $table->char('event_id',32)->primary();
            $table->string('event_code',48);
            $table->string('tenant_ref',64);
            $table->string('actor_ref',64);
            $table->char('property_fingerprint',64)->nullable();
            $table->unsignedBigInteger('event_at');
            $table->index(['event_at','event_id'],'portal_gsc_audit_retention_idx');
        });
        DB::statement("ALTER TABLE portal_gsc_security_events
            ADD CONSTRAINT portal_gsc_audit_id
            CHECK (event_id ~ '^[0-9a-f]{32}$')");
        DB::statement("ALTER TABLE portal_gsc_security_events
            ADD CONSTRAINT portal_gsc_audit_event
            CHECK (event_code IN (
                'oauth_pkce_prepared','oauth_callback_denied',
                'oauth_state_consumed','oauth_token_rejected',
                'property_grant_revoked','property_access_suspended'))");
        DB::statement("ALTER TABLE portal_gsc_security_events
            ADD CONSTRAINT portal_gsc_audit_fingerprint
            CHECK (property_fingerprint IS NULL OR
                property_fingerprint ~ '^[0-9a-f]{64}$')");
    }
    public function down():void
    {
        Schema::dropIfExists('portal_gsc_security_events');
    }
};
