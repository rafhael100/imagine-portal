<?php
// Owned GitHub service only. No generic VPS/local installation helper.
require __DIR__.'/../../vendor/autoload.php';$app=require __DIR__.'/../../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$completed=false;
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,"PHASE5_PREPARATION_DENIED\n");exit(1);});
register_shutdown_function(static function()use(&$completed){if(!$completed){fwrite(STDERR,"PHASE5_PREPARATION_INCOMPLETE\n");exit(1);}});
use Illuminate\Support\Facades\DB;
$c=config('database.connections.pgsql');
if(getenv('CI')!=='true'||getenv('ADMIN_PHASE5_CI')!=='1'||!app()->environment('testing')||config('database.default')!=='pgsql'
 ||$c['host']!=='127.0.0.1'||(string)$c['port']!=='5432'||$c['database']!=='performance_phase4_ci'||$c['username']!=='postgres'
 ||!empty($c['url'])||isset($c['read'])||isset($c['write'])||!empty($c['options']))throw new RuntimeException;
$db=DB::connection('pgsql');$pdo=$db->getPdo();$db->setReconnector(static function(){throw new RuntimeException('Reconnect denied');});
$r=$db->selectOne("SELECT current_database() AS db,session_user AS login,current_user AS actor,host(inet_server_addr()) AS addr,inet_server_port() AS port,(SELECT system_identifier::text FROM pg_control_system()) AS cluster");
if(!$r||$r->db!==$c['database']||$r->login!==$c['username']||$r->actor!==$r->login||(int)$r->port!==5432||$r->addr!==getenv('PHASE5_EXPECTED_DB_ADDR')||$r->cluster!==getenv('PHASE5_EXPECTED_CLUSTER'))throw new RuntimeException;
if(Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true,'--no-interaction'=>true])!==0||$db->getPdo()!==$pdo)throw new RuntimeException;
$completed=true;echo json_encode(['schema'=>'PHASE5_DISPOSABLE_PREPARATION_V1','completed'=>true,'same_connection'=>true])."\n";
