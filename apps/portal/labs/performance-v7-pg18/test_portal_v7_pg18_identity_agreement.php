<?php
declare(strict_types=1);

// CI ONLY: actual Portal F66/F76/F113 PostgreSQL session/organization/
// explicit-permission rows, plus independent synthetic agreement ownership.
require_once dirname(__DIR__,2).'/vendor/autoload.php';

use App\Domain\Performance\ActivationGatewayReviewLab as Gateway;
use App\Domain\Performance\PerformanceChannelReviewEnvelope as Activation;
use App\Domain\Performance\PortalPerformanceV7AgreementPlanLabIssuer as PlanIssuer;
use App\Labs\PerformanceV7Pg18\PortalV7SqlAgreementAuthorityLab as Authority;
use App\Labs\PerformanceV7Pg18\PortalV7SqlGatedSignedPlanReviewLab as Bridge;

function v7pgMust(bool $condition,string $message):void {
    if(!$condition)throw new RuntimeException('PORTAL V7 PG18 CI: '.$message);
}
function v7pgDeny(callable $action,string $message):void {
    try{$action();}catch(InvalidArgumentException|RuntimeException){return;}
    throw new RuntimeException('PORTAL V7 PG18 wrongly permitted: '.$message);
}
if(PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing'
    || getenv('PORTAL_PERF_V7_PG18_IDENTITY_LAB')!=='1'
    || getenv('PORTAL_PERF_V7_PLAN_AGREEMENT_LAB')!=='1'
    || getenv('PGHOST')!=='127.0.0.1' || getenv('PGPORT')!=='5432'
    || getenv('PGDATABASE')!=='portal_identity_lab'){
    throw new RuntimeException('Disposable Portal V7 PG18 CI gate closed.');
}
$connect=static fn(string $role,string $password):PDO=>new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab;connect_timeout=3',
    $role,$password,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]
);
$admin=$connect('postgres',getenv('F66_PG_ADMIN_PASSWORD'));
$reader=$connect('portal_v7_lab_reader',getenv('PORTAL_V7_PG_READER_PASSWORD'));
$unpriv=$connect('portal_v7_lab_unpriv',getenv('PORTAL_V7_PG_UNPRIV_PASSWORD'));
$authority=new Authority($reader);
$bridge=new Bridge($authority);
$identity='e1111111-1111-4111-8111-111111111111';
$org='e2222222-2222-4222-8222-222222222222';
$foreignOrg='e3333333-3333-4333-8333-333333333333';
$membership='e4444444-4444-4444-8444-444444444444';
$grant='e5555555-5555-4555-8555-555555555555';
$sessionId='e6666666-6666-4666-8666-666666666666';
$bearer='PORTAL-V7-PG18-CI-PRIVATE-SESSION-00000001';
$sessionDigest=hash('sha256',$bearer);
$tenant='tenant_f827_pg18_12345678';
$otherTenant='tenant_f827_pg18_87654321';
$agreement='agreement_f827_pg18_12345678';
$period='2026-11';
$approvalRevision=1;
$terms=hash('sha256','synthetic accepted commercial conditions v7 full-month only');
$activationKey=str_repeat('X',48);
$planKey=str_repeat('Y',48);
$eligible=['google_ads'=>true,'meta_ads'=>true,'chatgpt_ads'=>false];
$plan=['google_ads'=>100000,'meta_ads'=>70000];
$scope=[
    'tenant_id'=>$tenant,'agreement_id'=>$agreement,'period'=>$period,
    'organization_ref'=>$org,'order_ref'=>'order-f827-pg18-demo-01',
    'policy_revision'=>3,
];
$policy=[
    'revision'=>3,'mode'=>'manual','currency'=>'BRL','preferred_gateway'=>'asaas',
    'providers'=>[
        'asaas'=>[
            'enabled'=>true,'approved'=>true,'healthy'=>true,'fee_version'=>'SIM-TEST',
            'methods'=>['pix'=>[
                'enabled'=>true,'fixed_cents'=>100,
                'variable_bps'=>100,'settlement_days'=>1,
            ]],
        ],
        'c6'=>['enabled'=>false,'approved'=>false,'healthy'=>false,
            'fee_version'=>'SIM-C6','methods'=>[]],
    ],
];
$now=time();
$nowUtc=gmdate('Y-m-d\TH:i:s\Z',$now);
$issuedUtc=gmdate('Y-m-d\TH:i:s\Z',$now-20);
$issuedAt=$now-1;
$expiresAt=$now+90;
$original=Gateway::preview(
    ['google_ads','meta_ads'],$eligible,$policy,'pix',
    $org,$scope['order_ref'],3,$activationKey
);
$activation=Activation::issue(
    $original,$eligible,$org,$scope['order_ref'],
    3,$issuedUtc,$activationKey
);
$preSigned=PlanIssuer::issue(
    $activation,$original,$eligible,$scope,$plan,$nowUtc,
    'k_f827_plan_01','nonce_f827_plan_123456789012',
    $issuedAt,$expiresAt,$activationKey,$planKey
);
$canon=null;
$canon=static function(array $value)use(&$canon):array{
    if(array_is_list($value)){
        return array_map(static fn(mixed $part):mixed=>
            is_array($part)?$canon($part):$part,$value);
    }
    ksort($value,SORT_STRING);
    foreach($value as $name=>$part)if(is_array($part))$value[$name]=$canon($part);
    return $value;
};
$stable=$preSigned['payload'];
unset($stable['nonce'],$stable['issued_at'],$stable['expires_at'],$stable['key_id']);
$stableHash=hash('sha256',
    "IMAGINE-V7-ACCEPTANCE-STABLE-PLAN-V1\n".
    json_encode($canon($stable),
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE
    )
);
$admin->prepare(
    "INSERT INTO portal_identities
      (id,email_normalized,status,email_verified_at,mfa_confirmed_at,
       security_revision,created_at,updated_at)
     VALUES(?,?,'active',now()-interval '1 day',now()-interval '1 day',
       1,now(),now())"
)->execute([$identity,'f827-v7@example.invalid']);
$insertOrg=$admin->prepare(
    "INSERT INTO portal_organizations
      (id,reference,display_name,status,security_revision,created_at,updated_at)
     VALUES(?,?,?,'active',1,now(),now())"
);
$insertOrg->execute([$org,'f827-org-a','CI-only F827 organization A']);
$insertOrg->execute([$foreignOrg,'f827-org-b','CI-only F827 organization B']);
$admin->prepare(
    "INSERT INTO portal_memberships
      (id,identity_id,organization_id,role,status,membership_revision,created_at,updated_at)
     VALUES(?,?,?,'member','active',1,now(),now())"
)->execute([$membership,$identity,$org]);
$admin->prepare(
    "INSERT INTO portal_membership_grants
      (id,membership_id,permission_code,created_at,updated_at)
     VALUES(?,?,'services.manage',now(),now())"
)->execute([$grant,$membership]);
$admin->prepare(
    "INSERT INTO portal_auth_sessions
      (id,session_sha256,identity_id,organization_id,status,session_purpose,
       identity_revision,membership_revision,organization_revision,
       mfa_verified_at,issued_at,expires_at,created_at,updated_at)
     VALUES(?,?,?,?, 'active','business',1,1,1,
       now()-interval '1 minute',now()-interval '5 minutes',
       now()+interval '25 minutes',now(),now())"
)->execute([$sessionId,$sessionDigest,$identity,$org]);
$admin->prepare(
    "INSERT INTO public.portal_v7_agreement_authority_lab
      (tenant_id,agreement_id,period,organization_id,state,
       approval_revision,plan_sha256,terms_sha256,full_month_reviewed)
     VALUES (?,?,?,?,'synthetic_accepted',?,?,?,true)"
)->execute([$tenant,$agreement,$period,$org,1,$stableHash,$terms]);

$allowed=static fn(?string $opaque=null,?string $t=null,
    ?string $a=null,?string $month=null,?string $planHash=null,
    ?string $conditions=null,?int $revision=null):bool=>
    $authority->permits($opaque??$bearer,$t??$tenant,$a??$agreement,
        $month??$period,$planHash??$stableHash,$conditions??$terms,
        $revision??1);
v7pgMust($allowed(),'first-party-shaped PG session with real table MFA, explicit permission and agreement permits lab review');
$prepare=static fn(
    ?string $opaque=null,?string $expectedTenant=null,
    ?string $expectedAgreement=null,?string $termsHash=null,?int $revision=null,
    ?array $planned=null
):array=>$bridge->prepare(
    $opaque??$bearer,$expectedTenant??$tenant,$expectedAgreement??$agreement,
    $termsHash??$terms,$revision??1,
    $activation,$original,$eligible,$scope,$planned??$plan,$nowUtc,
    'k_f827_plan_01','nonce_f827_plan_123456789012',
    $issuedAt,$expiresAt,$activationKey,$planKey
);
$review=$prepare();
if(getenv('PORTAL_F829_EXPORT_SYNTHETIC')==='1') {
    // Deliberately excludes bearer/session hash, identity, OTP, credentials,
    // cookies, raw SQL rows and secrets. The signer uses fixed PUBLIC CI keys.
    // Only the signed synthetic commercial contract crosses repositories.
    $snapshot=[
        'source'=>'portal_f829_pg18_synthetic_review',
        'signed_plan'=>$review['signed_plan_review'],
        'activation'=>$activation,
        'expected'=>$scope+[
            'gateway'=>$original['gateway_quote']['gateway'],
            'method'=>$original['gateway_quote']['method'],
            'crm_review_mac'=>$original['crm_review']['mac'],
            'key_id'=>'k_f827_plan_01',
        ],
        'planned'=>$plan,
        'channels'=>array_keys($plan),
        'review_now_utc'=>$nowUtc,
        'stable_plan_sha256'=>$stableHash,
        'terms_sha256'=>$terms,
        'approval_revision'=>$approvalRevision,
        'actual_customer_acceptance'=>false,
    ];
    $written=file_put_contents(
        '/tmp/portal-f829-signed-plan-ci.json',
        json_encode($snapshot,JSON_THROW_ON_ERROR|
            JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
    v7pgMust(is_int($written)&&$written>100,
        'CI-only redacted signed review export written for crossrepo parity');
}
v7pgMust($review['stable_plan_sha256']===$stableHash &&
    $review['synthetic_pg18_session_mfa_agreement_checked']===true,
    'stable V7 plan hash matches DB-owned synthetic agreement');
v7pgMust($review['signed_plan_review']['mac']===$preSigned['mac'] &&
    $review['signed_plan_review']['payload']['activation_cents']===98000 &&
    $review['signed_plan_review']['payload']['estimated_management_fee_cents']===37000,
    'real F419/V7 signer stays compatible, R$980 activation and R$370 estimated management');
foreach([
    'portal_iam_authenticated','real_customer_acceptance_proven',
    'real_customer_consent_proven','billable','can_authorize_http',
    'can_charge','can_activate','ads_write_enabled','crm_write_enabled',
] as $flag)v7pgMust(($review[$flag]??null)===false,
    'PG fixture is not real customer authorization: '.$flag);

// A direct database login sees no identity, agreement or consent rows.
foreach([
    'portal_auth_sessions','portal_identities','portal_memberships',
    'portal_membership_grants','portal_v7_agreement_authority_lab'
] as $table) {
    try {$reader->query("SELECT * FROM public.$table");}
    catch(PDOException $e) {
        v7pgMust($e->getCode()==='42501','EXECUTE-only SQL login cannot read '.$table);
        continue;
    }
    throw new RuntimeException('Direct SELECT unexpectedly allowed on '.$table);
}
try {
    $unpriv->query("SELECT public.portal_v7_agreement_preflight_lab(
        '$sessionDigest','$tenant','$agreement','$period','$stableHash','$terms',1)");
    throw new RuntimeException('Unprivileged login can call preflight');
} catch(PDOException $e) {
    v7pgMust($e->getCode()==='42501','unprivileged login lacks function EXECUTE');
}
$reader->exec("SET app.portal_organization_ref='$foreignOrg'");
v7pgMust($allowed(),'forgeable organization GUC does not replace owner SQL mapping');
v7pgMust(!$allowed($bearer,$otherTenant),'tenant identity cannot be forged');
v7pgMust(!$allowed($bearer,'agreement_foreign_12345678'),'agreement mismatch denies');
v7pgMust(!$allowed($bearer,$tenant,$agreement,'2026-12'),'period mismatch denies');
v7pgMust(!$allowed('PORTAL-V7-PG18-CI-INVALID-SESSION-0000001'),'unissued bearer denies');
v7pgMust(!$allowed($bearer,$tenant,$agreement,$period,str_repeat('0',64)),
    'changed media-plan fingerprint denies');
v7pgMust(!$allowed($bearer,$tenant,$agreement,$period,$stableHash,str_repeat('f',64)),
    'changed accepted terms denies');
v7pgMust(!$allowed($bearer,$tenant,$agreement,$period,$stableHash,$terms,2),
    'unauthorized acceptance revision denies');

$update=static function(string $sql)use($admin):void{$admin->exec($sql);};
$update("UPDATE portal_membership_grants SET permission_code='services.view'
    WHERE id='$grant'");
v7pgMust(!$allowed(),'read-only grant cannot approve or sign a management contract');
v7pgDeny(fn()=> $prepare(),'signed plan not returned when services.manage absent');
$update("UPDATE portal_membership_grants SET permission_code='services.manage'
    WHERE id='$grant'");
$update("UPDATE portal_memberships SET status='revoked' WHERE id='$membership'");
v7pgMust(!$allowed(),'revoked membership invalidates active session');
$update("UPDATE portal_memberships SET status='active',membership_revision=2
    WHERE id='$membership'");
v7pgMust(!$allowed(),'changed membership revision invalidates previously-issued session');
$update("UPDATE portal_memberships SET membership_revision=1
    WHERE id='$membership'");
$update("UPDATE portal_identities SET security_revision=2 WHERE id='$identity'");
v7pgMust(!$allowed(),'changed identity revision invalidates session');
$update("UPDATE portal_identities SET security_revision=1 WHERE id='$identity'");
$update("UPDATE portal_organizations SET status='suspended' WHERE id='$org'");
v7pgMust(!$allowed(),'suspended organization denies agreement');
$update("UPDATE portal_organizations SET status='active',security_revision=2
    WHERE id='$org'");
v7pgMust(!$allowed(),'organization revision change invalidates session');
$update("UPDATE portal_organizations SET security_revision=1 WHERE id='$org'");
$update("UPDATE portal_auth_sessions SET expires_at=now()-interval '1 second'
    WHERE id='$sessionId'");
v7pgMust(!$allowed(),'expired Portal session blocks agreement');
$update("UPDATE portal_auth_sessions SET
    expires_at=now()+interval '25 minutes',
    mfa_verified_at=now()-interval '20 minutes'
    WHERE id='$sessionId'");
v7pgMust(!$allowed(),'old MFA session must refresh before signing');
$update("UPDATE portal_auth_sessions SET mfa_verified_at=now()-interval '1 minute'
    WHERE id='$sessionId'");
$update("UPDATE portal_v7_agreement_authority_lab
    SET state='synthetic_revoked',approval_revision=2
    WHERE tenant_id='$tenant' AND agreement_id='$agreement'");
v7pgMust(!$allowed(),'durable agreement revocation blocks valid MFA business session');
v7pgDeny(fn()=> $prepare(),'revoked agreement must not return V7 HMAC');
$update("UPDATE portal_v7_agreement_authority_lab
    SET state='synthetic_accepted',approval_revision=3
    WHERE tenant_id='$tenant' AND agreement_id='$agreement'");
v7pgMust(!$allowed(),'old approval revision cannot be reused after reacceptance');
v7pgMust($allowed($bearer,$tenant,$agreement,$period,$stableHash,$terms,3),
    'fresh expected revision explicitly permits reaccepted SQL review');

$update("UPDATE portal_v7_agreement_authority_lab
    SET full_month_reviewed=false,approval_revision=4
    WHERE tenant_id='$tenant' AND agreement_id='$agreement'");
v7pgMust(!$allowed($bearer,$tenant,$agreement,$period,$stableHash,$terms,4),
    'unreviewed partial month stays NO-GO');
$update("UPDATE portal_v7_agreement_authority_lab
    SET full_month_reviewed=true,approval_revision=5,organization_id='$foreignOrg'
    WHERE tenant_id='$tenant' AND agreement_id='$agreement'");
v7pgMust(!$allowed($bearer,$tenant,$agreement,$period,$stableHash,$terms,5),
    'agreement moved to foreign organization fails session ownership');
$update("UPDATE portal_v7_agreement_authority_lab
    SET organization_id='$org',approval_revision=6
    WHERE tenant_id='$tenant' AND agreement_id='$agreement'");
v7pgMust($allowed($bearer,$tenant,$agreement,$period,$stableHash,$terms,6),
    'explicit current approval revision passes again');

$update("UPDATE portal_auth_sessions SET status='revoked',revoked_at=now()
    WHERE id='$sessionId'");
v7pgMust(!$allowed($bearer,$tenant,$agreement,$period,$stableHash,$terms,6),
    'Portal session logout/revocation immediately invalidates preflight');
putenv('APP_ENV=production');
v7pgMust(!$allowed($bearer,$tenant,$agreement,$period,$stableHash,$terms,6),
    'refuse production even if SQL fixture reenabled');
v7pgMust(!Authority::limits()['portal_real_customer_authenticated']
    && !Authority::limits()['can_charge'],
    'successful CI database check never claims real IAM or PSP authority');

echo "PASS: Portal F66/F76/F113 real PG18 identity/MFA/revision/permission schema + independently owned agreement V7 and stable contract fingerprint; all fixtures synthetic and NO-GO.\n";
