<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up():void
    {
        if(DB::getDriverName()!=='pgsql')throw new RuntimeException('PostgreSQL required');
        Schema::create('performance_admin_lab_states',function(Blueprint $t):void{
            $t->string('tenant',32)->primary();$t->jsonb('payload');$t->unsignedInteger('revision');$t->timestampsTz();
        });
        Schema::create('performance_admin_lab_memberships',function(Blueprint $t):void{
            $t->id();$t->foreignId('admin_user_id')->constrained('portal_admin_users');
            $t->string('tenant',32);$t->string('role',32);$t->boolean('active')->default(false);
            $t->unsignedBigInteger('expires_at');$t->unique(['admin_user_id','tenant']);
        });
        Schema::create('performance_admin_lab_events',function(Blueprint $t):void{
            $t->id();$t->string('tenant',32);$t->foreignId('admin_user_id')->constrained('portal_admin_users');
            $t->uuid('idempotency_key');$t->string('request_hash',64);$t->jsonb('event');$t->timestampsTz();
            $t->unique(['tenant','admin_user_id','idempotency_key'],'perf_admin_lab_once');
        });
        foreach(['states','memberships','events'] as $table)DB::statement("ALTER TABLE performance_admin_lab_{$table} ADD CONSTRAINT perf_lab_{$table}_tenant CHECK (tenant IN ('lab-alfa','lab-beta'))");
        DB::statement("ALTER TABLE performance_admin_lab_memberships ADD CONSTRAINT perf_lab_role CHECK (role IN ('superadmin','operations','analyst','ai_supervisor','finance','auditor'))");
        DB::unprepared("CREATE FUNCTION perf_admin_lab_event_immutable() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Administrative events are immutable'; END $$; CREATE TRIGGER perf_admin_lab_event_guard BEFORE UPDATE OR DELETE ON performance_admin_lab_events FOR EACH ROW EXECUTE FUNCTION perf_admin_lab_event_immutable()");
    }
    public function down():void
    {
        Schema::dropIfExists('performance_admin_lab_events');DB::statement('DROP FUNCTION IF EXISTS perf_admin_lab_event_immutable()');
        Schema::dropIfExists('performance_admin_lab_memberships');Schema::dropIfExists('performance_admin_lab_states');
    }
};
