<?php
require __DIR__.'/../../../vendor/autoload.php';$app=require __DIR__.'/../../../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(getenv('CI')!=='true'||getenv('ADMIN_PHASE4_PG_CI')!=='1'||getenv('DB_DATABASE')!=='performance_phase4_ci')exit(1);
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
$secret=bin2hex(random_bytes(20));$password=bin2hex(random_bytes(24));$email='phase4-browser-'.Str::uuid().'@example.test';$company='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$id=DB::table('portal_admin_users')->insertGetId(['email'=>$email,'password_hash'=>Hash::make($password),'totp_secret_ciphertext'=>Crypt::encryptString($secret),'is_active'=>true,'last_totp_counter'=>-1,'session_version'=>1,'created_at'=>now(),'updated_at'=>now()]);
DB::table('portal_performance_services')->insert(['company_id'=>$company,'contract_ref'=>(string)Str::uuid(),'contract_version'=>1,'company_active'=>true,'status'=>'active','source_kind'=>'synthetic_persisted','expires_at'=>time()+600]);
DB::table('portal_performance_grants')->insert(['id'=>(string)Str::uuid(),'admin_user_id'=>$id,'company_id'=>$company,'operation'=>'dashboard.read','role'=>'analyst','revision'=>1,'expires_at'=>time()+600]);
$owner=new PDO(getenv('PHASE4_RECEIVER_ADMIN_DSN'),getenv('PHASE4_RECEIVER_OWNER_USER'),getenv('PHASE4_RECEIVER_OWNER_PASSWORD'));
$owner->exec('UPDATE performance_admin_inbox.service_delegations SET active=true,revoked_at=NULL,revision=revision+1');
$second=['email'=>'phase4-second-'.Str::uuid().'@example.test','password'=>bin2hex(random_bytes(24)),'secret'=>bin2hex(random_bytes(20)),'company'=>'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'];
$other=DB::table('portal_admin_users')->insertGetId(['email'=>$second['email'],'password_hash'=>Hash::make($second['password']),'totp_secret_ciphertext'=>Crypt::encryptString($second['secret']),'is_active'=>true,'last_totp_counter'=>-1,'session_version'=>1,'created_at'=>now(),'updated_at'=>now()]);
DB::table('portal_performance_services')->insert(['company_id'=>$second['company'],'contract_ref'=>(string)Str::uuid(),'contract_version'=>1,'company_active'=>true,'status'=>'active','source_kind'=>'synthetic_persisted','expires_at'=>time()+600]);
DB::table('portal_performance_grants')->insert(['id'=>(string)Str::uuid(),'admin_user_id'=>$other,'company_id'=>$second['company'],'operation'=>'dashboard.read','role'=>'auditor','revision'=>1,'expires_at'=>time()+600]);
umask(0077);file_put_contents(getenv('ADMIN_PHASE4_TLS').'/browser-vault.json',json_encode(compact('email','password','secret','company','second')));
