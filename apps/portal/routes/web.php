<?php

// The operational Portal has NO customer or authentication routes yet.
// A one-off lab is available ONLY to Laravel's CLI test process when an
// explicit runner-only flag is supplied. It cannot serve a browser or be
// enabled in production, even if an environment flag is accidentally set.
if (app()->environment('testing')
    && PHP_SAPI === 'cli'
    && getenv('PORTAL_CLIENT_LAB_ENABLED') === '1') {
    require __DIR__.'/../labs/client-auth/routes.php';
}
