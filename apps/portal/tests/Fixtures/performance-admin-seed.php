<?php
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(getenv('CI')!=='true'||!(app()->environment('testing')||(app()->environment('staging')&&App\Domain\PerformanceAdministration\PrivateDemonstrationPolicy::allowed()))||getenv('PERFORMANCE_ADMIN_PG_CI')!=='1'||!str_starts_with(config('database.connections.pgsql.database'),'perf_admin_'))throw new RuntimeException('Disposable CI only');
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use App\Domain\PerformanceAdministration\PinnedLaboratoryTransport;
$engine=new PinnedLaboratoryTransport();
foreach(['lab-alfa','lab-beta'] as $tenant)DB::table('performance_admin_lab_states')->insert(['tenant'=>$tenant,'revision'=>1,'payload'=>json_encode($engine->fixture($tenant,time()),JSON_THROW_ON_ERROR),'created_at'=>now(),'updated_at'=>now()]);
$secret=random_bytes(20);$password=bin2hex(random_bytes(24));$email='browser-'.bin2hex(random_bytes(8)).'@example.test';
$id=DB::table('portal_admin_users')->insertGetId(['email'=>$email,'password_hash'=>Hash::make($password),'totp_secret_ciphertext'=>Crypt::encryptString(bin2hex($secret)),'is_active'=>true,'last_totp_counter'=>-1,'session_version'=>1,'created_at'=>now(),'updated_at'=>now()]);
foreach(['lab-alfa','lab-beta'] as $tenant)DB::table('performance_admin_lab_memberships')->insert(['admin_user_id'=>$id,'tenant'=>$tenant,'role'=>'superadmin','active'=>true,'expires_at'=>time()+3600]);
$path=getenv('PERFORMANCE_ADMIN_BROWSER_VAULT');if(!$path||!is_dir(dirname($path)))throw new RuntimeException('Private CI vault path required');
umask(0077);file_put_contents($path,json_encode(['email'=>$email,'password'=>$password,'secret'=>bin2hex($secret)],JSON_THROW_ON_ERROR));echo "Administrative CI fixtures created; no credentials displayed.\n";
