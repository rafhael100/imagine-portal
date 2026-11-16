<?php
declare(strict_types=1);
// Credential-free HTML evidence, from the actual controller and compiled Blade.
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(getenv('CI')!=='true'||!app()->environment('testing')||getenv('PERFORMANCE_ADMIN_PG_CI')!=='1')throw new RuntimeException('CI only');
$actor=App\Models\PortalAdminUser::query()->firstOrFail();Illuminate\Support\Facades\Auth::guard('portal-admin')->login($actor);
$req=Illuminate\Http\Request::create('/portal-admin/performance/dashboard');$req->setLaravelSession(app('session')->driver());app()->instance('request',$req);
view()->share('errors',new Illuminate\Support\ViewErrorBag());
$view=(new App\Http\Controllers\PerformanceAdminController())->index($req,'dashboard');
$path=getenv('PERFORMANCE_ADMIN_EVIDENCE');if(!$path||!is_dir($path))throw new RuntimeException('Evidence directory required');
file_put_contents($path.'/dashboard.html',$view->render());
