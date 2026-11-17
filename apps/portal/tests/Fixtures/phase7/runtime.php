<?php
declare(strict_types=1);
try {
    if(PHP_SAPI!=='cli'||getenv('GITHUB_ACTIONS')!=='true'||getenv('ADMIN_PHASE7_CI')!=='1')throw new RuntimeException;
    $kind=$argv[1]??'';
    if($kind==='portal') {
        require '/var/www/html/vendor/autoload.php';
        $app=require '/var/www/html/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if(App\Domain\PortalAccess\Security\PortalAdminStagingSecurityPolicy::issues('staging')!==[]||!App\Domain\PerformanceAdministration\PrivateDemonstrationPolicy::allowed()||config('portal_admin.provision_enabled')===true)throw new RuntimeException;
        foreach(['pgsql'=>'portal_p6_runtime','performance_authority'=>'portal_p6_authority'] as $name=>$role) {
            $connection=Illuminate\Support\Facades\DB::connection($name);
            if($connection->getConfig('sslmode')!=='verify-full'||$connection->getConfig('sslrootcert')!=='/run/pki/portal_db_ca.pem')throw new RuntimeException;
            $row=$connection->selectOne("SELECT current_user actor,(SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()) tls,r.rolsuper OR r.rolbypassrls OR r.rolcreaterole OR r.rolcreatedb OR r.rolreplication unsafe FROM pg_roles r WHERE r.rolname=current_user");
            if($row->actor!==$role||!$row->tls||$row->unsafe)throw new RuntimeException;
        }
        // Exercise Laravel's real connector, not just a configuration substring.
        // The leaf certificate cannot validate either PostgreSQL server chain.
        $original=config('database.connections.pgsql.sslrootcert');
        config(['database.connections.pgsql.sslrootcert'=>'/run/pki/portal_client.pem']);
        Illuminate\Support\Facades\DB::purge('pgsql');$denied=false;
        try{Illuminate\Support\Facades\DB::connection('pgsql')->select('SELECT 1');}catch(Throwable){$denied=true;}
        if(!$denied)throw new RuntimeException;
        config(['database.connections.pgsql.sslrootcert'=>$original]);Illuminate\Support\Facades\DB::purge('pgsql');
        if((int)Illuminate\Support\Facades\DB::connection('pgsql')->selectOne('SELECT 1 AS value')->value!==1)throw new RuntimeException;
    } elseif($kind==='receiver') {
        $dsn=getenv('PERFORMANCE_PRIVATE_PG_DSN');$role=getenv('PERFORMANCE_PRIVATE_PG_USER');$password=trim(file_get_contents('/run/private/db-password'));
        if($role!=='performance_p6_reader'||!str_contains($dsn,'sslmode=verify-full;sslrootcert=/run/private/db-ca.pem'))throw new RuntimeException;
        $db=new PDO($dsn,$role,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $row=$db->query("SELECT current_user actor,(SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()) tls,r.rolsuper OR r.rolbypassrls OR r.rolcreaterole OR r.rolcreatedb OR r.rolreplication unsafe FROM pg_roles r WHERE r.rolname=current_user")->fetch(PDO::FETCH_ASSOC);
        if($row['actor']!==$role||!$row['tls']||$row['unsafe'])throw new RuntimeException;
        $address=gethostbyname('performance-db');
        if(filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)===false)throw new RuntimeException;
        $denied=false;
        try {new PDO("pgsql:host=wrong-name.example.invalid;hostaddr=$address;port=5432;dbname=performance_p6_synthetic;sslmode=verify-full;sslrootcert=/run/private/db-ca.pem",$role,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}catch(Throwable){$denied=true;}
        if(!$denied)throw new RuntimeException;
    } else throw new RuntimeException;
    echo 'PHASE7_RUNTIME_TLS_AND_ROLE_PASS '.$kind.PHP_EOL;
}catch(Throwable){fwrite(STDERR,"PHASE7_RUNTIME_CHECK_DENIED\n");exit(1);}

