<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'||$argc!==2
    ||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_F396_CI')!=='DISPOSABLE_ONLY')exit(64);

$root=dirname(__DIR__,4).'/apps/portal/app/Infrastructure/Recovery/';
foreach([
    'PortalHashedSessionStatusCandidate.php',
    'SignedRevocationCursorCandidateVerifier.php',
    'SignedRevocationRetryLeaseCandidateVerifier.php',
    'SignedRevocationAckClaimCandidateVerifier.php',
    'SignedRevocationRecoveryTriageCandidate.php',
]as$name){
    require $root.$name;
}

use App\Infrastructure\Recovery\PortalHashedSessionStatusCandidate;
use App\Infrastructure\Recovery\SignedRevocationCursorCandidateVerifier;
use App\Infrastructure\Recovery\SignedRevocationRetryLeaseCandidateVerifier;
use App\Infrastructure\Recovery\SignedRevocationAckClaimCandidateVerifier;
use App\Infrastructure\Recovery\SignedRevocationRecoveryTriageCandidate;

final class F396SyntheticRestrictedPg implements PortalHashedSessionStatusCandidate
{
    public string $status='not_revoked';
    public int $reads=0;
    public bool $fail=false;
    public string $expectedDigest='';
    public string $expectedOrganization='';

    public function inspectSessionDigestStatus(string $sessionSha256,string $organization):string{
        $this->reads++;
        if($this->fail)throw new RuntimeException('Simulated DB disconnect, never disclose');
        if($sessionSha256!==$this->expectedDigest
            ||$organization!==$this->expectedOrganization){
            throw new RuntimeException('Incorrect SQL scope attempted');
        }
        return $this->status;
    }
}
try{
    $dir=realpath($argv[1]);
    if(!is_string($dir)||!is_dir($dir))throw new RuntimeException();
    $read=static function(string $name)use($dir):array{
        $p=$dir.'/'.$name;
        if(!is_file($p)||is_link($p)||filesize($p)<2||filesize($p)>12000){
            throw new RuntimeException('Invalid test fixture');
        }
        $value=json_decode((string)file_get_contents($p),true,12,JSON_THROW_ON_ERROR);
        if(!is_array($value))throw new RuntimeException();
        return $value;
    };
    $active=$read('active.json');
    $expired=$read('expired.json');
    $exhausted=$read('exhausted.json');
    $expected=$read('expected.json');
    $pub=$read('public.json')['hex']??'';
    $org=$expected['organization'];
    $instance=$expected['instance'];
    $digest=$expected['session_sha256'];
    $id=$expected['intent_id'];
    $sql=new F396SyntheticRestrictedPg();
    $sql->expectedDigest=$digest;
    $sql->expectedOrganization=$org;
    $policy=new SignedRevocationRecoveryTriageCandidate(
        new SignedRevocationCursorCandidateVerifier(),
        new SignedRevocationRetryLeaseCandidateVerifier(),
        new SignedRevocationAckClaimCandidateVerifier(),
        $sql,$org,$instance,$pub
    );
    $count=0;
    $assert=static function(bool $condition)use(&$count):void{
        if(!$condition)throw new RuntimeException('F396 fail-closed triage invariant');
        $count++;
    };
    $inspect=static function(
        array $fixture,bool $withLease=true,bool $withAck=true,
        ?array $overrideCursor=null,?array $overrideLease=null,
        ?array $overrideAck=null,
        ?string $overrideCursorNonce=null,
        ?string $overrideLeaseNonce=null,
        ?string $overrideAckNonce=null,
        int $after=0
    )use($policy,$pub,$assert):array{
        $nonce=$fixture['nonces'];
        $cursor=$overrideCursor??$fixture['cursor'];
        $lease=$overrideLease??$fixture['lease'];
        $ack=$overrideAck??$fixture['ack'];
        $value=$policy->inspect(
            $cursor,$after,$overrideCursorNonce??$nonce['cursor'],
            $withLease?$lease:null,
            $withLease?$lease['lease_sha256']:null,
            $withLease?($overrideLeaseNonce??$nonce['lease']):null,
            $withAck?$ack:null,
            $withAck?($overrideAckNonce??$nonce['ack']):null,
            $fixture['at']
        );
        foreach([
            'canAuthorizeHttp','canAuthorizeBusiness','canIssueSession',
            'canRecoverFromRestore','canDeploy','canClaimPostgresRevoked',
            'canClaimQueueFullyProcessed','canSkipReconciliation',
        ]as$flag){
            $assert(($value[$flag]??null)===false);
        }
        return $value;
    };
    $status=static function(array $v,string $expected)use($assert):void{
        $assert(($v['reasonCode']??null)===$expected);
    };
    $state='ACTIVE_LEASE_CANDIDATE_STILL_NO_SERVING';
    $status($inspect($active,true,false),$state);
    $assert($sql->reads===1);

    // An ACK signed by the real Worker is NOT an independent PG row proof.
    $status($inspect($active,true,true),'ACK_CLAIM_CONTRADICTS_ACTIVE_POSTGRES');
    $assert($sql->reads===2);
    $status($inspect($active,true,false,overrideCursorNonce:str_repeat('0',64)),
        'INDEPENDENT_CURSOR_NOT_VERIFIED');
    $status($inspect($active,true,false,overrideLeaseNonce:str_repeat('0',64)),
        'LEASE_NOT_VERIFIED');
    $status($inspect($active,true,false,
        overrideCursor:[...$active['cursor'],'signature'=>str_repeat('0',128)]),
        'INDEPENDENT_CURSOR_NOT_VERIFIED');
    $status($inspect($active,true,false,
        overrideLease:[...$active['lease'],'session_sha256'=>str_repeat('0',64)]),
        'LEASE_NOT_VERIFIED');
    $status($inspect($active,false,false),'SIGNED_LEASE_REQUIRED');
    $assert($sql->reads===2);
    $status($inspect($active,true,true,
        overrideAckNonce:str_repeat('0',64)),
        'ACK_CLAIM_NOT_VERIFIED');
    $status($inspect($active,true,true,
        overrideAck:[...$active['ack'],'signature'=>str_repeat('0',128)]),
        'ACK_CLAIM_NOT_VERIFIED');
    $assert($sql->reads===2);

    $status($inspect($expired,true,false),
        'LEASE_EXPIRED_REQUIRE_NEW_SIGNED_CLAIM');
    $assert($sql->reads===3);
    $status($inspect($exhausted,true,false),
        'RETRY_BUDGET_EXHAUSTED_ESCALATE');
    $assert($sql->reads===4);
    $status($inspect($exhausted,true,true),
        'ACK_CLAIM_CONTRADICTS_ACTIVE_POSTGRES');
    $assert($sql->reads===5);

    $sql->status='missing';
    $status($inspect($active,true,true),'POSTGRES_SESSION_MISSING_ESCALATE');
    $sql->status='revoked';
    $status($inspect($active,true,true),
        'SQL_REVOKED_WITH_ACK_CLAIM_STILL_NO_SERVING');
    $status($inspect($active,true,false),
        'SQL_REVOKED_ACK_NOT_PRESENT_STILL_NO_SERVING');
    $sql->status='inconsistent';
    $status($inspect($active,true,true),'POSTGRES_STATUS_UNSAFE');
    $sql->status='not_revoked';$sql->fail=true;
    $status($inspect($active,true,true),'POSTGRES_STATUS_UNAVAILABLE');
    $sql->fail=false;

    // Empty page is not a global queue-complete attestation.
    $cursor=$active['empty'];
    $cursorNonce=$active['nonces']['empty'];
    $before=$sql->reads;
    $value=$policy->inspect($cursor,13,$cursorNonce,null,null,null,null,null,$active['at']);
    $status($value,'NO_NEXT_INTENT_NOT_QUEUE_COMPLETE');
    foreach(['canAuthorizeHttp','canClaimQueueFullyProcessed','canSkipReconciliation'] as $f){
        $assert(($value[$f]??null)===false);
    }
    $assert($sql->reads===$before);
    echo "PASS F396 PHP: {$count} no-skip triage checks with real Worker signed cursor/lease/forged ACK; SQL status always consulted when necessary, NO SERVING.\n";
}catch(Throwable $e){
    fwrite(STDERR,"FAIL F396: fail-closed recovery triage: ".get_class($e)."\n");
    exit(1);
}
