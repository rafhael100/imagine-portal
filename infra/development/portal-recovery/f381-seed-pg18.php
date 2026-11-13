<?php
declare(strict_types=1);

// F381: isolated PostgreSQL 18 synthetic source seeded exactly once.
if(PHP_SAPI!=='cli'||$argc!==1
    ||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_F381_CI')!=='DISPOSABLE_ONLY'
    ||getenv('PORTAL_F373_CI')!=='DISPOSABLE_ONLY')exit(64);

try {
    require '/opt/portal-recovery/test-f373-pg18.php';

    $reference=str_repeat('c',64);
    $digest=hash('sha256',$reference);
    $query=$db->prepare(
        "INSERT INTO public.portal_auth_sessions(
            id,session_sha256,identity_id,organization_id,status,session_purpose,
            identity_revision,membership_revision,organization_revision,
            issued_at,expires_at,mfa_verified_at,created_at,updated_at)
         VALUES (gen_random_uuid(),CAST(? AS char(64)),CAST(? AS uuid),CAST(? AS uuid),
            'active','business',?,?,?,
            clock_timestamp()-interval '1 minute',
            clock_timestamp()+interval '30 minutes',
            clock_timestamp()-interval '30 seconds',
            clock_timestamp(),clock_timestamp())"
    );
    $query->execute([$digest,$iid,$oid,$ir,$mr,$or]);
    if(!$auth->isActiveBusinessSession($reference)) {
        throw new RuntimeException('F381 actual PG18 browser auth did not see active CI session');
    }
    if($db->query('SELECT state FROM public.portal_recovery_epoch_candidates')
        ->fetchColumn()!=='quarantined') {
        throw new RuntimeException('F381 epoch must be quarantined');
    }
    $scope=[
        'organization'=>$oid,
        'instance'=>'f373_ci',
    ];
    $path='/opt/f381/scope.json';
    if(file_exists($path)||is_link($path))throw new RuntimeException('F381 scope fixture already exists');
    if(file_put_contents($path,json_encode($scope,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
        LOCK_EX)===false)throw new RuntimeException('F381 scope fixture not written');
    chmod($path,0600);
    echo "PASS F381 seed: real Portal PG18 identity, active synthetic session, quarantined epoch, scoped fixture.\n";
} catch(Throwable) {
    fwrite(STDERR,"FAIL F381 seed: isolated source rejected\n");
    exit(1);
}
