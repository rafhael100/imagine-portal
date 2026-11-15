<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F132: dedicated Portal ADMIN credentials, not reused from CRM.
 *
 * No administrator is seeded. Activation, password/TOTP provisioning and
 * organization memberships require a separately approved operator flow.
 */
return new class extends Migration {
    public function up():void
    {
        if(DB::getDriverName()!=='pgsql')
            throw new RuntimeException('Portal admin login requires PostgreSQL');

        Schema::create('portal_admin_users',function(Blueprint $t):void {
            $t->id();
            $t->string('email',190)->unique();
            $t->string('password_hash',255);
            $t->text('totp_secret_ciphertext');
            $t->boolean('is_active')->default(false);
            $t->bigInteger('last_totp_counter')->default(-1);
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE portal_admin_users
            ADD CONSTRAINT portal_admin_totp_replay CHECK(
                last_totp_counter >= -1
            )");
        DB::statement("ALTER TABLE portal_admin_users
            ADD CONSTRAINT portal_admin_email_canonical CHECK(
                email=lower(email) AND email LIKE '%@%'
            )");
    }

    public function down():void
    {
        if(DB::getDriverName()!=='pgsql')
            throw new RuntimeException('Portal admin rollback requires PostgreSQL');
        Schema::dropIfExists('portal_admin_users');
    }
};
