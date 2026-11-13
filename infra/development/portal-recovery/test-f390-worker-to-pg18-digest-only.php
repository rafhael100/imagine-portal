<?php

declare(strict_types=1);

/**
 * F390: the real PG18 worker only receives a SHA256 session digest and
 * an actual F387 receipt produced by Node's Worker F376/F386/F387.
 * The session bearer itself is NEVER read, stored or passed here.
 */
if(PHP_SAPI!=='cli'||$argc!==1
    ||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_F390_CI')!=='DISPOSABLE_ONLY')exit(64);

$phase='bootstrap';
try{
    require '/var/www/html/vendor/autoload.php';
    $dir='/opt/f390';
    $json=static function(string $name)use($dir):array{
        $path=$dir.'/'.$name;
        if(!is_file($path)||is_link($path)
            ||filesize($path)<2||filesize($path)>4096)throw new RuntimeException();
        $result=json_decode((string)file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
        if(!is_array($result))throw new RuntimeException();
        return $result;
    };
    $seed=$json('seed.json');
    $receipt=$json('receipt.json');
    $expect=$json('expected.json');
    $pub=$json('public.json')['hex']??'';
    $org=$seed['organization']??null;
    $instance=$seed['instance']??null;
    $digest=$seed['session_sha256']??null;
    if(!is_string($digest)||preg_match('/\A[a-f0-9]{64}\z/D',$digest)!==1
        ||!is_string($org)||$instance!=='f390_ci'
        ||array_key_exists('session_reference',$seed)
        ||$expect['session_sha256']!==$digest
        ||$expect['organization']!==$org||$expect['instance']!==$instance){
        throw new RuntimeException();
    }

    $phase='sql-runtime-setup';
    $secrets=json_decode((string)file_get_contents('/run/secrets/runtime.json'),
        true,8,JSON_THROW_ON_ERROR);
    if(!is_array($secrets)||($secrets['database']??null)!=='portal_identity_dev'
        ||!is_string($secrets['proof_key']??null)
        ||!is_string($secrets['identity_password']??null)
        ||!is_string($secrets['scope_pepper']??null)
        ||!is_string($secrets['totp_key']??null)){
        throw new RuntimeException();
    }
    $dsn='pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_dev';
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false];
    $dbRuntime=new PDO($dsn,'portal_browser_runtime',$secrets['identity_password'],$options);
    $auth=new App\Infrastructure\Identity\PostgresPortalBrowserAuthentication(
        $dbRuntime,'portal_identity_dev',$secrets['proof_key'],
        (string)base64_decode($secrets['totp_key'],true),$secrets['scope_pepper']
    );
    unset($secrets);
    $adminKey=trim((string)file_get_contents('/run/secrets/database-admin'));
    $dbAdmin=new PDO($dsn,'postgres',$adminKey,$options);
    unset($adminKey);

    $readState=static function(string $sessionDigest)use($dbAdmin):string{
        $q=$dbAdmin->prepare(
            'SELECT status FROM public.portal_auth_sessions '
            .'WHERE session_sha256=CAST(? AS char(64))'
        );
        $q->execute([$sessionDigest]);
        return (string)$q->fetchColumn();
    };
    $checker=new App\Infrastructure\Recovery\SignedRevocationIntentReadbackCandidateVerifier();
    $coordinator=new App\Infrastructure\Recovery\SignedRevocationDigestPgReconcileCandidate(
        $auth,$checker,$org,$instance,$pub
    );
    $id=$expect['intent_id'];
    $nonce=$expect['nonce'];
    $count=0;
    $check=static function(bool $yes)use(&$count):void{
        if(!$yes)throw new RuntimeException('F390 digest-only security invariant failed');
        $count++;
    };
    $result=static function(array $value,string $expected)use($check):void{
        $check(($value['status']??null)===$expected);
        foreach([
            'canAuthorizeHttp','canAuthorizeBusiness','canIssueSession',
            'canRecoverFromRestore','canDeploy','canClaimPostgresRevoked',
            'canAssertSessionExisted',
        ]as$field){
            $check(($value[$field]??null)===false);
        }
    };
    $check($readState($digest)==='active');
    $check(($checker->verify(
        $receipt,$org,$instance,$id,$digest,$nonce,$pub,time()
    )['reasonCode']??null)==='INTENT_CONFIRMED_PENDING_RECONCILIATION_NO_SERVING');
    $phase='strict-signed-negative-preconditions';
    $result($coordinator->inspectDigestOnly($id,$digest,str_repeat('0',64),$receipt,time()),
        'INDEPENDENT_DIGEST_INTENT_NOT_VERIFIED');
    $result($coordinator->inspectDigestOnly(str_repeat('0',32),$digest,$nonce,$receipt,time()),
        'INDEPENDENT_DIGEST_INTENT_NOT_VERIFIED');
    $result($coordinator->inspectDigestOnly($id,str_repeat('0',64),$nonce,$receipt,time()),
        'INDEPENDENT_DIGEST_INTENT_NOT_VERIFIED');
    $result($coordinator->inspectDigestOnly($id,$digest,$nonce,
        [...$receipt,'signature'=>str_repeat('0',128)],time()),
        'INDEPENDENT_DIGEST_INTENT_NOT_VERIFIED');
    $result($coordinator->inspectDigestOnly($id,$digest,$nonce,$receipt,time()+120),
        'INDEPENDENT_DIGEST_INTENT_NOT_VERIFIED');
    $result($coordinator->inspectDigestOnly($id,'not-a-digest',$nonce,$receipt,time()),
        'RECONCILE_DIGEST_INPUT_DENIED');
    $check(!$auth->revokeSessionDigest('invalid'));
    $check(!$auth->revokeSessionDigest(str_repeat('A',64)));
    $check($readState($digest)==='active');

    $phase='sql-revocation-forced-failure';
    $dbAdmin->exec(<<<'SQL'
CREATE FUNCTION public.portal_f390_injected_revoke_failure()
RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp
AS $f390$
BEGIN
 IF OLD.status='active' AND NEW.status='revoked' THEN
   RAISE EXCEPTION 'synthetic F390 SQL write failure'
       USING ERRCODE='40001';
 END IF;
 RETURN NEW;
END;
$f390$;
CREATE TRIGGER portal_f390_abort_revoke
BEFORE UPDATE ON public.portal_auth_sessions
FOR EACH ROW EXECUTE FUNCTION public.portal_f390_injected_revoke_failure();
SQL);
    try{
        $result($coordinator->inspectDigestOnly($id,$digest,$nonce,$receipt,time()),
            'POSTGRES_REVOKE_DIGEST_UNAVAILABLE');
        $check($readState($digest)==='active');
    }finally{
        $dbAdmin->exec('DROP TRIGGER IF EXISTS portal_f390_abort_revoke ON public.portal_auth_sessions');
        $dbAdmin->exec('DROP FUNCTION IF EXISTS public.portal_f390_injected_revoke_failure()');
    }

    $phase='recovery-without-original-bearer';
    $result($coordinator->inspectDigestOnly($id,$digest,$nonce,$receipt,time()),
        'LAB_REVOKE_FUNCTION_ACCEPTED_NO_STATUS_PROOF');
    $check($readState($digest)==='revoked');

    // The SQL function is idempotent and returns TRUE even if the digest
    // does not match a row. Thus *NEVER* promote this to verified user logout.
    $absentDigest=hash('sha256','synthetic-f390-no-matching-session');
    $check($readState($absentDigest)==='');
    $check($auth->revokeSessionDigest($absentDigest));
    $check($readState($absentDigest)==='');

    $check((int)$dbAdmin->query(
        "SELECT COUNT(*) FROM public.portal_auth_sessions WHERE status='active'"
    )->fetchColumn()===1); // only isolated F365 seed is still active
    echo "PASS F390: {$count} signed actual Worker intent -> restricted PG18 HMAC revoke by DIGEST ONLY; SQL failure/retry and no-row false-success prevented from authorizing.\n";
}catch(Throwable){
    fwrite(STDERR,"FAIL F390: disposable digest-only PG18 reconciliation phase {$phase}\n");
    exit(1);
}
