<?php
declare(strict_types=1);
// CLI fixture only; UI authorization is covered separately by PostgreSQL/HTTP tests.
if (PHP_SAPI!=='cli'||getenv('APP_ENV')==='production'||getenv('PORTAL_PURCHASE_PREVIEW_ENABLED')!=='1')exit(1);
try {
    $id=$argv[1]??'';if(!preg_match('/\ATEST-[a-f0-9]{32}\z/D',$id))throw new RuntimeException();
    require __DIR__.'/../vendor/autoload.php';
    $path='/run/secrets/preview-runtime.json';$stat=stat($path);
    if(!$stat||($stat['mode']&077)!==0||$stat['uid']!==82)throw new RuntimeException();
    $config=json_decode(file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
    $db=new PDO('pgsql:host=database;port=5432;dbname=portal_purchase_rehearsal;connect_timeout=3','purchase_rehearsal_a',$config['database_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);unset($config);
    $input=json_decode(stream_get_contents(STDIN,16384),true,16,JSON_THROW_ON_ERROR);
    if(!is_array($input)||array_diff(array_keys($input),['period','actual','evidence_ref']))throw new RuntimeException();
    $q=$db->prepare("SELECT revision FROM purchase_rehearsal.customer_orders WHERE id=? AND product='performance'");$q->execute([$id]);$revision=$q->fetchColumn();if($revision===false)throw new RuntimeException();
    (new App\Homologation\Commerce\PerformanceSettlement($db))->submit($id,(int)$revision,$input['period'],$input['actual'],$input['evidence_ref']);
    echo "PASS operator synthetic Performance report persisted; no PSP operation\n";
}catch(Throwable){fwrite(STDERR,"Synthetic report fixture failed; no credentials displayed\n");exit(1);}
