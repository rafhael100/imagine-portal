<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F138: shared PostgreSQL cache backing for password/MFA rate limits across
 * app processes in staging. Cache arrays per worker are not sufficient for
 * real administrative login throttling.
 */
return new class extends Migration {
    public function up():void
    {
        if(DB::getDriverName()!=='pgsql')
            throw new RuntimeException('F138 rate limiter cache needs PostgreSQL');

        Schema::create('cache',function(Blueprint $t):void {
            $t->string('key')->primary();
            $t->mediumText('value');
            $t->integer('expiration');
        });
        Schema::create('cache_locks',function(Blueprint $t):void {
            $t->string('key')->primary();
            $t->string('owner');
            $t->integer('expiration');
        });
    }

    public function down():void
    {
        if(DB::getDriverName()!=='pgsql')
            throw new RuntimeException('F138 cache rollback requires PostgreSQL');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
    }
};

