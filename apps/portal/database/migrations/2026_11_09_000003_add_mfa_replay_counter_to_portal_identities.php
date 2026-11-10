<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F70: monotonic per-identity TOTP counter for future replay protection.
 *
 * NO RLS policies, login, or authenticator store are enabled. This is
 * only a schema-level compare-and-swap target. A PostgreSQL restore could
 * roll it backwards; SEC-R03 external anti-rollback remains mandatory.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || !Schema::hasTable('portal_identities')) {
            throw new RuntimeException('F70 requires reviewed F66 PostgreSQL identity schema.');
        }

        Schema::table('portal_identities', function (Blueprint $table): void {
            $table->bigInteger('mfa_last_used_counter')->default(-1);
        });
        DB::statement(
            'ALTER TABLE portal_identities ADD CONSTRAINT portal_identity_mfa_counter_ck '
            . 'CHECK (mfa_last_used_counter >= -1)'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('F70 rollback requires PostgreSQL.');
        }
        DB::statement('ALTER TABLE portal_identities DROP CONSTRAINT IF EXISTS portal_identity_mfa_counter_ck');
        Schema::table('portal_identities', function (Blueprint $table): void {
            $table->dropColumn('mfa_last_used_counter');
        });
    }
};
