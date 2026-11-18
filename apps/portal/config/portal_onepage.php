<?php

return [
    // An explicit private runtime with the original native authentication.
    // This does not activate a PSP, provision terms, grant SQL roles or deploy.
    'enabled' => env('PORTAL_ONEPAGE_ENABLED', false) === true,
    'connection_file' => env('PORTAL_ONEPAGE_CONNECTION_FILE', '/run/secrets/onepage-runtime.json'),
];
