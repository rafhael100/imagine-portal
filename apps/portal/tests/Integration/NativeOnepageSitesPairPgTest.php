<?php
declare(strict_types=1);
namespace Tests\Integration;

use App\Domain\Commerce\OnepageAcceptedOrder;
use App\Infrastructure\Commerce\PostgresOnepageOrders;
use App\Domain\SitesIntegration\ApprovedOnepageBriefSnapshot;
use App\Domain\SitesIntegration\ApprovedOnepageBriefSnapshotSource;
use App\Domain\SitesIntegration\CurrentOnepageBriefApprovalWitness;
use App\Domain\SitesIntegration\DenyingCurrentOnepageBriefApprovalWitness;
use App\Domain\SitesIntegration\OnepageApprovedBriefExportGate;
use App\Domain\SitesIntegration\SiteContractSnapshot;
use App\Infrastructure\Commerce\PostgresPortalSiteContractSource;
use PDO;
use PDOException;

/** Runs the original native order/PSP-ledger chain plus the pinned REAL Sites receiver. */
final class NativeOnepageSitesPairPgTest extends NativeOnepageOrdersPgTest
{
    private ?string $admittedSiteRef = null;
    private ?PDO $briefFixtureBootstrap = null;
    private array $confirmedRightsHistory = [];
    private ?\Closure $revokeThroughPrivateRead = null;

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
        // R32: the same publisher cannot even read private buyer evidence.
        $readSql='SELECT portal_brief_rights_lab.read_current_rights(?,?::uuid,?::uuid,?::integer,?)';
        try {
            $publisher->prepare($readSql)->execute($args);
            self::fail('Commercial publisher must not read private buyer evidence.');
        } catch (PDOException $error) {
            self::assertSame('42501',$error->getCode());
        }
        $reader=$this->connectRightsFixture('portal_onepage_runtime');
        $read=$reader->prepare($readSql);
        $read->execute($args);
        $evidence=json_decode((string)$read->fetchColumn(),true,16,JSON_THROW_ON_ERROR);
        $read->closeCursor();
        self::assertSame($current['receipt_id'],$evidence['receipt_id']);
        self::assertSame($current['bytes_hex'],$evidence['bytes_hex']);
        self::assertSame($current['source_sha'],$evidence['source_sha']);
        self::assertSame($this->confirmedRightsHistory,$this->readRightsHistory($this->briefFixtureBootstrap));
        // R37: exercise the existing export gate with actual persisted buyer
        // evidence, rather than an unrelated marker or a denying source only.
        // Deliberately mislabel the buyer receipt as an approval in a TEST
        // double. This is an adversarial substitution, never an official adapter.
        $contracts = new PostgresPortalSiteContractSource($orders,$reference);
        $contract = $contracts->findAuthorizedSiteOrder($order->id);
        self::assertInstanceOf(SiteContractSnapshot::class,$contract);
        // R41: independent, reusable buyer-evidence reader, not an approved
        // source. Validate the persisted envelope against the actual order.
        $rightsReader=new NativeOnepageBuyerRightsLabReader($reader);
        $rights=$rightsReader->read($order,$reference,$this->admittedSiteRef,(int)$current['version'],$current['source_sha']);
        self::assertSame($evidence,$rights);
        foreach ([
            ['buyer'=>'00000000-0000-0000-0000-000000000000'],
            ['company'=>'00000000-0000-0000-0000-000000000000'],
            ['order_id'=>'00000000-0000-0000-0000-000000000000'],
            ['site_id'=>'00000000-0000-0000-0000-000000000000'],
            ['version'=>(string)$current['version']],
            ['source_sha'=>str_repeat('0',64)],
            ['bytes_hex'=>bin2hex('changed synthetic bytes')],
            ['rights_declared'=>false],
            ['receipt_id'=>'not-a-uuid'],
            ['approvalRef'=>'not-buyer-evidence'],
        ] as $drift) {
            self::assertNull(NativeOnepageBuyerRightsLabReader::validate(
                array_replace($rights,$drift),$order,$this->admittedSiteRef,(int)$current['version'],$current['source_sha']));
        }
        foreach ($deniedReferences as $deniedReference) {
            self::assertNull($rightsReader->read($order,$deniedReference,$this->admittedSiteRef,(int)$current['version'],$current['source_sha']));
        }
        self::assertNull($rightsReader->read($order,$reference,$this->admittedSiteRef,1,$current['source_sha']));
        self::assertNull($rightsReader->read($order,$reference,$this->admittedSiteRef,(int)$current['version'],str_repeat('0',64)));
        self::assertSame($this->confirmedRightsHistory,$this->readRightsHistory($this->briefFixtureBootstrap));
        // Keep the adversarial approval substitution strictly inside this test.
        $readCandidate = new class($rightsReader,$order,$reference,$contract,$current['source_sha']) implements ApprovedOnepageBriefSnapshotSource {
            public int $reads=0;
            public ?ApprovedOnepageBriefSnapshot $last=null;
            public function __construct(private NativeOnepageBuyerRightsLabReader $reader,
                private OnepageAcceptedOrder $order,private string $reference,
                private SiteContractSnapshot $contract,private string $digest) {}
            public function readExact(string $orderRef,string $siteRef,int $sourceVersion): ?ApprovedOnepageBriefSnapshot
            {
                ++$this->reads;
                $this->last=null;
                if ($orderRef!==$this->order->id) return null;
                $rights=$this->reader->read($this->order,$this->reference,$siteRef,$sourceVersion,$this->digest);
                if ($rights===null) return null;
                // The buyer receipt is deliberately NOT an editorial approval.
                return $this->last=new ApprovedOnepageBriefSnapshot($this->contract,[
                    'siteRef'=>$siteRef,'sourceVersion'=>$sourceVersion,
                    'snapshotRef'=>$rights['receipt_id'],'operationId'=>'r37-negative-only',
                    'idempotencyKey'=>'r37-negative-only','expectedBriefRevision'=>0,
                    'payloadRef'=>'r37-negative-only',
                ],hex2bin($rights['bytes_hex']),$rights['source_sha'],$rights['receipt_id'],1);
            }
        };
        $witness = new class implements CurrentOnepageBriefApprovalWitness {
            public int $reads=0;
            public function isCurrent(ApprovedOnepageBriefSnapshot $brief): bool
            {
                ++$this->reads;
                return (new DenyingCurrentOnepageBriefApprovalWitness())->isCurrent($brief);
            }
        };
        $denyExport = static function(OnepageApprovedBriefExportGate $gate,string $orderRef,string $siteRef,int $version):void {
            try {
                $gate->prepare($orderRef,$siteRef,$version);
                self::fail('Buyer rights alone must never export an approved briefing.');
            } catch (\RuntimeException $error) {
                self::assertSame('ONEPAGE_APPROVED_BRIEF_EXPORT_DENIED',$error->getMessage());
                self::assertNull($error->getPrevious());
            }
        };
        $gate=new OnepageApprovedBriefExportGate($contracts,$readCandidate,$witness);
        $denyExport($gate,$order->id,$this->admittedSiteRef,(int)$current['version']);
        self::assertSame(1,$readCandidate->reads);
        self::assertSame(1,$witness->reads,'Exact persisted bytes must reach the independent denying witness.');
        self::assertInstanceOf(ApprovedOnepageBriefSnapshot::class,$readCandidate->last);
        self::assertSame($current['receipt_id'],$readCandidate->last->approvalRef);
        self::assertSame($current['source_sha'],$readCandidate->last->sourceSha256);
        self::assertSame($current['bytes_hex'],bin2hex($readCandidate->last->sourceBytes));
        $denyExport($gate,$order->id,$this->admittedSiteRef,1);
        self::assertSame(2,$readCandidate->reads);
        self::assertNull($readCandidate->last);
        self::assertSame(1,$witness->reads,'Superseded rights cannot reach editorial verification.');
        foreach ($deniedReferences as $deniedReference) {
            $foreignGate=new OnepageApprovedBriefExportGate(
                new PostgresPortalSiteContractSource($orders,$deniedReference),$readCandidate,$witness);
            $denyExport($foreignGate,$order->id,$this->admittedSiteRef,(int)$current['version']);
        }
        self::assertSame(2,$readCandidate->reads,'Unauthorized buyers cannot reach the private source.');
        self::assertSame(1,$witness->reads);
        self::assertSame($this->confirmedRightsHistory,$this->readRightsHistory($this->briefFixtureBootstrap));
        // R38: compose the two previously separate observations on the same
        // order: pinned Sites receipt reader and persisted Portal buyer rights.
        // The receipt-reader login already exists in this disposable test.
        // Its temporary activation is a fixture control, not a new grant.
        self::assertSame('DISPOSABLE_PG18',getenv('PORTAL_NATIVE_ORDER_TEST'));
        $port=(string)getenv('PORTAL_NATIVE_ORDER_TEST_PORT');
        self::assertMatchesRegularExpression('/\A[0-9]{4,5}\z/D',$port);
        $connectSites=static function(string $role)use($port):PDO {
            return new PDO('pgsql:host=127.0.0.1;port='.$port.';dbname=portal_sites_pair_ci;connect_timeout=3',
                $role,'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STRINGIFY_FETCHES=>false]);
        };
        $sitesBootstrap=$connectSites('portal_native_test_bootstrap');
        self::assertSame('portal_native_test_bootstrap',$sitesBootstrap->query('SELECT session_user')->fetchColumn());
        self::assertSame('portal_sites_pair_ci',$sitesBootstrap->query('SELECT current_database()')->fetchColumn());
        self::assertSame(getenv('PORTAL_NATIVE_ORDER_TEST_DATA'),$sitesBootstrap->query('SHOW data_directory')->fetchColumn());
        $role='portal_sites_pair_receipt_reader';
        $binding=$sitesBootstrap->prepare('SELECT active FROM sites_core.db_login_bindings WHERE login_name=?');
        $binding->execute([$role]);
        self::assertFalse($binding->fetchColumn(),'R27/R35 must have restored revocation.');
        $binding->closeCursor();
        $wire=(new \App\Domain\SitesIntegration\SitesCreateProjectEncoder($contracts))->build(
            $order->id,'native-pair-operation-'.$order->id,'native-pair-idempotency-'.$order->id);
        $command=\Imagine\Sites\Integration\SiteProjectCommand::fromArray($wire);
        $admissionReads=0;
        $readAdmission=static function(SiteContractSnapshot $c)use($connectSites,$role,$command,&$admissionReads):?string {
            if ($c->tenantRef!==$command->tenantRef || $c->orderRef!==$command->orderRef ||
                $c->contractRef!==$command->contractRef || $c->contractRevision!==$command->contractRevision ||
                $c->productCode!==$command->productCode) return null;
            ++$admissionReads;
            $pdo=$connectSites($role);
            self::assertSame($role,$pdo->query('SELECT session_user')->fetchColumn());
            self::assertSame('on',$pdo->query('SHOW default_transaction_read_only')->fetchColumn());
            $db=new \Illuminate\Database\PostgresConnection($pdo,'portal_sites_pair_ci','',['driver'=>'pgsql']);
            $receipt=(new \App\Integration\StoredProjectIntakeReceiptReader($db))->readExact($command);
            return $receipt['siteRef'];
        };
        $setBinding=$sitesBootstrap->prepare('UPDATE sites_core.db_login_bindings SET active=?::boolean WHERE login_name=?');
        $bound=new \App\Domain\SitesIntegration\AdmissionBoundOnepageBriefSnapshotSource($contracts,$readAdmission,$readCandidate);
        $boundGate=new OnepageApprovedBriefExportGate($contracts,$bound,$witness);
        $beforeRights=$readCandidate->reads;
        $beforeWitness=$witness->reads;
        try {
            $setBinding->execute(['true',$role]);
            self::assertSame($this->admittedSiteRef,$readAdmission($contract));
            $denyExport($boundGate,$order->id,$this->admittedSiteRef,(int)$current['version']);
            self::assertSame(3,$admissionReads,'Independent admission must be read before and after the real rights source.');
            self::assertSame($beforeRights+1,$readCandidate->reads);
            self::assertSame($beforeWitness+1,$witness->reads,'The real rights still cannot replace editorial authority.');
            self::assertSame($current['bytes_hex'],bin2hex($readCandidate->last->sourceBytes));
            $foreignSite=$sitesBootstrap->query('SELECT gen_random_uuid()')->fetchColumn();
            $denyExport($boundGate,$order->id,$foreignSite,(int)$current['version']);
            self::assertSame(4,$admissionReads);
            self::assertSame($beforeRights+1,$readCandidate->reads,'Wrong site must not reach persisted rights.');
            $setBinding->execute(['false',$role]);
            $denyExport($boundGate,$order->id,$this->admittedSiteRef,(int)$current['version']);
            self::assertSame(5,$admissionReads);
            self::assertSame($beforeRights+1,$readCandidate->reads,'Revoked admission reader must not disclose persisted rights.');
            self::assertSame($beforeWitness+1,$witness->reads);
            // R39: use actual persisted rights (not the R27 marker) and
            // commit admission revocation after the private SQL read returns.
            // The seam must suppress the bytes BEFORE editorial verification.
            $setBinding->execute(['true',$role]);
            $positive=$bound->readExact($order->id,$this->admittedSiteRef,(int)$current['version']);
            self::assertInstanceOf(ApprovedOnepageBriefSnapshot::class,$positive);
            self::assertSame($current['source_sha'],$positive->sourceSha256);
            self::assertSame($current['bytes_hex'],bin2hex($positive->sourceBytes));
            $revokingSource=new class($readCandidate,$setBinding,$role) implements ApprovedOnepageBriefSnapshotSource {
                public int $calls=0;
                public bool $readSucceeded=false;
                public function __construct(private ApprovedOnepageBriefSnapshotSource $source,
                    private \PDOStatement $binding,private string $role) {}
                public function readExact(string $orderRef,string $siteRef,int $sourceVersion):?ApprovedOnepageBriefSnapshot
                {
                    ++$this->calls;
                    $candidate=$this->source->readExact($orderRef,$siteRef,$sourceVersion);
                    $this->readSucceeded=$candidate instanceof ApprovedOnepageBriefSnapshot;
                    $this->binding->execute(['false',$this->role]);
                    return $candidate;
                }
            };
            $raceBound=new \App\Domain\SitesIntegration\AdmissionBoundOnepageBriefSnapshotSource(
                $contracts,$readAdmission,$revokingSource);
            $raceGate=new OnepageApprovedBriefExportGate($contracts,$raceBound,$witness);
            $readsBeforeRace=$admissionReads;
            $rightsBeforeRace=$readCandidate->reads;
            $witnessBeforeRace=$witness->reads;
            $denyExport($raceGate,$order->id,$this->admittedSiteRef,(int)$current['version']);
            self::assertTrue($revokingSource->readSucceeded,'Persisted bytes were actually read before revocation.');
            self::assertSame(1,$revokingSource->calls);
            self::assertSame($rightsBeforeRace+1,$readCandidate->reads);
            self::assertSame($readsBeforeRace+2,$admissionReads,'Second independent receipt read must observe revocation.');
            self::assertSame($witnessBeforeRace,$witness->reads,'Revoked admission must not expose bytes to editorial verification.');
            $binding->execute([$role]);
            self::assertFalse($binding->fetchColumn());
            $binding->closeCursor();
            // A second request after revocation must stop before private SQL.
            $denyExport($raceGate,$order->id,$this->admittedSiteRef,(int)$current['version']);
            self::assertSame(1,$revokingSource->calls);
            self::assertSame($rightsBeforeRace+1,$readCandidate->reads);
            self::assertSame($witnessBeforeRace,$witness->reads);
            // Control: the Portal buyer still owns the same exact rights.
            // The denial above is due to the independent Sites boundary.
            $read->execute($args);
            self::assertSame($evidence,json_decode((string)$read->fetchColumn(),true,16,JSON_THROW_ON_ERROR));
            $read->closeCursor();
        } finally {
            $setBinding->execute(['false',$role]);
        }
        self::assertSame($this->confirmedRightsHistory,$this->readRightsHistory($this->briefFixtureBootstrap));
        self::assertSame(1,(int)$sitesBootstrap->query('SELECT count(*) FROM sites_core.portal_project_intakes')->fetchColumn());
        self::assertSame('blocked',$sitesBootstrap->query('SELECT delivery_state FROM sites_core.site_outbox')->fetchColumn());
        self::assertFalse($sitesBootstrap->query('SELECT publishable FROM sites_core.site_projects')->fetchColumn());
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
        // R33: the independent read exposes bytes, unlike confirm_exact.
        // Exercise its own transaction boundary, not a replay of the R30 writer.
        $reader->exec("SET lock_timeout='250ms'");
        $publisher->exec("SET lock_timeout='250ms'");
        try {
            $reader->beginTransaction();
            $read->execute($args);
            self::assertSame($evidence,json_decode((string)$read->fetchColumn(),true,16,JSON_THROW_ON_ERROR));
            $read->closeCursor();
            try {
                $publisher->prepare('SELECT portal_onepage.revoke_contract(?)')->execute([$publicationRef]);
                self::fail('Revocation must serialize with the independent byte disclosure.');
            } catch (PDOException $error) {
                self::assertSame('55P03',$error->getCode());
            }
            $reader->rollBack();
            $publisher->beginTransaction();
            $revoke=$publisher->prepare('SELECT portal_onepage.revoke_contract(?)');
            $revoke->execute([$publicationRef]);
            self::assertTrue($revoke->fetchColumn());
            $revoke->closeCursor();
            try {
                $read->execute($args);
                self::fail('Independent read must not disclose bytes during uncommitted revocation.');
            } catch (PDOException $error) {
                self::assertSame('55P03',$error->getCode());
            }
            $publisher->rollBack();
            // Reject stale transaction snapshots rather than trusting their bytes.
            foreach (['REPEATABLE READ','SERIALIZABLE'] as $isolation) {
                $reader->beginTransaction();
                $reader->exec('SET TRANSACTION ISOLATION LEVEL '.$isolation);
                $read->execute($args);
                self::assertNull($read->fetchColumn());
                $read->closeCursor();
                $reader->rollBack();
            }
            $read->execute($args);
            self::assertSame($evidence,json_decode((string)$read->fetchColumn(),true,16,JSON_THROW_ON_ERROR));
            $read->closeCursor();
        } finally {
            if ($reader->inTransaction()) $reader->rollBack();
            if ($publisher->inTransaction()) $publisher->rollBack();
            $reader->exec("SET lock_timeout='0'");
            $publisher->exec("SET lock_timeout='0'");
        }
        self::assertSame($this->confirmedRightsHistory,$this->readRightsHistory($this->connectRightsFixture('portal_native_test_bootstrap')));
        // The fixture receipt cannot install an approved source or an editorial witness.
        NativeOnepageSitesPairAssertions::assertBriefBoundaryDenied($orders,$order,$reference,$this->admittedSiteRef,true);
        foreach ($deniedReferences as $deniedReference) {
            NativeOnepageSitesPairAssertions::assertBriefBoundaryDenied($orders,$order,$deniedReference,$this->admittedSiteRef,false);
        }
        // R40: reuse the parent's terminal native revocation, at the actual
        // persisted byte-read boundary, after all other accepted-order tests.
        // Unlike R39, the independent Sites receipt remains readable.
        $this->revokeThroughPrivateRead = function(\Closure $revoke) use (
            $contracts,$readCandidate,$readAdmission,$witness,$denyExport,$order,$current,
            $setBinding,$role,$sitesBootstrap,$contract,&$admissionReads
        ): void {
            $setBinding->execute(['true',$role]);
            try {
                $source = new class($readCandidate,$revoke) implements ApprovedOnepageBriefSnapshotSource {
                    public ?ApprovedOnepageBriefSnapshot $observed=null;
                    public int $calls=0;
                    public function __construct(private ApprovedOnepageBriefSnapshotSource $source,private \Closure $revoke) {}
                    public function readExact(string $orderRef,string $siteRef,int $sourceVersion):?ApprovedOnepageBriefSnapshot
                    {
                        ++$this->calls;
                        $this->observed=$this->source->readExact($orderRef,$siteRef,$sourceVersion);
                        if ($this->observed !== null) ($this->revoke)();
                        return $this->observed;
                    }
                };
                $bound = new \App\Domain\SitesIntegration\AdmissionBoundOnepageBriefSnapshotSource(
                    $contracts,$readAdmission,$source);
                $gate = new OnepageApprovedBriefExportGate($contracts,$bound,$witness);
                $beforeAdmission=$admissionReads;
                $beforeWitness=$witness->reads;
                $beforeRights=$readCandidate->reads;
                $denyExport($gate,$order->id,$this->admittedSiteRef,(int)$current['version']);
                self::assertInstanceOf(ApprovedOnepageBriefSnapshot::class,$source->observed);
                self::assertSame($current['bytes_hex'],bin2hex($source->observed->sourceBytes));
                self::assertSame($current['source_sha'],$source->observed->sourceSha256);
                self::assertNull($contracts->findAuthorizedSiteOrder($order->id),'Native revocation must have committed.');
                self::assertSame($beforeAdmission+1,$admissionReads,'Revoked native authority must stop before the second Sites receipt read.');
                self::assertSame($beforeWitness,$witness->reads);
                self::assertSame($beforeRights+1,$readCandidate->reads);
                self::assertSame(1,$source->calls);
                // The historical admission is still readable independently:
                // its existence does not override the native revocation.
                self::assertSame($this->admittedSiteRef,$readAdmission($contract));
                $denyExport($gate,$order->id,$this->admittedSiteRef,(int)$current['version']);
                self::assertSame($beforeAdmission+2,$admissionReads);
                self::assertSame(1,$source->calls);
                self::assertSame($beforeWitness,$witness->reads);
                self::assertSame('blocked',$sitesBootstrap->query('SELECT delivery_state FROM sites_core.site_outbox')->fetchColumn());
                self::assertFalse($sitesBootstrap->query('SELECT publishable FROM sites_core.site_projects')->fetchColumn());
            } finally {
                $setBinding->execute(['false',$role]);
            }
        };
    }

    protected function revokeAcceptedContract(PDO $publisher): void
    {
        self::assertInstanceOf(\Closure::class,$this->revokeThroughPrivateRead);
        ($this->revokeThroughPrivateRead)(function() use ($publisher):void {
            parent::revokeAcceptedContract($publisher);
        });
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
        // Separate, read-only operation must also deny after native revocation;
        // no historic bytes may escape even though the receipt still exists.
        $reader=$this->connectRightsFixture('portal_onepage_runtime');
        $read=$reader->prepare('SELECT portal_brief_rights_lab.read_current_rights(?,?::uuid,?::uuid,?::integer,?)');
        foreach ($this->confirmedRightsHistory as $row) {
            $read->execute([hash('sha256',$reference),$order->id,$this->admittedSiteRef,(int)$row['version'],$row['source_sha']]);
            self::assertNull($read->fetchColumn());
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
