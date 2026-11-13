<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'||$argc!==1
    ||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_F372_CI')!=='DISPOSABLE_ONLY')exit(64);

require '/opt/portal-recovery/seed-ci.php';

use App\Infrastructure\Recovery\IndependentWitnessCandidateVerifier as Verifier;
use App\Infrastructure\Recovery\OnlineWitnessFenceCandidate as Fence;
use App\Infrastructure\Recovery\PortalOnlineWitnessSource as Source;
use App\Infrastructure\Recovery\FencedPortalSessionReadCandidate as SessionProbe;
use App\Infrastructure\Recovery\FencedPortalGrantReadCandidate as GrantProbe;
use App\Infrastructure\Identity\PostgresPortalPermissionPreflight;

final class SyntheticF372WitnessSource implements Source
{
    public bool $offline=false;
    public function __construct(
        public array $snapshot,
        public array $receipt,
    ) {}
    public function currentPortalEpoch():array
    {
        if($this->offline)throw new RuntimeException('synthetic independent witness is offline');
        return $this->snapshot;
    }
    public function latestSignedWitness():array
    {
        if($this->offline)throw new RuntimeException('synthetic source unavailable');
        return $this->receipt;
    }
}

$reference=str_repeat('a',64);
$digest=hash('sha256',$reference);
$ins=$db->prepare(
    "INSERT INTO public.portal_auth_sessions(
        id,session_sha256,identity_id,organization_id,status,session_purpose,
        identity_revision,membership_revision,organization_revision,
        issued_at,expires_at,mfa_verified_at,created_at,updated_at
    ) VALUES (gen_random_uuid(),CAST(? AS char(64)),CAST(? AS uuid),CAST(? AS uuid),
        'active','business',?,?,?,
        clock_timestamp()-interval '1 minute',
        clock_timestamp()+interval '30 minutes',
        clock_timestamp()-interval '30 seconds',
        clock_timestamp(),clock_timestamp())"
);
$ins->execute([$digest,$iid,$oid,$ir,$mr,$or]);
if(!$auth->isActiveBusinessSession($reference)){
    throw new RuntimeException('Real SQL authentication adapter must see synthetic active session');
}

$epoch=[
    'organization'=>$oid,
    'instance'=>'f372_ci',
    'generation'=>1,
    'session_revision'=>(int)$ir,
    'membership_revision'=>(int)$mr,
    'witnessed_sequence'=>1,
];
$now=time();
$witness=[
    'schema'=>Verifier::SCHEMA,
    'organization'=>$oid,
    'instance'=>'f372_ci',
    'generation_floor'=>1,
    'session_floor'=>(int)$ir,
    'membership_floor'=>(int)$mr,
    'sequence'=>1,
    'issued_at'=>$now,
    'expires_at'=>$now+60,
    'signature'=>'',
];
$keypair=sodium_crypto_sign_keypair();
$secret=sodium_crypto_sign_secretkey($keypair);
$public=bin2hex(sodium_crypto_sign_publickey($keypair));
$witness['signature']=bin2hex(sodium_crypto_sign_detached(
    Verifier::canonical($witness),$secret
));
$source=new SyntheticF372WitnessSource($epoch,$witness);
$fence=new Fence($source,new Verifier(),$public,$oid,'f372_ci');
$session=new SessionProbe($auth,$fence);
$grant=new GrantProbe(new PostgresPortalPermissionPreflight($runtime,'portal_identity_dev'),$fence);

$assess=static function(array $r,string $expected):void{
    if(($r['status']??null)!==$expected)throw new RuntimeException('F372 unexpected status');
    foreach(['canAuthorizeHttp','canAuthorizeBusiness','canIssueSession',
      'canReissueCredential','canRecoverFromRestore','canDeploy'] as $field) {
        if(($r[$field]??null)!==false)throw new RuntimeException('F372 tried to authorize '.$field);
    }
};
$assess($session->inspectSession($reference),'LAB_READ_OBSERVED_STILL_NO_SERVING');
$assess($session->inspectSession(str_repeat('b',64)),'LAB_READ_DENIED_STILL_NO_SERVING');
$assess($session->inspectSession('malformed'),'INVALID_CANDIDATE_SESSION_REFERENCE');
$assess($grant->inspectCandidateGrant($reference,$oid,'services.view'),
    'LAB_READ_DENIED_STILL_NO_SERVING');
$assess($grant->inspectCandidateGrant($reference,$oid,'services.manage'),
    'LAB_READ_DENIED_STILL_NO_SERVING');
$assess($grant->inspectCandidateGrant($reference,'11111111-1111-4111-8111-111111111111','services.view'),
    'CANDIDATE_SCOPE_DENIED');
$assess($grant->inspectCandidateGrant($reference,$oid,'unrecognized.permission'),
    'LAB_READ_DENIED_STILL_NO_SERVING');

$source->snapshot['witnessed_sequence']=2;
$assess($session->inspectSession($reference),'WITNESS_PRECHECK_DENIED');
$assess($grant->inspectCandidateGrant($reference,$oid,'services.view'),'WITNESS_PRECHECK_DENIED');
$source->snapshot['witnessed_sequence']=1;
$source->offline=true;
$assess($session->inspectSession($reference),'WITNESS_PRECHECK_DENIED');
$source->offline=false;
$source->receipt['signature']=str_repeat('0',128);
$assess($session->inspectSession($reference),'WITNESS_PRECHECK_DENIED');
$source->receipt=$witness;

if(!$auth->revoke($reference))throw new RuntimeException('Real synthetic session revocation failed');
if($auth->isActiveBusinessSession($reference))throw new RuntimeException('Revoked session active');
$assess($session->inspectSession($reference),'LAB_READ_DENIED_STILL_NO_SERVING');

sodium_memzero($secret);
echo "PASS F372: real Portal PostgreSQL session and grant adapters behind signed witness pre/post; no HTTP/serving/grants.\n";
