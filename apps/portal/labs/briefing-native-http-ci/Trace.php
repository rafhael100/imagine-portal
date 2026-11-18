<?php
declare(strict_types=1);
namespace NativeBriefingFixture;
final class Trace
{
    public const MARKERS = ['PASS BRIEFING_AUTOLOAD_READY','PASS BRIEFING_BOOTSTRAP_READY','PASS BRIEFING_MAINTENANCE_CONFIG_READY','PASS BRIEFING_MAINTENANCE_BEFORE_DDL','PASS BRIEFING_DATABASE_CREATED','PASS BRIEFING_TEST_ENTERED','PASS BRIEFING_NEW_DATABASE_GATE_PASSED','PASS BRIEFING_LARAVEL_SQL_BEFORE_DDL','PASS BRIEFING_MIGRATIONS_COMPLETE','PASS BRIEFING_SCHEMA_COMPLETE','PASS BRIEFING_FIXTURES_READY','PASS BRIEFING_COMMIT_OWNER_REPLAY_REVOCATION','PASS BRIEFING_WORKER_COMPLETE'];
    public const PHASE_COUNTS = ['AUTOLOAD'=>[0],'BOOTSTRAP'=>[1],'MAINTENANCE_CONFIG'=>[2],'MAINTENANCE_GATE'=>[3],'CREATE_DATABASE'=>[4],'TEST_BOOTSTRAP'=>[5],'NEW_DATABASE_GATE'=>[6],'ADMIN_GATE'=>[7],'MIGRATIONS'=>[8],'SECURITY_DDL'=>[9],'FIXTURES'=>[10],'CASES'=>[11],'TEST_TEARDOWN'=>[12],'FINALIZE'=>[12]];
    private static string $phase='AUTOLOAD';
    private static int $count=0;
    public static function enter(string $phase):void
    {
        if (!isset(self::PHASE_COUNTS[$phase]) || !in_array(self::$count,self::PHASE_COUNTS[$phase],true)) throw new \RuntimeException('TRACE_ORDER_REFUSED');
        self::$phase=$phase;
    }
    public static function pass(string $marker):void
    {
        if ((self::MARKERS[self::$count]??null)!==$marker) throw new \RuntimeException('TRACE_ORDER_REFUSED');
        $line=$marker."\n";
        if(fwrite(STDOUT,$line)!==strlen($line))throw new \RuntimeException('TRACE_WRITE_REFUSED');
        self::$count++;
    }
    public static function phase():string {return self::$phase;}
}
