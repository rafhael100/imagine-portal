<?php

declare(strict_types=1);
// Disposable CI harness only. This file is not an application entry point for deployment.
if (PHP_SAPI!=='cli-server' || getenv('GITHUB_ACTIONS')!=='true'
    || getenv('PORTAL_PURCHASE_HTTP_CI')!=='1') {
    http_response_code(404); exit;
}
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
// Use real web/CSRF middleware, not the framework's testing bypass.
if ($app->environment()!=='staging') { http_response_code(404); exit; }
$app['config']->set('session.driver','file');
$app['config']->set('session.secure',false); // HTTP loopback CI only; never used for VPS.
$app->register(App\Providers\PurchaseRehearsalServiceProvider::class);
$app->instance(App\Homologation\PurchaseRehearsalScope::class,new class implements App\Homologation\PurchaseRehearsalScope {
    public function organization(Illuminate\Http\Request $request): ?string { return 'test-org-a'; }
});
$app->instance(App\Homologation\RoleBoundTestPurchase::class,new App\Homologation\RoleBoundTestPurchase(
    new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_purchase_rehearsal','purchase_rehearsal_a','ci-synthetic-a-only')
));
$request=Illuminate\Http\Request::capture();
$response=$kernel->handle($request);
$response->send();
$kernel->terminate($request,$response);
