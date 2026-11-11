<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GSC-F113: review-only, additive permission vocabulary.
 *
 * Adding a permission code does NOT grant it to any membership, enable RLS,
 * connect Google OAuth, issue a session, or authorize production traffic.
 * Do not deploy without independent DBA/AppSec review and rollback plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertPostgresIdentityFoundation();

        DB::statement('ALTER TABLE public.portal_membership_grants '
            .'DROP CONSTRAINT portal_grant_permission_ck');

        DB::statement(
            'ALTER TABLE public.portal_membership_grants '
            .'ADD CONSTRAINT portal_grant_permission_ck '
            ."CHECK (permission_code IN ("
            ."'services.view','services.manage','billing.view','members.manage',"
            ."'seo.gsc.connect'))"
        );
    }

    public function down(): void
    {
        $this->assertPostgresIdentityFoundation();

        // Never silently invalidate or delete granted permissions on rollback.
        // The migration runner must have privileged RLS visibility; a hidden
        // grant would also make PostgreSQL reject the new CHECK constraint.
        if (DB::table('portal_membership_grants')
            ->where('permission_code', 'seo.gsc.connect')
            ->exists()) {
            throw new RuntimeException(
                'GSC-F113 rollback refused: active seo.gsc.connect grants require explicit revocation and audit.'
            );
        }

        DB::statement('ALTER TABLE public.portal_membership_grants '
            .'DROP CONSTRAINT portal_grant_permission_ck');

        DB::statement(
            'ALTER TABLE public.portal_membership_grants '
            .'ADD CONSTRAINT portal_grant_permission_ck '
            ."CHECK (permission_code IN ("
            ."'services.view','services.manage','billing.view','members.manage'))"
        );
    }

    private function assertPostgresIdentityFoundation(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || !Schema::hasTable('portal_membership_grants')
            || !Schema::hasTable('portal_memberships')) {
            throw new RuntimeException(
                'GSC-F113 requires the existing PostgreSQL Portal identity foundation.'
            );
        }
    }
};
