<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||$argc!==4
    ||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_F377_CI')!=='DISPOSABLE_ONLY'
    ||getenv('PORTAL_F378_CI')!=='DISPOSABLE_ONLY')exit(64);

require dirname(__DIR__,5).'/apps/portal/app/Infrastructure/Recovery/PinnedHttpsWitnessPublisherCandidate.php';
use App\Infrastructure\Recovery\PinnedHttpsWitnessPublisherCandidate as Publisher;

try {
    $dir=realpath($argv[1]);
    if(!is_string($dir)||!is_dir($dir)||!is_file($argv[3]))throw new RuntimeException();
    $key=trim((string)file_get_contents($dir.'/publisher-hmac.b64'));
    $publisher=new Publisher(
        'https://witness.f377.test.invalid:18444/v1/portal/witness-advance',
        $argv[2],'org_f377','instance_f377',$argv[3],
        'witness.f377.test.invalid:18444:127.0.0.1',
    );
    // Witness first, with no local transaction committed yet.
    $result=$publisher->attemptAdvance(12,5,4,7,$key);
    if($result['status']!=='LAB_WITNESS_ADVANCED_STILL_NO_SERVING'
        ||$result['canAuthorizeHttp']!==false
        ||$result['canRecoverFromRestore']!==false)throw new RuntimeException();
    echo "PASS F378: independently witnessed epoch 13 before Portal PostgreSQL writes; NO SERVING.\n";
}catch(Throwable){
    fwrite(STDERR,"FAIL F378: witness-first publish attempt refused.\n");
    exit(1);
}
