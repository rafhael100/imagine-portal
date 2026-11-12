<?php
declare(strict_types=1);
if(getenv('APP_ENV')!=='testing'||getenv('PORTAL_BROWSER_PG_LAB')!=='DISPOSABLE_PG18'
 ||getenv('PGHOST')!=='127.0.0.1'||getenv('PGPORT')!=='5432'||getenv('PGDATABASE')!=='portal_browser_lab'){
 fwrite(STDERR,"Disposable concurrency laboratory required.\n");exit(1);
}
require __DIR__.'/../../vendor/autoload.php';
try{
 $input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
 $db=new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_browser_lab','portal_browser_runtime',
 (string)getenv('PORTAL_BROWSER_RUNTIME_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
 $adapter=new App\Infrastructure\Identity\PostgresPortalBrowserAuthentication($db,'portal_browser_lab',
 $input['proof'],base64_decode($input['key'],true),$input['pepper']);
 $issued=$adapter->completeMfa($input['pending'],$input['code'],'192.0.2.20')!==null;
 echo json_encode(['issued'=>$issued],JSON_THROW_ON_ERROR);
}catch(Throwable){fwrite(STDERR,"MFA concurrency worker failed.\n");exit(1);}
