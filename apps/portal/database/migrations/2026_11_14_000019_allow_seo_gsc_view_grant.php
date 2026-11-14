<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F110: expand the closed Portal permission vocabulary with SEO metrics VIEW.
 * This is NOT a grant issuer, an OAuth allowance or an HTTP authorization.
 * The existing seo.gsc.connect remains separate; no rows are modified.
 *
 * The migration is a REVIEW candidate only; production requires independent
 * AppSec/DBA approval, database backup and an approved release.
 */
return new class extends Migration
{
    private const ORIGINAL = [
        'services.view',
        'services.manage',
        'billing.view',
        'members.manage',
        'seo.gsc.connect',
    ];

    public function up(): void
    {
        $this->requireFoundation();

        DB::statement('ALTER TABLE public.portal_membership_grants '
            .'DROP CONSTRAINT portal_grant_permission_ck');

        DB::statement('ALTER TABLE public.portal_membership_grants '
            .'ADD CONSTRAINT portal_grant_permission_ck '
            .self::checkSql([...self::ORIGINAL,'seo.gsc.view']));
    }

    public function down(): void
    {
        $this->requireFoundation();

        // A rollback must NOT strand or implicitly destroy any view grants.
        // Force-RLS means a nonprivileged caller could see zero rows despite
        // real grants. Refuse downgrade unless migration connection has
        // proven global row visibility.
        $role=DB::selectOne(
            'SELECT rolsuper,rolbypassrls FROM pg_roles WHERE rolname=current_user'
        );
        if (!is_object($role) ||
            (!self::pgTrue($role->rolsuper??null) &&
             !self::pgTrue($role->rolbypassrls??null))) {
            throw new RuntimeException(
                'GSC-F110 rollback requires a reviewed globally visible migration identity.'
            );
        }

        $grants=DB::table('portal_membership_grants')
            ->where('permission_code','seo.gsc.view')->count();
        if ($grants!==0) {
            throw new RuntimeException(
                'GSC-F110 rollback refused: seo.gsc.view grants require audited revocation first.'
            );
        }

        DB::statement('ALTER TABLE public.portal_membership_grants '
            .'DROP CONSTRAINT portal_grant_permission_ck');

        DB::statement('ALTER TABLE public.portal_membership_grants '
            .'ADD CONSTRAINT portal_grant_permission_ck '
            .self::checkSql(self::ORIGINAL));
    }

    private static function pgTrue(mixed $value): bool
    {
        return in_array($value,[true,'t','1',1],true);
    }

    /** Whitelist is a constant compiled here, not SQL built from users. */
    private static function checkSql(array $codes): string
    {
        foreach ($codes as $code) {
            if (!in_array($code,[...self::ORIGINAL,'seo.gsc.view'],true)) {
                throw new RuntimeException('GSC-F110 unexpected permission code.');
            }
        }

        return 'CHECK (permission_code IN ('
            .implode(',',array_map(
                static fn(string $code):string => "'".$code."'",
                $codes
            )).'))';
    }

    private function requireFoundation(): void
    {
        if (DB::getDriverName()!=='pgsql'
            || !Schema::hasTable('portal_membership_grants')
            || !Schema::hasTable('portal_memberships')) {
            throw new RuntimeException(
                'GSC-F110 requires Portal identity PostgreSQL foundation.'
            );
        }

        $check=DB::selectOne(
            "SELECT COUNT(*)::integer AS count FROM pg_constraint
             WHERE conrelid='public.portal_membership_grants'::regclass
               AND conname='portal_grant_permission_ck' AND contype='c'"
        );
        if (!is_object($check) || (int)($check->count??0)!==1) {
            throw new RuntimeException(
                'GSC-F110 cannot replace an unknown or missing check constraint.'
            );
        }
    }
};
