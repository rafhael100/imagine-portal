<?php
declare(strict_types=1);

/**
 * F110 disposable PG18 proof: real encrypted PKCE state ledger under RLS.
 * Run AFTER F108 (schema/seed) + F109 (locks), in isolated GitHub Actions.
 */
use App\Domain\SeoReview\GoogleSearchConsoleOAuthPreflight;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthRlsLabBoundary;

require_once __DIR__.'/../../vendor/autoload.php';

function requireF110(bool $ok,string $reason):void {
    if(!$ok)throw new RuntimeException('SEO F110 disposable CI: '.$reason);
}
if(PHP_SAPI!=='cli' || getenv('CI')!=='true' ||
    getenv('APP_ENV')!=='testing' ||
    getenv('PORTAL_SEO_F108_PG_CI')!=='1' ||
    getenv('PORTAL_SEO_F109_PG_CI')!=='1' ||
    getenv('PORTAL_SEO_F110_PG_CI')!=='1' ||
    getenv('DB_HOST')!=='127.0.0.1' ||
    getenv('DB_DATABASE')!=='portal_gsc_f108_ci' ||
    getenv('POSTGRES_PASSWORD')!=='f108-ci-postgres-only' ||
    getenv('F108_ROLE_PASSWORD')!=='f108-ci-role-only')
    throw new RuntimeException('F110 requires disposable PostgreSQL CI');

function connectF110(string $user,string $password):PDO {
    return new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_gsc_f108_ci',
        $user,$password,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false
        ]);
}
$admin=connectF110('postgres',getenv('POSTGRES_PASSWORD'));
$admin->exec(
    'GRANT SELECT, INSERT, UPDATE ON portal_gsc_oauth_states TO portal_f108_service'
);
$r=$admin->query("SELECT relrowsecurity, relforcerowsecurity
    FROM pg_class WHERE oid='public.portal_gsc_oauth_states'::regclass")->fetch();
requireF110($r!==false && in_array($r['relrowsecurity'],[true,'t'],true)
    && in_array($r['relforcerowsecurity'],[true,'t'],true),
    'OAuth state ledger is missing FORCE RLS');

$pdo=connectF110('portal_f108_service',getenv('F108_ROLE_PASSWORD'));
$pdo2=connectF110('portal_f108_service',getenv('F108_ROLE_PASSWORD'));
$now=1800000000;
$tenantA=['tenantRef'=>'demo-tenant','actorRef'=>'portal-user-a'];
$tenantB=['tenantRef'=>'other-tenant','actorRef'=>'portal-user-a'];
$actorB=['tenantRef'=>'demo-tenant','actorRef'=>'other-actor'];
$config=[
    'clientId'=>'123456789-demo-app.apps.googleusercontent.com',
    'registeredRedirectUri'=>'https://portal.example.com/integrations/google/search-console/callback'
];
$key=random_bytes(32); // throwaway server-only key, NEVER printed or committed
$boundary=new GoogleSearchConsoleOAuthRlsLabBoundary($pdo,$key);
$otherConnection=new GoogleSearchConsoleOAuthRlsLabBoundary($pdo2,$key);
$preflight=new GoogleSearchConsoleOAuthPreflight();

function issuedF110(
    GoogleSearchConsoleOAuthPreflight $preflight,
    array $config,array $tenant,int $now
):array {
    $r=$preflight->prepare($config,$tenant,$now);
    requireF110(is_array($r),'synthetic PKCE preflight refused');
    return $r;
}
function rowF110(PDO $admin,string $state):array {
    $stmt=$admin->prepare(
        'SELECT tenant_ref,actor_ref,sealed_verifier,consumed_at
         FROM portal_gsc_oauth_states WHERE state_hash=:digest'
    );
    $stmt->execute(['digest'=>hash('sha256',$state)]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    requireF110(is_array($row),'stored PKCE state missing');
    return $row;
}

$flowA=issuedF110($preflight,$config,$tenantA,$now);
$stateA=$flowA['publicAuthorization']['state'];
$serverOnlyA=$flowA['serverOnly'];
requireF110($boundary->storePending($tenantA,$serverOnlyA,$now),
    'first encrypted state store failed');
requireF110(!$boundary->storePending($tenantA,$serverOnlyA,$now),
    'duplicate state inserted');
requireF110(!$boundary->storePending($tenantB,$serverOnlyA,$now),
    'tenant mismatch accepted before insertion');
requireF110(!$boundary->storePending($actorB,$serverOnlyA,$now),
    'actor mismatch accepted before insertion');
requireF110(!$boundary->storePending([
    ...$tenantA,'customerDisplayAuthorized'=>true
],$serverOnlyA,$now),'extra untrusted context accepted');
$row=rowF110($admin,$stateA);
requireF110($row['tenant_ref']==='demo-tenant' &&
    $row['actor_ref']==='portal-user-a',
    'stored state has incorrect scope');
requireF110(str_starts_with($row['sealed_verifier'],'v1.') &&
    !str_contains($row['sealed_verifier'],$serverOnlyA['codeVerifier']) &&
    $row['consumed_at']===null,
    'PKCE code verifier was exposed or already consumed');

// The reader role sees no rows outside a transaction with a local tenant.
requireF110((int)$pdo->query('SELECT count(*) FROM portal_gsc_oauth_states')
    ->fetchColumn()===0,'PKCE state leaked without RLS context');

// A state from A can never be claimed with B's tenant, or another actor.
$codeCallback=['state'=>$stateA,'code'=>'synthetic-code-2026'];
requireF110($otherConnection->reviewAndConsume($tenantB,$codeCallback,$now+5)
    ===null,'other tenant consumed A state');
requireF110($otherConnection->reviewAndConsume($actorB,$codeCallback,$now+5)
    ===null,'other actor consumed A state');
requireF110($otherConnection->reviewAndConsume($tenantA,[
    'state'=>$stateA,'code'=>'synthetic-code-2026',
    'scope'=>'https://www.googleapis.com/auth/webmasters'
],$now+5)===null,'extra Google scope accepted');
requireF110(rowF110($admin,$stateA)['consumed_at']===null,
    'failed callback consumed state');

$review=$boundary->reviewAndConsume($tenantA,$codeCallback,$now+5);
requireF110(is_array($review),'valid atomic one-use callback refused');
requireF110(($review['decision']??null)
    ==='CODE_CONSUMED_AWAITING_TRUSTED_TOKEN_BACKEND' &&
    ($review['stateConsumed']??null)===true,
    'unexpected review decision');
foreach([
    'oauthVerified','tenantGrantVerified','propertyAuthorizationVerified',
    'tokenExchangeAuthorized','customerDisplayAuthorized',
    'publicationAuthorized','networkPerformed'
] as $flag) requireF110(($review[$flag]??null)===false,
    'review falsely granted '.$flag);
requireF110(($review['releaseStatus']??null)
    ==='HOLD_EXTERNAL_AUTHENTICATED_BACKEND',
    'callback prematurely authorized');
$reviewText=json_encode($review,JSON_THROW_ON_ERROR);
requireF110(!str_contains($reviewText,$stateA)
    && !str_contains($reviewText,$serverOnlyA['codeVerifier'])
    && !str_contains($reviewText,'synthetic-code-2026')
    && !str_contains($reviewText,'example.com')
    && !str_contains($reviewText,'demo-tenant'),
    'secret or tenant leaked from safe callback');
requireF110($otherConnection->reviewAndConsume($tenantA,$codeCallback,$now+6)
    ===null,'state replay succeeded on different connection');
requireF110(rowF110($admin,$stateA)['consumed_at']!==null,
    'atomic state CAS did not persist');
requireF110((int)$pdo->query('SELECT count(*) FROM portal_gsc_oauth_states')
    ->fetchColumn()===0,'state context persisted after callback commit');

// Consent denial also consumes the state once. A later code must fail.
$denial=issuedF110($preflight,$config,$tenantB,$now+20);
$stateB=$denial['publicAuthorization']['state'];
requireF110($otherConnection->storePending($tenantB,$denial['serverOnly'],$now+20),
    'second tenant state store failed');
requireF110($boundary->reviewAndConsume($tenantA,[
    'state'=>$stateB,'error'=>'access_denied'
],$now+22)===null,'A denied state for B unexpectedly');
$denied=$otherConnection->reviewAndConsume($tenantB,[
    'state'=>$stateB,'error'=>'access_denied'
],$now+22);
requireF110(is_array($denied) && $denied['decision']==='CONSENT_DENIED_HOLD'
    && $denied['stateConsumed']===true,
    'consent denial not recorded as one-use');
requireF110($boundary->reviewAndConsume($tenantB,[
    'state'=>$stateB,'code'=>'synthetic-code-2026'
],$now+25)===null,'denied OAuth state reused');

// An expired state cannot be consumed or transformed into a grant.
$expired=issuedF110($preflight,$config,$tenantA,$now+30);
$expiredState=$expired['publicAuthorization']['state'];
requireF110($boundary->storePending($tenantA,$expired['serverOnly'],$now+30),
    'expired fixture could not be staged');
requireF110($otherConnection->reviewAndConsume($tenantA,[
    'state'=>$expiredState,'code'=>'synthetic-code-2026'
],$now+631)===null,'expired state accepted');
requireF110(rowF110($admin,$expiredState)['consumed_at']===null,
    'expired state marked consumed');
requireF110($boundary->reviewAndConsume($tenantA,[
    'state'=>'invalid','code'=>'synthetic-code-2026'
],$now+40)===null,'invalid state accepted');

// No ambient caller transaction may be reused to alter RLS context.
$pdo->beginTransaction();
try {
    requireF110(!$boundary->storePending($tenantA,$expired['serverOnly'],$now+30),
        'reentrant transaction accepted');
}finally{$pdo->rollBack();}

// Guard cannot be enabled by an arbitrary non-test environment.
putenv('APP_ENV=production');
requireF110(!$boundary->storePending($tenantA,$expired['serverOnly'],$now+30),
    'production invocation unexpectedly enabled');
requireF110($boundary->reviewAndConsume($tenantA,$codeCallback,$now+40)
    ===null,'production callback invocation enabled');
putenv('APP_ENV=testing');

requireF110(!GoogleSearchConsoleOAuthRlsLabBoundary::authorizesGoogleHttp()
    && !GoogleSearchConsoleOAuthRlsLabBoundary::authorizesTokenExchange()
    && !GoogleSearchConsoleOAuthRlsLabBoundary::authorizesCustomerDisplay()
    && !GoogleSearchConsoleOAuthRlsLabBoundary::authenticatesPortalSession(),
    'F110 lab falsely claims production permission');

echo "F110 PG18: encrypted OAuth PKCE state under tenant RLS, one-use callback,\n".
     "cross-tenant/actor denial, consent denial, expiry and replay PASS;\n".
     "no Google network calls, no secrets logged, no production authorization\n";
