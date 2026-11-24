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
    public function __construct(private PDO $reader, PDO $bootstrap)
    {
        // Only the already-attested bootstrap may inspect data_directory.
        // The restricted runtime must not receive pg_read_all_settings.
        if (getenv('PORTAL_NATIVE_ORDER_TEST') !== 'DISPOSABLE_PG18' ||
            $bootstrap->query('SELECT session_user')->fetchColumn() !== 'portal_native_test_bootstrap' ||
            $bootstrap->query('SHOW data_directory')->fetchColumn() !== getenv('PORTAL_NATIVE_ORDER_TEST_DATA') ||
            $bootstrap->query('SELECT current_database()')->fetchColumn() !== 'portal_native_orders_ci' ||
            $reader->query('SELECT current_database()')->fetchColumn() !== 'portal_native_orders_ci' ||
            $reader->query('SELECT session_user')->fetchColumn() !== 'portal_onepage_runtime' ||
            $reader->query('SELECT host(inet_server_addr()), inet_server_port()')->fetch(PDO::FETCH_NUM) !==
                $bootstrap->query('SELECT host(inet_server_addr()), inet_server_port()')->fetch(PDO::FETCH_NUM)) {
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
        $q=$this->reader->prepare('SELECT portal_brief_rights_lab.read_current_rights(?,?::uuid,?::uuid,?::integer,?)');
        try {
            $q->execute([hash('sha256',$reference),$order->id,$site,$version,$digest]);
            $raw=$q->fetchColumn();
        } finally {
            $q->closeCursor();
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
