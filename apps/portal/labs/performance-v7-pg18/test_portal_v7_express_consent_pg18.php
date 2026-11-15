<?php
declare(strict_types=1);

// INCLUDED from F829 fixture immediately after a real PostgreSQL-shaped
// session+agreement preflight. Never run separately: no HTTP, UI or real user.
// The synthetic bearer and identity IDs are deliberately not persisted here.
use App\Labs\PerformanceV7Pg18\PortalV7SqlExpressConsentLab as ExpressConsent;

if(PHP_SAPI!=='cli'
    || getenv('APP_ENV')!=='testing'
    || getenv('PORTAL_PERF_V7_EXPRESS_CONSENT_LAB')!=='1'
    || !isset($admin,$reader,$unpriv,$bearer,$tenant,$agreement,$period,
        $stableHash,$terms,$identity,$org,$sessionId,$sessionDigest)) {
    throw new RuntimeException('F830 must use F829 disposable PG18 fixture only.');
}
$consent=new ExpressConsent($reader);
$record=static fn(
    string $action,int $previous,bool $express=true,
    ?string $session=null,?string $selectedTenant=null,
    ?string $selectedPlan=null,?string $selectedTerms=null,
    int $contractRevision=1
):int=>$consent->record(
    $session??$bearer,$selectedTenant??$tenant,$agreement,
    $period,$selectedPlan??$stableHash,$selectedTerms??$terms,
    $contractRevision,$previous,$action,$express
);
$current=static fn(
    int $eventRevision,?string $selectedSession=null,int $contractRevision=1
):bool=>$consent->current(
    $selectedSession??$bearer,$tenant,$agreement,$period,
    $stableHash,$terms,$contractRevision,$eventRevision
);
v7pgMust(!$current(1),'no valid consent event before an express action');
v7pgMust($record('synthetic_accepted',0,false)===0,
    'missing express confirmation cannot create acceptance');
v7pgMust($record('synthetic_revoked',0)===0,
    'cannot revoke an acceptance that never existed');
v7pgMust($record('synthetic_accepted',0,true,
    'PORTAL-F830-UNKNOWN-CI-SESSION-BEARER-000')===0,
    'a forged/unknown bearer cannot accept an agreement');
v7pgMust($record('synthetic_accepted',0,true,null,
    'tenant_forged_12345678')===0,'foreign company cannot accept');
v7pgMust($record('synthetic_accepted',0,true,null,null,
    str_repeat('0',64))===0,'altered plan fingerprint cannot be accepted');
v7pgMust($record('synthetic_accepted',0,true,null,null,
    null,str_repeat('f',64))===0,'unapproved terms cannot be accepted');
v7pgMust($record('synthetic_accepted',0)===1,
    'explicit synthetic action from valid PG session appends revision 1');
v7pgMust($current(1),'latest version 1 is synthetically accepted');

$event=$admin->prepare(
    'SELECT state,event_revision,actor_id,organization_id,
        plan_sha256,terms_sha256,occurred_at
     FROM public.portal_v7_consent_events_lab
     WHERE tenant_id=:tenant AND agreement_id=:agreement AND period=:period
     ORDER BY event_revision'
);
$event->execute(['tenant'=>$tenant,'agreement'=>$agreement,'period'=>$period]);
$rows=$event->fetchAll(PDO::FETCH_ASSOC);
v7pgMust(count($rows)===1
    && $rows[0]['state']==='synthetic_accepted'
    && (int)$rows[0]['event_revision']===1
    && $rows[0]['actor_id']===$identity
    && $rows[0]['organization_id']===$org
    && $rows[0]['plan_sha256']===$stableHash
    && $rows[0]['terms_sha256']===$terms,
    'actor, company, terms and plan originate in trusted SQL session/agreement, not caller');
foreach(['INSERT','UPDATE','DELETE','SELECT'] as $priv) {
    $allowed=$reader->query("SELECT has_table_privilege(
       session_user,'public.portal_v7_consent_events_lab','$priv')"
    )->fetchColumn();
    v7pgMust(!in_array($allowed,[true,1,'1','t'],true),
        'SQL login cannot '.$priv.' consent events directly');
}
try {
    $unpriv->query("SELECT public.portal_v7_consent_current_lab(
      '$sessionDigest','$tenant','$agreement','$period',
      '$stableHash','$terms',1,1)");
    throw new RuntimeException('Unauthenticated SQL role can execute consent predicate');
}catch(PDOException $e){
    v7pgMust($e->getCode()==='42501','unprivileged SQL role cannot EXECUTE consent predicate');
}
v7pgMust($record('synthetic_accepted',0)===0,
    'double-click optimistic revision 0 rejected after revision 1');
v7pgMust($record('synthetic_accepted',1)===0,
    'cannot append duplicate accepted state');
v7pgMust($record('synthetic_revoked',0)===0,
    'stale optimistic revocation revision denied');
v7pgMust($record('synthetic_revoked',1)===2,
    'revocation from same verified business session appends revision 2');
v7pgMust(!$current(1) && !$current(2),
    'revoked latest event blocks old and new positive authorization');
v7pgMust($record('synthetic_revoked',2)===0,
    'repeat revocation is idempotently denied');

v7pgMust($record('synthetic_accepted',2)===3,
    'new explicit synthetic reacceptance requires current revision 2');
v7pgMust(!$current(1) && $current(3),
    'old accepted event may not be reused after new acceptance');
v7pgMust($record('synthetic_revoked',2)===0,
    'stale tab may not revoke newer revision 3');
$admin->exec("UPDATE public.portal_v7_agreement_authority_lab
    SET approval_revision=2
    WHERE tenant_id='$tenant' AND agreement_id='$agreement'");
v7pgMust(!$current(3),'independent agreement revision invalidates previously accepted event');
v7pgMust($record('synthetic_revoked',3)===0,
    'changed agreement version cannot receive revocation via stale contract revision');
$admin->exec("UPDATE public.portal_v7_agreement_authority_lab
    SET approval_revision=1
    WHERE tenant_id='$tenant' AND agreement_id='$agreement'");
v7pgMust($current(3),'restored synthetic approval revision permits current review only');

$admin->exec("UPDATE portal_memberships SET status='suspended' WHERE id='$membership'");
v7pgMust(!$current(3),'fresh consent recheck denies suspended membership');
v7pgMust($record('synthetic_revoked',3)===0,
    'suspended membership cannot exercise manage-services operation');
$admin->exec("UPDATE portal_memberships SET status='active' WHERE id='$membership'");
v7pgMust($current(3),'unblocked active session can inspect current synthetic event');
$admin->exec("UPDATE portal_auth_sessions SET status='revoked',revoked_at=now()
    WHERE id='$sessionId'");
v7pgMust(!$current(3),'session logout/revocation blocks even valid accepted event');
v7pgMust($record('synthetic_revoked',3)===0,
    'revoked session cannot record another event');
$admin->exec("UPDATE portal_auth_sessions SET status='active',revoked_at=NULL
    WHERE id='$sessionId'"); // CI restoration only; never resurrect operational sessions.
v7pgMust($current(3),'synthetic fixture restored for remaining F829 tests');

putenv('PORTAL_PERF_V7_EXPRESS_CONSENT_LAB=0');
v7pgMust(!$current(3) && $record('synthetic_revoked',3)===0,
    'feature switch disabled even with valid session and agreement');
putenv('PORTAL_PERF_V7_EXPRESS_CONSENT_LAB=1');
v7pgMust(!ExpressConsent::limits()['actual_customer_click_proven']
    && !ExpressConsent::limits()['legal_customer_acceptance_proven']
    && !ExpressConsent::limits()['billing_enabled'],
    'synthetic SQL row not operational customer contract or permission');
echo "PASS Portal F830: latest append-only synthetic acceptance/revocation bound to SQL MFA session actor; reacceptance, permission revisions, idempotency, RLS and session logout enforced; NO real customer assent.\n";
