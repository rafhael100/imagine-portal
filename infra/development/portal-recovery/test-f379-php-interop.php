<?php
declare(strict_types=1);

if (PHP_SAPI!=='cli'||$argc!==2
    ||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_F379_CI')!=='DISPOSABLE_ONLY')exit(64);

require dirname(__DIR__,3).'/apps/portal/app/Infrastructure/Recovery/ChallengeBoundWitnessCandidateVerifier.php';

use App\Infrastructure\Recovery\ChallengeBoundWitnessCandidateVerifier as Verifier;

try {
    $dir=realpath($argv[1]);
    if(!is_string($dir)||!is_dir($dir))throw new RuntimeException();
    $read=static function(string $name)use($dir):array{
        $path=$dir.'/'.$name;
        if(is_link($path)||!is_file($path)
            ||filesize($path)<1||filesize($path)>4096)throw new RuntimeException();
        $data=json_decode((string)file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new RuntimeException();
        return $data;
    };
    $old=$read('old-receipt.json');
    $new=$read('new-receipt.json');
    $legacy=$read('legacy-receipt.json');
    $oldSnapshot=$read('old-snapshot.json');
    $newSnapshot=$read('new-snapshot.json');
    $nonces=$read('nonces.json');
    $public=$read('public-key.json')['hex']??'';
    $a=$nonces['first'];
    $b=$nonces['second'];
    $v=new Verifier();
    $checks=0;
    $assess=static function(array $snap,array $receipt,string $expectedNonce,
        string $key,int $time,string $reason)use($v,&$checks):void{
        $result=$v->verify($snap,$receipt,$expectedNonce,$key,$time);
        if(($result['reasonCode']??null)!==$reason) {
            throw new RuntimeException('F379 unexpected negative reason');
        }
        foreach(['canAuthorizeHttp','canAuthorizeBusiness','canIssueSession',
                 'canRecoverFromRestore','canDeploy']as$field){
            if(($result[$field]??null)!==false)throw new RuntimeException('F379 unauthorized privilege');
        }
        $checks++;
    };
    $now=time();
    $matched='WITNESS_MATCHED_STILL_NO_SERVING';
    $conflict='WITNESS_EPOCH_CONFLICT';
    $contract='WITNESS_CONTRACT_DENIED';
    $assess($oldSnapshot,$old,$a,$public,$now,$matched);
    $assess($newSnapshot,$new,$b,$public,$now,$matched);
    $assess($oldSnapshot,$new,$b,$public,$now,$conflict);
    $assess($newSnapshot,$old,$a,$public,$now,$conflict);
    $assess($oldSnapshot,$old,$b,$public,$now,'WITNESS_NONCE_DENIED');
    $assess($newSnapshot,$new,$a,$public,$now,'WITNESS_NONCE_DENIED');
    $assess($newSnapshot,[...$new,'nonce'=>$a],$a,$public,$now,
        'WITNESS_SIGNATURE_DENIED');
    $assess($newSnapshot,[...$new,'sequence'=>12],$b,$public,$now,
        'WITNESS_SIGNATURE_DENIED');
    $assess($newSnapshot,[...$new,'signature'=>str_repeat('0',128)],$b,$public,$now,
        'WITNESS_SIGNATURE_DENIED');
    $assess($newSnapshot,$new,$b,str_repeat('0',64),$now,
        'WITNESS_SIGNATURE_DENIED');
    $assess($newSnapshot,$new,'not-hex',$public,$now,$contract);
    $assess($newSnapshot,[...$new,'nonce'=>str_repeat('F',64)],$b,$public,$now,$contract);
    $assess($newSnapshot,[...$new,'nonce'=>true],$b,$public,$now,$contract);
    $assess($newSnapshot,[...$new,'nonce'=>$b,'extra'=>'unsafe'],$b,$public,$now,$contract);
    $assess([...$newSnapshot,'session_revision'=>true],$new,$b,$public,$now,$contract);
    $assess([...$newSnapshot,'extra'=>'untrusted'],$new,$b,$public,$now,$contract);
    $assess([...$newSnapshot,'organization'=>'other_org'],$new,$b,$public,$now,
        'WITNESS_SCOPE_DENIED');
    $assess($newSnapshot,$legacy,$b,$public,$now,$contract);
    $assess($newSnapshot,$new,$b,$public,$now+65,'WITNESS_FRESHNESS_DENIED');
    $assess($newSnapshot,$new,$b,$public,$now-40,'WITNESS_FRESHNESS_DENIED');
    $assess($newSnapshot,[...$new,'expires_at'=>$now+150],$b,$public,$now,
        'WITNESS_FRESHNESS_DENIED');
    $assess($newSnapshot,$new,$b,'invalid',$now,$contract);
    echo "PASS F379 PHP: {$checks} signed Worker nonce-replay/legacy/epoch/freshness negative cases; no serving.\n";
} catch(Throwable) {
    fwrite(STDERR,"FAIL F379: challenged Worker/PHP signature interoperability\n");
    exit(1);
}
