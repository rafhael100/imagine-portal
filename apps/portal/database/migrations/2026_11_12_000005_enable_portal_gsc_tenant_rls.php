<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DRAFT tenant isolation at the PostgreSQL layer.
 * This does NOT authenticate a request. Only a trusted backend may set
 * app.tenant_ref in a transaction after independently verifying membership.
 */
return new class extends Migration
{
    /** Fixed identifiers only: never sourced from tenant/user input. */
    private const POLICIES=[
        'portal_gsc_oauth_states'=>'gsc_states_tenant_policy',
        'portal_gsc_property_grants'=>'gsc_grants_tenant_policy',
        'portal_gsc_security_events'=>'gsc_events_tenant_policy',
        'portal_gsc_oauth_token_candidates'=>'gsc_tokens_tenant_policy'
    ];

    public function up():void
    {
        if(DB::getDriverName()!=='pgsql')
            throw new RuntimeException('GSC tenant RLS requires PostgreSQL');
        foreach(self::POLICIES as $table=>$policy) {
            if(!Schema::hasTable($table))
                throw new RuntimeException('GSC RLS missing prerequisite table');
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY {$policy} ON {$table}
                FOR ALL
                USING (tenant_ref = current_setting('app.tenant_ref',true))
                WITH CHECK (tenant_ref = current_setting('app.tenant_ref',true))");
        }
    }

    public function down():void
    {
        if(DB::getDriverName()!=='pgsql')
            throw new RuntimeException('GSC tenant RLS requires PostgreSQL');
        foreach(array_reverse(self::POLICIES,true) as $table=>$policy) {
            DB::statement("DROP POLICY IF EXISTS {$policy} ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
