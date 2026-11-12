<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** DRAFT: restricted token CANDIDATE storage; no operational authorization. */
return new class extends Migration
{
    public function up():void
    {
        if(DB::getDriverName()!=='pgsql')
            throw new RuntimeException('GSC vault requires isolated PostgreSQL');
        Schema::create('portal_gsc_oauth_token_candidates',
            function(Blueprint $t):void{
                $t->string('tenant_ref',64);
                $t->string('actor_ref',64);
                $t->char('property_fingerprint',64);
                $t->string('scope',128);
                $t->text('sealed_tokens');
                $t->unsignedBigInteger('issued_at');
                $t->unsignedBigInteger('expires_at');
                $t->unsignedBigInteger('revoked_at')->nullable();
                $t->primary(['tenant_ref','actor_ref','property_fingerprint'],
                    'portal_gsc_token_candidates_pk');
                $t->index('expires_at','portal_gsc_token_candidates_expiry_idx');
            });
        DB::statement("ALTER TABLE portal_gsc_oauth_token_candidates
            ADD CONSTRAINT portal_gsc_token_fingerprint
            CHECK (property_fingerprint ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE portal_gsc_oauth_token_candidates
            ADD CONSTRAINT portal_gsc_token_scope
            CHECK (scope='https://www.googleapis.com/auth/webmasters.readonly')");
        DB::statement("ALTER TABLE portal_gsc_oauth_token_candidates
            ADD CONSTRAINT portal_gsc_token_sealing
            CHECK (sealed_tokens LIKE 'v1.%' OR sealed_tokens LIKE 'v2.%')");
        DB::statement('ALTER TABLE portal_gsc_oauth_token_candidates
            ADD CONSTRAINT portal_gsc_token_time
            CHECK (expires_at>issued_at AND expires_at<=issued_at+3600
                AND (revoked_at IS NULL OR revoked_at>=issued_at))');
    }
    public function down():void
    {
        Schema::dropIfExists('portal_gsc_oauth_token_candidates');
    }
};
