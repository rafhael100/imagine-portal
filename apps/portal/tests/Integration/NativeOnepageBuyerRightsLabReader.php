<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Commerce\OnepageAcceptedOrder;
use PDO;
use SensitiveParameter;

/**
 * Read-only buyer evidence from the disposable R28/R32 SQL fixture.
 * Deliberately does not implement ApprovedOnepageBriefSnapshotSource or
 * CurrentOnepageBriefApprovalWitness: buyer rights are not editorial approval.
 * No production registration, route, grant, writer or export.
 */
final readonly class NativeOnepageBuyerRightsLabReader
{
    private string $port;
    private string $serverAddress;

    public function __construct(private PDO $reader, PDO $bootstrap)
    {
        $port=getenv('PORTAL_NATIVE_ORDER_TEST_PORT');
        if (!is_string($port) || preg_match('/\A[0-9]{4,5}\z/D',$port)!==1) {
            throw new \RuntimeException('Disposable buyer rights reader required.');
        }
        $this->port=$port;
        try {
            $this->serverAddress=self::expectedServerAddress();
            // Only the already-attested bootstrap may inspect data_directory.
            // The restricted runtime must not receive pg_read_all_settings.
            if ($bootstrap->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql' ||
                $bootstrap->inTransaction() ||
                $bootstrap->query('SELECT session_user')->fetchColumn()!=='portal_native_test_bootstrap' ||
                $bootstrap->query('SELECT current_user')->fetchColumn()!=='portal_native_test_bootstrap' ||
                $bootstrap->query('SHOW data_directory')->fetchColumn()!==getenv('PORTAL_NATIVE_ORDER_TEST_DATA') ||
                $bootstrap->query('SELECT current_database()')->fetchColumn()!=='portal_native_orders_ci' ||
                !self::endpoint($bootstrap,$port,$this->serverAddress)) {
                throw new \RuntimeException();
            }
            $this->ready();
        } catch (\Throwable) {
            throw new \RuntimeException('Disposable buyer rights reader required.');
        }
    }

    private static function expectedServerAddress():string
    {
        // The DSN stays on loopback. In hosted CI PostgreSQL observes its
        // own Docker service address, already inspected by the native suite.
        if (getenv('GITHUB_ACTIONS')!=='true') return '127.0.0.1';
        $address=getenv('PORTAL_NATIVE_ORDER_TEST_SERVER_ADDRESS');
        if (!in_array(getenv('GITHUB_EVENT_NAME'),['pull_request','workflow_dispatch'],true) ||
            !is_string($address) || filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)===false) {
            throw new \RuntimeException();
        }
        $octets=array_map('intval',explode('.',$address));
        if (!($octets[0]===10 || ($octets[0]===172 && $octets[1]>=16 && $octets[1]<=31) ||
            ($octets[0]===192 && $octets[1]===168))) {
            throw new \RuntimeException();
        }
        return $address;
    }

    private static function endpoint(PDO $db,string $port,string $address):bool
    {
        $row=$db->query('SELECT host(inet_server_addr()), inet_server_port()')->fetch(PDO::FETCH_NUM);
        return is_array($row) && $row[0]===$address && (string)$row[1]===$port;
    }

    private function ready():void
    {
        // This independent reader owns no caller transaction. SQL-level lock
        // tests still use their separate connections directly. Re-attest on
        // every use because PDO state is mutable even in a readonly wrapper.
        if (getenv('PORTAL_NATIVE_ORDER_TEST')!=='DISPOSABLE_PG18' ||
            getenv('PORTAL_NATIVE_ORDER_TEST_PORT')!==$this->port ||
            self::expectedServerAddress()!==$this->serverAddress ||
            $this->reader->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql' ||
            $this->reader->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION ||
            $this->reader->inTransaction() ||
            $this->reader->query('SELECT current_database()')->fetchColumn()!=='portal_native_orders_ci' ||
            $this->reader->query('SELECT session_user')->fetchColumn()!=='portal_onepage_runtime' ||
            $this->reader->query('SELECT current_user')->fetchColumn()!=='portal_onepage_runtime' ||
            !self::endpoint($this->reader,$this->port,$this->serverAddress)) {
            throw new \RuntimeException('Disposable buyer rights reader required.');
        }
    }

    /** @return array{receipt_id:string,order_id:string,site_id:string,company:string,buyer:string,version:int,source_sha:string,bytes_hex:string,rights_declared:bool}|null */
    public function read(OnepageAcceptedOrder $order, #[SensitiveParameter] string $reference,
        string $site, int $version, string $digest): ?array
    {
        if (!self::uuid($site) || $version < 1 || $version > 2147483647 ||
            preg_match('/\A[a-f0-9]{64}\z/D',$reference) !== 1 ||
            preg_match('/\A[a-f0-9]{64}\z/D',$digest) !== 1) return null;
        try {
            $this->ready();
            $q=$this->reader->prepare('SELECT portal_brief_rights_lab.read_current_rights(?,?::uuid,?::uuid,?::integer,?)');
            try {
                $q->execute([hash('sha256',$reference),$order->id,$site,$version,$digest]);
                $raw=$q->fetchColumn();
            } finally {
                $q->closeCursor();
            }
        } catch (\Throwable) {
            // Do not disclose driver diagnostics or private selectors to a
            // future consumer of this lab reader. Infrastructure errors are
            // distinct from an ordinary denied (null) evidence lookup.
            throw new \RuntimeException('Disposable buyer rights reader unavailable.');
        }
        if (!is_string($raw) || strlen($raw)>310000) return null;
        try {
            $e=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return self::validate($e,$order,$site,$version,$digest);
    }

    /** Validate the exact stored evidence, never construct approval or export. */
    public static function validate(mixed $e, OnepageAcceptedOrder $order,
        string $site, int $version, string $digest): ?array
    {
        if (!is_array($e) || count($e)!==9 ||
            !is_string($e['receipt_id']??null) || !self::uuid($e['receipt_id']) ||
            ($e['order_id']??null)!==$order->id || ($e['site_id']??null)!==$site ||
            ($e['company']??null)!==$order->companyId || ($e['buyer']??null)!==$order->buyerId ||
            ($e['version']??null)!==$version || ($e['source_sha']??null)!==$digest ||
            ($e['rights_declared']??null)!==true || !is_string($e['bytes_hex']??null)) return null;
        $hex=$e['bytes_hex'];
        if (strlen($hex)<2 || strlen($hex)>300000 || strlen($hex)%2!==0 ||
            preg_match('/\A[a-f0-9]+\z/D',$hex)!==1 ||
            !hash_equals($digest,hash('sha256',hex2bin($hex)))) return null;
        return $e;
    }

    private static function uuid(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D',$value)===1;
    }
}
