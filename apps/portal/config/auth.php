<?php
declare(strict_types=1);

use App\Models\PortalAdminUser;

return [
    // Portal administration has its own guard and user table. CRM sessions
    // and customer sessions must never be accepted as admin credentials.
    'defaults'=>[
        'guard'=>'portal-admin',
        'passwords'=>'portal_admin_users',
    ],
    'guards'=>[
        'portal-admin'=>[
            'driver'=>'session',
            'provider'=>'portal_admin_users',
        ],
    ],
    'providers'=>[
        'portal_admin_users'=>[
            'driver'=>'eloquent',
            'model'=>PortalAdminUser::class,
        ],
    ],
    'passwords'=>[],
    'password_timeout'=>10800,
];
