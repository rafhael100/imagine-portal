<?php
return [
    // Never exposes a production route or grants operational authorization.
    'enabled'=>env('PERFORMANCE_ADMIN_LAB_ENABLED',false),
    'engine_root'=>env('PERFORMANCE_ADMIN_LAB_ENGINE_ROOT',''),
    'engine_sha'=>'238bcb026aca0a02d90c0ebff38fb2d4f35d8e1f',
    'engine_hash'=>'10fe958e078db09cda20c202da3c96e60fca973850d1e077423da3af64aea114',
    'finance_hash'=>'4140213bb138e5e02b0ec2e6182ff4e0bbf79013c262a6ada61b97afaac1cb66',
];
