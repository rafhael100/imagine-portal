<?php
declare(strict_types=1);

/**
 * F397: consume ACTUAL JavaScript Worker signed F392/F393/F395 receipts
 * against the ACTUAL restricted Portal PostgreSQL18 status adapter.
 * One intent references an active real synthetic session; a second valid
 * intent references a digest with no corresponding session.
 */
if(PHP_SAPI!=='cli'||$argc!==1
    ||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_F397_CI')!=='DISPOSABLE_ONLY')exit(64);

$phase='setup';
try{
    require '/var/www/html/vendor/autoload.php';
    $dir='/opt/f390';
    $read=static function(string $name)use($dir):array{
        $file=$dir.'/'.$name;
        if(!is_file($file)||is_link($file)
            ||filesize($file)<2||filesize($file)>12000)throw new RuntimeException();
        $json=json_decode((string)file_get_contents($file),true,12,JSON_THROW_ON_ERROR);
        if(!is_array($json))throw new RuntimeException();
        return $json;
    };
    $seed=$read('seed.json');
    $worker=$read('f397-receipts.json');
    $org=$seed['organization']??'';
    $inst=$seed['instance']??'';
    $digest=$seed['session_sha256']??'';
    if($inst!=='f390_ci'||$org!==($worker['organization']??null)
        ||$digest!==($worker['first']['cursor']['receipt']['session_sha256']??null)
        ||array_key_exists('session_reference',$seed))throw new RuntimeException();
    $public=$worker['public']??'';

    $secret=json_decode((string)file_get_contents('/run/secrets/runtime.json'),
        true,8,JSON_THROW_ON_ERROR);
    if(!is_array($secret)||($secret['database']??null)!=='portal_identity_dev'
        ||!is_string($secret['proof_key']??null)
        ||!is_string($secret['identity_password']??null)
        ||!is_string($secret['scope_pepper']??null)
        ||!is_string($secret['totp_key']??null))throw new RuntimeException();
    $dsn='pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_dev';
    $opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false];
    $runtime=new PDO($dsn,'portal_browser_runtime',$secret['identity_password'],$opts);
    $auth=new App\Infrastructure\Identity\PostgresPortalBrowserAuthentication(
        $runtime,'portal_identity_dev',$secret['proof_key'],
        (string)base64_decode($secret['totp_key'],true),$secret['scope_pepper']
    );
    unset($secret);
    $adminPassword=trim((string)file_get_contents('/run/secrets/database-admin'));
    $admin=new PDO($dsn,'postgres',$adminPassword,$opts);
    unset($adminPassword);
    $policy=new App\Infrastructure\Recovery\SignedRevocationRecoveryTriageCandidate(
        new App\Infrastructure\Recovery\SignedRevocationCursorCandidateVerifier(),
        new App\Infrastructure\Recovery\SignedRevocationRetryLeaseCandidateVerifier(),
        new App\Infrastructure\Recovery\SignedRevocationAckClaimCandidateVerifier(),
        $auth,$org,$inst,$public
    );
    $checks=0;
    $assert=static function(bool $test)use(&$checks):void{
        if(!$test)throw new RuntimeException('F397 real PG18 triage invariant');
        $checks++;
    };
    $evaluate=static function(array $group,bool $withAck,
        ?array $replacementCursor=null,?array $replacementLease=null,
        ?array $replacementAck=null,
        ?string $differentCursorNonce=null
    )use($policy,$assert):array{
        $cursor=$group['cursor'];
        $lease=$group['lease'];
        $ack=$group['ack']??null;
        $result=$policy->inspect(
            $replacementCursor??$cursor['receipt'],
            $cursor['after'],
            $differentCursorNonce??$cursor['nonce'],
            $replacementLease??$lease['receipt'],
            $lease['receipt']['lease_sha256'],
            $lease['nonce'],
            $withAck?($replacementAck??$ack['receipt']):null,
            $withAck?$ack['nonce']:null,
            time()
        );
        foreach([
            'canAuthorizeHttp','canAuthorizeBusiness','canIssueSession',
            'canRecoverFromRestore','canDeploy','canClaimPostgresRevoked',
            'canClaimQueueFullyProcessed','canSkipReconciliation',
        ]as$key){
            $assert(($result[$key]??null)===false);
        }
        return $result;
    };
    $status=static function(array $v,string $expected)use($assert):void{
        $assert(($v['reasonCode']??null)===$expected);
    };
    $dbState=static function(string $hash)use($admin):string{
        $query=$admin->prepare(
          'SELECT status FROM public.portal_auth_sessions '
          .'WHERE session_sha256=CAST(? AS char(64))'
        );
        $query->execute([$hash]);
        return (string)$query->fetchColumn();
    };
    $first=$worker['first'];
    $missing=$worker['missing'];
    $missingDigest=$missing['cursor']['receipt']['session_sha256'];
    $phase='independent-claims-vs-local-sql';
    $assert($dbState($digest)==='active');
    $assert($auth->inspectSessionDigestStatus($digest,$org)==='not_revoked');
    $assert($auth->inspectSessionDigestStatus($missingDigest,$org)==='missing');
    $status($evaluate($first,true),'ACK_CLAIM_CONTRADICTS_ACTIVE_POSTGRES');
    $status($evaluate($first,false),'ACTIVE_LEASE_CANDIDATE_STILL_NO_SERVING');
    $status($evaluate($missing,false),'POSTGRES_SESSION_MISSING_ESCALATE');
    $status($evaluate($first,true,
        replacementCursor:[...$first['cursor']['receipt'],
            'signature'=>str_repeat('0',128)]),
        'INDEPENDENT_CURSOR_NOT_VERIFIED');
    $status($evaluate($first,true,
        replacementLease:[...$first['lease']['receipt'],
            'signature'=>str_repeat('0',128)]),
        'LEASE_NOT_VERIFIED');
    $status($evaluate($first,true,
        replacementAck:[...$first['ack']['receipt'],
            'signature'=>str_repeat('0',128)]),
        'ACK_CLAIM_NOT_VERIFIED');
    $status($evaluate($first,true,
        differentCursorNonce:str_repeat('0',64)),
        'INDEPENDENT_CURSOR_NOT_VERIFIED');

    $phase='force-actual-pg18-revoke-failure';
    $admin->exec(<<<'SQL'
CREATE FUNCTION public.portal_f397_force_revoke_failure()
RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp
AS $f397$
BEGIN
  IF OLD.status='active' AND NEW.status='revoked' THEN
    RAISE EXCEPTION 'synthetic F397 PG18 SQL update failure'
      USING ERRCODE='40001';
  END IF;
  RETURN NEW;
END;
$f397$;
CREATE TRIGGER portal_f397_abort_revoke BEFORE UPDATE ON public.portal_auth_sessions
FOR EACH ROW EXECUTE FUNCTION public.portal_f397_force_revoke_failure();
SQL);
    try{
        try{$auth->revokeSessionDigest($digest);$assert(false);}
        catch(RuntimeException $e){
            $assert($e->getMessage()==='Portal authentication database unavailable.');
        }
        $assert($dbState($digest)==='active');
        $status($evaluate($first,true),'ACK_CLAIM_CONTRADICTS_ACTIVE_POSTGRES');
    }finally{
        $admin->exec('DROP TRIGGER IF EXISTS portal_f397_abort_revoke ON public.portal_auth_sessions');
        $admin->exec('DROP FUNCTION IF EXISTS public.portal_f397_force_revoke_failure()');
    }

    $phase='restricted-postgresql-revoked-after-recovery';
    $assert($auth->revokeSessionDigest($digest));
    $assert($auth->inspectSessionDigestStatus($digest,$org)==='revoked');
    $assert($dbState($digest)==='revoked');
    $status($evaluate($first,true),'SQL_REVOKED_WITH_ACK_CLAIM_STILL_NO_SERVING');
    $status($evaluate($first,false),'SQL_REVOKED_ACK_NOT_PRESENT_STILL_NO_SERVING');
    $status($evaluate($missing,false),'POSTGRES_SESSION_MISSING_ESCALATE');
    $assert((int)$admin->query(
        "SELECT COUNT(*) FROM public.portal_auth_sessions WHERE status='active'"
    )->fetchColumn()===1); // separate F365 synthetic seed
    echo "PASS F397: {$checks} real signed Worker cursor/lease/forged ACK vs restricted PG18 active/missing/revoked states and injected SQL outage; NO SERVING.\n";
}catch(Throwable){
    fwrite(STDERR,"FAIL F397: disposable signed Worker to real PostgreSQL18 triage phase {$phase}\n");
    exit(1);
}
