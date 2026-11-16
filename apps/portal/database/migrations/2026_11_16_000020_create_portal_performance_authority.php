<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up():void {
  if(DB::getDriverName()!=='pgsql')throw new RuntimeException('PostgreSQL required');
  DB::unprepared("CREATE TABLE portal_performance_services (
   company_id uuid PRIMARY KEY, contract_ref uuid NOT NULL, contract_version bigint NOT NULL CHECK(contract_version>0),
   company_active boolean NOT NULL DEFAULT false, status text NOT NULL CHECK(status IN ('active','suspended','cancelled')),
   source_kind text NOT NULL CHECK(source_kind IN ('synthetic_persisted','portal_contract_authority')),
   expires_at bigint NOT NULL, revoked_at bigint, updated_at timestamptz NOT NULL DEFAULT now());
  CREATE TABLE portal_performance_grants (
   id uuid PRIMARY KEY, admin_user_id bigint NOT NULL REFERENCES portal_admin_users(id), company_id uuid NOT NULL REFERENCES portal_performance_services(company_id),
   operation text NOT NULL CHECK(operation IN ('dashboard.read','clients.read','reports.read')),
   role text NOT NULL CHECK(role IN ('superadmin','operations','analyst','ai_supervisor','finance','auditor')),
   revision bigint NOT NULL CHECK(revision>0), expires_at bigint NOT NULL, revoked_at bigint,
   UNIQUE(admin_user_id,company_id,operation));
  CREATE TABLE portal_performance_access_audit (
   id uuid PRIMARY KEY, admin_user_id bigint NOT NULL, company_id uuid NOT NULL, operation text NOT NULL,
   correlation_id uuid NOT NULL, outcome text NOT NULL CHECK(outcome IN ('granted','denied','completed','failed','revoked')),
   lease_digest text, observed_at bigint NOT NULL);");
  foreach(['portal_performance_services','portal_performance_grants','portal_performance_access_audit'] as $table){
   DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
   $actor=$table==='portal_performance_services'?'':" AND admin_user_id::text=current_setting('app.performance_actor',true)";
   DB::statement("CREATE POLICY performance_scope ON $table USING(company_id::text=current_setting('app.performance_company',true)$actor) WITH CHECK(company_id::text=current_setting('app.performance_company',true)$actor)");
  }
 }
 public function down():void {throw new RuntimeException('Preserve Performance authority and audit history; disable feature instead');}
};
