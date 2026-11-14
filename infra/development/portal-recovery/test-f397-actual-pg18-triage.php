<?php
declare(strict_types=1);

/**
 * F397: full signed F392+F395+F393 policy with ACTUAL restricted PG18
 * Portal SQL reader/revoke, not F396's in-process fake status adapter.
 * Laboratory-only. No public Laravel bindings, no keys or raw bearer.
 */
if(PHP_SAPI!=='cli'||$argc!==1
    ||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_F397_CI')!=='DISPOSABLE_ONLY')exit(64);
$phase='read';
try{
    require '/var/www/html/vendor/autoload.php';
    $dir='/opt/f397';
    $read=static function(string $name)use($dir):array{
        $path=$dir.'/'.$name;
        if(!is_file($path)||is_link($path)
            ||filesize($path)<2||filesize($path)>12000)throw new RuntimeException();
        $v=json_decode((string)file_get_contents($path),true,12,JSON_THROW_ON_ERROR);
        if(!is_array($v))throw new RuntimeException();
        return $v;
    };
    $seed=$read('seed.json');
    $active=$read('active.json');
    $expired=$read('expired.json');
    $exhausted=$read('exhausted.json');
    $expected=$read('expected.json');
    $pub=$read('public.json')['hex']??'';
    $org=$seed['organization']??null;
    $instance=$seed['instance']??null;
    $digest=$seed['session_sha256']??null;
    if(!is_string($org)||$instance!=='f390_ci'
        ||!is_string($digest)
        ||preg_match('/\A[a-f0-9]{64}\z/D',$digest)!==1
        ||array_key_exists('session_reference',$seed)
        ||$expected['organization']!==$org
        ||$expected['instance']!==$instance
        ||$expected['session_sha256']!==$digest)throw new RuntimeException();

    $phase='restricted-runtime';
    $keys=json_decode((string)file_get_contents('/run/secrets/runtime.json'),
      true,8,JSON_THROW_ON_ERROR);
    if(!is_array($keys)||($keys['database']??null)!=='portal_identity_dev'
        ||!is_string($keys['proof_key']??null)
        ||!is_string($keys['identity_password']??null)
        ||!is_string($keys['scope_pepper']??null)
        ||!is_string($keys['totp_key']??null))throw new RuntimeException();
    $dsn='pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_dev';
    $opt=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false];
    $runtime=new PDO($dsn,'portal_browser_runtime',$keys['identity_password'],$opt);
    $auth=new App\Infrastructure\Identity\PostgresPortalBrowserAuthentication(
      $runtime,'portal_identity_dev',$keys['proof_key'],
      (string)base64_decode($keys['totp_key'],true),$keys['scope_pepper']
    );
    unset($keys);
    $adminPassword=trim((string)file_get_contents('/run/secrets/database-admin'));
    $admin=new PDO($dsn,'postgres',$adminPassword,$opt);
    unset($adminPassword);

    $policy=new App\Infrastructure\Recovery\SignedRevocationRecoveryTriageCandidate(
        new App\Infrastructure\Recovery\SignedRevocationCursorCandidateVerifier(),
        new App\Infrastructure\Recovery\SignedRevocationRetryLeaseCandidateVerifier(),
        new App\Infrastructure\Recovery\SignedRevocationAckClaimCandidateVerifier(),
        $auth,$org,$instance,$pub
    );
    $checks=0;
    $assert=static function(bool $ok)use(&$checks):void{
        if(!$ok)throw new RuntimeException('F397 PG18 policy invariant');
        $checks++;
    };
    $inspect=static function(array $f,bool $withAck=true,bool $withLease=true,
        ?array $cursorOverride=null,?array $leaseOverride=null,
        ?array $ackOverride=null)
        use($policy,$assert):array{
        $n=$f['nonces'];
        $lease=$leaseOverride??$f['lease'];
        $value=$policy->inspect(
            $cursorOverride??$f['cursor'],0,$n['cursor'],
            $withLease?$lease:null,
            $withLease?$lease['lease_sha256']:null,
            $withLease?$n['lease']:null,
            $withAck?($ackOverride??$f['ack']):null,
            $withAck?$n['ack']:null,
            $f['at']
        );
        foreach([
            'canAuthorizeHttp','canAuthorizeBusiness','canIssueSession',
            'canRecoverFromRestore','canDeploy','canClaimPostgresRevoked',
            'canClaimQueueFullyProcessed','canSkipReconciliation',
        ]as$flag)$assert(($value[$flag]??null)===false);
        return $value;
    };
    $status=static function(array $r,string $wanted)use($assert):void{
        $assert(($r['reasonCode']??null)===$wanted);
    };

    $phase='active-sql-vs-forged-ack';
    $assert($auth->inspectSessionDigestStatus($digest,$org)==='not_revoked');
    $status($inspect($active),'ACK_CLAIM_CONTRADICTS_ACTIVE_POSTGRES');
    $status($inspect($active,false),'ACTIVE_LEASE_CANDIDATE_STILL_NO_SERVING');
    $status($inspect($active,false,true,
        [...$active['cursor'],'signature'=>str_repeat('0',128)]),
        'INDEPENDENT_CURSOR_NOT_VERIFIED');
    $status($inspect($active,false,true,null,
        [...$active['lease'],'signature'=>str_repeat('0',128)]),
        'LEASE_NOT_VERIFIED');
    $status($inspect($active,true,false),'SIGNED_LEASE_REQUIRED');
    $status($inspect($expired,false),'LEASE_EXPIRED_REQUIRE_NEW_SIGNED_CLAIM');
    $status($inspect($exhausted,false),'RETRY_BUDGET_EXHAUSTED_ESCALATE');

    $phase='real-pg18-rollback-failure';
    $admin->exec(<<<'SQL'
CREATE FUNCTION public.portal_f397_force_failure()
RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp
AS $f397$
BEGIN
 IF OLD.status='active' AND NEW.status='revoked' THEN
  RAISE EXCEPTION 'synthetic F397 SQL write failure' USING ERRCODE='40001';
 END IF;
 RETURN NEW;
END;
$f397$;
CREATE TRIGGER portal_f397_abort
BEFORE UPDATE ON public.portal_auth_sessions FOR EACH ROW
EXECUTE FUNCTION public.portal_f397_force_failure();
SQL);
    try {
        try {
            $auth->revokeSessionDigest($digest);
            $assert(false);
        }catch(PDOException){
            $assert(true);
        }
        $assert($auth->inspectSessionDigestStatus($digest,$org)==='not_revoked');
        $status($inspect($active),'ACK_CLAIM_CONTRADICTS_ACTIVE_POSTGRES');
    }finally {
        $admin->exec('DROP TRIGGER IF EXISTS portal_f397_abort ON public.portal_auth_sessions');
        $admin->exec('DROP FUNCTION IF EXISTS public.portal_f397_force_failure()');
    }

    $phase='pg18-revoke-while-ack-present';
    $assert($auth->revokeSessionDigest($digest));
    $assert($auth->inspectSessionDigestStatus($digest,$org)==='revoked');
    $status($inspect($active),'SQL_REVOKED_WITH_ACK_CLAIM_STILL_NO_SERVING');
    $status($inspect($active,false),'SQL_REVOKED_ACK_NOT_PRESENT_STILL_NO_SERVING');
    $assert($auth->inspectSessionDigestStatus(
      $digest,'11111111-1111-4111-8111-111111111111'
    )==='missing');
    $assert($auth->inspectSessionDigestStatus(
      hash('sha256','f397-no-corresponding-session'),$org
    )==='missing');

    $phase='signed-empty-cursor-no-authority';
    $empty=$active['empty'];
    $emptyResult=$policy->inspect(
        $empty,13,$active['nonces']['empty'],
        null,null,null,null,null,$active['at']
    );
    $status($emptyResult,'NO_NEXT_INTENT_NOT_QUEUE_COMPLETE');
    foreach(['canAuthorizeHttp','canClaimQueueFullyProcessed','canSkipReconciliation']as$flag){
        $assert(($emptyResult[$flag]??null)===false);
    }
    echo "PASS F397: {$checks} real PG18 HMAC role+signed independent cursor/lease/forged ACK; active conflict, injected SQL fault, revoked still NO SERVING.\n";
}catch(Throwable $error){
    fwrite(STDERR,"FAIL F397: actual PG18 signed triage, phase {$phase}: ".get_class($error)."\n");
    exit(1);
}
