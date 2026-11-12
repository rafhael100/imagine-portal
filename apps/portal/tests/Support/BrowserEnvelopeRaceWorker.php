<?php
declare(strict_types=1);
if(getenv('APP_ENV')!=='testing'||getenv('PORTAL_BROWSER_PG_LAB')!=='DISPOSABLE_PG18'
 ||getenv('PGHOST')!=='127.0.0.1'||getenv('PGPORT')!=='5432'||getenv('PGDATABASE')!=='portal_browser_lab'){
 fwrite(STDERR,"Disposable envelope laboratory required.\n");exit(1);
}
require __DIR__.'/../../vendor/autoload.php';
try{
 $input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
 $db=new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_browser_lab','portal_browser_envelope_runtime',
 (string)getenv('PORTAL_BROWSER_ENVELOPE_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
 $cipher=new Illuminate\Encryption\Encrypter(base64_decode($input['key'],true),'AES-256-CBC');
 $handler=new App\Infrastructure\Identity\EncryptedPostgresPortalSessionHandler($db,'portal_browser_lab',$cipher);
 $store=new Illuminate\Session\Store('__Host-imagine-portal-session',$handler,$input['id'],'json');
 $store->start();
 $counter=(int)$store->get('counter',0);
 usleep(100000);
 $store->put('counter',$counter+1);
 $store->save();
 echo json_encode(['done'=>true],JSON_THROW_ON_ERROR);
}catch(Throwable){fwrite(STDERR,"Envelope concurrency worker failed.\n");exit(1);}
