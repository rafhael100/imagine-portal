<?php

declare(strict_types=1);

// F133 CI-only parity across two real repositories, not permission to send.
// Both schema files are local to the GitHub runner after immutable checkouts.
require_once dirname(__DIR__,2).'/app/Domain/SiteIntake/SiteEngineSchemaDriftGate.php';

use App\Domain\SiteIntake\SiteEngineSchemaDriftGate;

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
