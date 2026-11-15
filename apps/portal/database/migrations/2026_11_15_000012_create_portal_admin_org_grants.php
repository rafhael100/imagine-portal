<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F134: Portal admin organization grants are assigned by trusted server-side
 * database operations, never from self-service forms or incoming requests.
 *
 * This does NOT prove contracted service eligibility or property ownership.
 * The grant has no OAuth/GSC privilege and never authorizes publication.
 */
return new class extends Migration {
    public function up():void
    {
        if(DB::getDriverName()!=='pgsql')
            throw new RuntimeException('Portal admin grants require PostgreSQL');

        Schema::create('portal_admin_org_grants',function(Blueprint $t):void {
            $t->id();
            $t->foreignId('admin_user_id')
                ->constrained('portal_admin_users')
                ->cascadeOnDelete();
            $t->string('organization_ref',100);
            $t->string('permission',32);
            $t->string('status',16);
            $t->unsignedBigInteger('expires_at');
            $t->unsignedBigInteger('revoked_at')->nullable();
            $t->timestampsTz();
            $t->unique([
                'admin_user_id','organization_ref','permission'
            ],'portal_admin_org_grant_identity');
            $t->index([
                'organization_ref','permission','status'
            ],'portal_admin_org_active_idx');
        });
        DB::statement("ALTER TABLE portal_admin_org_grants
            ADD CONSTRAINT portal_admin_grant_integrity CHECK (
                organization_ref ~ '^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,99}$'
                AND permission='seo.view'
                AND status IN ('active','revoked')
                AND expires_at>=1700000000
                AND expires_at<=4102444800
                AND ((status='active' AND revoked_at IS NULL)
                  OR (status='revoked' AND revoked_at IS NOT NULL))
            )");
    }

    public function down():void
    {
        if(DB::getDriverName()!=='pgsql')
            throw new RuntimeException('Portal admin grant rollback requires PostgreSQL');
        Schema::dropIfExists('portal_admin_org_grants');
    }
};
