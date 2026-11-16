<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Additive candidate only. No user, grant or company eligibility is seeded.
return new class extends Migration {
 public function up():void {
  if(DB::getDriverName()!=='pgsql')throw new RuntimeException('PostgreSQL required');
  Schema::create('portal_automation_grants',function(Blueprint $t){
   $t->uuid('id')->primary();$t->foreignId('admin_user_id')->constrained('portal_admin_users')->restrictOnDelete();
   $t->uuid('company_id');$t->string('operation',40);$t->unsignedInteger('revision');$t->string('state',16);
   $t->unsignedBigInteger('expires_at');$t->unsignedBigInteger('revoked_at')->nullable();$t->timestampsTz();
   $t->unique(['admin_user_id','company_id','operation']);
  });
  DB::statement("ALTER TABLE portal_automation_grants ADD CONSTRAINT automation_grant_valid CHECK(operation='automation.state.read' AND revision>0 AND state IN ('active','revoked') AND expires_at BETWEEN 1700000000 AND 4102444800 AND ((state='active' AND revoked_at IS NULL) OR (state='revoked' AND revoked_at IS NOT NULL)))");
  Schema::create('portal_automation_access_audit',function(Blueprint $t){
   $t->uuid('id')->primary();$t->unsignedBigInteger('admin_user_id');$t->uuid('company_id');
   $t->string('operation',40);$t->string('outcome',16);$t->unsignedInteger('grant_revision')->nullable();
   $t->unsignedBigInteger('session_version');$t->unsignedBigInteger('observed_at');
  });
  DB::statement("ALTER TABLE portal_automation_access_audit ADD CONSTRAINT automation_audit_valid CHECK(operation='automation.state.read' AND outcome IN ('granted','denied') AND session_version>0 AND observed_at>0)");
  foreach(['portal_automation_grants','portal_automation_access_audit'] as $table){
   DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
   DB::statement("CREATE POLICY automation_actor_company ON $table USING(admin_user_id=NULLIF(current_setting('app.automation_admin_id',true),'')::bigint AND company_id=NULLIF(current_setting('app.automation_company_id',true),'')::uuid) WITH CHECK(admin_user_id=NULLIF(current_setting('app.automation_admin_id',true),'')::bigint AND company_id=NULLIF(current_setting('app.automation_company_id',true),'')::uuid)");
  }
 }
 public function down():void { throw new RuntimeException('Preserve automation grants/audit; roll back application only after review'); }
};
