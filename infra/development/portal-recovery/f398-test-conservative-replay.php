<?php
declare(strict_types=1);

/**
 * F398: at-least-once laboratory cursor scan must stop at first unresolved
 * intent; signed empty cursor alone never proves queue finished.
 */
if(PHP_SAPI!=='cli'||$argc!==2
    ||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_F398_CI')!=='DISPOSABLE_ONLY')exit(64);

$root=dirname(__DIR__,4).'/apps/portal/app/Infrastructure/Recovery/';
foreach([
    'PortalHashedSessionStatusCandidate.php',
    'SignedRevocationCursorCandidateVerifier.php',
    'SignedRevocationRetryLeaseCandidateVerifier.php',
    'SignedRevocationAckClaimCandidateVerifier.php',
    'SignedRevocationRecoveryTriageCandidate.php',
    'ConservativeSignedRevocationScanCandidate.php',
]as$name)require $root.$name;

use App\Infrastructure\Recovery\PortalHashedSessionStatusCandidate;
use App\Infrastructure\Recovery\SignedRevocationCursorCandidateVerifier;
use App\Infrastructure\Recovery\SignedRevocationRetryLeaseCandidateVerifier;
use App\Infrastructure\Recovery\SignedRevocationAckClaimCandidateVerifier;
use App\Infrastructure\Recovery\SignedRevocationRecoveryTriageCandidate;
use App\Infrastructure\Recovery\ConservativeSignedRevocationScanCandidate;

final class F398RestrictedPgStatus implements PortalHashedSessionStatusCandidate
{
    public string $status='not_revoked';
    public int $reads=0;
    public function __construct(
        private readonly string $organization,
        private readonly string $digest,
    ) {}
    public function inspectSessionDigestStatus(string $sessionSha256,string $organization):string
    {
        $this->reads++;
        if($sessionSha256!==$this->digest||$organization!==$this->organization){
            throw new RuntimeException('Unexpected tenant/status read');
        }
        return $this->status;
    }
}
try{
    $dir=realpath($argv[1]);
    if(!is_string($dir)||!is_dir($dir))throw new RuntimeException();
    $read=static function(string $name)use($dir):array{
        $path=$dir.'/'.$name;
        if(!is_file($path)||is_link($path)||filesize($path)<2||filesize($path)>12000){
            throw new RuntimeException();
        }
        $v=json_decode((string)file_get_contents($path),true,12,JSON_THROW_ON_ERROR);
        if(!is_array($v))throw new RuntimeException();
        return $v;
    };
    $f=$read('active.json');
    $expired=$read('expired.json');
    $expected=$read('expected.json');
    $public=$read('public.json')['hex']??'';
    $sql=new F398RestrictedPgStatus(
        $expected['organization'],$expected['session_sha256']
    );
    $triage=new SignedRevocationRecoveryTriageCandidate(
        new SignedRevocationCursorCandidateVerifier(),
        new SignedRevocationRetryLeaseCandidateVerifier(),
        new SignedRevocationAckClaimCandidateVerifier(),
        $sql,$expected['organization'],$expected['instance'],$public
    );
    $scanner=new ConservativeSignedRevocationScanCandidate($triage);
    $item=static function(array $fixture,bool $includeAck=true):array{
        return [
            'cursor'=>$fixture['cursor'],
            'cursorNonce'=>$fixture['nonces']['cursor'],
            'lease'=>$fixture['lease'],
            'leaseDigest'=>$fixture['lease']['lease_sha256'],
            'leaseNonce'=>$fixture['nonces']['lease'],
            'ack'=>$includeAck?$fixture['ack']:null,
            'ackNonce'=>$includeAck?$fixture['nonces']['ack']:null,
        ];
    };
    $first=$item($f);
    $empty=[
        'cursor'=>$f['empty'],
        'cursorNonce'=>$f['nonces']['empty'],
        'lease'=>null,
        'leaseDigest'=>null,
        'leaseNonce'=>null,
        'ack'=>null,
        'ackNonce'=>null,
    ];
    $checks=0;
    $verify=static function(array $out,string $reason,int $observed,int $last)
        use(&$checks):void{
        if(($out['reasonCode']??null)!==$reason
            ||($out['verifiedLocalRowsObserved']??null)!==$observed
            ||($out['lastVolatileObservedSequence']??null)!==$last
            ||($out['isDurableCheckpoint']??null)!==false){
            throw new RuntimeException('F398 wrong at-least-once scan result');
        }
        foreach([
            'canAuthorizeHttp','canAuthorizeBusiness','canIssueSession',
            'canRecoverFromRestore','canDeploy','canClaimPostgresRevoked',
            'canClaimQueueFullyProcessed','canSkipReconciliation',
        ]as$field){
            if(($out[$field]??null)!==false)throw new RuntimeException();
        }
        $checks++;
    };
    $now=$f['at'];
    $verify($scanner->inspectBatch([], $now),
        'SCAN_NO_INPUT_NOT_AUTHORITY',0,0);
    $verify($scanner->inspectBatch(array_fill(0,17,$first),$now),
        'SCAN_LIMIT_OR_CLOCK_DENIED',0,0);
    $verify($scanner->inspectBatch([$first],-1),
        'SCAN_LIMIT_OR_CLOCK_DENIED',0,0);
    $verify($scanner->inspectBatch([$first],$now),
        'SCAN_HALTED_UNRESOLVED',0,0);
    if($sql->reads!==1)throw new RuntimeException('Expected restricted status read');

    $sql->status='revoked';
    $verify($scanner->inspectBatch([$first,$empty],$now),
        'SCAN_SIGNED_EMPTY_NOT_QUEUE_COMPLETE',1,13);
    $firstReads=$sql->reads;
    $verify($scanner->inspectBatch([$first,$empty],$now),
        'SCAN_SIGNED_EMPTY_NOT_QUEUE_COMPLETE',1,13);
    if($sql->reads!==$firstReads+1)throw new RuntimeException('Restart did not replay SQL');
    $verify($scanner->inspectBatch([$first],$now),
        'SCAN_MORE_SIGNED_PAGES_REQUIRED',1,13);
    $verify($scanner->inspectBatch([$first,$first],$now),
        'SCAN_HALTED_UNRESOLVED',1,13);
    $verify($scanner->inspectBatch([$empty],$now),
        'SCAN_HALTED_UNRESOLVED',0,0);

    $verify($scanner->inspectBatch([$item($f,false)],$now),
        'SCAN_HALTED_UNRESOLVED',0,0);
    $bad=$first;
    $bad['ack']['signature']=str_repeat('0',128);
    $verify($scanner->inspectBatch([$bad,$empty],$now),
        'SCAN_HALTED_UNRESOLVED',0,0);
    $bad=$first;
    $bad['cursor']['signature']=str_repeat('0',128);
    $verify($scanner->inspectBatch([$bad],$now),
        'SCAN_HALTED_UNRESOLVED',0,0);
    $bad=$first;
    $bad['leaseNonce']=str_repeat('0',64);
    $verify($scanner->inspectBatch([$bad],$now),
        'SCAN_HALTED_UNRESOLVED',0,0);
    $bad=$first;
    $bad['unexpected']='not-allowed';
    $verify($scanner->inspectBatch([$bad],$now),
        'SCAN_INPUT_CONTRACT_DENIED',0,0);
    $sql->status='missing';
    $verify($scanner->inspectBatch([$first,$empty],$now),
        'SCAN_HALTED_UNRESOLVED',0,0);
    $sql->status='not_revoked';
    $verify($scanner->inspectBatch([$item($expired,false)],$expired['at']),
        'SCAN_HALTED_UNRESOLVED',0,0);

    echo "PASS F398: {$checks} signed bounded cursor replay scenarios; forged ACK/active/missing/expired/duplicate halt, empty never means processed, NO SERVING.\n";
}catch(Throwable){
    fwrite(STDERR,"FAIL F398: bounded independent signed cursor replay contract\n");
    exit(1);
}
