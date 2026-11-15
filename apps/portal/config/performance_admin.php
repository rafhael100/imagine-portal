<?php
return [
    // Never exposes a production route or grants operational authorization.
    'service_review_enabled'=>false, // Deliberately unwired; no environment toggle opens transport.
    'service_review_url'=>'',
    'service_envelope_hash'=>'7c08724dbbdbfe8edb806d6a948c38ecc24e2927953eba14fba7cc1a04389cfe',
    'mode'=>env('PERFORMANCE_ADMIN_MODE','isolated_ci'),
    'synthetic_only'=>true,
    'enabled'=>env('PERFORMANCE_ADMIN_LAB_ENABLED',false),
    'engine_root'=>env('PERFORMANCE_ADMIN_LAB_ENGINE_ROOT',''),
    'engine_sha'=>'51fc81d6d0f0d99d907a06928b223a74c065a6b3',
    'engine_hash'=>'a34c64c1cf47f6778c3b55e561a6bf3ae00650423d3d0ed5e838793ed7eb11dc',
    'finance_hash'=>'4140213bb138e5e02b0ec2e6182ff4e0bbf79013c262a6ada61b97afaac1cb66',
];
