<?php
declare(strict_types=1);
namespace Tests\Integration;

use App\Domain\Commerce\OnepageAcceptedOrder;
use App\Infrastructure\Commerce\PostgresOnepageOrders;
use PDO;
use PDOException;

/** Runs the original native order/PSP-ledger chain plus the pinned REAL Sites receiver. */
final class NativeOnepageSitesPairPgTest extends NativeOnepageOrdersPgTest
{
    private ?string $admittedSiteRef = null;
    private ?PDO $briefFixtureBootstrap = null;
    private array $confirmedRightsHistory = [];

    protected function afterAcceptedOrder(PDO $bootstrap,PostgresOnepageOrders $orders,OnepageAcceptedOrder $order,string $reference):void
    {
        $this->briefFixtureBootstrap = $bootstrap;
        $this->admittedSiteRef = NativeOnepageSitesPairAssertions::run($bootstrap,$orders,$order,$reference);
    }

    protected function afterAcceptedOrderAccessCases(PostgresOnepageOrders $orders, OnepageAcceptedOrder $order, string $reference, array $deniedReferences): void
    {
        self::assertNotNull($this->admittedSiteRef);
        NativeOnepageSitesPairAssertions::assertBriefBoundaryDenied($orders,$order,$reference,$this->admittedSiteRef,true);
        self::assertNotNull($this->briefFixtureBootstrap);
        NativeOnepageBriefRightsPgAssertions::run($this->briefFixtureBootstrap,$order,$this->admittedSiteRef,$reference,$deniedReferences);
        // R29: retain the actual persisted R28 receipts before native revocation.
        $this->confirmedRightsHistory = $this->readRightsHistory($this->briefFixtureBootstrap);
        self::assertCount(2,$this->confirmedRightsHistory);
        $current = $this->confirmedRightsHistory[1];
        $runtime = $this->connectRightsFixture('portal_onepage_runtime');
        $publisher = $this->connectRightsFixture('portal_onepage_publisher');
        // Commercial contract publication is NOT buyer rights or editorial authority.
        self::assertSame('portal_onepage_publisher',$publisher->query('SELECT session_user')->fetchColumn());
        $args=[hash('sha256',$reference),$order->id,$this->admittedSiteRef,(int)$current['version'],$current['source_sha']];
        $sql='SELECT portal_brief_rights_lab.confirm_exact(?,?::uuid,?::uuid,?::integer,?,true)';
        try {
            $publisher->prepare($sql)->execute($args);
            self::fail('Commercial publisher must not invoke buyer rights confirmation.');
        } catch (PDOException $error) {
            self::assertSame('42501',$error->getCode());
        }
        // Control: the same exact replay remains available to the buyer before revocation.
        $query=$runtime->prepare($sql);
        $query->execute($args);
        self::assertSame($current['receipt_id'],$query->fetchColumn());
        self::assertSame($this->confirmedRightsHistory,$this->readRightsHistory($this->briefFixtureBootstrap));
        // R30: exercise both orders of the actual native contract lock through
        // the rights fixture entry, not merely the underlying native reader.
        $publication=$this->briefFixtureBootstrap->prepare('SELECT publication_ref FROM portal_onepage.pending_orders WHERE id=?::uuid');
        $publication->execute([$order->id]);
        $publicationRef=$publication->fetchColumn();
        self::assertIsString($publicationRef);
        $runtime->exec("SET lock_timeout='250ms'");
        $publisher->exec("SET lock_timeout='250ms'");
        try {
            $runtime->beginTransaction();
            $query->execute($args);
            self::assertSame($current['receipt_id'],$query->fetchColumn());
            $query->closeCursor();
            try {
                $publisher->prepare('SELECT portal_onepage.revoke_contract(?)')->execute([$publicationRef]);
                self::fail('Native revocation must not pass the rights transaction lock.');
            } catch (PDOException $error) {
                self::assertSame('55P03',$error->getCode());
            }
            $runtime->rollBack();
            // Invert the ordering: an uncommitted native revocation owns the
            // publication lock. A rights replay must not return a stale receipt.
            $publisher->beginTransaction();
            $revoke=$publisher->prepare('SELECT portal_onepage.revoke_contract(?)');
            $revoke->execute([$publicationRef]);
            self::assertTrue($revoke->fetchColumn());
            $revoke->closeCursor();
            try {
                $query->execute($args);
                self::fail('Rights replay must not pass the in-flight native revocation.');
            } catch (PDOException $error) {
                self::assertSame('55P03',$error->getCode());
            }
            $publisher->rollBack();
        } finally {
            if ($runtime->inTransaction()) $runtime->rollBack();
            if ($publisher->inTransaction()) $publisher->rollBack();
            $runtime->exec("SET lock_timeout='0'");
            $publisher->exec("SET lock_timeout='0'");
        }
        // Rollback leaves the exact current receipt usable and history intact;
        // the parent subsequently commits the actual revocation (R29).
        $query=$runtime->prepare($sql);
        $query->execute($args);
        self::assertSame($current['receipt_id'],$query->fetchColumn());
        $query->closeCursor();
        self::assertSame($this->confirmedRightsHistory,$this->readRightsHistory($this->connectRightsFixture('portal_native_test_bootstrap')));
        // The fixture receipt cannot install an approved source or an editorial witness.
        NativeOnepageSitesPairAssertions::assertBriefBoundaryDenied($orders,$order,$reference,$this->admittedSiteRef,true);
        foreach ($deniedReferences as $deniedReference) {
            NativeOnepageSitesPairAssertions::assertBriefBoundaryDenied($orders,$order,$deniedReference,$this->admittedSiteRef,false);
        }
    }

    protected function afterRevokedOrder(PostgresOnepageOrders $orders, OnepageAcceptedOrder $order, string $reference): void
    {
        self::assertNotNull($this->admittedSiteRef);
        NativeOnepageSitesPairAssertions::assertBriefBoundaryDenied($orders,$order,$reference,$this->admittedSiteRef,false);
        // The parent has committed the original native contract revocation.
        // Use a fresh connection and the same previously successful selector.
        self::assertCount(2,$this->confirmedRightsHistory);
        self::assertNull($orders->findOwnedAcceptedOrder($reference,$order->id));
        $runtime=$this->connectRightsFixture('portal_onepage_runtime');
        $query=$runtime->prepare('SELECT portal_brief_rights_lab.confirm_exact(?,?::uuid,?::uuid,?::integer,?,true)');
        foreach ($this->confirmedRightsHistory as $row) {
            $query->execute([hash('sha256',$reference),$order->id,$this->admittedSiteRef,(int)$row['version'],$row['source_sha']]);
            self::assertNull($query->fetchColumn(),'Revoked native contract must deny even exact rights receipt replay.');
        }
        // Historical bytes/receipts remain intact, but they are not current authorization.
        $observer=$this->connectRightsFixture('portal_native_test_bootstrap');
        self::assertSame($this->confirmedRightsHistory,$this->readRightsHistory($observer));
        self::assertSame(1,(int)$observer->query('SELECT count(*) FROM portal_onepage.pending_orders')->fetchColumn());
    }

    private function connectRightsFixture(string $role): PDO
    {
        self::assertSame('DISPOSABLE_PG18',getenv('PORTAL_NATIVE_ORDER_TEST'));
        $port=(string)getenv('PORTAL_NATIVE_ORDER_TEST_PORT');
        self::assertMatchesRegularExpression('/\\A[0-9]{4,5}\\z/D',$port);
        $pdo=new PDO('pgsql:host=127.0.0.1;port='.$port.';dbname=portal_native_orders_ci;connect_timeout=3',
            $role,'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STRINGIFY_FETCHES=>false]);
        self::assertSame($role,$pdo->query('SELECT session_user')->fetchColumn());
        self::assertSame('portal_native_orders_ci',$pdo->query('SELECT current_database()')->fetchColumn());
        self::assertNotNull($this->briefFixtureBootstrap);
        self::assertSame($this->briefFixtureBootstrap->query('SELECT host(inet_server_addr())')->fetchColumn(),$pdo->query('SELECT host(inet_server_addr())')->fetchColumn());
        if ($role==='portal_native_test_bootstrap') {
            self::assertSame(getenv('PORTAL_NATIVE_ORDER_TEST_DATA'),$pdo->query('SHOW data_directory')->fetchColumn());
        }
        return $pdo;
    }

    private function readRightsHistory(PDO $observer): array
    {
        return $observer->query("SELECT c.receipt_id::text,c.order_id::text,c.site_id::text,c.company::text,c.buyer::text,c.version,c.source_sha,c.rights_declared,encode(v.source_bytes,'hex') AS bytes_hex,v.contract_ref,v.contract_revision
            FROM portal_brief_rights_lab.confirmations c JOIN portal_brief_rights_lab.versions v USING(order_id,site_id,version,source_sha,company,buyer) ORDER BY c.version")->fetchAll(PDO::FETCH_ASSOC);
    }
}
