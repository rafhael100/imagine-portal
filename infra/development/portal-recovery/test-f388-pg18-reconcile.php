<?php
declare(strict_types=1);

/**
 * F388: actual isolated Portal PostgreSQL 18 DB and restricted SQL role.
 * A synthetic Ed25519 receipt represents the F387 *protocol*, not an
 * operational external signer. All sessions and credentials are CI-only.
 */
if(PHP_SAPI!=='cli'||$argc!==1
    ||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_ENROLLMENT_CI')!=='DISPOSABLE_ONLY'
    ||getenv('PORTAL_RESTORE_QUARANTINE_CI')!=='DISPOSABLE_ONLY'
    ||getenv('PORTAL_F388_CI')!=='DISPOSABLE_ONLY')exit(64);

$phase='bootstrap';
try {
    require '/opt/portal-recovery/seed-ci.php';

    $realSql=$auth;
    $proof=new App\Infrastructure\Recovery\SignedRevocationIntentReadbackCandidateVerifier();
    $keypair=sodium_crypto_sign_keypair();
    $privateKey=sodium_crypto_sign_secretkey($keypair);
    $public=bin2hex(sodium_crypto_sign_publickey($keypair));
    $instance='instance_f388_ci';
    $reference=bin2hex(random_bytes(32));
    $digest=hash('sha256',$reference);
    $intent=bin2hex(random_bytes(16));
    $nonce=bin2hex(random_bytes(32));
    $now=time();
    $receipt=[
        'schema'=>App\Infrastructure\Recovery\SignedRevocationIntentReadbackCandidateVerifier::SCHEMA,
        'organization'=>$oid,
        'instance'=>$instance,
        'intent_id'=>$intent,
        'session_sha256'=>$digest,
        'intent_sequence'=>13,
        'observed_sequence'=>13,
        'session_floor'=>5,
        'issued_at'=>$now,
        'expires_at'=>$now+30,
        'nonce'=>$nonce,
        'status'=>'pending_local_reconciliation',
        'signature'=>'',
    ];
    $sign=static function(array $value)use(&$privateKey):array{
        $value['signature']=bin2hex(sodium_crypto_sign_detached(
            App\Infrastructure\Recovery\SignedRevocationIntentReadbackCandidateVerifier::canonical($value),
            $privateKey
        ));
        return $value;
    };
    $receipt=$sign($receipt);

    $writer=$db->prepare(
        "INSERT INTO public.portal_auth_sessions(
          id,session_sha256,identity_id,organization_id,status,
          session_purpose,identity_revision,membership_revision,
          organization_revision,issued_at,expires_at,mfa_verified_at,
          created_at,updated_at)
        VALUES(gen_random_uuid(),CAST(? AS char(64)),CAST(? AS uuid),
          CAST(? AS uuid),'active','business',?,?,?,
          clock_timestamp()-interval '1 minute',
          clock_timestamp()+interval '30 minutes',
          clock_timestamp()-interval '30 seconds',
          clock_timestamp(),clock_timestamp())"
    );
    $writer->execute([$digest,$iid,$oid,$ir,$mr,$or]);
    $readState=static function()use($db,$digest):string{
        $q=$db->prepare('SELECT status FROM public.portal_auth_sessions WHERE session_sha256=?');
        $q->execute([$digest]);
        return (string)$q->fetchColumn();
    };

    $reconciler=new App\Infrastructure\Recovery\SignedRevocationIntentPgReconcileCandidate(
        $realSql,$proof,$oid,$instance,$public
    );
    $checks=0;
    $assert=static function(bool $valid)use(&$checks):void{
        if(!$valid)throw new RuntimeException('F388 candidate invariant failed');
        $checks++;
    };
    $result=static function(array $value,string $expected)use($assert):void{
        $assert(($value['status']??null)===$expected);
        foreach([
          'canAuthorizeHttp','canAuthorizeBusiness','canIssueSession',
          'canRecoverFromRestore','canDeploy','canClaimPostgresRevoked',
        ]as$flag){
            $assert(($value[$flag]??null)===false);
        }
    };

    $phase='synthetic-live-session';
    $assert($realSql->isActiveBusinessSession($reference));
    $assert($readState()==='active');

    $phase='signed-binding-negative';
    $result($reconciler->inspectRetry($reference,$intent,$nonce,[],$now),
        'INDEPENDENT_INTENT_NOT_VERIFIED');
    $result($reconciler->inspectRetry($reference,'wrong',$nonce,$receipt,$now),
        'RECONCILE_INPUT_DENIED');
    $result($reconciler->inspectRetry($reference,$intent,str_repeat('0',64),$receipt,$now),
        'INDEPENDENT_INTENT_NOT_VERIFIED');
    $result($reconciler->inspectRetry(bin2hex(random_bytes(32)),$intent,$nonce,$receipt,$now),
        'INDEPENDENT_INTENT_NOT_VERIFIED');
    $result($reconciler->inspectRetry($reference,$intent,$nonce,
        [...$receipt,'session_floor'=>6],$now),'INDEPENDENT_INTENT_NOT_VERIFIED');
    $result($reconciler->inspectRetry($reference,$intent,$nonce,
        $sign([...$receipt,'organization'=>'different_org']),$now),
        'INDEPENDENT_INTENT_NOT_VERIFIED');
    $result($reconciler->inspectRetry($reference,$intent,$nonce,
        $receipt,$now+120),'INDEPENDENT_INTENT_NOT_VERIFIED');
    $assert($readState()==='active');
    $assert($realSql->isActiveBusinessSession($reference));

    $phase='forced-real-pg18-failure';
    $db->exec(<<<'SQL'
CREATE FUNCTION public.portal_f388_revoke_failure()
RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog,pg_temp
AS $f388$
BEGIN
    IF OLD.status='active' AND NEW.status='revoked' THEN
        RAISE EXCEPTION 'synthetic F388 local PG failure' USING ERRCODE='40001';
    END IF;
    RETURN NEW;
END;
$f388$;
CREATE TRIGGER portal_f388_abort_revoke
BEFORE UPDATE ON public.portal_auth_sessions
FOR EACH ROW EXECUTE FUNCTION public.portal_f388_revoke_failure();
SQL);
    try {
        $result($reconciler->inspectRetry($reference,$intent,$nonce,$receipt,time()),
            'POSTGRES_REVOKE_UNAVAILABLE');
        $assert($realSql->isActiveBusinessSession($reference));
        $assert($readState()==='active');
    }finally{
        $db->exec('DROP TRIGGER IF EXISTS portal_f388_abort_revoke ON public.portal_auth_sessions');
        $db->exec('DROP FUNCTION IF EXISTS public.portal_f388_revoke_failure()');
    }

    $phase='durable-recovery-and-recheck';
    $result($reconciler->inspectRetry($reference,$intent,$nonce,$receipt,time()),
        'LAB_POSTGRES_REVOKE_OBSERVED_STILL_NO_SERVING');
    $assert(!$realSql->isActiveBusinessSession($reference));
    $assert($readState()==='revoked');
    $assert((int)$db->query(
        "SELECT count(*) FROM public.portal_auth_sessions WHERE status='active'"
    )->fetchColumn()===1); // only the F365 independent seed remains active
    sodium_memzero($privateKey);
    echo "PASS F388: {$checks} real PostgreSQL18 session remains active on SQL failure, valid signed intent permits only retry/recheck revocation, never serving.\n";
}catch(Throwable){
    fwrite(STDERR,"FAIL F388: isolated PG18 signed-intent retry phase {$phase}\n");
    exit(1);
}
