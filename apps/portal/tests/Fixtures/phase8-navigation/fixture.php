<?php
declare(strict_types=1);
// Fixed synthetic users, existing ephemeral cluster identity and owner-only
// one-shots. No SQL/identity/scope is accepted from a browser or CLI argument.
$stage='guard';
try {
 if(PHP_SAPI!=='cli'||getenv('GITHUB_ACTIONS')!=='true'||getenv('ADMIN_PHASE7_CI')!=='1'||getenv('ADMIN_PHASE8_NAVIGATION_CI')!=='1')throw new RuntimeException;
 $kind=$argv[1]??'';$action=$argv[2]??'';
 if(!in_array($kind,['portal','receiver'],true)||!in_array($action,['prepare','snapshot','clients-only','revoke-clients','restore-clients','revoke-reports','restore-reports'],true))throw new RuntimeException;
 $host=$kind==='portal'?'portal-db':'performance-db';$database=$kind==='portal'?'portal_p6_synthetic':'performance_p6_synthetic';
 $owner=$kind==='portal'?'portal_p6_migrator':'performance_p6_migrator';$ca=$kind==='portal'?'/run/pki/portal_db_ca.pem':'/run/private/db-ca.pem';
 $db=new PDO("pgsql:host=$host;port=5432;dbname=$database;sslmode=verify-full;sslrootcert=$ca",$owner,trim(file_get_contents('/run/phase7/owner-password')),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $identity=$db->query("SELECT current_database() db,current_user actor,(SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()) tls,(SELECT system_identifier::text FROM pg_control_system()) cluster")->fetch(PDO::FETCH_ASSOC);
 if($identity['db']!==$database||$identity['actor']!==$owner||!$identity['tls']||!hash_equals(trim(file_get_contents('/run/phase7/cluster')),$identity['cluster']))throw new RuntimeException;
 $companies=['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'];$operations=['dashboard.read','clients.read','reports.read'];
 if($kind==='portal'){
  $stage='portal-bootstrap';require '/var/www/html/vendor/autoload.php';$app=require '/var/www/html/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  if(config('app.env')!=='staging'||config('database.connections.pgsql.username')!==$owner||config('database.connections.pgsql.database')!==$database||config('database.connections.pgsql.sslmode')!=='verify-full')throw new RuntimeException;
  if($action==='prepare'){
   $stage='portal-users';$vault=json_decode(file_get_contents('/run/phase7/navigation-vault.json'),true,16,JSON_THROW_ON_ERROR);
   if(count($vault['users'])!==2)throw new RuntimeException;
   Illuminate\Support\Facades\DB::transaction(function()use($vault,$companies,$operations){
    foreach($vault['users'] as $index=>$user){
     if($user['email']!=='phase8-navigation-'.$index.'@example.test'||$user['company']!==$companies[$index])throw new RuntimeException;
     if(Illuminate\Support\Facades\DB::table('portal_admin_users')->where('email',$user['email'])->exists())throw new RuntimeException;
     if(!Illuminate\Support\Facades\DB::table('portal_performance_services')->where('company_id',$user['company'])->where('source_kind','synthetic_persisted')->where('status','active')->exists())throw new RuntimeException;
     $id=Illuminate\Support\Facades\DB::table('portal_admin_users')->insertGetId(['email'=>$user['email'],'password_hash'=>Illuminate\Support\Facades\Hash::make($user['password']),
      'totp_secret_ciphertext'=>Illuminate\Support\Facades\Crypt::encryptString($user['secret']),'is_active'=>true,'last_totp_counter'=>-1,'session_version'=>1,'created_at'=>now(),'updated_at'=>now()]);
     foreach($index===0?$operations:['reports.read'] as $op)Illuminate\Support\Facades\DB::table('portal_performance_grants')->insert(['id'=>(string)Illuminate\Support\Str::uuid(),'admin_user_id'=>$id,'company_id'=>$user['company'],
      'operation'=>$op,'role'=>'analyst','revision'=>1,'expires_at'=>time()+1800]);
    }
   });
  }elseif($action==='snapshot'){
   $rows=$db->query("SELECT a.company_id::text,a.operation,a.correlation_id::text FROM portal_performance_access_audit a JOIN portal_admin_users u ON u.id=a.admin_user_id WHERE u.email IN ('phase8-navigation-0@example.test','phase8-navigation-1@example.test') AND a.outcome='completed' ORDER BY a.correlation_id")->fetchAll(PDO::FETCH_ASSOC);
   echo json_encode(['completed'=>$rows],JSON_THROW_ON_ERROR).PHP_EOL;exit(0);
  }elseif($action==='clients-only'){
   $stage='portal-client-only';$actor=(int)$db->query("SELECT id FROM portal_admin_users WHERE email='phase8-navigation-1@example.test'")->fetchColumn();if($actor<1)throw new RuntimeException;
   Illuminate\Support\Facades\DB::transaction(function()use($actor,$companies){
    $count=Illuminate\Support\Facades\DB::table('portal_performance_grants')->where('admin_user_id',$actor)->where('company_id',$companies[1])->where('operation','reports.read')->whereNull('revoked_at')->update(['revoked_at'=>time(),'revision'=>2]);if($count!==1)throw new RuntimeException;
    Illuminate\Support\Facades\DB::table('portal_performance_grants')->insert(['id'=>(string)Illuminate\Support\Str::uuid(),'admin_user_id'=>$actor,'company_id'=>$companies[1],
     'operation'=>'clients.read','role'=>'analyst','revision'=>1,'expires_at'=>time()+1800]);
   });
  }elseif($action==='revoke-clients'||$action==='restore-clients'){
   $stage='portal-client-grant';$value=$action==='revoke-clients'?time():null;
   $stmt=$db->prepare("UPDATE portal_performance_grants SET revoked_at=?,revision=revision+1 WHERE admin_user_id=(SELECT id FROM portal_admin_users WHERE email='phase8-navigation-0@example.test') AND company_id=? AND operation='clients.read'");$stmt->execute([$value,$companies[0]]);if($stmt->rowCount()!==1)throw new RuntimeException;
  }else throw new RuntimeException;
 }else{
  if($action==='prepare'){
   $stage='receiver-delegations';$fingerprint=trim(file_get_contents('/run/phase7/certificate-sha256'));if(!preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint))throw new RuntimeException;
   $db->beginTransaction();
   foreach($companies as $company)foreach(['clients.read','reports.read'] as $op){
    $stmt=$db->prepare("INSERT INTO performance_admin_inbox.service_delegations(database_login,issuer,key_id,certificate_sha256,company_id,operation,revision,active,expires_at,revoked_at) SELECT database_login,issuer,key_id,certificate_sha256,company_id,?,1,true,expires_at,NULL FROM performance_admin_inbox.service_delegations WHERE database_login='performance_p6_reader' AND issuer='portal_service' AND key_id='phase7_key_01' AND certificate_sha256=? AND company_id=? AND operation='dashboard.read' AND active AND revoked_at IS NULL AND expires_at>now()");
    $stmt->execute([$op,$fingerprint,$company]);if($stmt->rowCount()!==1)throw new RuntimeException;
   }
   $db->commit();
  }elseif($action==='snapshot'){
   $stage='receiver-snapshot';$queries=['first'=>['page'=>1],'november'=>['period'=>'2026-11','page'=>2],'december'=>['period'=>'2026-12','page'=>3]];$rows=[];
   foreach($db->query('SELECT company_id,correlation_id,request_digest,response FROM performance_admin_inbox.receipts ORDER BY correlation_id')->fetchAll(PDO::FETCH_ASSOC) as $row){
    $response=json_decode($row['response'],true,16,JSON_THROW_ON_ERROR);$op=$response['operation'];
    $stmt=$db->prepare("SELECT revision,extract(epoch FROM expires_at)::bigint AS expires_epoch FROM performance_admin_inbox.service_delegations WHERE database_login='performance_p6_reader' AND issuer='portal_service' AND key_id='phase7_key_01' AND company_id=? AND operation=?");$stmt->execute([$row['company_id'],$op]);$grant=$stmt->fetch(PDO::FETCH_ASSOC);$matches=[];
    if($grant){
     $revision=hash('sha256',json_encode(['portal_service',$row['company_id'],$op,$grant],JSON_THROW_ON_ERROR));
     foreach($queries as $label=>$query){$body=json_encode((object)$query,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);$digest=hash('sha256',"imagine.performance.admin.s2s.v1\n".$op."\n".hash('sha256',$body)."\n".$revision);if(hash_equals($digest,$row['request_digest']))$matches[]=$label;}
    }
    $rows[]=['company_id'=>$row['company_id'],'operation'=>$op,'correlation_id'=>$row['correlation_id'],'query_matches'=>$matches,
     'status'=>$response['status'],'metrics_null'=>$response['metrics']===null,'billable'=>$response['billable'],'external_operations_enabled'=>$response['external_operations_enabled']];
   }
   echo json_encode(['receipts'=>$rows],JSON_THROW_ON_ERROR).PHP_EOL;exit(0);
  }elseif($action==='revoke-reports'||$action==='restore-reports'){
   $stage='receiver-report-grant';$active=$action==='restore-reports'?'true':'false';
   $stmt=$db->prepare("UPDATE performance_admin_inbox.service_delegations SET active=$active,revision=revision+1 WHERE database_login='performance_p6_reader' AND issuer='portal_service' AND key_id='phase7_key_01' AND company_id=? AND operation='reports.read'");$stmt->execute([$companies[0]]);if($stmt->rowCount()!==1)throw new RuntimeException;
  }else throw new RuntimeException;
 }
 echo 'PHASE8_NAVIGATION_FIXTURE_OK'.PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'PHASE8_NAVIGATION_FIXTURE_DENIED stage='.$stage.' type='.get_class($e).PHP_EOL);exit(1);}
