<?php

declare(strict_types=1);
// Private synthetic sandbox only. Never connects to the Portal identity database.
$ci=getenv('GITHUB_ACTIONS')==='true'&&getenv('PORTAL_PREVIEW_LOOPBACK_TEST')==='1';
$origin=$ci?'http://127.0.0.1:18083':'https://portal-dev.imaginecomunica.com.br';
if (getenv('PORTAL_PURCHASE_PREVIEW_ENABLED')!=='1'||getenv('APP_ENV')!=='staging'
    ||getenv('APP_URL')!==$origin
    ||getenv('PORTAL_DEVELOPMENT_AUTH_ENABLED')!=='false') { http_response_code(404); exit; }
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
if ($path==='/up') { header('Content-Type: application/json'); echo '{"synthetic_preview":true}'; exit; }
if (!is_string($path)) { http_response_code(404); exit; }
require __DIR__.'/private-preview-route-gate.php';
if (!PrivatePreviewRouteGate::allows($path,$ci)) { http_response_code(404); exit; }
$handle=fopen('/run/secrets/preview-runtime.json','rb');
$stat=$handle===false?false:fstat($handle);
if (!$stat||($stat['mode']&077)!==0||$stat['uid']!==82||$stat['size']>2048) { http_response_code(503); exit; }
$config=json_decode(stream_get_contents($handle),true,8,JSON_THROW_ON_ERROR); fclose($handle);
if (!is_array($config)||!is_string($config['database_password']??null)
    ||!preg_match('/\A[a-f0-9]{64}\z/D',$config['database_password'])
    ||!is_string($config['customer_scope_key']??null)
    ||!preg_match('/\A[a-f0-9]{64}\z/D',$config['customer_scope_key'])
    ||!is_string($config['app_key']??null)||!str_starts_with($config['app_key'],'base64:')
    ||strlen(base64_decode(substr($config['app_key'],7),true)?:'')!==32) { http_response_code(503); exit; }
// Gateway is private to this sandbox and pins Host. Ignore all forwarded headers.
$_SERVER['HTTPS']=$ci?'off':'on'; $_SERVER['SERVER_PORT']=$ci?'18083':'443';
$_SERVER['HTTP_HOST']=$ci?'127.0.0.1:18083':'portal-dev.imaginecomunica.com.br';
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class); $kernel->bootstrap();
foreach (['app.key'=>$config['app_key'],'app.debug'=>false,'session.driver'=>'file',
 'session.cookie'=>$ci?'imagine-preview-ci':'__Host-imagine-purchase-preview','session.secure'=>!$ci,
 'session.http_only'=>true,'session.domain'=>null,'session.path'=>'/',
 'session.same_site'=>'strict','cache.default'=>'file'] as $key=>$value) $app['config']->set($key,$value);
$app['config']->set('cache.stores.file',['driver'=>'file','path'=>storage_path('framework/cache/data')]);
$app->register(App\Providers\PurchaseRehearsalServiceProvider::class);
$app->register(App\Providers\FullPortalPreviewServiceProvider::class);
// Explicit shared synthetic workspace for the existing protected development operator.
// This is not a real Portal principal, customer account or business permission.
$app->instance(App\Homologation\PurchaseRehearsalScope::class,new class implements App\Homologation\PurchaseRehearsalScope {
 public function organization(Illuminate\Http\Request $request): ?string { return 'test-org-a'; }
});
try {
 $pdo=new PDO('pgsql:host=database;port=5432;dbname=portal_purchase_rehearsal;connect_timeout=3',
  'purchase_rehearsal_a',$config['database_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $app->instance(App\Homologation\RoleBoundTestPurchase::class,new App\Homologation\RoleBoundTestPurchase($pdo));
 $app->instance(App\Homologation\PreviewOrderListing::class,new App\Homologation\PreviewOrderListing($pdo));
 $app->instance(App\Homologation\CustomerPreviewStore::class,new App\Homologation\CustomerPreviewStore($pdo,$config['customer_scope_key']));
} catch (Throwable) { http_response_code(503); echo 'Ambiente de teste temporariamente indisponível.'; exit; }
unset($config,$pdo);
$request=Illuminate\Http\Request::capture(); $response=$kernel->handle($request);
$response->headers->set('Cache-Control','no-store, private');
$response->headers->set('Content-Security-Policy',"default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
$response->headers->set('X-Content-Type-Options','nosniff');
$response->headers->set('Referrer-Policy','no-referrer');
$response->headers->set('X-Robots-Tag','noindex, nofollow');
$response->send(); $kernel->terminate($request,$response);
