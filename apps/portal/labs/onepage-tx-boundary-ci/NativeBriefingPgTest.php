<?php
declare(strict_types=1);
namespace Tests\Integration;
use PDO;
use Tests\TestCase;
use NativeBriefingFixture\{CiGate,Trace};
use TxBoundaryFixture\Peer;
/** Only synthetic SQL. Neither native journey nor product schema is installed. */
class NativeBriefingPgTest extends TestCase
{
    private const ORDER='00000000-0000-4000-8000-000000000001';
    private function confirm(PDO $db,string $order=self::ORDER,int $revision=1):string
    {$q=$db->prepare('SELECT tx_boundary_fixture.confirm(?::uuid,?,?)');$q->execute([$order,$revision,'synthetic-fixed-digest']);return $q->fetchColumn();}
    private function invalidate(PDO $db,string $kind):string
    {$q=$db->prepare('SELECT tx_boundary_fixture.invalidate(?::uuid,?)');$q->execute([self::ORDER,$kind]);return $q->fetchColumn();}
    private function resetFixture(PDO $db):void
    {$db->exec('TRUNCATE tx_boundary_fixture.evidence');$db->exec('UPDATE tx_boundary_fixture.binding SET active=true,revision=1');}
    private function waitForLock(PDO $db,int $backend):void
    {
        // Observable server lock wait, same key and blocker. Elapsed time alone is NEVER evidence.
        $q=$db->prepare("SELECT EXISTS(SELECT FROM pg_locks w JOIN pg_locks h
            ON h.locktype=w.locktype AND h.database=w.database AND h.classid=w.classid AND h.objid=w.objid AND h.objsubid=w.objsubid
            WHERE w.pid=? AND w.locktype='advisory' AND NOT w.granted AND h.granted AND h.pid=pg_backend_pid()
            AND pg_backend_pid()=ANY(pg_blocking_pids(?)))");
        $deadline=hrtime(true)+3000000000;
        do{$q->execute([$backend,$backend]);if($q->fetchColumn()===true)return;usleep(1000);}while(hrtime(true)<$deadline);
        self::fail('Expected matching advisory lock wait was not observed.');
    }
    public function test_transaction_boundary_only():void
    {
        require_once __DIR__.'/Peer.php';Trace::pass('PASS BRIEFING_TEST_ENTERED');Trace::enter('NEW_DATABASE_GATE');
        $port=(string)getenv('PORTAL_NATIVE_ORDER_TEST_PORT');self::assertMatchesRegularExpression('/\A[0-9]{4,5}\z/D',$port);
        config(['database.default'=>'pgsql','database.connections.pgsql'=>['driver'=>'pgsql','host'=>'127.0.0.1','port'=>(int)$port,
            'database'=>'portal_native_orders_ci','username'=>'portal_native_test_bootstrap','password'=>'','charset'=>'utf8','prefix'=>'','search_path'=>'public','sslmode'=>'disable']]);
        $this->app['db']->purge('pgsql');$attestation=CiGate::beforeWrites('portal_native_orders_ci');Trace::pass('PASS BRIEFING_NEW_DATABASE_GATE_PASSED');Trace::enter('ADMIN_GATE');
        $raw=new PDO('pgsql:host=127.0.0.1;port='.$port.';dbname=portal_native_orders_ci;connect_timeout=3','portal_native_test_bootstrap','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
        $db=CiGate::adminBeforeWrites($raw,$attestation);Trace::pass('PASS BRIEFING_LARAVEL_SQL_BEFORE_DDL');Trace::enter('SECOND_CONNECTION');
        $peer=null;
        try {
            $peer=new Peer($attestation['identity']);
            self::assertNotSame((int)$db->query('SELECT pg_backend_pid()')->fetchColumn(),$peer->backend);
            Trace::pass('PASS BRIEFING_TWO_CONNECTIONS_ATTESTED');Trace::enter('FIXTURE_DDL');
            CiGate::sameConnection($this->app['db']->connection('pgsql')->getPdo(),$attestation['pdo']);
            $db->exec(file_get_contents(__DIR__.'/fixture.sql'));
            Trace::pass('PASS BRIEFING_SYNTHETIC_DDL_COMPLETE');Trace::enter('WAIT_CASES');
            // Revocation wins: child cannot confirm until parent commits; after waiting it must close.
            $db->beginTransaction();self::assertSame('invalidated',$this->invalidate($db,'revoke'));
            $peer->start('confirm');$this->waitForLock($db,$peer->backend);$db->commit();self::assertSame('closed',$peer->finish());
            self::assertSame(0,(int)$peer->call('count'));
            Trace::pass('PASS BRIEFING_LOCK_WAIT_OBSERVED');Trace::enter('CASES');
            // Confirmation wins: only one record; concurrent revoke waits and then invalidates consumption.
            $this->resetFixture($db);$db->beginTransaction();self::assertSame('recorded',$this->confirm($db));
            self::assertSame(0,(int)$peer->call('count'));$peer->start('revoke');$this->waitForLock($db,$peer->backend);$db->commit();
            self::assertSame('invalidated',$peer->finish());self::assertSame(1,(int)$peer->call('count'));self::assertSame('closed',$peer->call('confirm'));
            // Concurrent identical confirmation waits, then replays without duplicate.
            $this->resetFixture($db);$db->beginTransaction();self::assertSame('recorded',$this->confirm($db));
            $peer->start('confirm');$this->waitForLock($db,$peer->backend);$db->commit();self::assertSame('replay',$peer->finish());self::assertSame(1,(int)$peer->call('count'));
            // Rollback publishes nothing and releases the lock.
            $this->resetFixture($db);$db->beginTransaction();self::assertSame('recorded',$this->confirm($db));self::assertSame(0,(int)$peer->call('count'));
            $db->rollBack();self::assertSame(0,(int)$peer->call('count'));self::assertSame('recorded',$peer->call('confirm'));self::assertSame('replay',$peer->call('confirm'));
            // Context revision changes before confirmer can acquire the stable binding lock.
            $this->resetFixture($db);$db->beginTransaction();self::assertSame('invalidated',$this->invalidate($db,'revision'));
            $peer->start('confirm');$this->waitForLock($db,$peer->backend);$db->commit();self::assertSame('closed',$peer->finish());self::assertSame(0,(int)$peer->call('count'));
            $this->resetFixture($db);self::assertSame('closed',$this->confirm($db,'00000000-0000-4000-8000-000000000099'));
            self::assertSame('closed',$this->confirm($db,self::ORDER,2));self::assertSame(0,(int)$peer->call('count'));
            // Negative control: a confirmer-only lock MUST fail the contract, not pass as protected.
            $db->beginTransaction();self::assertSame('recorded',$this->confirm($db));
            self::assertSame('invalidated',$peer->call('mutant')); // bypass writer demonstrably did not wait
            self::assertFalse($db->query('SELECT active FROM tx_boundary_fixture.binding')->fetchColumn());$db->commit();
            self::assertSame(1,(int)$peer->call('count')); // counterexample detected: record and revocation raced
            $this->resetFixture($db);self::assertSame(0,(int)$peer->call('count'));
        } finally {
            $clean=true;try{if($db->inTransaction())$db->rollBack();}catch(\Throwable){$clean=false;}
            try{if($peer!==null)$peer->close();}catch(\Throwable){$clean=false;}
            if(!$clean)throw new \RuntimeException('TX_TEST_CLEANUP_REFUSED');
        }
        Trace::pass('PASS BRIEFING_TX_CONCURRENCY_COMPLETE');Trace::enter('TEST_TEARDOWN');
    }
}
