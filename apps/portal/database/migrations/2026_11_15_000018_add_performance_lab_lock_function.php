<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up():void {
  DB::unprepared("CREATE FUNCTION public.performance_admin_lab_lock_member(actor bigint, scope text)
   RETURNS SETOF public.performance_admin_lab_memberships LANGUAGE sql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
   SELECT * FROM public.performance_admin_lab_memberships WHERE admin_user_id=actor AND tenant=scope AND active=true AND expires_at>extract(epoch FROM clock_timestamp())::bigint FOR UPDATE $$;
   REVOKE ALL ON FUNCTION public.performance_admin_lab_lock_member(bigint,text) FROM PUBLIC");
 }
 public function down():void {DB::statement('DROP FUNCTION public.performance_admin_lab_lock_member(bigint,text)');}
};
