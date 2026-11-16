<?php
return [
    'native_read_enabled'=>env('PERFORMANCE_NATIVE_READ_ENABLED',false),
    // Never exposes a production route or grants operational authorization.
    'service_review_enabled'=>false, // Deliberately unwired; no environment toggle opens transport.
    'service_review_url'=>'',
    'service_review_host'=>'',
    'service_review_ca'=>'',
    'service_review_certificate'=>'',
    'service_review_private_key'=>'',
    'service_review_port'=>443,
    'service_review_address'=>'', // Private IPv4 pinned with TLS hostname verification; no DNS/proxy fallback.
    'service_envelope_hash'=>'7c08724dbbdbfe8edb806d6a948c38ecc24e2927953eba14fba7cc1a04389cfe',
    'mode'=>env('PERFORMANCE_ADMIN_MODE','isolated_ci'),
    'synthetic_only'=>true,
    'enabled'=>env('PERFORMANCE_ADMIN_LAB_ENABLED',false),
    'engine_root'=>env('PERFORMANCE_ADMIN_LAB_ENGINE_ROOT',''),
    'engine_sha'=>'5c5802bbcf231853764d7241fd3acf1a35588de7',
    'engine_hash'=>'a34c64c1cf47f6778c3b55e561a6bf3ae00650423d3d0ed5e838793ed7eb11dc',
    'finance_hash'=>'4140213bb138e5e02b0ec2e6182ff4e0bbf79013c262a6ada61b97afaac1cb66',
];
