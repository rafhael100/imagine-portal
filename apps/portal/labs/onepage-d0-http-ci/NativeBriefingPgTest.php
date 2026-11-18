<?php
declare(strict_types=1);
namespace Tests\Integration;

use App\Domain\Identity\Security\PortalPasswordCredential;
use App\Domain\Identity\Security\PortalTotpSecretCipher;
use App\Homologation\Commerce\ApprovedOfferCatalog;
use App\Infrastructure\Commerce\PostgresOnepageOrders;
use App\Infrastructure\Identity\PostgresPortalBrowserAuthentication;
use Illuminate\Support\Facades\Artisan;
use PDO;
use PDOException;
use Tests\TestCase;
use NativeBriefingFixture\Trace;

/** Product integration against a fresh, explicitly requested local PG18 cluster. */
class NativeBriefingPgTest extends TestCase
{
    private readonly PDO $admin;
    private array $cluster;
    private int $port;
    private string $proof;
    private string $key;
    private string $pepper;

    private function connect(string $role):PDO
    {
        return new PDO('pgsql:host=127.0.0.1;port='.$this->port.';dbname=portal_native_orders_ci;connect_timeout=3',
            $role,'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    }

    private function expectedServerAddress():string
    {
        self::assertSame('true',getenv('GITHUB_ACTIONS'));
        self::assertSame('REVIEWED_SINGLE_OPENED_PR',getenv('BRIEFING_CI_SCOPE'));
        return '127.0.0.1';
    }

    private function prepareDatabase():void
    {
        self::assertSame('DISPOSABLE_PG18',getenv('PORTAL_NATIVE_ORDER_TEST'));
        $port=getenv('PORTAL_NATIVE_ORDER_TEST_PORT');
        self::assertIsString($port);self::assertMatchesRegularExpression('/\A[0-9]{4,5}\z/D',$port);
        $this->port=(int)$port;
        $serverAddress=$this->expectedServerAddress();
        $admin=$this->connect('portal_native_test_bootstrap');
        config(['database.default'=>'pgsql','database.connections.pgsql'=>[
            'driver'=>'pgsql','host'=>'127.0.0.1','port'=>$this->port,'database'=>'portal_native_orders_ci',
            'username'=>'portal_native_test_bootstrap','password'=>'','charset'=>'utf8','prefix'=>'','search_path'=>'public','sslmode'=>'disable',
        ]]);
        $this->app['db']->purge('pgsql');
        $attestation=\NativeBriefingFixture\CiGate::beforeWrites('portal_native_orders_ci');
        $this->cluster=$attestation['identity'];
        Trace::pass('PASS BRIEFING_NEW_DATABASE_GATE_PASSED');
        Trace::enter('ADMIN_GATE');
        $this->admin=\NativeBriefingFixture\CiGate::adminBeforeWrites($admin,$attestation);
        Trace::pass('PASS BRIEFING_LARAVEL_SQL_BEFORE_DDL');
        Trace::enter('MIGRATIONS');
        foreach ([
            '2026_11_09_000002_create_portal_identity_foundation.php',
            '2026_11_09_000003_add_mfa_replay_counter_to_portal_identities.php',
            '2026_11_10_000004_add_org_revision_to_portal_sessions.php',
            '2026_11_10_000005_create_portal_auth_throttle_buckets.php',
            '2026_11_10_000008_add_session_purpose_to_portal_auth_sessions.php',
            '2026_11_11_000012_fence_portal_membership_revision.php',
            '2026_11_11_000013_fence_portal_organization_revision.php',
            '2026_11_11_000014_guard_portal_session_issuance.php',
            '2026_11_12_000016_fence_portal_identity_revision.php',
        ] as $migration) {
            \NativeBriefingFixture\CiGate::sameConnection($this->app['db']->connection('pgsql')->getPdo(),$attestation['pdo']);
            self::assertSame(0,Artisan::call('migrate',['--path'=>'database/migrations/'.$migration,'--force'=>true,'--no-interaction'=>true]));
        }
        Trace::pass('PASS BRIEFING_MIGRATIONS_COMPLETE');
        Trace::enter('SECURITY_DDL');
        foreach (['portal_browser_auth.sql','portal_browser_principal_candidate.sql','portal_onepage_orders.sql'] as $file) {
            $this->admin->exec(file_get_contents(database_path('security/'.$file)));
        }
        // These logins exist only inside this empty, independently identified disposable cluster.
        foreach (['portal_browser_runtime','portal_onepage_runtime','portal_onepage_publisher'] as $role) {
            $this->admin->exec('ALTER ROLE '.$role.' LOGIN');
        }
        $this->proof=bin2hex(random_bytes(32));$this->key=random_bytes(32);$this->pepper=bin2hex(random_bytes(32));
        $this->admin->prepare('INSERT INTO portal_browser_private.proof_key(secret) VALUES(?)')->execute([$this->proof]);
    }

    public function test_native_http_briefing_only():void
    {
        require_once __DIR__.'/Scenario.php';
        Trace::pass('PASS BRIEFING_TEST_ENTERED');Trace::enter('NEW_DATABASE_GATE');
        $this->prepareDatabase();
        Trace::pass('PASS BRIEFING_SCHEMA_COMPLETE');Trace::enter('FIXTURES');
        \NativeHttpBriefing\Scenario::run($this->app,$this->admin,$this->cluster,$this->port,
            $this->proof,$this->key,$this->pepper,fn(string $role):PDO=>$this->connect($role));
        Trace::pass('PASS BRIEFING_D0_HTTP_COMPLETE');Trace::enter('TEST_TEARDOWN');
    }
}
