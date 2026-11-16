<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if (config('automation_admin.transport_enabled') === true
            && \App\Domain\AutomationAdministration\AutomationPolicyCatalog::available()) {
            $this->app->bind(\App\Domain\AutomationAdministration\AutomationStateReader::class, function () {
                $client = new \App\Domain\AutomationAdministration\AutomationS2SClient(
                    (string)config('automation_admin.ca_file'), (string)config('automation_admin.certificate_file'),
                    (string)config('automation_admin.private_key_file'), (string)config('automation_admin.signing_key_file'));
                $authority = new \App\Domain\AutomationAdministration\DatabaseAutomationAuthority;
                return $client->reader(fn(int $operator, string $company) => $authority->allows($operator, $company));
            });
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
