<?php
declare(strict_types=1);

// INCLUDED only after Portal F831 PostgreSQL session + acceptance/revocation
// fixture has created a current synthetic acceptance at event revision 3.
use App\Labs\PerformanceV7Pg18\PortalV7SqlTermsPresentationReceiptLab as TermsReceipt;

if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing'
    || getenv('PORTAL_PERF_V7_TERMS_PRESENTATION_LAB')!=='1'
    || !isset($admin,$reader,$unpriv,$bearer,$tenant,$agreement,
        $period,$stableHash,$terms,$identity,$org,$sessionId,
        $sessionDigest,$record,$current)) {
    throw new RuntimeException('F832 requires the F831 disposable CI fixture.');
}
$termsLab=new TermsReceipt($reader);
$show=static fn(?string $session=null,?string $t=null,
    ?string $plan=null,?string $acceptedTerms=null,
    int $contractRev=1,int $previousRev=4):?string=>
    $termsLab->register(
        $session??$bearer,$t??$tenant,$agreement,$period,
        $plan??$stableHash,$acceptedTerms??$terms,$contractRev,$previousRev
    );
$accept=static fn(string $challenge,int $prev=4,bool $express=true,
    ?string $session=null,?string $plan=null,
    ?string $acceptedTerms=null,int $contractRev=1):int=>
    $termsLab->accept(
        $session??$bearer,$challenge,$tenant,$agreement,$period,
        $plan??$stableHash,$acceptedTerms??$terms,$contractRev,$prev,$express
    );
$valid=static fn(int $rev,?string $session=null,int $contractRev=1,
    ?string $acceptedTerms=null):bool=>
    $termsLab->isCurrent(
        $session??$bearer,$tenant,$agreement,$period,$stableHash,
        $acceptedTerms??$terms,$contractRev,$rev
    );

v7pgMust(!$valid(3),'a prior F831 acceptance without presentation receipt is insufficient');
v7pgMust($record('synthetic_revoked',3)===4,
    'F832 starts from revoked F831 approval at event revision 4');
v7pgMust(!$valid(3),'revoked signed review remains unusable');
v7pgMust($accept('00000000-0000-4000-8000-000000000000')===0,
    'accepting without registered presentation fails');
v7pgMust($show($bearer,$tenant,str_repeat('0',64))===null,
    'presenting altered media plan cannot generate receipt');
v7pgMust($show($bearer,$tenant,$stableHash,str_repeat('f',64))===null,
    'presenting altered terms cannot generate receipt');
v7pgMust($show('PORTAL-F832-UNISSUED-SESSION-000000000001')===null,
    'unknown Portal browser bearer cannot receive presentation');
v7pgMust($show($bearer,'tenant_foreign_12345678')===null,
    'cross-tenant presentation denied');
v7pgMust($show($bearer,$tenant,$stableHash,$terms,2)===null,
    'stale agreement version cannot register presentation');
v7pgMust($show($bearer,$tenant,$stableHash,$terms,1,3)===null,
    'stale consent revision cannot register presentation');

$challenge=$show();
v7pgMust(is_string($challenge) &&
    preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/D',$challenge)===1,
    'one short-lived PostgreSQL-issued presentation receipt exists');
v7pgMust($accept($challenge,4,false)===0,
    'synthetic acceptance requires an explicit true confirmation');
v7pgMust($accept($challenge,3)===0,
    'stale previous consent revision cannot be accepted');
v7pgMust($accept($challenge,4,true,'PORTAL-F832-UNKNOWN-SESSION-000000001')===0,
    'presentation issued to another session cannot be stolen');
v7pgMust($accept($challenge,4,true,$bearer,str_repeat('f',64))===0,
    'changing accepted media plan invalidates presentation');
v7pgMust($accept($challenge,4,true,$bearer,$stableHash,str_repeat('a',64))===0,
    'changing terms after rendering invalidates presentation');

v7pgMust($accept($challenge)===5,
    'F831 consent event revision 5 created using one current F832 presentation receipt');
v7pgMust($valid(5),'latest accepted V7 terms have an unambiguous presentation-event link');
v7pgMust(!$valid(3),'old accepted revision is never current');
v7pgMust($accept($challenge)===0,
    'double-click or replay cannot reuse the consumed receipt');

$admin->prepare(
    'SELECT l.consent_revision,l.presentation_challenge,
        p.actor_id,p.organization_id,p.plan_sha256,p.terms_sha256
     FROM public.portal_v7_terms_acceptance_link_lab l
     JOIN public.portal_v7_terms_presentation_lab p
       ON p.challenge=l.presentation_challenge
     WHERE l.tenant_id=? AND l.agreement_id=? AND l.period=?'
)->execute([$tenant,$agreement,$period]);
$linked=$admin->query(
    "SELECT l.consent_revision,l.presentation_challenge,p.actor_id,
            p.organization_id,p.plan_sha256,p.terms_sha256
       FROM public.portal_v7_terms_acceptance_link_lab l
       JOIN public.portal_v7_terms_presentation_lab p
         ON p.challenge=l.presentation_challenge
      WHERE l.tenant_id='$tenant' AND l.agreement_id='$agreement'"
)->fetchAll(PDO::FETCH_ASSOC);
v7pgMust(count($linked)===1
    && (int)$linked[0]['consent_revision']===5
    && $linked[0]['presentation_challenge']===$challenge
    && $linked[0]['actor_id']===$identity
    && $linked[0]['organization_id']===$org
    && $linked[0]['plan_sha256']===$stableHash
    && $linked[0]['terms_sha256']===$terms,
    'link and actor recorded from server-owned agreement/session, not caller');

foreach ([
    'portal_v7_terms_presentation_lab',
    'portal_v7_terms_acceptance_link_lab'
] as $table){
    foreach(['SELECT','INSERT','UPDATE','DELETE'] as $action){
        $has=$reader->query("SELECT has_table_privilege(
            session_user,'public.$table','$action')"
        )->fetchColumn();
        v7pgMust(!in_array($has,[true,1,'1','t'],true),
            'client SQL login cannot directly '.$action.' '.$table);
    }
}
try{
    $unpriv->query(
        "SELECT public.portal_v7_terms_current_with_receipt_lab(
           '$sessionDigest','$tenant','$agreement','$period',
           '$stableHash','$terms',1,5)"
    );
    throw new RuntimeException('unauthorized role can verify consent');
}catch(PDOException $e){
    v7pgMust($e->getCode()==='42501',
        'unprivileged database role cannot EXECUTE receipt-backed authorization');
}

v7pgMust($record('synthetic_revoked',5)===6,
    'F831 revocation supersedes acceptance even with intact receipt link');
v7pgMust(!$valid(5),'revoked acceptance cannot revive with valid presentation');
v7pgMust($accept($challenge,6)===0,
    'old receipt cannot authorize a later agreement revision');

$expired=$show($bearer,$tenant,$stableHash,$terms,1,6);
v7pgMust(is_string($expired),'can register receipt to test expiration');
$admin->prepare(
    "UPDATE public.portal_v7_terms_presentation_lab
       SET registered_at=clock_timestamp()-interval '4 minutes',
           expires_at=clock_timestamp()-interval '2 minutes'
     WHERE challenge=CAST(:challenge AS uuid)"
)->execute(['challenge'=>$expired]);
v7pgMust($accept($expired,6)===0,
    'expired receipt cannot authorize despite unchanged plan and fresh MFA');

$next=$show($bearer,$tenant,$stableHash,$terms,1,6);
v7pgMust(is_string($next) && $next!==$challenge && $next!==$expired,
    'reacceptance requires a newly registered current presentation');
v7pgMust($accept($next,6)===7,
    'new registration plus explicit acceptance yields next revision');
v7pgMust($valid(7) && !$valid(5),
    'only latest accepted revision with its own receipt passes');

$admin->exec("UPDATE portal_auth_sessions SET status='revoked',
    revoked_at=now() WHERE id='$sessionId'");
v7pgMust(!$valid(7),'session logout denies even linked current acceptance');
v7pgMust($show($bearer,$tenant,$stableHash,$terms,1,7)===null,
    'revoked session cannot obtain a new presentation receipt');
$admin->exec("UPDATE portal_auth_sessions SET status='active',
    revoked_at=NULL WHERE id='$sessionId'"); // test fixture only, not real recovery
v7pgMust($valid(7),'CI session restoration for subsequent F829 regression');

$admin->exec("UPDATE portal_v7_agreement_authority_lab
    SET terms_sha256='".str_repeat('a',64)."',approval_revision=2
    WHERE tenant_id='$tenant' AND agreement_id='$agreement'");
v7pgMust(!$valid(7),'changing legal terms and agreement revision invalidates prior receipt');
$admin->exec("UPDATE portal_v7_agreement_authority_lab
    SET terms_sha256='$terms',approval_revision=1
    WHERE tenant_id='$tenant' AND agreement_id='$agreement'");
v7pgMust($valid(7),'CI restores synthetic agreement fixture');

putenv('PORTAL_PERF_V7_TERMS_PRESENTATION_LAB=0');
v7pgMust(!$valid(7) && $accept($next,7)===0,
    'lab feature flag disabled refuses all checks');
putenv('PORTAL_PERF_V7_TERMS_PRESENTATION_LAB=1');
v7pgMust(
    !TermsReceipt::limits()['actual_html_rendering_proven']
    && !TermsReceipt::limits()['actual_customer_approval_proven']
    && !TermsReceipt::limits()['legal_contract_accepted']
    && !TermsReceipt::limits()['billable'],
    'SQL presentation and click simulation NEVER establish real legal authority'
);
echo "PASS F832: short-lived session-bound V7 terms receipt, single-use acceptance link, replay/expiry/tenant/term change/revocation denied; NO HTML or customer assent.\n";
