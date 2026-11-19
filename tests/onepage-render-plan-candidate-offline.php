<?php

declare(strict_types=1);

// Purely synthetic composition. Does not call the Sites renderer here;
// the paired compatibility suite supplies independent, pinned HTML.
require __DIR__.'/../apps/portal/app/Domain/SitesIntegration/SiteContractSnapshot.php';
require __DIR__.'/../apps/portal/app/Domain/SitesIntegration/OnepageBriefSnapshotCandidateEncoder.php';
require __DIR__.'/../apps/portal/app/Domain/SitesIntegration/OnepageRenderPlanCandidateEncoder.php';

use App\Domain\SitesIntegration\SiteContractSnapshot;
use App\Domain\SitesIntegration\OnepageBriefSnapshotCandidateEncoder as Snapshot;
use App\Domain\SitesIntegration\OnepageRenderPlanCandidateEncoder as Plan;

$checks = 0;
$ok = static function(bool $accept,string $message) use (&$checks): void {
    if (!$accept) throw new RuntimeException('Candidate plan offline failure: '.$message);
    ++$checks;
};
$deny = static function(callable $run,string $message) use ($ok): void {
    try { $run(); } catch (RuntimeException $e) {
        $ok($e->getMessage()==='ONEPAGE_RENDER_PLAN_CANDIDATE_DENIED' &&
            $e->getPrevious()===null,$message);
        return;
    }
    $ok(false,$message.' was accepted');
};
$json = static fn (array $value): string => json_encode(
    $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
)."\n";
$contract = new SiteContractSnapshot('synthetic-org','synthetic-order',
    'synthetic-contract',1,'onepage');
$selection = [
    'siteRef'=>'11111111-1111-4111-8111-111111111111',
    'snapshotRef'=>'22222222-2222-4222-8222-222222222222',
    'operationId'=>'synthetic-snapshot-operation',
    'idempotencyKey'=>'synthetic-snapshot-key',
    'sourceVersion'=>12,
    'expectedBriefRevision'=>0,
    'payloadRef'=>'synthetic-rich-body',
];
$source = $json([
    'company_name'=>'Loja fictícia', 'company_intro'=>'Apenas um experimento.',
    'services'=>'Serviços fictícios', 'current_site'=>'','contact_site'=>'',
    'domain'=>'', 'email'=>'contato@example.invalid','phone'=>'','address'=>'',
    'whatsapp'=>'','notes'=>'Sem dados reais.', 'ai_support'=>false,
    'logo_unavailable'=>true, 'manual_unavailable'=>true,
]);
$pair = Snapshot::build($contract,$selection,$source);
$editorial = [
    'productCode'=>'onepage', 'brand'=>'Loja fictícia',
    'headline'=>'Conheça a demonstração',
    'description'=>"Texto ilustrativo com várias\nlinhas.",
    'callToAction'=>'Saiba mais',
    'benefits'=>['Apenas conteúdo sintético','Sem coleta de dados'],
];
$editorialBytes = $json($editorial);
$renderSelection = [
    'operationId'=>'synthetic-render-operation',
    'idempotencyKey'=>'synthetic-render-key',
    'releaseRef'=>'synthetic-release-01',
    'renderInputRef'=>'synthetic-editorial-01',
];
$expected = ['sha256'=>str_repeat('a',64),'sizeBytes'=>5223];
$plan = Plan::build($pair,$editorialBytes,$renderSelection,$expected);
$ok($plan === Plan::build($pair,$editorialBytes,$renderSelection,$expected),
    'same source/edit selection emits stable plan bytes and hashes');
$ok($plan['editorialBytes'] === $editorialBytes &&
    $plan['editorialSha256'] === hash('sha256',$editorialBytes),
    'editorial bytes are not automatically rewritten or inferred from rich content');
$ok($plan['planSha256'] === hash('sha256',$plan['planBytes']) &&
    str_ends_with($plan['planBytes'],"\n"),
    'plan digest binds all original generated plan bytes including final LF');
foreach (['authorityVerified','clientAccepted','canPublish','canAcceptRealOrders'] as $key) {
    $ok($plan[$key]===false,$key.' remains false');
}
$object = json_decode($plan['planBytes'],true,8,JSON_THROW_ON_ERROR);
$ok(count($object)===9 &&
    $object['schemaVersion']==='sites.brief-render-plan.candidate.v0.1',
    'nine-field Sites plan without unknown keys');
$ok($object['operationId']===$renderSelection['operationId'] &&
    $object['idempotencyKey']===$renderSelection['idempotencyKey'] &&
    $object['releaseRef']===$renderSelection['releaseRef'],
    'independent render operation and release identifiers');
$ok($object['snapshot']===[
    'snapshotRef'=>$selection['snapshotRef'],
    'briefRevision'=>1,
    'manifestSha256'=>$pair['manifestSha256'],
], 'exact brief snapshot selection and manifest hash');
$ok($object['renderInput']===[
    'payloadRef'=>$renderSelection['renderInputRef'],
    'mediaType'=>'application/json',
    'sizeBytes'=>strlen($editorialBytes),
    'sha256'=>hash('sha256',$editorialBytes),
], 'separately selected editorial descriptor');
$ok($object['expectedArtifact']===$expected,
    'expected artifact is only declared; runtime renderer must verify it');

$differentBytes = substr($editorialBytes,0,-1)."\r\n";
$new = Plan::build($pair,$differentBytes,$renderSelection,$expected);
$ok($new['editorialSha256']!==$plan['editorialSha256'] &&
    $new['planSha256']!==$plan['planSha256'],
    'same editorial JSON with different line ending changes exact content commitments');

foreach ([
    'brand blank Unicode'=>['brand'=>"\u{00A0}\u{2003}"],
    'headline empty'=>['headline'=>''],
    'CTA only whitespace'=>['callToAction'=>"   "],
    'description too long'=>['description'=>str_repeat('z',601)],
    'brand too long'=>['brand'=>str_repeat('Z',121)],
    'benefit only blank'=>['benefits'=>["\u{00A0}","   "]],
    'too many benefits'=>['benefits'=>array_fill(0,9,'synthetic')],
    'no benefits'=>['benefits'=>[]],
    'wrong product'=>['productCode'=>'landing_page'],
    'inline NUL'=>['headline'=>"A\0B"],
    'untrusted boolean'=>['canPublish'=>true],
] as $label=>$delta) {
    $deny(static fn()=>Plan::build($pair,$json(array_replace($editorial,$delta)),
        $renderSelection,$expected),$label);
}
$badEditorial = substr($editorialBytes,0,-2)."\nNOT_JSON";
$deny(static fn()=>Plan::build($pair,$badEditorial,$renderSelection,$expected),
    'malformed editorial bytes');
$deny(static fn()=>Plan::build($pair,"{\xFF}",$renderSelection,$expected),
    'invalid UTF8 editorial');
$deny(static fn()=>Plan::build($pair,
    '{"productCode":"onepage","productCode":"onepage"}',
    $renderSelection,$expected),'duplicate editorial key');

foreach ([
    'source bytes changed'=>['sourceBytes'=>$pair['sourceBytes'].' '],
    'source digest changed'=>['payloadSha256'=>str_repeat('b',64)],
    'manifest bytes changed'=>['manifestBytes'=>$pair['manifestBytes'].' '],
    'authority promoted'=>['canPublish'=>true],
    'acceptance promoted'=>['clientAccepted'=>true],
    'extra field'=>['untrustedAuthority'=>true],
] as $label=>$delta) {
    $deny(static fn()=>Plan::build(array_replace($pair,$delta),
        $editorialBytes,$renderSelection,$expected),$label);
}
// The digest alone is not an attestation of the manifest's internal
// metadata. A self-consistently re-hashed, invalid revision or selector
// must fail here before the downstream Sites candidate receives a plan.
$mutatedSnapshot = static function (array $delta) use ($pair, $json): array {
    $manifest = json_decode($pair['manifestBytes'], true, 8, JSON_THROW_ON_ERROR);
    foreach ($delta as $field => $value) {
        if ($field === 'payloadRef') $manifest['payload']['payloadRef'] = $value;
        else $manifest[$field] = $value;
    }
    $bytes = $json($manifest);
    return array_replace($pair, [
        'manifestBytes' => $bytes,
        'manifestSha256' => hash('sha256', $bytes),
    ]);
};
foreach ([
    'revision does not follow expected revision'=>['expectedBriefRevision'=>1],
    'contract revision below minimum'=>['contractRevision'=>0],
    'source version below minimum'=>['sourceVersion'=>-1],
    'source version above maximum'=>['sourceVersion'=>9007199254740992],
    'invalid original operation selector'=>['operationId'=>'../bad'],
    'invalid original order selector'=>['orderRef'=>'bad order'],
    'invalid original contract selector'=>['contractRef'=>'bad/contract'],
    'invalid original payload selector'=>['payloadRef'=>'https://bad.example'],
] as $label => $delta) {
    $deny(static fn()=>Plan::build(
        $mutatedSnapshot($delta), $editorialBytes, $renderSelection, $expected,
    ), 're-hashed manifest: '.$label);
}

// Re-hashing both the source and manifest does not turn invalid rich
// source bytes into a legitimate output of the candidate export adapter.
$repackagedSource = static function (string $bytes) use ($pair, $json): array {
    $manifest = json_decode($pair['manifestBytes'], true, 8, JSON_THROW_ON_ERROR);
    $manifest['payload']['sizeBytes'] = strlen($bytes);
    $manifest['payload']['sha256'] = hash('sha256', $bytes);
    $manifestBytes = $json($manifest);
    return array_replace($pair, [
        'sourceBytes' => $bytes,
        'payloadSha256' => hash('sha256', $bytes),
        'manifestBytes' => $manifestBytes,
        'manifestSha256' => hash('sha256', $manifestBytes),
    ]);
};
$originalSource = json_decode($source, true, 8, JSON_THROW_ON_ERROR);
foreach ([
    'malformed but consistently digested source'=>"{invalid JSON",
    'unknown rich body field'=>$json(array_replace($originalSource,['unknownField'=>'synthetic'])),
    'source with materials enabled'=>$json(array_replace($originalSource,['logo_unavailable'=>false])),
    'source with blank services'=>$json(array_replace($originalSource,['services'=>'   '])),
    'source with invalid contact address'=>$json(array_replace($originalSource,['email'=>'not-an-email'])),
] as $label => $bytes) {
    $deny(static fn()=>Plan::build(
        $repackagedSource($bytes), $editorialBytes, $renderSelection, $expected,
    ), 're-hashed rich source: '.$label);
}
$noncanonicalManifest = substr($pair['manifestBytes'], 0, -1)."\r\n";
$deny(static fn()=>Plan::build(array_replace($pair, [
    'manifestBytes'=>$noncanonicalManifest,
    'manifestSha256'=>hash('sha256',$noncanonicalManifest),
]),$editorialBytes,$renderSelection,$expected),
    're-hashed but noncanonical snapshot exporter manifest');
// This is intentionally permitted: the original exporter validates and binds
// different valid source bytes without rewriting those bytes.
$validAlternate = Snapshot::build(
    $contract,$selection,substr($source,0,-1)."\r\n",
);
$alternatePlan = Plan::build($validAlternate,$editorialBytes,$renderSelection,$expected);
$ok($alternatePlan['planSha256']!==$plan['planSha256'] &&
    $alternatePlan['authorityVerified']===false,
    'changed valid source from canonical exporter remains unprivileged');

foreach ([
    'release path'=>['releaseRef'=>'../untrusted'],
    'output URL'=>['renderInputRef'=>'https://bad.example/'],
    'empty op'=>['operationId'=>''],
    'space key'=>['idempotencyKey'=>'bad key'],
    'extra authority'=>['authorityVerified'=>true],
] as $label=>$delta) {
    $deny(static fn()=>Plan::build($pair,$editorialBytes,
        array_replace($renderSelection,$delta),$expected),$label);
}
foreach ([
    'size string'=>['sizeBytes'=>'5223'],
    'size zero'=>['sizeBytes'=>0],
    'size too large'=>['sizeBytes'=>150001],
    'hash uppercase'=>['sha256'=>str_repeat('A',64)],
    'hash invalid'=>['sha256'=>'0'],
    'extra output'=>['published'=>true],
] as $label=>$delta) {
    $deny(static fn()=>Plan::build($pair,$editorialBytes,
        $renderSelection,array_replace($expected,$delta)),$label);
}
$deny(static fn()=>Plan::build($pair,
    str_repeat(' ', 32769),$renderSelection,$expected),
    'oversized editorial input');
echo "PORTAL ONEPAGE RENDER PLAN CANDIDATE OFFLINE PASS: {$checks} checks; no publishing authority.\n";
