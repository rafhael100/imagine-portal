<?php
return [
    // Never exposes a production route or grants operational authorization.
    'mode'=>env('PERFORMANCE_ADMIN_MODE','isolated_ci'),
    'synthetic_only'=>true,
    'enabled'=>env('PERFORMANCE_ADMIN_LAB_ENABLED',false),
    'engine_root'=>env('PERFORMANCE_ADMIN_LAB_ENGINE_ROOT',''),
    'engine_sha'=>'434f231471a1bfcab5af26d38005c4a59253b0de',
    'engine_hash'=>'a34c64c1cf47f6778c3b55e561a6bf3ae00650423d3d0ed5e838793ed7eb11dc',
    'finance_hash'=>'4140213bb138e5e02b0ec2e6182ff4e0bbf79013c262a6ada61b97afaac1cb66',
];
