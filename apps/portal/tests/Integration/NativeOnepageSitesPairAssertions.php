<?php
declare(strict_types=1);
namespace Tests\Integration;

use App\Domain\Commerce\OnepageAcceptedOrder;
use App\Domain\SitesIntegration\SitesCreateProjectEncoder;
use App\Infrastructure\Commerce\PostgresOnepageOrders;
use App\Infrastructure\Commerce\PostgresPortalSiteContractSource;
use DomainException;
use Illuminate\Database\PostgresConnection;
use PDO;
use PHPUnit\Framework\Assert;
use RuntimeException;

/** Cross-repository product proof. No HTTP, fake receiver, PSP call or publication. */
final class NativeOnepageSitesPairAssertions
{
    public static function run(PDO $portal,PostgresOnepageOrders $orders,OnepageAcceptedOrder $order,string $reference):void
    {
        Assert::assertSame('DISPOSABLE_PG18',getenv('PORTAL_NATIVE_ORDER_TEST'));
        Assert::assertSame('portal_native_test_bootstrap',$portal->query('SELECT session_user')->fetchColumn());
        Assert::assertSame('portal_native_orders_ci',$portal->query('SELECT current_database()')->fetchColumn());
        Assert::assertSame(getenv('PORTAL_NATIVE_ORDER_TEST_DATA'),$portal->query('SHOW data_directory')->fetchColumn());
        $source=getenv('PORTAL_SITES_PAIR_SOURCE');
        Assert::assertIsString($source);Assert::assertSame($source,realpath($source));
        $pins=json_decode(file_get_contents(__DIR__.'/portal-sites-source-pins.json'),true,32,JSON_THROW_ON_ERROR);
        foreach ($pins['sha256'] as $file=>$digest) {
            Assert::assertSame($source.'/'.$file,realpath($source.'/'.$file));
            Assert::assertSame($digest,hash_file('sha256',$source.'/'.$file),'Paired Sites source changed: '.$file);
        }
        foreach (['.env','.env.testing','bootstrap/cache/config.php'] as $file) {
            Assert::assertFileDoesNotExist($source.'/apps/sites/'.$file);
        }
        $database='portal_sites_pair_ci';
        $exists=$portal->query("SELECT count(*) FROM pg_database WHERE datname='portal_sites_pair_ci'")->fetchColumn();
        Assert::assertSame(0,(int)$exists,'Pair test requires a fresh separate Sites database.');
        $portal->exec('CREATE DATABASE portal_sites_pair_ci');
        $port=(string)getenv('PORTAL_NATIVE_ORDER_TEST_PORT');
        $environment=[
            'PATH'=>'/usr/bin:/bin','APP_ENV'=>'testing','APP_DEBUG'=>'false','LOG_CHANNEL'=>'stderr',
            'CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync',
            'DB_CONNECTION'=>'pgsql','DB_HOST'=>'127.0.0.1','DB_PORT'=>$port,'DB_DATABASE'=>$database,
            'DB_USERNAME'=>'portal_native_test_bootstrap','DB_PASSWORD'=>'',
        ];
        foreach (['LD_LIBRARY_PATH','PHPRC'] as $name) {
            if (is_string($value=getenv($name))) $environment[$name]=$value;
        }
        $command=[PHP_BINARY];
        if (is_string($ini=php_ini_loaded_file())) array_push($command,'-c',$ini);
        array_push($command,'artisan','migrate','--force','--no-interaction','--no-ansi');
        $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$source.'/apps/sites',$environment);
        if (!is_resource($process)) throw new RuntimeException('Cannot run pinned Sites migrations.');
        fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);
        Assert::assertSame(0,proc_close($process),'Pinned real Sites migrations failed: '.$stdout.$stderr);
        $connect=static function(string $role)use($port,$database):PDO {
            return new PDO('pgsql:host=127.0.0.1;port='.$port.';dbname='.$database,$role,'',[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STRINGIFY_FETCHES=>false,
            ]);
        };
        $sites=$connect('portal_native_test_bootstrap');
        Assert::assertSame($portal->query('SHOW data_directory')->fetchColumn(),$sites->query('SHOW data_directory')->fetchColumn());
        Assert::assertSame(count(glob($source.'/apps/sites/database/migrations/*.php')),
            (int)$sites->query('SELECT count(*) FROM migrations')->fetchColumn());
        Assert::assertSame(0,(int)$sites->query('SELECT count(*) FROM sites_core.site_projects')->fetchColumn());

        // Explicit fixture capability, scoped to the SAME Portal organization reference.
        // No production role/grant/tenant is inferred or installed by this test.
        $tenant=$sites->query('SELECT gen_random_uuid()')->fetchColumn();
        $sites->prepare('INSERT INTO sites_core.tenant_accounts(tenant_id,portal_org_ref) VALUES(?::uuid,?)')
            ->execute([$tenant,$order->companyReference]);
        $sites->exec('CREATE ROLE portal_sites_pair_receiver LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT');
        $sites->prepare('INSERT INTO sites_core.db_login_bindings(login_name,tenant_id,active,can_receive_project,can_read) VALUES(?,?::uuid,true,true,false)')
            ->execute(['portal_sites_pair_receiver',$tenant]);
        $sites->exec('GRANT CONNECT ON DATABASE portal_sites_pair_ci TO portal_sites_pair_receiver');
        $sites->exec('GRANT USAGE ON SCHEMA sites_core TO portal_sites_pair_receiver');
        $sites->exec('GRANT EXECUTE ON FUNCTION sites_core.record_portal_project_request(text,text,text,text,text,integer,text,text) TO portal_sites_pair_receiver');

        require_once $source.'/src/Integration/SiteProjectCommand.php';
        require_once $source.'/apps/sites/app/Integration/PortalProjectIntakeRecorder.php';
        $contracts=new PostgresPortalSiteContractSource($orders,$reference);
        $wire=(new SitesCreateProjectEncoder($contracts))->build($order->id,'native-pair-operation-'.$order->id,'native-pair-idempotency-'.$order->id);
        Assert::assertSame($order->companyReference,$wire['tenantRef']);
        Assert::assertNotSame($order->companyId,$wire['tenantRef']);
        Assert::assertNotSame($tenant,$wire['tenantRef']);
        Assert::assertSame($order->id,$wire['orderRef']);
        Assert::assertSame($order->contractRef,$wire['contractRef']);
        Assert::assertSame($order->contractRevision,$wire['contractRevision']);
        $command=\Imagine\Sites\Integration\SiteProjectCommand::fromArray($wire);
        $record=static function(array $payload)use($connect,$database):array {
            $db=new PostgresConnection($connect('portal_sites_pair_receiver'),$database,'',['driver'=>'pgsql']);
            return (new \App\Integration\PortalProjectIntakeRecorder($db))
                ->record(\Imagine\Sites\Integration\SiteProjectCommand::fromArray($payload));
        };
        $receipt=$record($wire);
        Assert::assertSame([
            'schemaVersion','tenantRef','orderRef','contractRef','contractRevision','productCode','operationId','idempotencyKey',
            'siteRef','commandDigest','status','state','authorityVerified','clientAccepted','canPublish',
        ],array_keys($receipt));
        Assert::assertSame('sites.project-intake.receipt.v1',$receipt['schemaVersion']);
        foreach (['tenantRef','orderRef','contractRef','contractRevision','productCode','operationId','idempotencyKey'] as $field) {
            Assert::assertSame($wire[$field],$receipt[$field]);
        }
        Assert::assertMatchesRegularExpression('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D',$receipt['siteRef']);
        Assert::assertNotSame($order->id,$receipt['siteRef']);
        Assert::assertSame($command->semanticFingerprint(),$receipt['commandDigest']);
        Assert::assertSame('recorded',$receipt['status']);
        Assert::assertSame('awaiting_official_authority',$receipt['state']);
        foreach (['authorityVerified','clientAccepted','canPublish'] as $field) Assert::assertFalse($receipt[$field]);
        Assert::assertSame($receipt,$record($wire),'Exact replay over a NEW PDO connection preserves the committed siteRef.');
        foreach ([['operationId'=>'another-operation'],['idempotencyKey'=>'another-key'],
            ['contractRevision'=>$order->contractRevision+1],['orderRef'=>'another-order']] as $difference) {
            try {$record(array_replace($wire,$difference));Assert::fail('Expected native Sites identity conflict.');}
            catch(DomainException $error) {
                Assert::assertSame('Sites project intake conflict.',$error->getMessage());Assert::assertNull($error->getPrevious());
            }
        }
        try {$record(array_replace($wire,['tenantRef'=>$order->companyId]));Assert::fail('UUID must not substitute for the Portal reference.');}
        catch(DomainException $error){Assert::assertSame('Sites project intake unavailable.',$error->getMessage());}
        foreach (['contract_refs','site_projects','portal_project_intakes','site_outbox'] as $table) {
            Assert::assertSame(1,(int)$sites->query('SELECT count(*) FROM sites_core.'.$table)->fetchColumn());
        }
        $saved=$sites->query('SELECT portal_order_ref,command_digest,authority_verified,publishable,lifecycle_state FROM sites_core.site_projects')->fetch(PDO::FETCH_ASSOC);
        Assert::assertSame($order->id,$saved['portal_order_ref']);Assert::assertSame($receipt['commandDigest'],$saved['command_digest']);
        Assert::assertFalse($saved['authority_verified']);Assert::assertFalse($saved['publishable']);
        Assert::assertSame('awaiting_official_authority',$saved['lifecycle_state']);
        Assert::assertSame('blocked',$sites->query('SELECT delivery_state FROM sites_core.site_outbox')->fetchColumn());
        Assert::assertSame('pending_payment',$portal->query('SELECT status FROM portal_onepage.pending_orders')->fetchColumn());
    }
}
