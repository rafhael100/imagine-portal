<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F129: immutable-ish append-only review events for signer key revocation.
 *
 * UPDATE/DELETE privilege is NOT granted to the review service or its audit
 * writer by any migration. Production governance, key issuer verification
 * and DB recovery still require independent approval.
 */
return new class extends Migration {
    public function up():void
    {
        if(DB::getDriverName()!=='pgsql')
            throw new RuntimeException('SEO F129 revocation audit requires PostgreSQL');

        Schema::create('portal_seo_build_signer_events',function(Blueprint $t):void {
            $t->string('tenant_ref',80);
            $t->char('event_digest',64);
            $t->string('signer_ref',80);
            $t->char('public_key_fingerprint',64);
            $t->string('actor_ref',64);
            $t->string('event_code',32);
            $t->unsignedBigInteger('occurred_at');
            $t->primary(['tenant_ref','event_digest'],'seo_signer_event_pk');
            $t->index(['tenant_ref','signer_ref','occurred_at'],
                'seo_signer_event_tenant_time_idx');
        });
        DB::statement("ALTER TABLE portal_seo_build_signer_events
            ADD CONSTRAINT seo_signer_event_integrity CHECK (
                tenant_ref ~ '^[a-z0-9][a-z0-9_-]{1,79}$'
                AND event_digest ~ '^[a-f0-9]{64}$'
                AND signer_ref ~ '^[a-z0-9][a-z0-9_-]{1,79}$'
                AND public_key_fingerprint ~ '^[a-f0-9]{64}$'
                AND actor_ref ~ '^[a-z0-9][a-z0-9_-]{2,63}$'
                AND event_code='signer_key_revoked'
                AND occurred_at>=1700000000
                AND occurred_at<=4102444800
            )");
        DB::statement('ALTER TABLE portal_seo_build_signer_events
            ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE portal_seo_build_signer_events
            FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY seo_signer_events_tenant_rls
            ON portal_seo_build_signer_events FOR ALL
            USING (tenant_ref=current_setting('app.tenant_ref',true))
            WITH CHECK (tenant_ref=current_setting('app.tenant_ref',true))");
    }

    public function down():void
    {
        if(DB::getDriverName()!=='pgsql')
            throw new RuntimeException('SEO F129 audit rollback requires PostgreSQL');
        Schema::dropIfExists('portal_seo_build_signer_events');
    }
};
