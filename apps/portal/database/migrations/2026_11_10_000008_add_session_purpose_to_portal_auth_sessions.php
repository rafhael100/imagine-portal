<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PORTAL-F113: deny-by-default session purpose separation.
 *
 * No session issuer, login, HTTP access or business authorization is enabled.
 * Legacy rows have NULL purpose and MUST be denied by all future typed gates.
 * Independently reviewed issuer is required before writing any new purpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || !Schema::hasTable('portal_auth_sessions')
            || !Schema::hasColumn('portal_auth_sessions', 'organization_revision')) {
            throw new RuntimeException('F113 requires F66 + F76 PostgreSQL session foundation.');
        }

        Schema::table('portal_auth_sessions', function (Blueprint $table): void {
            // NULL means legacy/unclassified and NEVER means business.
            $table->string('session_purpose', 24)->nullable();
        });

        foreach ([
            "ALTER TABLE portal_auth_sessions ADD CONSTRAINT portal_session_purpose_ck
             CHECK (session_purpose IS NULL
               OR session_purpose IN ('business','invitation_only'))",
            "ALTER TABLE portal_auth_sessions ADD CONSTRAINT portal_invitation_session_lifetime_ck
             CHECK (session_purpose IS DISTINCT FROM 'invitation_only'
               OR (organization_revision IS NOT NULL
                 AND expires_at <= issued_at + interval '1 hour'))",
            "ALTER TABLE portal_auth_sessions ADD CONSTRAINT portal_business_session_lifetime_ck
             CHECK (session_purpose IS DISTINCT FROM 'business'
               OR (organization_revision IS NOT NULL
                 AND expires_at <= issued_at + interval '1 day'))",
        ] as $sql) {
            DB::statement($sql);
        }

        // No UPDATE may silently upgrade an invitation credential into a
        // business credential, not even when revisions happen to match.
        // A future credential rotation issues a NEW session reference/row.
        DB::statement(<<<'SQL'
CREATE FUNCTION public.portal_f113_reject_session_purpose_change()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog, pg_temp
AS $f113$
BEGIN
  IF NEW.session_purpose IS DISTINCT FROM OLD.session_purpose THEN
    RAISE EXCEPTION 'Session purpose cannot change after issuance'
      USING ERRCODE='23514';
  END IF;
  RETURN NEW;
END;
$f113$
SQL);
        DB::statement(<<<'SQL'
CREATE TRIGGER portal_f113_session_purpose_immutable
BEFORE UPDATE OF session_purpose ON public.portal_auth_sessions
FOR EACH ROW
EXECUTE FUNCTION public.portal_f113_reject_session_purpose_change()
SQL);

        // Deliberately NO RLS allow policy, table grant or HTTP adapter.
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('F113 rollback requires PostgreSQL.');
        }
        DB::statement(
            'DROP TRIGGER IF EXISTS portal_f113_session_purpose_immutable '
            .'ON public.portal_auth_sessions'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS public.portal_f113_reject_session_purpose_change()'
        );
        foreach ([
            'portal_business_session_lifetime_ck',
            'portal_invitation_session_lifetime_ck',
            'portal_session_purpose_ck',
        ] as $constraint) {
            DB::statement(
                "ALTER TABLE public.portal_auth_sessions DROP CONSTRAINT IF EXISTS {$constraint}"
            );
        }
        Schema::table('portal_auth_sessions', function (Blueprint $table): void {
            $table->dropColumn('session_purpose');
        });
    }
};
