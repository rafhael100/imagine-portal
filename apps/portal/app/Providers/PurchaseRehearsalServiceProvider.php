<?php

declare(strict_types=1);
namespace App\Providers;

use App\Homologation\PurchaseRehearsalScope;
use App\Http\Controllers\PurchaseRehearsalController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Not registered in bootstrap/providers.php. Default scope always denies. */
final class PurchaseRehearsalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(PurchaseRehearsalScope::class,static fn()=>new class implements PurchaseRehearsalScope {
            public function organization(Request $request): ?string { return null; }
        });
    }

    public function boot(): void
    {
        Route::prefix('_rehearsal/purchase')->middleware(['web','throttle:120,1'])->group(function(): void {
            Route::get('/',[PurchaseRehearsalController::class,'index'])->name('rehearsal.purchase');
            Route::post('orders',[PurchaseRehearsalController::class,'create'])->name('rehearsal.orders.create');
            Route::get('orders/{id}',[PurchaseRehearsalController::class,'show'])->name('rehearsal.orders.show');
            Route::post('orders/{id}/payment',[PurchaseRehearsalController::class,'payment'])->name('rehearsal.orders.payment');
            Route::post('orders/{id}/briefing',[PurchaseRehearsalController::class,'briefing'])->name('rehearsal.orders.briefing');
        });
    }
}
