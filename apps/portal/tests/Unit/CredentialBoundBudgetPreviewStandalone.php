<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('F64 contract runs on CLI only');
}
putenv('APP_ENV=testing');
putenv('PORTAL_F64_BUDGET_PREVIEW_LAB=1');

require_once dirname(__DIR__,2)
    .'/app/Domain/PerformanceDashboard/CredentialBoundBudgetPreview.php';

use App\Domain\PerformanceDashboard\CredentialBoundBudgetPreview as Preview;

$scenario=[
    'schema_version'=>'perf.planning.offline-budget.synthetic.v1',
    'scenario_ref'=>'scenario-demo-f106-a',
    'currency'=>'BRL',
    'start_date'=>'2028-02-28',
    'end_date'=>'2028-03-01',
    'number_of_days'=>3,
    'total_centavos'=>10001,
    'channel_split_bp'=>['google_ads'=>5500,'meta_ads'=>4500],
    'channel_totals_centavos'=>['google_ads'=>5500,'meta_ads'=>4501],
    'daily'=>[
        ['date'=>'2028-02-28','total_centavos'=>3334,
            'google_ads_centavos'=>1833,'meta_ads_centavos'=>1501],
        ['date'=>'2028-02-29','total_centavos'=>3334,
            'google_ads_centavos'=>1834,'meta_ads_centavos'=>1500],
        ['date'=>'2028-03-01','total_centavos'=>3333,
            'google_ads_centavos'=>1833,'meta_ads_centavos'=>1500],
    ],
    'simulation_only'=>true,
    'advertiser_approval_verified'=>false,
    'provider_connected'=>false,
    'media_execution_allowed'=>false,
    'billing_allowed'=>false,
    'ready_for_production'=>false,
];
$dto=[
    'schema_version'=>'perf.portal.credential-bound-budgets.synthetic.v1',
    'status'=>'credential_scoped_demo_budget_preview_only',
    'simulation_only'=>true,
    'credential_binding_confirmed'=>true,
    'portal_identity_authenticated'=>false,
    'advertiser_approval_verified'=>false,
    'provider_connected'=>false,
    'media_execution_allowed'=>false,
    'billing_allowed'=>false,
    'ready_for_production'=>false,
    'scenarios'=>[$scenario],
];

$n=0;
$check=static function(bool $yes,string $label)use(&$n):void {
    if(!$yes)throw new RuntimeException('FAIL PORTAL-F64 '.$label);
    ++$n;
};
$preview=Preview::fromDto($dto);
$check($preview instanceof Preview,'accept precise deterministic synthetic budget');
$check(count($preview->scenarios)===1,'one scenario visible');
$check($preview->scenarios[0]['total_centavos']===10001,'integer centavos retained');
$check($preview->scenarios[0]['channel_totals_centavos']['google_ads']===5500,
    'Google total exact');
$check($preview->scenarios[0]['channel_totals_centavos']['meta_ads']===4501,
    'Meta total exact');
$check($preview->scenarios[0]['daily'][1]['date']==='2028-02-29',
    'calendar leap day checked');
$check(!$preview->canExecuteMedia(),'no Ads authorization');
$check(!$preview->canBill(),'no billing');
$check(!$preview->hasHumanApproval(),'no human approval');
$check(!$preview->isAuthenticatedPortalSession(),'no Portal login');

$deny=static function(array $mutation,string $label)use($dto,$check):void {
    $changed=array_replace($dto,$mutation);
    $check(Preview::fromDto($changed)===null,'reject '.$label);
};
$scenarioMutation=static function(array $mutation,string $label)use($dto,$scenario,$deny):void {
    $deny(['scenarios'=>[array_replace($scenario,$mutation)]],$label);
};
foreach([
    ['schema_version'=>'prod'],
    ['status'=>'connected'],
    ['simulation_only'=>false],
    ['credential_binding_confirmed'=>false],
    ['portal_identity_authenticated'=>true],
    ['advertiser_approval_verified'=>true],
    ['provider_connected'=>true],
    ['media_execution_allowed'=>true],
    ['billing_allowed'=>true],
    ['ready_for_production'=>true],
    ['provider_token'=>'fake'],
    ['scenarios'=>array_merge($dto['scenarios'],$dto['scenarios'])],
    ['scenarios'=>[[]]],
] as $mutation) $deny($mutation,'top-level elevated flag, duplicate or schema');

foreach([
    ['schema_version'=>'prod'],
    ['scenario_ref'=>'prod-account-id'],
    ['currency'=>'USD'],
    ['number_of_days'=>4],
    ['total_centavos'=>100.01],
    ['total_centavos'=>-1],
    ['total_centavos'=>0],
    ['total_centavos'=>2000000001],
    ['total_centavos'=>'10001'],
    ['start_date'=>'2028-02-30'],
    ['start_date'=>'2028-03-02'],
    ['start_date'=>'2028-2-28'],
    ['end_date'=>'2028-02-27'],
    ['end_date'=>'2028-03-02'],
    ['channel_split_bp'=>['google_ads'=>5500,'meta_ads'=>4499]],
    ['channel_split_bp'=>['google_ads'=>-1,'meta_ads'=>10001]],
    ['channel_split_bp'=>['google_ads'=>'5500','meta_ads'=>4500]],
    ['channel_totals_centavos'=>['google_ads'=>5501,'meta_ads'=>4500]],
    ['channel_totals_centavos'=>['google_ads'=>5500,'meta_ads'=>4502]],
    ['daily'=>[]],
    ['daily'=>array_slice($scenario['daily'],0,2)],
    ['daily'=>array_replace($scenario['daily'],[
        1=>array_replace($scenario['daily'][1],['date'=>'2028-03-01'])
    ])],
    ['daily'=>array_replace($scenario['daily'],[
        0=>array_replace($scenario['daily'][0],['google_ads_centavos'=>1834])
    ])],
    ['daily'=>array_replace($scenario['daily'],[
        0=>array_replace($scenario['daily'][0],['meta_ads_centavos'=>1500])
    ])],
    ['daily'=>array_replace($scenario['daily'],[
        0=>array_replace($scenario['daily'][0],['total_centavos'=>3333])
    ])],
    ['daily'=>array_replace($scenario['daily'],[
        0=>array_replace($scenario['daily'][0],['total_centavos'=>3334.0])
    ])],
    ['simulation_only'=>false],
    ['advertiser_approval_verified'=>true],
    ['provider_connected'=>true],
    ['media_execution_allowed'=>true],
    ['billing_allowed'=>true],
    ['ready_for_production'=>true],
    ['oauth_token'=>'secret'],
] as $mutation) $scenarioMutation($mutation,'scenario tamper and unsafe flag');

$empty=$dto;$empty['scenarios']=[];
$check(Preview::fromDto($empty) instanceof Preview,
    'empty preview is safe, does not infer provider spending');
$missing=$dto;unset($missing['scenarios']);
$check(Preview::fromDto($missing)===null,'missing scenarios field denied');

if (isset($argv[1])) {
    $path=$argv[1];
    $check(is_string($path) && is_file($path) && !is_link($path)
        && filesize($path)<128000,'real PostgreSQL JSON is local small file');
    $real=json_decode((string)file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
    $result=Preview::fromDto($real);
    $check($result instanceof Preview,
        'real Performance F107 PostgreSQL JSON accepted by Portal');
    $check(count($result->scenarios)===1,'real login A sees only own scenario');
    $check($result->scenarios[0]['scenario_ref']==='scenario-demo-f106-a',
        'real SQL scenario reference');
    $check($result->scenarios[0]['total_centavos']===10001,
        'real SQL total exact');
    $check($result->scenarios[0]['channel_totals_centavos']['google_ads']===5500,
        'real SQL Google allocated');
    $check($result->scenarios[0]['channel_totals_centavos']['meta_ads']===4501,
        'real SQL Meta allocated');
    $check(!$result->canExecuteMedia() && !$result->canBill(),
        'real DB preview no actions');
}
putenv('PORTAL_F64_BUDGET_PREVIEW_LAB=0');
$check(Preview::fromDto($dto)===null,'lab mode required');
putenv('PORTAL_F64_BUDGET_PREVIEW_LAB=1');
putenv('APP_ENV=production');
$check(Preview::fromDto($dto)===null,'production invocation forbidden');

echo "PASS PORTAL-F64 $n strict BRL daily budget and NO-GO contract assertions\n";
