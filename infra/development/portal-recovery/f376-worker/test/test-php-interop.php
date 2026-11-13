<?php
declare(strict_types=1);
require dirname(__DIR__,5).'/apps/portal/app/Infrastructure/Recovery/IndependentWitnessCandidateVerifier.php';

use App\Infrastructure\Recovery\IndependentWitnessCandidateVerifier as Verifier;

if(PHP_SAPI!=='cli'||$argc!==2
    ||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_F376_CI')!=='DISPOSABLE_ONLY')exit(64);
try {
    $dir=realpath($argv[1]);
    if(!is_string($dir))throw new RuntimeException();
    $read=static function(string $name)use($dir):string{
        $path=$dir.'/'.$name;
        if(is_link($path)||!is_file($path)||filesize($path)>4096)throw new RuntimeException();
        return (string)file_get_contents($path);
    };
    $snapshot=json_decode($read('portal-snapshot.json'),true,8,JSON_THROW_ON_ERROR);
    $receipt=json_decode($read('witness.json'),true,8,JSON_THROW_ON_ERROR);
    $public=trim($read('signing-public.hex'));
    if(!is_array($snapshot)||!is_array($receipt))throw new RuntimeException();
    $verifier=new Verifier();
    $result=$verifier->verify($snapshot,$receipt,$public,time());
    if($result['reasonCode']!=='WITNESS_MATCHED_STILL_NO_SERVING'
        ||$result['canAuthorizeHttp']!==false
        ||$result['canAuthorizeBusiness']!==false
        ||$result['canDeploy']!==false)throw new RuntimeException();
    $old=$snapshot;
    $old['session_revision']--;
    $old['witnessed_sequence']--;
    $result=$verifier->verify($old,$receipt,$public,time());
    if($result['reasonCode']!=='WITNESS_RESTORED_EPOCH_CONFLICT')throw new RuntimeException();
    $forged=$receipt;
    $forged['sequence']++;
    $result=$verifier->verify($snapshot,$forged,$public,time());
    if($result['reasonCode']!=='WITNESS_SIGNATURE_DENIED')throw new RuntimeException();
    echo "PASS F376 PHP interoperability: Cloudflare Worker-style Ed25519 signature accepted, stale snapshot and forgery denied, no serving.\n";
}catch(Throwable){
    fwrite(STDERR,"FAIL F376: witness signature not compatible with Portal verifier\n");
    exit(1);
}
