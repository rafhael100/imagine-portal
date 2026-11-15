<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Review-only schema for the one-use OAuth state ledger.
 * PostgreSQL only. No OAuth routes, tokens or customer data in this migration.
 * Run only in an authorized isolated environment after independent DBA review.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('GSC OAuth state ledger requires isolated PostgreSQL');
        }
        Schema::create('portal_gsc_oauth_states', function (Blueprint $table): void {
            $table->char('state_hash', 64)->primary();
            $table->string('tenant_ref', 64);
            $table->string('actor_ref', 64);
            $table->text('sealed_verifier');
            $table->unsignedBigInteger('issued_at');
            $table->unsignedBigInteger('expires_at');
            $table->unsignedBigInteger('consumed_at')->nullable();
            $table->index('expires_at', 'portal_gsc_oauth_states_expires_idx');
        });
        DB::statement("ALTER TABLE portal_gsc_oauth_states
            ADD CONSTRAINT portal_gsc_oauth_states_hash
            CHECK (state_hash ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE portal_gsc_oauth_states
            ADD CONSTRAINT portal_gsc_oauth_states_sealed
            CHECK (sealed_verifier LIKE 'v1.%')");
        DB::statement('ALTER TABLE portal_gsc_oauth_states
            ADD CONSTRAINT portal_gsc_oauth_states_ttl
            CHECK (expires_at - issued_at = 600)');
        DB::statement('ALTER TABLE portal_gsc_oauth_states
            ADD CONSTRAINT portal_gsc_oauth_states_consumption
            CHECK (consumed_at IS NULL OR
                (consumed_at >= issued_at AND consumed_at <= expires_at))');
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_gsc_oauth_states');
    }
};
