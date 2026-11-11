<?php

declare(strict_types=1);

// F133 CI-only parity across two real repositories, not permission to send.
// Both schema files are local to the GitHub runner after immutable checkouts.
require_once dirname(__DIR__,2).'/app/Domain/SiteIntake/SiteEngineSchemaDriftGate.php';
require_once dirname(__DIR__,2).'/app/Domain/SiteIntake/SiteEngineContractVocabulary.php';

use App\Domain\SiteIntake\SiteEngineSchemaDriftGate;
use App\Domain\SiteIntake\SiteEngineContractVocabulary;

if (getenv('GITHUB_ACTIONS')!=='true'
    || getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    || getenv('PORTAL_F133_SITES_CROSS_REPO_LAB')!=='1') {
    fwrite(STDERR,"F133 refuses non-disposable GitHub runner.\n");
    exit(2);
}
$root=dirname(__DIR__,4);
$upstream=$root.'/sites-source/contracts/portal-sites/v1/create-project.schema.json';
$local=$root.'/apps/portal/labs/sites-intake-f55/create-project.schema.snapshot.json';
$checks=0;
function check133(bool $condition,string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException('F133 FAIL: '.$message);
    }
}
check133(is_file($upstream)&&is_file($local),'both repositories checked out');
$gate=new SiteEngineSchemaDriftGate();
$result=$gate->compare($upstream,$local);
check133($result===['matched'=>true,'reason'=>'pinned_schema_match_only'],
    'real Sites schema matches the pinned Portal snapshot exactly');
$contents=file_get_contents($upstream);
check133(is_string($contents),'schema bytes available');
check133(sha1('blob '.strlen($contents)."\0".$contents)
    ===SiteEngineSchemaDriftGate::PINNED_GIT_BLOB_SHA1,
    'exact reviewed Git blob SHA1 from Sites contract');
$schema=json_decode($contents,true,32,JSON_THROW_ON_ERROR);
check133(is_array($schema)
    && ($schema['additionalProperties']??null)===false
    && ($schema['required']??[])===[
        'schemaVersion','operationId','tenantRef','orderRef',
        'contractRef','contractRevision','productCode','idempotencyKey',
    ],'candidate schema remains closed with eight expected fields');
check133(($schema['properties']['productCode']['enum']??null)
    ===['onepage','landing_page'],'only Onepage/Landing Page products');
check133(!SiteEngineSchemaDriftGate::authorizesIntegration()
    && !SiteEngineSchemaDriftGate::verifiesRemoteOrigin(),
    'schema parity never authenticates or authorizes real integration');

// F133 also checks the latest Sites-to-Portal review candidate contract
// against the Portal's existing v2 vocabulary, without promoting any sender.
$contractDir=$root.'/sites-source/contracts/portal-sites/v1/';
$epochFile=$contractDir.'review-handoff-epoch-envelope.schema.json';
$reviewFile=$contractDir.'review-handoff-envelope.schema.json';
$authFile=$contractDir.'project-authorization-snapshot.candidate.schema.json';
check133(is_file($epochFile)&&is_file($reviewFile)&&is_file($authFile),
    'real Sites review and authority schemas exist');
$epochRaw=file_get_contents($epochFile);
$reviewRaw=file_get_contents($reviewFile);
$authRaw=file_get_contents($authFile);
check133(is_string($epochRaw)&&is_string($reviewRaw)&&is_string($authRaw),
    'real Sites secondary contract bytes readable');
check133(sha1('blob '.strlen($epochRaw)."\0".$epochRaw)
    ==='d985f8e406b49741480678ecb7f639ec0b38dc30',
    'v2 review epoch schema pinned to exact upstream Git blob');
check133(sha1('blob '.strlen($reviewRaw)."\0".$reviewRaw)
    ==='bc59c5a56838adbc3779afb88243f702b08216ee',
    'v1 review candidate schema pinned to exact upstream Git blob');
check133(sha1('blob '.strlen($authRaw)."\0".$authRaw)
    ==='d1dc7e1c77d9c813bc3fd2031e4b40ee28e97da0',
    'project authority snapshot schema pinned to upstream blob');
$epoch=json_decode($epochRaw,true,32,JSON_THROW_ON_ERROR);
$review=json_decode($reviewRaw,true,32,JSON_THROW_ON_ERROR);
$auth=json_decode($authRaw,true,32,JSON_THROW_ON_ERROR);
check133(($epoch['additionalProperties']??null)===false
    && ($epoch['required']??null)===SiteEngineContractVocabulary::reviewProposalV2Keys()
    && array_keys($epoch['properties']??[])===SiteEngineContractVocabulary::reviewProposalV2Keys(),
    'current Portal v2 review vocabulary matches actual Sites schema');
check133(($epoch['properties']['schemaVersion']['const']??null)
    ===SiteEngineContractVocabulary::SITES_REVIEW_PROPOSAL_V2
    && ($epoch['properties']['issuer']['const']??null)==='imagine-sites'
    && ($epoch['properties']['audience']['const']??null)==='imagine-portal',
    'v2 issuer/audience and exact version remain fixed');
check133(($review['properties']['candidate']['properties']['officialClientAcceptance']['const']??null)===false
    && ($review['properties']['candidate']['properties']['publishable']['const']??null)===false
    && ($review['properties']['candidate']['properties']['portalAuthorityRequired']['const']??null)===true,
    'review candidate cannot grant acceptance or publication');
check133(($auth['additionalProperties']??null)===false
    && ($auth['properties']['status']['enum']??null)===['eligible','suspended','revoked']
    && ($auth['properties']['boundCommand']['$ref']??null)==='./create-project.schema.json',
    'project authority candidate cannot silently bypass bound command');
check133(!SiteEngineContractVocabulary::mayInvokeOperationalSitesEngine()
    && !SiteEngineContractVocabulary::mayAcceptCustomerApproval()
    && !SiteEngineContractVocabulary::mayRequestPublication(),
    'matching schemas never authorize Sites operation or publication');

// A different but valid JSON schema MUST fail, including same basic keys.
$tmp=sys_get_temp_dir().'/portal-f133-'.bin2hex(random_bytes(10));
if (!mkdir($tmp,0700)) {
    throw new RuntimeException('F133 isolated temporary test directory unavailable');
}
try {
    $altered=str_replace('"landing_page"','"landing_page_replaced"',$contents);
    check133($altered!==$contents,'negative test truly alters an upstream enum');
    $alteredPath=$tmp.'/changed-upstream.json';
    file_put_contents($alteredPath,$altered,LOCK_EX);
    check133($gate->compare($alteredPath,$local)
        ===['matched'=>false,'reason'=>'upstream_schema_drift'],
        'semantic upstream drift fails closed');

    $tamperedPath=$tmp.'/tampered-portal-snapshot.json';
    file_put_contents($tamperedPath,$altered,LOCK_EX);
    check133($gate->compare($upstream,$tamperedPath)
        ===['matched'=>false,'reason'=>'portal_snapshot_not_pinned'],
        'altered Portal local snapshot fails pin validation');

    check133($gate->compare($tmp.'/missing.json',$local)
        ===['matched'=>false,'reason'=>'file_unavailable_or_oversize'],
        'missing upstream contract cannot pass');
} finally {
    foreach (glob($tmp.'/*.json')?:[] as $file) {
        unlink($file);
    }
    rmdir($tmp);
}
echo "F133 PASS: {$checks} real two-repository Sites CreateProject schema parity assertions; NO dispatch.\n";
