<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F134: persistent admin session generation. Rotating it revokes all
 * existing authenticated AND password-pending sessions without needing a
 * shared cache to enumerate opaque session IDs.
 */
return new class extends Migration {
    public function up():void
    {
        if(DB::getDriverName()!=='pgsql')
            throw new RuntimeException('F134 admin session rotation needs PostgreSQL');
        Schema::table('portal_admin_users',function(Blueprint $t):void {
            $t->unsignedBigInteger('session_version')->default(1);
        });
        DB::statement('ALTER TABLE portal_admin_users
            ADD CONSTRAINT portal_admin_session_version_valid
            CHECK(session_version>=1 AND session_version<9223372036854775807)');
    }

    public function down():void
    {
        if(DB::getDriverName()!=='pgsql')
            throw new RuntimeException('F134 rollback needs PostgreSQL');
        Schema::table('portal_admin_users',function(Blueprint $t):void {
            $t->dropColumn('session_version');
        });
    }
};
