<?php

declare(strict_types=1);

/**
 * F385: no public serving; actual PG18 auth SQL + actual Laravel logout
 * controller, using only CI-created identities and disposable DB/roles.
 */
if(PHP_SAPI!=='cli' || $argc!==1
    || getenv('GITHUB_ACTIONS')!=='true'
    || getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    || getenv('PORTAL_ENROLLMENT_CI')!=='DISPOSABLE_ONLY'
    || getenv('PORTAL_RESTORE_QUARANTINE_CI')!=='DISPOSABLE_ONLY'
    || getenv('PORTAL_F385_CI')!=='DISPOSABLE_ONLY')exit(64);

$phase='bootstrap';
try {
    require '/opt/portal-recovery/seed-ci.php';

    $app=require '/var/www/html/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $checks=0;
    $check=static function(bool $pass)use(&$checks):void {
        if(!$pass)throw new RuntimeException('F385 synthetic invariant failed');
        $checks++;
    };

    $insert=static function(string $reference)use($db,$iid,$oid,$ir,$mr,$or):void {
        $query=$db->prepare(
            "INSERT INTO public.portal_auth_sessions(
                id,session_sha256,identity_id,organization_id,status,
                session_purpose,identity_revision,membership_revision,
                organization_revision,issued_at,expires_at,mfa_verified_at,
                created_at,updated_at
             ) VALUES(gen_random_uuid(),CAST(? AS char(64)),CAST(? AS uuid),
                CAST(? AS uuid),'active','business',?,?,?,
                clock_timestamp()-interval '1 minute',
                clock_timestamp()+interval '30 minutes',
                clock_timestamp()-interval '30 seconds',
                clock_timestamp(),clock_timestamp())"
        );
        $query->execute([hash('sha256',$reference),$iid,$oid,$ir,$mr,$or]);
    };

    $dbStatus=static function(string $reference)use($db):string {
        $query=$db->prepare(
            "SELECT status FROM public.portal_auth_sessions
             WHERE session_sha256=CAST(? AS char(64))"
        );
        $query->execute([hash('sha256',$reference)]);
        return (string)$query->fetchColumn();
    };

    $logout=static function(?string $reference)use($auth):array {
        $handler=new \Illuminate\Session\ArraySessionHandler(60);
        $session=new \Illuminate\Session\Store('portal-f385-ci',$handler);
        $session->start();
        if($reference!==null) {
            $session->put('portal.browser_auth',[
                'phase'=>'business',
                'reference'=>$reference,
            ]);
        }
        $request=\Illuminate\Http\Request::create(
            'https://portal.example.test/__f385/logout',
            'POST',[],[],[],
            ['HTTPS'=>'on','HTTP_HOST'=>'portal.example.test',
             'CONTENT_TYPE'=>'application/json'],
            '{}'
        );
        $request->setLaravelSession($session);
        $controller=new \App\Http\Controllers\PortalBrowserAuthController($auth);
        $response=$controller->logout($request);
        return [
            'status'=>$response->getStatusCode(),
            'body'=>$response->getContent(),
            'session_state'=>$session->get('portal.browser_auth'),
        ];
    };

    $phase='successful-real-pg18-revocation';
    $reference=str_repeat('d',64);
    $insert($reference);
    $check($auth->isActiveBusinessSession($reference));
    $check($dbStatus($reference)==='active');
    $done=$logout($reference);
    $check($done['status']===204);
    $check($done['session_state']===null);
    $check(!$auth->isActiveBusinessSession($reference));
    $check($dbStatus($reference)==='revoked');

    // A second attempt with no stored reference must be idempotent, without
    // accidentally issuing/renewing a session.
    $again=$logout(null);
    $check($again['status']===204);
    $check((int)$db->query(
        "SELECT count(*) FROM public.portal_auth_sessions
          WHERE status='active'"
    )->fetchColumn()===1); // the independent F365 seed still active

    $phase='forced-real-sql-failure';
    $failing=str_repeat('e',64);
    $insert($failing);
    $check($auth->isActiveBusinessSession($failing));

    $db->exec(<<<'SQL'
CREATE FUNCTION public.portal_f385_forced_revoke_failure()
RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog,pg_temp
AS $f385$
BEGIN
    IF OLD.status = 'active' AND NEW.status = 'revoked' THEN
        RAISE EXCEPTION 'synthetic failed durable PostgreSQL revocation'
            USING ERRCODE='40001';
    END IF;
    RETURN NEW;
END;
$f385$;
CREATE TRIGGER portal_f385_abort_revoke
BEFORE UPDATE ON public.portal_auth_sessions
FOR EACH ROW EXECUTE FUNCTION public.portal_f385_forced_revoke_failure();
SQL);

    try {
        $failed=$logout($failing);
        $check($failed['status']===503);
        $check($failed['session_state']===null);
        $check(str_contains((string)$failed['body'],'authentication_unavailable'));
        $check(!str_contains((string)$failed['body'],'synthetic failed'));
        // This is the critical NO-GO gap: clearing a browser cookie does not
        // prove the backend token was revoked; do NOT claim success.
        $check($auth->isActiveBusinessSession($failing));
        $check($dbStatus($failing)==='active');
    } finally {
        $db->exec('DROP TRIGGER IF EXISTS portal_f385_abort_revoke ON public.portal_auth_sessions');
        $db->exec('DROP FUNCTION IF EXISTS public.portal_f385_forced_revoke_failure()');
    }

    $phase='cleanup-and-retry';
    $check($auth->revoke($failing));
    $check(!$auth->isActiveBusinessSession($failing));
    $check($dbStatus($failing)==='revoked');
    $check((int)$db->query(
        "SELECT count(*) FROM public.portal_auth_sessions
          WHERE status='active'"
    )->fetchColumn()===1); // F365 seed (not exposed to any HTTP)

    echo "PASS F385: {$checks} genuine PostgreSQL18 logout/revoke success and forced SQL rollback; failed DB revocation remains active and is NOT safe for serving.\n";
}catch(Throwable) {
    fwrite(STDERR,"FAIL F385: isolated PG18 logout/revoke phase {$phase}\n");
    exit(1);
}
