<?php

declare(strict_types=1);

/**
 * F391 real PostgreSQL18 + actual signed F376/F386/F387 receipt.
 * Operator-only SQL inspector verifies DB state solely in disposable CI.
 */
if(PHP_SAPI!=='cli'||$argc!==1
    ||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_F391_CI')!=='DISPOSABLE_ONLY')exit(64);
$phase='setup';
try{
    require '/var/www/html/vendor/autoload.php';
    $dir='/opt/f390';
    $read=static function(string $filename)use($dir):array{
        $path=$dir.'/'.$filename;
        if(!is_file($path)||is_link($path)||filesize($path)<2
            ||filesize($path)>4096)throw new RuntimeException();
        $a=json_decode((string)file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
        if(!is_array($a))throw new RuntimeException();
        return $a;
    };
    $seed=$read('seed.json');
    $receipt=$read('receipt.json');
    $expected=$read('expected.json');
    $public=$read('public.json')['hex']??'';
    $digest=$seed['session_sha256']??null;
    $org=$seed['organization']??null;
    $inst=$seed['instance']??null;
    if(!is_string($digest)||preg_match('/\A[a-f0-9]{64}\z/D',$digest)!==1
        ||!is_string($org)||$inst!=='f390_ci'
        ||$expected['session_sha256']!==$digest
        ||$expected['organization']!==$org
        ||$expected['instance']!==$inst
        ||array_key_exists('session_reference',$seed))throw new RuntimeException();

    $keys=json_decode((string)file_get_contents('/run/secrets/runtime.json'),true,8,JSON_THROW_ON_ERROR);
    if(!is_array($keys)||($keys['database']??null)!=='portal_identity_dev'
        ||!is_string($keys['proof_key']??null)
        ||!is_string($keys['identity_password']??null)
        ||!is_string($keys['totp_key']??null)
        ||!is_string($keys['scope_pepper']??null))throw new RuntimeException();
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false];
    $dsn='pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_dev';
    $runtime=new PDO($dsn,'portal_browser_runtime',$keys['identity_password'],$options);
    $auth=new App\Infrastructure\Identity\PostgresPortalBrowserAuthentication(
        $runtime,'portal_identity_dev',$keys['proof_key'],
        (string)base64_decode($keys['totp_key'],true),$keys['scope_pepper']
    );
    unset($keys);
    $adminKey=trim((string)file_get_contents('/run/secrets/database-admin'));
    $admin=new PDO($dsn,'postgres',$adminKey,$options);
    unset($adminKey);

    $witness=new App\Infrastructure\Recovery\SignedRevocationIntentReadbackCandidateVerifier();
    $write=new App\Infrastructure\Recovery\SignedRevocationDigestPgReconcileCandidate(
        $auth,$witness,$org,$inst,$public
    );
    $reconcile=new App\Infrastructure\Recovery\SignedRevocationDigestStatusPgReconcileCandidate(
        $write,$auth,$org
    );
    $intent=$expected['intent_id'];
    $nonce=$expected['nonce'];
    $count=0;
    $check=static function(bool $ok)use(&$count):void{
        if(!$ok)throw new RuntimeException('F391 strict status invariant failed');
        $count++;
    };
    $assert=static function(array $result,string $expectedStatus)use($check):void{
        $check(($result['status']??null)===$expectedStatus);
        foreach([
            'canAuthorizeHttp','canAuthorizeBusiness','canIssueSession',
            'canRecoverFromRestore','canDeploy','canClaimPostgresRevoked',
            'canAssertSessionExisted',
        ] as $flag){
            $check(($result[$flag]??null)===false);
        }
    };
    $dbState=static function(string $hash)use($admin):string{
        $stmt=$admin->prepare(
            'SELECT status FROM public.portal_auth_sessions WHERE session_sha256=CAST(? AS char(64))'
        );
        $stmt->execute([$hash]);
        return (string)$stmt->fetchColumn();
    };
    $badOrg='11111111-1111-4111-8111-111111111111';
    $missing=hash('sha256','f391-does-not-exist-in-real-pg18');
    $phase='pre-revocation-status-and-denials';
    $check($dbState($digest)==='active');
    $check($auth->inspectSessionDigestStatus($digest,$org)==='not_revoked');
    $check($auth->inspectSessionDigestStatus($digest,$badOrg)==='missing');
    $check($auth->inspectSessionDigestStatus($missing,$org)==='missing');
    $check($auth->inspectSessionDigestStatus('not-a-digest',$org)==='denied');
    $check($auth->inspectSessionDigestStatus($digest,'not-a-uuid')==='denied');

    $proofDenied=$runtime->prepare(
        'SELECT public.portal_browser_revoke_status_candidate(?,CAST(? AS uuid),?)'
    );
    $proofDenied->execute([$digest,$org,str_repeat('0',64)]);
    $check($proofDenied->fetchColumn()==='denied');
    try{
        $runtime->query('SELECT status FROM public.portal_auth_sessions LIMIT 1');
        $check(false);
    }catch(PDOException){$check(true);}

    $check(($witness->verify(
        $receipt,$org,$inst,$intent,$digest,$nonce,$public,time()
    )['reasonCode']??null)==='INTENT_CONFIRMED_PENDING_RECONCILIATION_NO_SERVING');

    $assert($reconcile->inspectAndVerifyRow(
        $intent,$digest,$nonce,[...$receipt,'signature'=>str_repeat('0',128)],time()
    ),'INDEPENDENT_OR_POSTGRES_WRITE_NOT_CONFIRMED');
    $assert($reconcile->inspectAndVerifyRow(
        str_repeat('0',32),$digest,$nonce,$receipt,time()
    ),'INDEPENDENT_OR_POSTGRES_WRITE_NOT_CONFIRMED');
    $assert($reconcile->inspectAndVerifyRow(
        $intent,$missing,$nonce,$receipt,time()
    ),'INDEPENDENT_OR_POSTGRES_WRITE_NOT_CONFIRMED');
    $check($dbState($digest)==='active');

    $phase='forced-sql-update-failure';
    $admin->exec(<<<'SQL'
CREATE FUNCTION public.portal_f391_fail_revoke()
RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp
AS $f391$
BEGIN
  IF OLD.status='active' AND NEW.status='revoked' THEN
    RAISE EXCEPTION 'synthetic F391 SQL failure' USING ERRCODE='40001';
  END IF;
  RETURN NEW;
END;
$f391$;
CREATE TRIGGER portal_f391_abort_revoke
BEFORE UPDATE ON public.portal_auth_sessions
FOR EACH ROW EXECUTE FUNCTION public.portal_f391_fail_revoke();
SQL);
    try{
        $assert($reconcile->inspectAndVerifyRow($intent,$digest,$nonce,$receipt,time()),
            'INDEPENDENT_OR_POSTGRES_WRITE_NOT_CONFIRMED');
        $check($auth->inspectSessionDigestStatus($digest,$org)==='not_revoked');
    }finally{
        $admin->exec('DROP TRIGGER IF EXISTS portal_f391_abort_revoke ON public.portal_auth_sessions');
        $admin->exec('DROP FUNCTION IF EXISTS public.portal_f391_fail_revoke()');
    }

    $phase='actual-sql-revoke-and-readback';
    $assert($reconcile->inspectAndVerifyRow($intent,$digest,$nonce,$receipt,time()),
        'LAB_PG_ROW_REVOKED_STILL_NO_SERVING');
    $check($auth->inspectSessionDigestStatus($digest,$org)==='revoked');
    $check($dbState($digest)==='revoked');

    // Idempotent retry and role/HMAC-scoped status verify still NO SERVING.
    $assert($reconcile->inspectAndVerifyRow($intent,$digest,$nonce,$receipt,time()),
        'LAB_PG_ROW_REVOKED_STILL_NO_SERVING');
    $check($auth->inspectSessionDigestStatus($missing,$org)==='missing');
    $check($auth->revokeSessionDigest($missing)); // SQL TRUE even no matching row
    $check($auth->inspectSessionDigestStatus($missing,$org)==='missing');
    $check($dbState($missing)==='');

    $check((int)$admin->query(
        "SELECT COUNT(*) FROM public.portal_auth_sessions WHERE status='active'"
    )->fetchColumn()===1); // F365 test seed only
    echo "PASS F391: {$count} real PostgreSQL18 tenant/HMAC status distinguishes revoked vs active vs missing after Worker signed intent; no serving.\n";
}catch(Throwable){
    fwrite(STDERR,"FAIL F391: disposable restricted PG18 status phase {$phase}\n");
    exit(1);
}
