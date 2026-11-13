<?php

declare(strict_types=1);

/**
 * Build one local HTML report from disposable CI PostgreSQL 18 data.
 *
 * No HTTP route, authorization, public asset, customer data, deployment,
 * external provider, or real SQL role is created by this CLI-only script.
 */
if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('PORTAL_BROWSER_PG_LAB') !== 'DISPOSABLE_PG18'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGPORT') !== '5432'
    || getenv('PGDATABASE') !== 'portal_browser_lab') {
    fwrite(STDERR, "Disposable CI-only PG18 showcase required.\n");
    exit(2);
}

require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\Organizations\OrganizationId;
use Tests\Support\DisposablePg18ProjectInboxReader;
use Tests\Support\DisposablePg18ProjectPreparationReader;
use Tests\Support\DisposablePg18ShowcaseOverview;

$database = new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_browser_lab',
    'postgres',(string)getenv('PGPASSWORD'), [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ],
);
if ($database->query('SELECT current_database()')->fetchColumn() !== 'portal_browser_lab'
    || (int)$database->query(
        "SELECT current_setting('server_version_num')::integer/10000"
    )->fetchColumn() !== 18) {
    throw new RuntimeException('Unexpected database or PostgreSQL version.');
}

// The schema and server are destroyed at the end of the GitHub Actions job.
// This script runs AFTER the isolated tests and resets ONLY that CI database.
$database->beginTransaction();
try {
    $database->exec(<<<'SQL'
TRUNCATE public.portal_service_request_transitions,
 public.portal_service_requests,public.portal_service_project_checkpoints,
 public.portal_service_projects,public.portal_service_resource_claims,
 public.portal_email_proof_challenges,public.portal_auth_sessions,
 public.portal_membership_grants,public.portal_memberships,public.portal_identities,
 public.portal_organizations,public.portal_auth_attempt_buckets CASCADE;

INSERT INTO public.portal_organizations(id,reference,display_name,status)
VALUES ('11111111-1111-4111-8111-111111111111',
        'showcase-fictional-org','Empresa Imagine Fictícia','active');
INSERT INTO public.portal_service_projects(id,organization_id,project_ref,product_code)
VALUES ('33333333-3333-4333-8333-333333333333',
        '11111111-1111-4111-8111-111111111111',
        'DEMO-PROJECT-ONEPAGE','sites_onepage'),
       ('44444444-4444-4444-8444-444444444444',
        '11111111-1111-4111-8111-111111111111',
        'DEMO-PROJECT-LANDING','sites_landing_page'),
       ('55555555-5555-4555-8555-555555555555',
        '11111111-1111-4111-8111-111111111111',
        'DEMO-PROJECT-PERFORMANCE','performance_ai');

INSERT INTO public.portal_service_project_checkpoints
 (id,project_id,organization_id,checkpoint_code)
SELECT gen_random_uuid(),p.id,p.organization_id,c.code
 FROM public.portal_service_projects p
 JOIN LATERAL (
    VALUES ('sites_onepage','briefing'),
           ('sites_onepage','content'),
           ('sites_onepage','domain'),
           ('sites_landing_page','briefing'),
           ('sites_landing_page','content'),
           ('sites_landing_page','consent'),
           ('performance_ai','goals'),
           ('performance_ai','ad_accounts'),
           ('performance_ai','creatives'),
           ('performance_ai','budget')
 ) AS c(product,code) ON c.product=p.product_code;

UPDATE public.portal_service_project_checkpoints SET state='reviewed'
 WHERE project_id='33333333-3333-4333-8333-333333333333';
UPDATE public.portal_service_project_checkpoints SET state='reviewed'
 WHERE project_id='44444444-4444-4444-8444-444444444444'
   AND checkpoint_code='briefing';
UPDATE public.portal_service_project_checkpoints SET state='reviewed'
 WHERE project_id='55555555-5555-4555-8555-555555555555'
   AND checkpoint_code IN ('goals','ad_accounts');

INSERT INTO public.portal_service_requests(id,organization_id,project_id,request_ref)
VALUES ('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        '11111111-1111-4111-8111-111111111111',
        '33333333-3333-4333-8333-333333333333','DEMO-REQ-ONEPAGE'),
       ('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        '11111111-1111-4111-8111-111111111111',
        '44444444-4444-4444-8444-444444444444','DEMO-REQ-LANDING'),
       ('cccccccc-cccc-4ccc-8ccc-cccccccccccc',
        '11111111-1111-4111-8111-111111111111',
        '55555555-5555-4555-8555-555555555555','DEMO-REQ-PERFORMANCE');

UPDATE public.portal_service_requests SET status='submitted'
 WHERE request_ref IN ('DEMO-REQ-LANDING','DEMO-REQ-PERFORMANCE');
UPDATE public.portal_service_requests SET status='in_review'
 WHERE request_ref='DEMO-REQ-PERFORMANCE';
SQL);
    $database->commit();
} catch (Throwable $error) {
    if ($database->inTransaction()) $database->rollBack();
    throw $error;
}

$organization=new OrganizationId('11111111-1111-4111-8111-111111111111');
$preparation=(new DisposablePg18ProjectPreparationReader($database))
    ->project($organization);
if ($preparation->reason!=='preview_ready' || count($preparation->rows)!==3
    || $preparation->authorizesService()) {
    throw new RuntimeException('Unsafe or incomplete synthetic preparation evidence.');
}
$items=[];
foreach ([
    '33333333-3333-4333-8333-333333333333',
    '44444444-4444-4444-8444-444444444444',
    '55555555-5555-4555-8555-555555555555',
] as $projectId) {
    $projection=(new DisposablePg18ProjectInboxReader($database))
        ->project($organization,$projectId);
    if (!$projection->isPreparedPreview() || count($projection->items)!==1
        || $projection->mayExposeCustomerData()) {
        throw new RuntimeException('Unsafe or inconsistent project request history.');
    }
    foreach ($projection->items as $item) {
        $items[]=[
            $item->requestId,$item->serviceReference,$item->status->label(),
            'Revisão fictícia '.$item->revision,
        ];
    }
}
if (count($items)!==3) {
    throw new RuntimeException('Synthetic readout must cover exactly three requests.');
}

$overview=DisposablePg18ShowcaseOverview::fromRows($preparation->rows,$items);
if ($overview!==[
    'projects'=>3,'requests'=>3,
    'checkpoints_reviewed'=>6,'checkpoints_total'=>10,
]) {
    throw new RuntimeException('Synthetic PG18 summary does not reconcile.');
}

$html=view('portal.demo.integration-rehearsal',[
    'preparationRows'=>$preparation->rows,
    'requestRows'=>$items,
    'overview'=>$overview,
])->render();

$logo=public_path('assets/logo-imagine.png');
$bytes=file_get_contents($logo);
if (!is_string($bytes) || strlen($bytes)!==5594
    || sha1('blob '.strlen($bytes)."\0".$bytes)
        !== '678adeceb3a8ecdaeb6b576b9ee18d1bf0396f77') {
    throw new RuntimeException('Pinned Imagine logo integrity failure.');
}
$logoRef='src="/assets/logo-imagine.png"';
if (substr_count($html,$logoRef)!==1
    || substr_count($html,"img-src 'self' data:")!==1) {
    throw new RuntimeException('Unexpected HTML logo or CSP contract.');
}
$html=str_replace(
    $logoRef,'src="data:image/png;base64,'.base64_encode($bytes).'"',$html,
);
$html=str_replace("img-src 'self' data:","img-src data:",$html);

foreach ([
    'DEMO-PROJECT-ONEPAGE','DEMO-PROJECT-LANDING','DEMO-PROJECT-PERFORMANCE',
    '3/3 itens revisados','1/3 itens revisados','2/4 itens revisados',
    'DEMO-REQ-ONEPAGE','DEMO-REQ-LANDING','DEMO-REQ-PERFORMANCE',
    'LABORATÓRIO DESCARTÁVEL','noindex,nofollow',
    'Visão geral dos serviços','6/10',
    'Conteúdo','Consentimento','Criativos','Orçamento',
    'href="#pi-pg18-projects"','href="#pi-pg18-requests"',
] as $expected) {
    if (!str_contains($html,$expected)) {
        throw new RuntimeException('Missing synthetic showcase contract: '.$expected);
    }
}
if (preg_match('~<\s*(script|form|input|iframe|button)\b~i',$html)===1
    || preg_match('~https?://|mailto:|javascript:~i',$html)===1
    || substr_count($html,'<table>')!==2
    || substr_count($html,'role="region"')!==2
    || substr_count($html,'tabindex="0"')!==2) {
    throw new RuntimeException('Unsafe or incomplete offline preview structure.');
}
$destination=storage_path('app/portal-pg18-integrated-showcase');
if (file_exists($destination) || is_link($destination)
    || !mkdir($destination,0700,true)) {
    throw new RuntimeException('Synthetic CI destination already exists.');
}
if (file_put_contents($destination.'/index.html',$html,LOCK_EX)===false) {
    throw new RuntimeException('Cannot create synthetic offline proof.');
}
chmod($destination.'/index.html',0600);
$manifest=[
    'schema'=>'imagine.portal.pg18-integrated-showcase.v1',
    'synthetic_only'=>true,
    'production_approved'=>false,
    'customer_access_approved'=>false,
    'source'=>'disposable_postgresql_18',
    'projects'=>3,'requests'=>3,
    'html_sha256'=>hash('sha256',$html),
];
if (file_put_contents(
    $destination.'/manifest.json',
    json_encode($manifest,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n",
    LOCK_EX,
)===false) {
    throw new RuntimeException('Cannot write synthetic manifest.');
}
chmod($destination.'/manifest.json',0600);
if (hash_file('sha256',$destination.'/index.html')!==$manifest['html_sha256']) {
    throw new RuntimeException('Offline preview SHA-256 verification failed.');
}
echo json_encode([
    'result'=>'SYNTHETIC_OFFLINE_PREVIEW_ONLY',
    'html'=>$destination.'/index.html',
    'projects'=>3,'requests'=>3,
    'sha256'=>$manifest['html_sha256'],
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
