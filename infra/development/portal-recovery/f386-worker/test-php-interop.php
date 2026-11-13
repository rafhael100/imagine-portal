<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'||$argc!==2
    ||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_F386_CI')!=='DISPOSABLE_ONLY')exit(64);

require dirname(__DIR__,4).'/apps/portal/app/Infrastructure/Recovery/ChallengeBoundWitnessCandidateVerifier.php';

use App\Infrastructure\Recovery\ChallengeBoundWitnessCandidateVerifier as Verifier;

try{
    $dir=realpath($argv[1]);
    if(!is_string($dir)||!is_dir($dir))throw new RuntimeException();
    $json=static function(string $name)use($dir):array{
        $p=$dir.'/'.$name;
        if(!is_file($p)||is_link($p)||filesize($p)<2||filesize($p)>4096) {
            throw new RuntimeException();
        }
        $data=json_decode((string)file_get_contents($p),true,8,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new RuntimeException();
        return $data;
    };
    $receipt=$json('receipt.json');
    $old=$json('old-snapshot.json');
    $new=$json('current-snapshot.json');
    $pub=$json('public.json')['hex']??'';
    $nonce=$receipt['nonce']??'';
    $check=static function(array $snapshot,array $proof,string $challenge,
        string $public,int $at,string $expected):void {
        $v=(new Verifier())->verify($snapshot,$proof,$challenge,$public,$at);
        if(($v['reasonCode']??null)!==$expected)throw new RuntimeException();
        foreach([
            'canAuthorizeHttp','canAuthorizeBusiness','canIssueSession',
            'canRecoverFromRestore','canDeploy',
        ] as $flag){
            if(($v[$flag]??null)!==false)throw new RuntimeException();
        }
    };
    $now=time();
    $check($old,$receipt,$nonce,$pub,$now,'WITNESS_EPOCH_CONFLICT');
    $check($new,$receipt,$nonce,$pub,$now,'WITNESS_MATCHED_STILL_NO_SERVING');
    $check($old,$receipt,str_repeat('0',64),$pub,$now,'WITNESS_NONCE_DENIED');
    $forged=$receipt;
    $forged['session_floor']++;
    $check($new,$forged,$nonce,$pub,$now,'WITNESS_SIGNATURE_DENIED');
    $check($new,$receipt,$nonce,str_repeat('0',64),$now,'WITNESS_SIGNATURE_DENIED');
    $check($new,$receipt,$nonce,$pub,$now+100,'WITNESS_FRESHNESS_DENIED');
    echo "PASS F386 PHP: 6 Ed25519 witness comparisons deny old PG epoch after external intent, including valid signatures; NO SERVING.\n";
}catch(Throwable){
    fwrite(STDERR,"FAIL F386: signed independent revocation intent interoperability\n");
    exit(1);
}
