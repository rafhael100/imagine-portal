<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||$argc!==1
    ||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_RESTORE_QUARANTINE_CI')!=='DISPOSABLE_ONLY'
    ||getenv('PORTAL_ENROLLMENT_CI')!=='DISPOSABLE_ONLY')exit(64);

require '/opt/portal-activation/test_activation.php';

$source=$db->query("SELECT current_database()")->fetchColumn();
if($source!=='portal_identity_dev')throw new RuntimeException('CI source mismatch');
$iid=$db->query('SELECT id FROM public.portal_identities')->fetchColumn();
$oid=$db->query('SELECT id FROM public.portal_organizations')->fetchColumn();
$ir=$db->query('SELECT security_revision FROM public.portal_identities')->fetchColumn();
$mr=$db->query('SELECT membership_revision FROM public.portal_memberships')->fetchColumn();
$or=$db->query('SELECT security_revision FROM public.portal_organizations')->fetchColumn();
$token=hash('sha256','synthetic-revoked-later-f365-ci-token');
$q=$db->prepare(
    "INSERT INTO public.portal_auth_sessions(
       id,session_sha256,identity_id,organization_id,status,
       session_purpose,identity_revision,membership_revision,
       organization_revision,issued_at,expires_at,mfa_verified_at,
       created_at,updated_at
     ) VALUES(gen_random_uuid(),CAST(? AS char(64)),CAST(? AS uuid),CAST(? AS uuid),
       'active','business',?,?,?,
       clock_timestamp()-interval '1 minute',
       clock_timestamp()+interval '30 minutes',
       clock_timestamp()-interval '30 seconds',
       clock_timestamp(),clock_timestamp())"
);
$q->execute([$token,$iid,$oid,$ir,$mr,$or]);
if(!in_array($db->query(
    "SELECT public.portal_browser_active('".$token."')"
)->fetchColumn(),[true,'t','1',1],true)){
    throw new RuntimeException('Fixture must prove OLD snapshot can authorize session');
}
echo "PASS source synthetic active session before pg_dump; no customer credentials.\n";
