<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F137: Laravel's standard database session schema. Browser sessions MUST
 * still be encrypted, Secure, HttpOnly and backed by an authorized runtime
 * DB role before any staging activation. CI uses disposable PostgreSQL18.
 */
return new class extends Migration {
    public function up():void
    {
        if(DB::getDriverName()!=='pgsql')
            throw new RuntimeException('F137 Portal session storage requires PostgreSQL');

        Schema::create('sessions',function(Blueprint $table):void {
            $table->string('id',255)->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address',45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down():void
    {
        if(DB::getDriverName()!=='pgsql')
            throw new RuntimeException('F137 session rollback requires PostgreSQL');
        Schema::dropIfExists('sessions');
    }
};
