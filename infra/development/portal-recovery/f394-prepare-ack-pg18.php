<?php

declare(strict_types=1);

/**
 * F394: real restricted PG18 SQL status gates a disposable ACK body file.
 * No ACK writer key, network client, Laravel route or production credential.
 */
if(PHP_SAPI!=='cli'||$argc!==1
    ||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_F394_CI')!=='DISPOSABLE_ONLY')exit(64);
$phase='load';
try{
    require '/var/www/html/vendor/autoload.php';
    $dir='/opt/f390';
    $read=static function(string $name)use($dir):array{
        $path=$dir.'/'.$name;
        if(!is_file($path)||is_link($path)||filesize($path)<2
            ||filesize($path)>4096)throw new RuntimeException();
        $a=json_decode((string)file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
        if(!is_array($a))throw new RuntimeException();
        return $a;
    };
    $seed=$read('seed.json');
    $receipt=$read('receipt.json');
    $expected=$read('expected.json');
    $pub=$read('public.json')['hex']??'';
    $digest=$seed['session_sha256']??null;
    $org=$seed['organization']??null;
    $instance=$seed['instance']??null;
    if(!is_string($digest)||preg_match('/\A[a-f0-9]{64}\z/D',$digest)!==1
        ||!is_string($org)||$instance!=='f390_ci'
        ||$expected['session_sha256']!==$digest
        ||$expected['organization']!==$org
        ||$expected['instance']!==$instance
        ||array_key_exists('session_reference',$seed)
        ||file_exists($dir.'/ack-body.json')){
        throw new RuntimeException();
    }
    $keys=json_decode((string)file_get_contents('/run/secrets/runtime.json'),
        true,8,JSON_THROW_ON_ERROR);
    if(!is_array($keys)||($keys['database']??null)!=='portal_identity_dev'
        ||!is_string($keys['proof_key']??null)
        ||!is_string($keys['identity_password']??null)
        ||!is_string($keys['scope_pepper']??null)
        ||!is_string($keys['totp_key']??null))throw new RuntimeException();
    $dsn='pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_dev';
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false];
    $runtime=new PDO($dsn,'portal_browser_runtime',$keys['identity_password'],$options);
    $auth=new App\Infrastructure\Identity\PostgresPortalBrowserAuthentication(
        $runtime,'portal_identity_dev',$keys['proof_key'],
        (string)base64_decode($keys['totp_key'],true),$keys['scope_pepper']
    );
    unset($keys);
    $adminPassword=trim((string)file_get_contents('/run/secrets/database-admin'));
    $admin=new PDO($dsn,'postgres',$adminPassword,$options);
    unset($adminPassword);

    $verify=new App\Infrastructure\Recovery\SignedRevocationIntentReadbackCandidateVerifier();
    $write=new App\Infrastructure\Recovery\SignedRevocationDigestPgReconcileCandidate(
        $auth,$verify,$org,$instance,$pub
    );
    $status=new App\Infrastructure\Recovery\SignedRevocationDigestStatusPgReconcileCandidate(
        $write,$auth,$org
    );
    $plan=new App\Infrastructure\Recovery\Pg18VerifiedRevocationAckPreparationCandidate(
        $status,$org,$instance
    );
    $id=$expected['intent_id'];
    $nonce=$expected['nonce'];
    $checks=0;
    $check=static function(bool $yes)use(&$checks):void{
        if(!$yes)throw new RuntimeException('F394 invariant not met');
        $checks++;
    };
    $noAuthority=static function(array $result)use($check):void{
        foreach([
            'canAuthorizeHttp','canAuthorizeBusiness','canIssueSession',
            'canRecoverFromRestore','canDeploy','canClaimPostgresRevoked',
            'canClaimQueueFullyProcessed',
        ]as$key){
            $check(($result[$key]??null)===false);
        }
    };
    $phase='signed-intent-active-postgres';
    $check(($verify->verify(
        $receipt,$org,$instance,$id,$digest,$nonce,$pub,time()
    )['reasonCode']??null)==='INTENT_CONFIRMED_PENDING_RECONCILIATION_NO_SERVING');
    $check($auth->inspectSessionDigestStatus($digest,$org)==='not_revoked');

    $wrong=$plan->prepare(str_repeat('0',32),$digest,$nonce,$receipt,time());
    $check(($wrong['status']??null)==='F394_LOCAL_REVOCATION_NOT_VERIFIED');
    $check(($wrong['ackBody']??null)===null);
    $noAuthority($wrong);
    $check(!is_file($dir.'/ack-body.json'));

    $phase='forced-pg18-revocation-failure';
    $admin->exec(<<<'SQL'
CREATE FUNCTION public.portal_f394_force_failure()
RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp
AS $f394$
BEGIN
  IF OLD.status='active' AND NEW.status='revoked' THEN
    RAISE EXCEPTION 'synthetic F394 PostgreSQL revoked write failed'
        USING ERRCODE='40001';
  END IF;
  RETURN NEW;
END;
$f394$;
CREATE TRIGGER portal_f394_abort
BEFORE UPDATE ON public.portal_auth_sessions FOR EACH ROW
EXECUTE FUNCTION public.portal_f394_force_failure();
SQL);
    try{
        $failed=$plan->prepare($id,$digest,$nonce,$receipt,time());
        $check(($failed['status']??null)==='F394_LOCAL_REVOCATION_NOT_VERIFIED');
        $check(($failed['ackBody']??null)===null);
        $noAuthority($failed);
        $check($auth->inspectSessionDigestStatus($digest,$org)==='not_revoked');
        $check(!is_file($dir.'/ack-body.json'));
    }finally{
        $admin->exec('DROP TRIGGER IF EXISTS portal_f394_abort ON public.portal_auth_sessions');
        $admin->exec('DROP FUNCTION IF EXISTS public.portal_f394_force_failure()');
    }

    $phase='postgres-revoked-and-publisher-body-ready';
    $success=$plan->prepare($id,$digest,$nonce,$receipt,time());
    $check(($success['status']??null)==='LAB_ACK_BODY_PREPARED_STILL_NO_SERVING');
    $noAuthority($success);
    $body=$success['ackBody']??null;
    $check(is_array($body));
    $check(array_keys($body)===[
        'organization','instance','intent_id','session_sha256',
        'intent_sequence','local_status',
    ]);
    $check(($body['organization']??null)===$org);
    $check(($body['instance']??null)===$instance);
    $check(($body['intent_id']??null)===$id);
    $check(($body['session_sha256']??null)===$digest);
    $check(($body['intent_sequence']??null)===13);
    $check(($body['local_status']??null)==='revoked_claimed');
    $check($auth->inspectSessionDigestStatus($digest,$org)==='revoked');
    $path=$dir.'/ack-body.json';
    if(file_exists($path)||is_link($path))throw new RuntimeException();
    $check(file_put_contents($path,json_encode(
        $body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES
    ),LOCK_EX)!==false);
    chmod($path,0600);
    $check(is_file($path));
    echo "PASS F394 PG18: {$checks} verified restricted SQL status gates ACK publisher body; fault/invalid intent produces no ACK and never serving.\n";
}catch(Throwable){
    fwrite(STDERR,"FAIL F394: disposable verified PG18 ACK preparation phase {$phase}\n");
    exit(1);
}
