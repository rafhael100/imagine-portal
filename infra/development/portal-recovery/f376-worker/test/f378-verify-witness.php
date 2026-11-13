<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||$argc!==5
    ||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_F378_CI')!=='DISPOSABLE_ONLY')exit(64);

require dirname(__DIR__,5).'/apps/portal/app/Infrastructure/Recovery/IndependentWitnessCandidateVerifier.php';
use App\Infrastructure\Recovery\IndependentWitnessCandidateVerifier as Verifier;

try {
    $expected=$argv[1]==='stale'?'WITNESS_RESTORED_EPOCH_CONFLICT':
        ($argv[1]==='matched'?'WITNESS_MATCHED_STILL_NO_SERVING':null);
    if($expected===null)throw new RuntimeException();
    $read=static function(string $filename):string{
        $stat=lstat($filename);
        if(!is_array($stat)||($stat['mode']&0170000)!==0100000
            ||($stat['mode']&0077)!==0||$stat['size']<1||$stat['size']>4096)
            throw new RuntimeException();
        return (string)file_get_contents($filename);
    };
    $snapshot=json_decode($read($argv[2]),true,8,JSON_THROW_ON_ERROR);
    $receipt=json_decode($read($argv[3]),true,8,JSON_THROW_ON_ERROR);
    $public=trim($read($argv[4]));
    $result=(new Verifier())->verify($snapshot,$receipt,$public,time());
    if($result['reasonCode']!==$expected)throw new RuntimeException();
    foreach(['canAuthorizeHttp','canAuthorizeBusiness',
             'canReactivateSession','canRecoverFromRestore','canDeploy']as$field){
        if($result[$field]!==false)throw new RuntimeException();
    }
    echo "PASS F378: independent signed Worker witness comparison $expected; NO SERVING.\n";
}catch(Throwable){
    fwrite(STDERR,"FAIL F378: signed witness mismatch or malformed evidence.\n");
    exit(1);
}
