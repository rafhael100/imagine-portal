<?php
declare(strict_types=1);

/**
 * F126: actual Engine F121 audit/signature -> Portal F122 -> PG18 F123
 * one-use evidence. Must follow F123 and F124 in disposable CI database.
 */
require_once __DIR__.'/../../vendor/autoload.php';

use App\Domain\SeoReview\PortalSeoPgSignedEvidenceReplayLab as Replay;

function check126(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException('F126 PG18 signed source: '.$why);
}
if(PHP_SAPI!=='cli' ||
   getenv('CI')!=='true' ||
   getenv('APP_ENV')!=='testing' ||
   getenv('PORTAL_SEO_F118_LAB')!=='1' ||
   getenv('PORTAL_SEO_F122_LAB')!=='1' ||
   getenv('PORTAL_SEO_F123_PG_CI')!=='1' ||
   getenv('PORTAL_SEO_F126_PG_CI')!=='1' ||
   getenv('DB_CONNECTION')!=='pgsql' ||
   getenv('DB_HOST')!=='127.0.0.1' ||
   getenv('DB_DATABASE')!=='portal_gsc_f108_ci' ||
   getenv('F123_ROLE_PASSWORD')!=='f123-ci-role-only' ||
   count($argv)!==3)
    throw new RuntimeException('F126 requires disposable signed PG18 CI');

function db126(string $user,string $password):PDO {
    return new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=portal_gsc_f108_ci',
        $user,$password,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false
        ]
    );
}
function count126(PDO $db):int {
    return (int)$db->query(
        'SELECT COUNT(*) FROM portal_seo_build_evidence_nonces'
    )->fetchColumn();
}
$envBytes=file_get_contents($argv[1]);
$pubBytes=file_get_contents($argv[2]);
check126(is_string($envBytes)&&strlen($envBytes)>100&&strlen($envBytes)<160000,
    'bounded source-signed evidence');
check126(is_string($pubBytes)&&strlen($pubBytes)<=100,'bounded public key');
$envelope=json_decode($envBytes,true,64,JSON_THROW_ON_ERROR);
$trustedPublic=base64_decode(trim($pubBytes),true);
check126(is_array($envelope)&&is_string($trustedPublic)&&
    strlen($trustedPublic)===32,'invalid signing evidence');

$admin=db126('postgres',getenv('POSTGRES_PASSWORD'));
$service=db126('portal_f123_service',getenv('F123_ROLE_PASSWORD'));
check126(count126($admin)===5,'F123/F124 prerequisite seed count');
check126(count126($service)===0,'tenant context leaked from prior transaction');

$context=['tenantRef'=>'demo-tenant','actorRef'=>'portal-f126-review'];
$lab=new Replay($service);
$sha='3c75b8e7b64f4173a17f4daab45cea1b8bdc5554';
$accepted=$lab->consume(
    $envelope,$trustedPublic,$context,'github-actions-f122',$sha,time()
);
check126(is_array($accepted),'actual Engine F121 source signature rejected');
check126($accepted['nonceConsumedAtomicallyInPgLab']===true,
    'source signature not consumed exactly once');
check126($accepted['sanitizedBuildReview']['reviewStatus']===
    'HUMAN_REVIEW_REQUIRED','source clean report gained publication status');
foreach(['realServiceSignerVerified','realPortalTenantVerified',
    'realReplayLedgerDeployed','customerDisplayAuthorized',
    'publicationAuthorized'] as $flag)
    check126($accepted[$flag]===false,
        'false product authorization from source evidence: '.$flag);
check126(count126($admin)===6,'first source-owned signature not persisted');
check126($lab->consume(
    $envelope,$trustedPublic,$context,'github-actions-f122',$sha,time()
)===null,'real Engine signature replay accepted twice');
check126(count126($admin)===6,'replay inserted another nonce');

$otherContext=['tenantRef'=>'other-tenant','actorRef'=>'portal-f126-other'];
check126($lab->consume(
    $envelope,$trustedPublic,$otherContext,'github-actions-f122',$sha,time()
)===null,'Engine signature crossed tenant boundary');
check126($lab->consume(
    $envelope,$trustedPublic,$context,'wrong-run',$sha,time()
)===null,'Engine signature crossed run boundary');
check126($lab->consume(
    $envelope,$trustedPublic,$context,'github-actions-f122',
    str_repeat('0',40),time()
)===null,'Engine signature accepted wrong immutable commit');
check126(count126($service)===0,'session-scoped tenant leaked');
check126(count126($admin)===6,'negative cases mutated ledger');

putenv('APP_ENV=production');
check126($lab->consume(
    $envelope,$trustedPublic,$context,'github-actions-f122',$sha,time()
)===null,'production mode unexpectedly used CI-signed evidence');
check126(count126($admin)===6,'production test mutated ledger');
echo "F126 PG18 E2E: real Engine audits and signs; Portal verifies, ".
    "consumes once, rejects replay/tenant/run/commit; no release\n";
