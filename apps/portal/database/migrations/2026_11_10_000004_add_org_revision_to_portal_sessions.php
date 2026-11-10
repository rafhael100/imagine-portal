<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F76: organization security revision must be witnessed by each session.
 * NULL deliberately DENIES legacy sessions until securely re-issued.
 *
 * This migration does not grant RLS policy, access, or session authority.
 * It must not be deployed without a migration/revocation plan and DBA review.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || !Schema::hasTable('portal_auth_sessions')) {
            throw new RuntimeException('F76 requires PostgreSQL Portal identity foundation.');
        }
        Schema::table('portal_auth_sessions', function (Blueprint $table): void {
            // No DEFAULT: historical sessions must not implicitly inherit 1.
            $table->unsignedBigInteger('organization_revision')->nullable();
        });
        DB::statement(
            'ALTER TABLE portal_auth_sessions ADD CONSTRAINT portal_session_org_revision_ck '
            . 'CHECK (organization_revision IS NULL OR organization_revision > 0)'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('F76 rollback requires PostgreSQL.');
        }
        DB::statement(
            'ALTER TABLE portal_auth_sessions DROP CONSTRAINT IF EXISTS portal_session_org_revision_ck'
        );
        Schema::table('portal_auth_sessions', function (Blueprint $table): void {
            $table->dropColumn('organization_revision');
        });
    }
};
