<?php
declare(strict_types=1);

// F389 synthetic-only PG18 seed for actual Worker->signed receipt->real PG retry.
if(PHP_SAPI!=='cli'||$argc!==1
    ||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_ENROLLMENT_CI')!=='DISPOSABLE_ONLY'
    ||getenv('PORTAL_RESTORE_QUARANTINE_CI')!=='DISPOSABLE_ONLY'
    ||getenv('PORTAL_F389_CI')!=='DISPOSABLE_ONLY')exit(64);

try {
    require '/opt/portal-recovery/seed-ci.php';
    $ref=bin2hex(random_bytes(32));
    $digest=hash('sha256',$ref);
    $q=$db->prepare(
        "INSERT INTO public.portal_auth_sessions(
           id,session_sha256,identity_id,organization_id,status,session_purpose,
           identity_revision,membership_revision,organization_revision,
           issued_at,expires_at,mfa_verified_at,created_at,updated_at)
         VALUES(gen_random_uuid(),CAST(? AS char(64)),CAST(? AS uuid),
           CAST(? AS uuid),'active','business',?,?,?,
           clock_timestamp()-interval '1 minute',
           clock_timestamp()+interval '30 minutes',
           clock_timestamp()-interval '30 seconds',clock_timestamp(),clock_timestamp())"
    );
    $q->execute([$digest,$iid,$oid,$ir,$mr,$or]);
    if(!$auth->isActiveBusinessSession($ref))throw new RuntimeException();
    $seed=[
        'organization'=>$oid,
        'instance'=>'f389_ci',
        'session_reference'=>$ref,
        'session_sha256'=>$digest,
    ];
    $path='/opt/f389/seed.json';
    if(file_exists($path)||is_link($path))throw new RuntimeException();
    if(file_put_contents($path,
        json_encode($seed,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
        LOCK_EX)===false)throw new RuntimeException();
    chmod($path,0600);
    echo "PASS F389 seed: real PostgreSQL18 active synthetic session and protected reference file.\n";
}catch(Throwable){
    fwrite(STDERR,"FAIL F389 seed: isolated Portal session provisioning refused\n");
    exit(1);
}
