<?php

use App\Providers\AppServiceProvider;
use App\Providers\PortalDevelopmentAuthServiceProvider;
use App\Providers\OnepageCommerceServiceProvider;

return [
    \App\Providers\OnepageD0CandidateServiceProvider::class,
    AppServiceProvider::class,
    PortalDevelopmentAuthServiceProvider::class,
    OnepageCommerceServiceProvider::class,
];
