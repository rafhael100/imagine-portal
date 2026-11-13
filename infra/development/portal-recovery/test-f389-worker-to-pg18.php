<?php
declare(strict_types=1);

/**
 * F389 separate PHP process against real disposable PostgreSQL18 and F387
 * signed receipt actually emitted by the F376 Worker code in Node 24.
 * No HTTP serving, operational service, production credentials or deploy.
 */
if(PHP_SAPI!=='cli'||$argc!==1
    ||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_F389_CI')!=='DISPOSABLE_ONLY')exit(64);

$phase='load-fixture';
try{
    require '/var/www/html/vendor/autoload.php';
    $dir='/opt/f389';
    $read=static function(string $name)use($dir):array{
        $path=$dir.'/'.$name;
        if(!is_file($path)||is_link($path)||filesize($path)<2||filesize($path)>4096){
            throw new RuntimeException();
        }
        $data=json_decode((string)file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new RuntimeException();
        return $data;
    };
    $seed=$read('seed.json');
    $receipt=$read('receipt.json');
    $expected=$read('expected.json');
    $public=$read('public.json')['hex']??'';
    $ref=$seed['session_reference']??null;
    $org=$seed['organization']??null;
    $instance=$seed['instance']??null;
    if(!is_string($ref)||preg_match('/\A[a-f0-9]{64}\z/D',$ref)!==1
        ||!is_string($org)||!is_string($instance)
        ||$expected['session_sha256']!==hash('sha256',$ref)
        ||$expected['organization']!==$org
        ||$expected['instance']!==$instance
        ||$instance!=='f389_ci')throw new RuntimeException();

    $phase='real-postgresql-runtime';
    $secret=json_decode((string)file_get_contents('/run/secrets/runtime.json'),
        true,8,JSON_THROW_ON_ERROR);
    if(!is_array($secret)
        ||($secret['database']??null)!=='portal_identity_dev'
        ||!is_string($secret['proof_key']??null)
        ||!is_string($secret['identity_password']??null)
        ||!is_string($secret['scope_pepper']??null)
        ||!is_string($secret['totp_key']??null))throw new RuntimeException();
    $dsn='pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_dev';
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false];
    $runtime=new PDO($dsn,'portal_browser_runtime',$secret['identity_password'],$options);
    $auth=new App\Infrastructure\Identity\PostgresPortalBrowserAuthentication(
        $runtime,'portal_identity_dev',
        $secret['proof_key'],
        (string)base64_decode($secret['totp_key'],true),
        $secret['scope_pepper'],
    );
    unset($secret);
    $adminPassword=trim((string)file_get_contents('/run/secrets/database-admin'));
    $admin=new PDO($dsn,'postgres',$adminPassword,$options);
    unset($adminPassword);
    $digest=hash('sha256',$ref);
    $dbStatus=static function()use($admin,$digest):string{
        $q=$admin->prepare(
          'SELECT status FROM public.portal_auth_sessions '
          .'WHERE session_sha256=CAST(? AS char(64))'
        );
        $q->execute([$digest]);
        return (string)$q->fetchColumn();
    };
    $checker=new App\Infrastructure\Recovery\SignedRevocationIntentReadbackCandidateVerifier();
    $candidate=new App\Infrastructure\Recovery\SignedRevocationIntentPgReconcileCandidate(
        $auth,$checker,$org,$instance,$public
    );
    $intent=$expected['intent_id'];
    $nonce=$expected['nonce'];
    $checks=0;
    $check=static function(bool $pass)use(&$checks):void{
        if(!$pass)throw new RuntimeException('F389 isolated invariant failed');
        $checks++;
    };
    $inspect=static function(array $result,string $expectedStatus)use($check):void{
        $check(($result['status']??null)===$expectedStatus);
        foreach([
          'canAuthorizeHttp','canAuthorizeBusiness','canIssueSession',
          'canRecoverFromRestore','canDeploy','canClaimPostgresRevoked',
        ]as$field){
            $check(($result[$field]??null)===false);
        }
    };
    $phase='signed-worker-receipt-and-still-active-session';
    $check($auth->isActiveBusinessSession($ref));
    $check($dbStatus()==='active');
    $check(($checker->verify(
        $receipt,$org,$instance,$intent,$digest,$nonce,$public,time()
    )['reasonCode']??null)==='INTENT_CONFIRMED_PENDING_RECONCILIATION_NO_SERVING');

    $inspect($candidate->inspectRetry($ref,$intent,str_repeat('0',64),$receipt,time()),
        'INDEPENDENT_INTENT_NOT_VERIFIED');
    $inspect($candidate->inspectRetry($ref,$intent,$nonce,
        [...$receipt,'signature'=>str_repeat('0',128)],time()),
        'INDEPENDENT_INTENT_NOT_VERIFIED');
    $inspect($candidate->inspectRetry($ref,str_repeat('f',32),$nonce,$receipt,time()),
        'INDEPENDENT_INTENT_NOT_VERIFIED');
    $check($dbStatus()==='active');

    $phase='real-sql-fault-after-independent-witness-commit';
    $admin->exec(<<<'SQL'
CREATE FUNCTION public.portal_f389_force_revoke_failure()
RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp
AS $f389$
BEGIN
  IF OLD.status='active' AND NEW.status='revoked' THEN
     RAISE EXCEPTION 'synthetic F389 PostgreSQL transaction failure'
       USING ERRCODE='40001';
  END IF;
  RETURN NEW;
END;
$f389$;
CREATE TRIGGER portal_f389_abort_revoke
BEFORE UPDATE ON public.portal_auth_sessions
FOR EACH ROW EXECUTE FUNCTION public.portal_f389_force_revoke_failure();
SQL);
    try{
        $inspect($candidate->inspectRetry($ref,$intent,$nonce,$receipt,time()),
            'POSTGRES_REVOKE_UNAVAILABLE');
        $check($dbStatus()==='active');
        $check($auth->isActiveBusinessSession($ref));
    }finally{
        $admin->exec('DROP TRIGGER IF EXISTS portal_f389_abort_revoke ON public.portal_auth_sessions');
        $admin->exec('DROP FUNCTION IF EXISTS public.portal_f389_force_revoke_failure()');
    }

    $phase='durable-postgresql-recovery';
    $inspect($candidate->inspectRetry($ref,$intent,$nonce,$receipt,time()),
        'LAB_POSTGRES_REVOKE_OBSERVED_STILL_NO_SERVING');
    $check($dbStatus()==='revoked');
    $check(!$auth->isActiveBusinessSession($ref));
    $check((int)$admin->query(
        "SELECT count(*) FROM public.portal_auth_sessions WHERE status='active'"
    )->fetchColumn()===1); // isolated F365 seed only
    echo "PASS F389: {$checks} signed REAL Worker F386/F387 intent -> real PostgreSQL18 SQL fault -> durable retry, no HTTP or grant.\n";
}catch(Throwable){
    fwrite(STDERR,"FAIL F389: synthetic Worker-to-PostgreSQL reconciliation phase {$phase}\n");
    exit(1);
}
