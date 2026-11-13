<?php

declare(strict_types=1);

/**
 * F390: only a SHA256 digest leaves the session-creating PG18 process.
 * The 256-bit synthetic bearer is discarded before the Worker/repair starts.
 */
if (PHP_SAPI!=='cli'||$argc!==1
    ||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_ENROLLMENT_CI')!=='DISPOSABLE_ONLY'
    ||getenv('PORTAL_RESTORE_QUARANTINE_CI')!=='DISPOSABLE_ONLY'
    ||getenv('PORTAL_F390_CI')!=='DISPOSABLE_ONLY')exit(64);

try {
    require '/opt/portal-recovery/seed-ci.php';

    $reference=bin2hex(random_bytes(32));
    $digest=hash('sha256',$reference);
    $insert=$db->prepare(
        "INSERT INTO public.portal_auth_sessions(
            id,session_sha256,identity_id,organization_id,status,
            session_purpose,identity_revision,membership_revision,
            organization_revision,issued_at,expires_at,mfa_verified_at,
            created_at,updated_at)
         VALUES (gen_random_uuid(),CAST(? AS char(64)),CAST(? AS uuid),
            CAST(? AS uuid),'active','business',?,?,?,
            clock_timestamp()-interval '1 minute',
            clock_timestamp()+interval '30 minutes',
            clock_timestamp()-interval '30 seconds',
            clock_timestamp(),clock_timestamp())"
    );
    $insert->execute([$digest,$iid,$oid,$ir,$mr,$or]);
    if(!$auth->isActiveBusinessSession($reference)) {
        throw new RuntimeException('synthetic Portal SQL session was not active');
    }
    unset($reference);
    $fixture=[
        'organization'=>$oid,
        'instance'=>'f390_ci',
        'session_sha256'=>$digest,
    ];
    $path='/opt/f390/seed.json';
    if(is_file($path)||is_link($path)
        ||file_put_contents($path,json_encode($fixture,
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),LOCK_EX)===false) {
        throw new RuntimeException('F390 private synthetic fixture refused');
    }
    chmod($path,0600);
    $raw=(string)file_get_contents($path);
    if(str_contains($raw,'session_reference')
        ||str_contains($raw,'password')||str_contains($raw,'token')) {
        throw new RuntimeException('F390 must not persist raw session bearer');
    }
    echo "PASS F390 seed: PostgreSQL18 real session exists; only SHA256 digest persisted in disposable fixture.\n";
} catch(Throwable) {
    fwrite(STDERR,"FAIL F390: isolated digest-only seed refused\n");
    exit(1);
}
