<?php
declare(strict_types=1);
require __DIR__.'/../composite-cart-f351/SyntheticDraftWriter.php';

use App\Homologation\CompositeCartLab\SyntheticDraftWriter;

/** CLI worker for REAL concurrent PG18 CI. No route or reusable API. */
if (PHP_SAPI!=='cli'||getenv('PORTAL_F352_DISPOSABLE')!=='SYNTHETIC_CONCURRENT_ONLY'
    ||getenv('PGHOST')!=='127.0.0.1'
    ||getenv('PGDATABASE')!=='portal_composite_f350_ci'
    ||count($argv)!==4) {
    fwrite(STDERR,"F352: disposable CLI worker scope required\n");
    exit(2);
}
[$script,$role,$request,$variant]=$argv;
if (!in_array($role,['f350_tenant_a_reader','f350_tenant_b_reader'],true)||
    !preg_match('/\Af351-[a-z0-9-]{1,72}\z/D',$request)||
    !in_array($variant,['reference','changed'],true)){
    fwrite(STDERR,"F352: invalid synthetic worker selection\n");
    exit(2);
}
$selection=$variant==='reference'
    ? ['site'=>'onepage','channels'=>['google_ads','meta_ads'],'tier'=>'recommended']
    : ['site'=>'landing-pages','channels'=>['meta_ads'],'tier'=>'minimum'];
try{
    $conn=new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_composite_f350_ci;connect_timeout=4;sslmode=disable',
        'postgres','f350-disposable-admin-only',[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false
        ]);
    $result=(new SyntheticDraftWriter($conn))->write($role,$request,$selection);
    echo json_encode(['draftId'=>$result['draftId'],'replayed'=>$result['replayed'],
        'releaseStatus'=>$result['releaseStatus']],JSON_THROW_ON_ERROR)."\n";
}catch(InvalidArgumentException){
    // Replayed key with a changed payload is a SECURITY HOLD, not success.
    fwrite(STDERR,"F352: synthetic replay mismatch held\n");
    exit(3);
}catch(Throwable){
    fwrite(STDERR,"F352: synthetic concurrent worker failed\n");
    exit(2);
}
