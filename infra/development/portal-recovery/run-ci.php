<?php
declare(strict_types=1);

/** Not a production recovery command. Explicit GitHub-runner-only target. */
require __DIR__.'/IsolatedRestoreQuarantine.php';
try {
    if(PHP_SAPI!=='cli'||$argc!==1
        ||getenv('GITHUB_ACTIONS')!=='true'
        ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
        ||getenv('PORTAL_RESTORE_QUARANTINE_CI')!=='DISPOSABLE_ONLY'
        ||getenv('APP_ENV')!=='staging') {
        throw new RuntimeException();
    }
    $path='/run/secrets/database-admin';
    $file=lstat($path);
    if(!is_array($file)||($file['mode']&0170000)!==0100000
        ||($file['mode']&0077)!==0||$file['uid']!==posix_geteuid()
        ||$file['nlink']!==1||$file['size']!==64)throw new RuntimeException();
    $password=file_get_contents($path);
    if(!is_string($password)||preg_match('/\A[a-f0-9]{64}\z/D',$password)!==1)
        throw new RuntimeException();
    $db=new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_restore_f365_ci;connect_timeout=3;sslmode=disable',
        'postgres',$password,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES=>false,
            PDO::ATTR_PERSISTENT=>false
        ]
    );
    unset($password);
    $status=(new \Imagine\Development\IsolatedRestoreQuarantine($db))->apply();
    if($status['status']!=='OFFLINE_QUARANTINED_STILL_NO_SERVING')
        throw new RuntimeException();
    echo "PASS restored synthetic PG18 sessions and pending evidence quarantined; NO SERVING.\n";
}catch(Throwable){
    fwrite(STDERR,"NO-GO: restored synthetic database not verified or not quarantined.\n");
    exit(1);
}
