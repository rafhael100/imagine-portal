<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F135: append-only event evidence for administrator session invalidation.
 * No email, IP, password, TOTP material or session token is recorded.
 *
 * A PostgreSQL superuser/table owner can still disable triggers. Independent
 * audit retention and privileged-access governance are production gates.
 */
return new class extends Migration {
    public function up():void
    {
        if(DB::getDriverName()!=='pgsql')
            throw new RuntimeException('F135 admin audit requires PostgreSQL');

        Schema::create('portal_admin_security_events',function(Blueprint $t):void {
            $t->bigIncrements('id');
            $t->char('event_ref',32)->unique();
            $t->unsignedBigInteger('admin_user_id');
            $t->string('event_code',30);
            $t->unsignedBigInteger('version_before');
            $t->unsignedBigInteger('version_after');
            $t->string('actor_context',40);
            $t->timestampTz('occurred_at');
            $t->index(['admin_user_id','occurred_at'],
                'portal_admin_security_subject_idx');
        });
        DB::statement("ALTER TABLE portal_admin_security_events
            ADD CONSTRAINT portal_admin_event_integrity CHECK (
                event_ref ~ '^[a-f0-9]{32}$'
                AND event_code IN ('sessions_revoked','admin_deactivated')
                AND actor_context IN (
                    'ci_synthetic_operator','staging_tty_unverified'
                )
                AND version_before>=1
                AND version_after=version_before+1
                AND version_after<9223372036854775807
                AND admin_user_id>0
            )");
        DB::unprepared(<<<'SQL'
CREATE FUNCTION public.portal_f135_reject_security_event_mutation()
RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog,public
AS $f135$
BEGIN
    RAISE EXCEPTION 'F135: admin security events are append-only'
        USING ERRCODE='23514';
END;
$f135$
SQL);
        DB::statement("CREATE TRIGGER portal_f135_append_only_security_event
            BEFORE UPDATE OR DELETE ON public.portal_admin_security_events
            FOR EACH ROW EXECUTE FUNCTION
                public.portal_f135_reject_security_event_mutation()");
    }

    public function down():void
    {
        if(DB::getDriverName()!=='pgsql')
            throw new RuntimeException('F135 audit rollback needs PostgreSQL');
        DB::statement('DROP TRIGGER IF EXISTS portal_f135_append_only_security_event
            ON public.portal_admin_security_events');
        DB::statement('DROP FUNCTION IF EXISTS
            public.portal_f135_reject_security_event_mutation()');
        Schema::dropIfExists('portal_admin_security_events');
    }
};
