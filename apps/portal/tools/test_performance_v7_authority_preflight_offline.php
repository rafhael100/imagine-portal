<?php
declare(strict_types=1);

// Existing Portal authority contracts and REAL PHP V7 issuer, instantiated
// only with synthetic memory-based resolvers. NO routes, IAM, DB, PSP or Ads.
$root=__DIR__.'/../app/Domain';
$requires=[
    '/Identity/PortalUserId.php',
    '/Organizations/OrganizationId.php',
    '/Organizations/OrganizationRole.php',
    '/Organizations/MembershipStatus.php',
    '/Authorization/Permission.php',
    '/Organizations/OrganizationMembership.php',
    '/Authorization/AuthorizationContext.php',
    '/Authorization/AuthorizationDecision.php',
    '/Authorization/OrganizationAuthorizationService.php',
    '/PortalAccess/Authority/PortalSessionPurpose.php',
    '/PortalAccess/Authority/PortalSessionSnapshot.php',
    '/PortalAccess/Authority/PortalMembershipAuthority.php',
    '/PortalAccess/Authority/PortalSessionAuthority.php',
    '/PortalAccess/Authority/PortalResourceAuthority.php',
    '/PortalAccess/Authority/PortalOrganizationAuthority.php',
    '/PortalAccess/Authority/PortalOrganizationSnapshot.php',
    '/PortalAccess/Authority/PortalBoundaryVerdict.php',
    '/PortalAccess/Authority/PortalAuthorityBoundary.php',
    '/PortalAccess/Authority/DenyingPortalAuthorities.php',
    '/Performance/ActivationPostpaidPreview.php',
    '/PaymentRouting/GatewayRoutingPreview.php',
    '/PaymentRouting/GatewayPolicyReview.php',
    '/PaymentRouting/PortalGatewayReviewExporter.php',
    '/Performance/ActivationGatewayReviewLab.php',
    '/Performance/PerformanceChannelReviewEnvelope.php',
    '/Performance/PortalPerformanceV7AgreementPlanLabIssuer.php',
    '/Performance/PortalPerformanceV7AuthorityPreflightLab.php',
];
foreach($requires as $path)require_once $root.$path;

use App\Domain\Authorization\Permission;
use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\OrganizationId;
use App\Domain\Organizations\OrganizationMembership;
use App\Domain\Organizations\OrganizationRole;
use App\Domain\Organizations\MembershipStatus;
use App\Domain\PortalAccess\Authority\PortalSessionSnapshot;
use App\Domain\PortalAccess\Authority\PortalSessionPurpose;
use App\Domain\PortalAccess\Authority\PortalSessionAuthority;
use App\Domain\PortalAccess\Authority\PortalMembershipAuthority;
use App\Domain\PortalAccess\Authority\PortalResourceAuthority;
use App\Domain\PortalAccess\Authority\PortalOrganizationAuthority;
use App\Domain\PortalAccess\Authority\PortalOrganizationSnapshot;
use App\Domain\PortalAccess\Authority\PortalAuthorityBoundary;
use App\Domain\PortalAccess\Authority\DenyingPortalAuthorities;
use App\Domain\Performance\ActivationGatewayReviewLab as Gateway;
use App\Domain\Performance\PerformanceChannelReviewEnvelope as ReviewIssuer;
use App\Domain\Performance\PortalPerformanceV7AuthorityPreflightLab as Preflight;

function aMust(bool $ok,string $description):void {
    if(!$ok)throw new RuntimeException('Portal V7 authority CI: '.$description);
}
function aDeny(callable $fn,string $description):void {
    try {$fn();}catch(InvalidArgumentException|RuntimeException){return;}
    throw new RuntimeException('Portal V7 authority CI incorrectly allowed: '.$description);
}
if(getenv('APP_ENV')!=='testing' ||
   getenv('PORTAL_PERF_V7_PLAN_AGREEMENT_LAB')!=='1' ||
   getenv('PORTAL_PERF_V7_AUTHORITY_PREFLIGHT_LAB')!=='1') {
    throw new RuntimeException('CI-only auth environment missing.');
}
$nowUtc='2026-11-14T21:05:00Z';
$issuedUtc='2026-11-14T21:00:00Z';
$now=(new DateTimeImmutable($nowUtc,new DateTimeZone('UTC')))->getTimestamp();
$org=new OrganizationId('org-v7-review-demo');
$actor=new PortalUserId('portal-v7-actor-001');
$tenant='tenant_v7auth_12345678';
$agreement='agreement_v7auth_12345678';
$scope=[
    'tenant_id'=>$tenant,'agreement_id'=>$agreement,
    'period'=>'2026-11','organization_ref'=>$org->value,
    'order_ref'=>'order-v7-review-demo','policy_revision'=>3,
];
$permissions=[Permission::ManageServices];
$session=static fn(
    bool $active=true,bool $mfa=true,
    int $accountRev=4,int $sessionAccountRev=4,
    ?OrganizationId $sessionOrg=null,
    int $membershipRev=2,int $orgRev=3,
    PortalSessionPurpose $purpose=PortalSessionPurpose::Business,
    ?int $expiry=null
):PortalSessionSnapshot=>new PortalSessionSnapshot(
    $actor,$active,$mfa,$now-3600,$expiry??$now+3600,
    $accountRev,$sessionAccountRev,$sessionOrg??$org,
    $membershipRev,$orgRev,$purpose
);
$membership=static fn(
    MembershipStatus $status=MembershipStatus::Active,
    ?array $grants=null,int $revision=2,
    ?OrganizationId $owner=null
):OrganizationMembership=>new OrganizationMembership(
    $actor,$owner??$org,OrganizationRole::Member,$status,
    $grants??[Permission::ManageServices],$revision
);
$makeBoundary=static function(
    ?PortalSessionSnapshot $s,
    ?OrganizationMembership $m,
    ?OrganizationId $resourceOwner,
    ?PortalOrganizationSnapshot $organizationState=null
)use($org,$agreement):PortalAuthorityBoundary {
    $sessions=new class($s) implements PortalSessionAuthority {
        public function __construct(private ?PortalSessionSnapshot $s){}
        public function find(string $ref):?PortalSessionSnapshot {
            return $ref==='portal-business-lab-session'?$this->s:null;
        }
    };
    $memberships=new class($m) implements PortalMembershipAuthority {
        public function __construct(private ?OrganizationMembership $m){}
        public function findFor(PortalUserId $actor,OrganizationId $selected):?OrganizationMembership {
            return $this->m;
        }
    };
    $resources=new class($resourceOwner,$agreement) implements PortalResourceAuthority {
        public function __construct(private ?OrganizationId $owner,private string $agreement){}
        public function ownerOf(OrganizationId $selected,string $ref):?OrganizationId {
            return $ref===$this->agreement?$this->owner:null;
        }
    };
    $organizations=new class($organizationState??new PortalOrganizationSnapshot($org,true,3))
        implements PortalOrganizationAuthority {
        public function __construct(private PortalOrganizationSnapshot $state){}
        public function lookup(OrganizationId $selected):?PortalOrganizationSnapshot {
            return $this->state;
        }
    };
    return new PortalAuthorityBoundary($sessions,$memberships,$resources,$organizations);
};
$eligible=['meta_ads'=>true,'google_ads'=>true,'chatgpt_ads'=>false];
$psp=[
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
$activationKey=str_repeat('X',48);
$planKey=str_repeat('Y',48);
$review=Gateway::preview(['meta_ads','google_ads'],$eligible,$psp,
    'pix',$org->value,$scope['order_ref'],3,$activationKey);
$activation=ReviewIssuer::issue($review,$eligible,
    $org->value,$scope['order_ref'],3,$issuedUtc,$activationKey);
$plan=['google_ads'=>100000,'meta_ads'=>70000];
$run=static function(
    PortalAuthorityBoundary $bound,
    ?string $sid='portal-business-lab-session',
    ?OrganizationId $selected=null,
    ?string $tenantExpected=null,
    ?string $agreementExpected=null,
    ?array $overScope=null,
    ?array $activationEnvelope=null
)use($org,$tenant,$agreement,$activation,$review,$eligible,$scope,$plan,
    $now,$nowUtc,$activationKey,$planKey):array {
    return Preflight::review(
        $bound,$sid,$selected??$org,
        $tenantExpected??$tenant,$agreementExpected??$agreement,
        $activationEnvelope??$activation,$review,$eligible,
        $overScope??$scope,$plan,
        $nowUtc,'k_v7_auth_lab_001','nonce_v7_auth_preflight_12345678',
        $now-1,$now+90,$activationKey,$planKey
    );
};
$good=$makeBoundary($session(),$membership(),$org);
$verified=$run($good);
aMust($verified['synthetic_business_session_and_membership_checked']===true &&
    ($verified['signed_plan_review']['payload']['activation_cents']??null)===98000 &&
    ($verified['signed_plan_review']['payload']['estimated_management_fee_cents']??null)===37000,
    'valid synthetic business session + service management permits ONLY signed plan draft');
foreach(['real_iam_authenticated','real_customer_acceptance_proven',
    'customer_consent_verified','finance_approved','billable',
    'can_charge','can_activate','ads_write_enabled','crm_write_enabled'] as $flag){
    aMust(($verified[$flag]??null)===false,
        'simulation never authorizes real operation '.$flag);
}
aMust(!$good->inspect('portal-business-lab-session',$org,$agreement,
    Permission::ManageServices->value,$now)->canAuthorizeHttp(),
    'positive synthetic verdict is never HTTP authorization');
aDeny(fn()=> $run($good,null,new OrganizationId('org-foreign-demo')),
    'tenant selected from browser cannot override session organization');
aDeny(fn()=> $run($good,'crm-bearer-not-a-portal-session'),
    'CRM identity is not an authorized Portal business session');
$badScope=$scope;$badScope['tenant_id']='tenant_attacker_123456';
aDeny(fn()=> $run($good,null,null,null,null,$badScope),
    'signed plan tenant cannot be substituted');
$badScope=$scope;$badScope['agreement_id']='agreement_attacker_123456';
aDeny(fn()=> $run($good,null,null,null,null,$badScope),
    'agreement cannot be substituted');
$badScope=$scope;$badScope['organization_ref']='org-foreign-demo';
aDeny(fn()=> $run($good,null,null,null,null,$badScope),
    'organization cannot be replaced');
aDeny(fn()=> $run($good,null,null,'tenant_attacker_123456'),
    'independently expected tenant mismatch');
aDeny(fn()=> $run($good,null,null,null,'agreement_attacker_123456'),
    'independent agreement mismatch');

$failures=[
    ['revoked session',$session(active:false),$membership(),$org,null],
    ['MFA absent',$session(mfa:false),$membership(),$org,null],
    ['expired session',$session(expiry:$now-1),$membership(),$org,null],
    ['stale account revision',$session(sessionAccountRev:3),$membership(),$org,null],
    ['wrong session organization',$session(sessionOrg:new OrganizationId('org-foreign-demo')),$membership(),$org,null],
    ['invitation-only session',$session(purpose:PortalSessionPurpose::InvitationOnly),$membership(),$org,null],
    ['revoked membership',$session(),$membership(status:MembershipStatus::Revoked),$org,null],
    ['stale membership revision',$session(),$membership(revision:3),$org,null],
    ['wrong resource owner',$session(),$membership(),new OrganizationId('org-foreign-demo'),null],
    ['missing permission',$session(),$membership(grants:[Permission::ViewServices]),$org,null],
    ['suspended organization',$session(),$membership(),$org,new PortalOrganizationSnapshot($org,false,3)],
    ['organization revision changed',$session(),$membership(),$org,new PortalOrganizationSnapshot($org,true,4)],
];
foreach($failures as [$why,$s,$m,$owner,$state]){
    aDeny(fn()=> $run($makeBoundary($s,$m,$owner,$state)),$why);
}
$providers=new DenyingPortalAuthorities();
$disabledBoundary=new PortalAuthorityBoundary($providers,$providers,$providers,$providers);
aDeny(fn()=> $run($disabledBoundary),'default deny without configured authority');
$forged=$activation;$forged['mac']=str_repeat('0',64);
aDeny(fn()=> $run($good,null,null,null,null,null,$forged),
    'synthetic authority cannot override invalid F419 HMAC');

putenv('PORTAL_PERF_V7_AUTHORITY_PREFLIGHT_LAB=0');
aDeny(fn()=> $run($good),'preflight flag disabled');
putenv('PORTAL_PERF_V7_AUTHORITY_PREFLIGHT_LAB=1');
putenv('APP_ENV=production');
aDeny(fn()=> $run($good),'production disabled');
echo "OK: Portal V7 signed review requires synthetic org-bound business MFA+services.manage candidate, blocks stale/revoked identities; NEVER real IAM or charge\n";
