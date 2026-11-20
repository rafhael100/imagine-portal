<?php
declare(strict_types=1);
// Run BEFORE any F423 Performance decision DDL. Read-only checks of the effective
// Laravel environment AND actual TCP PostgreSQL connection used by the app.
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('PORTAL_PREVIEW_LOOPBACK_TEST')!=='1'
  || getenv('APP_ENV')!=='staging'||getenv('APP_DEBUG')!=='false'
  || getenv('APP_URL')!=='http://127.0.0.1:18083') exit(1);
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config('app.env')!=='staging'||config('app.url')!=='http://127.0.0.1:18083'
    || config('app.debug')!==false) exit(1);
$path='/run/secrets/preview-runtime.json';$stat=@stat($path);
if(!is_array($stat)||($stat['mode']&077)!==0||$stat['uid']!==82||$stat['size']>2048) exit(1);
$cfg=json_decode((string)@file_get_contents($path),true);
if(!is_array($cfg)||!preg_match('/\A[a-f0-9]{64}\z/D',$cfg['database_password']??'')) exit(1);
try {
 $db=new PDO('pgsql:host=database;port=5432;dbname=portal_purchase_rehearsal;connect_timeout=3',
   'purchase_rehearsal_a',$cfg['database_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $row=$db->query("SELECT current_database() d,current_user u,session_user s,
   current_setting('server_version_num')::int v,inet_server_addr() IS NOT NULL tcp")->fetch(PDO::FETCH_ASSOC);
 if($row['d']!=='portal_purchase_rehearsal'||$row['u']!=='purchase_rehearsal_a'
  ||$row['s']!=='purchase_rehearsal_a'||(int)$row['v']<180000||(int)$row['v']>=190000
  ||!in_array($row['tcp'],['t',true,'1',1],true)) throw new RuntimeException();
 $role=$db->query("SELECT rolsuper,rolbypassrls,rolcreatedb,rolcreaterole
   FROM pg_roles WHERE rolname=current_user")->fetch(PDO::FETCH_ASSOC);
 foreach($role as $flag) if(in_array($flag,[true,'t','1',1],true)) throw new RuntimeException();
 $review=$db->query("SELECT relrowsecurity,relforcerowsecurity
   FROM pg_class WHERE oid='purchase_rehearsal.f423_briefing_reviews'::regclass")->fetch(PDO::FETCH_ASSOC);
 foreach($review as $flag) if(!in_array($flag,[true,'t','1',1],true)) throw new RuntimeException();
 if(in_array($db->query("SELECT to_regclass('purchase_rehearsal.f423_performance_decisions') IS NOT NULL")->fetchColumn(),[true,'t','1',1],true))
   throw new RuntimeException();
} catch(Throwable) {
 fwrite(STDERR,"F423: missing effective Laravel/SQL disposable attestation; DDL forbidden\n"); exit(1);
}
echo "PASS F423 S2S PRE-DDL: Laravel staging + TCP PG18 unprivileged disposable role; decision ledger absent\n";
