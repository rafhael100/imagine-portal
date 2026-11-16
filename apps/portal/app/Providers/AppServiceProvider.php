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
        $this->app->bind(\App\Domain\PerformanceAdministration\AuthorizedPerformanceRead::class,function(){
            $authority=new \App\Domain\PerformanceAdministration\NativePerformanceAuthority;
            $loadIdentity=static function(){
                $path=config('performance_admin.service_identity_file');
                if(!is_string($path)||!is_file($path)||is_link($path))return null;
                $s=lstat($path);if(!in_array($s['mode']&0777,[0400,0600],true)||$s['size']>16384)return null;
                return json_decode(file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
            };
            return new \App\Domain\PerformanceAdministration\AuthorizedPerformanceRead($authority,
                function($company,$op,$correlation)use($authority,$loadIdentity){
                    $lease=$authority->lease($company,$op,$correlation);
                    $identity=function()use($authority,$loadIdentity,$company,$op,$correlation,$lease){
                        if(!hash_equals($lease,$authority->lease($company,$op,$correlation)))throw new \RuntimeException('PERFORMANCE_AUTHORITY_CHANGED');
                        return $loadIdentity();
                    };
                    return (new \App\Domain\PerformanceAdministration\SignedServiceReadClient($identity,
                        static fn($event,$id)=>\App\Domain\PerformanceAdministration\PerformanceSecurityEvents::record($event,$id)))
                        ->read($company,$op,[],$correlation);
                });
        });
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
