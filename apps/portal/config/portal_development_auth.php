<?php

return [
    // Both this switch and SESSION_DRIVER=portal_private_pg are required.
    'enabled' => env('PORTAL_DEVELOPMENT_AUTH_ENABLED', false) === true,
    'organization' => env('PORTAL_DEVELOPMENT_ORGANIZATION'),
    'secret_file' => env('PORTAL_DEVELOPMENT_AUTH_FILE', '/run/secrets/runtime.json'),
];
