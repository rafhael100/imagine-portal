<?php
declare(strict_types=1);
namespace App\Homologation\CompositeCartLab;

use App\Homologation\Commerce\CompositeQuotePreview;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;
require_once __DIR__.'/../../app/Homologation/Commerce/CompositeQuotePreview.php';

/**
 * F351: fixture-only deterministic write of server-repriced anonymous drafts.
 *
 * This class REQUIRES an isolated, loopback, admin-only PostgreSQL18 DB.
 * It is NOT an operational writer identity, API, order or checkout. It cannot
 * infer organization authority from a browser-provided tenant string; tenant
 * role identifiers here are fixed synthetic TEST FIXTURES ONLY.
 */
final class SyntheticDraftWriter
{
    private CompositeQuotePreview $calculator;

    public function __construct(private readonly PDO $db)
    {
        $this->calculator=new CompositeQuotePreview();
    }

    /** @return array{draftId:string,replayed:bool,releaseStatus:string} */
    public function write(string $role,string $requestKey,array $selection): array
    {
        if (getenv('PORTAL_F351_DISPOSABLE')!=='LOCAL_SYNTHETIC_WRITER_ONLY'
            ||getenv('PORTAL_F350_DISPOSABLE')!=='LOCAL_127_0_0_1_ONLY'
            ||getenv('PGHOST')!=='127.0.0.1'
            ||getenv('PGPORT')!=='5432'
            ||getenv('PGDATABASE')!=='portal_composite_f350_ci'
            ||!in_array($role,['f350_tenant_a_reader','f350_tenant_b_reader'],true)
            ||!preg_match('/\Af351-[a-z0-9-]{1,72}\z/D',$requestKey))
            throw new InvalidArgumentException('F351: synthetic writer scope required');
        $connection=$this->db->query(
            'SELECT current_user AS role,current_database() AS db,inet_server_port() AS port'
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($connection)
            ||$connection['role']!=='postgres'
            ||$connection['db']!=='portal_composite_f350_ci'
            ||(int)$connection['port']!==5432)
            throw new RuntimeException('F351: only disposable SQL fixture owner may write');

        // No amounts or customer data accepted. The approved PHP catalog
        // computes the quote and the exact 3-part arithmetic afresh.
        $quote=$this->calculator->quote($selection);
        $json=json_encode($quote,JSON_THROW_ON_ERROR);
        $digest=hash('sha256',$json);
        $selectionHash=hash('sha256',json_encode([
            $quote['site'],$quote['channels'],$quote['media_tier']
        ],JSON_THROW_ON_ERROR));
        $keyHash=hash('sha256',$requestKey);
        $raw=bin2hex(random_bytes(16));
        $uuid=sprintf('%s-%s-4%s-8%s-%s',
            substr($raw,0,8),substr($raw,8,4),substr($raw,13,3),
            substr($raw,17,3),substr($raw,20,12));
        $this->db->beginTransaction();
        try {
            $sql=<<<'SQL'
INSERT INTO composite_cart_f350.draft_quote
 (id,tenant_role,catalog_version,selection_hash,server_quote,
  request_hash,quote_digest,expires_at)
VALUES (:id,:role,:catalog,:selection,CAST(:payload AS jsonb),
 :request,:digest,statement_timestamp()+interval '20 minutes')
ON CONFLICT (tenant_role,request_hash) WHERE request_hash IS NOT NULL
DO NOTHING
SQL;
            $insert=$this->db->prepare($sql);
            $insert->execute([
                ':id'=>$uuid,':role'=>$role,
                ':catalog'=>$quote['catalog_version'],
                ':selection'=>$selectionHash,
                ':payload'=>$json,
                ':request'=>$keyHash,':digest'=>$digest
            ]);
            $fresh=$insert->rowCount()===1;
            // After a concurrent INSERT conflict resolves, READ COMMITTED
            // sees the winning row. SELECT FOR UPDATE protects this check
            // until our transaction commits. No UPDATE is ever performed.
            $select=$this->db->prepare(<<<'SQL'
SELECT id,selection_hash,quote_digest,catalog_version,revoked_at,
       expires_at>statement_timestamp() AS alive
FROM composite_cart_f350.draft_quote
WHERE tenant_role=:role AND request_hash=:request
FOR UPDATE
SQL);
            $select->execute([':role'=>$role,':request'=>$keyHash]);
            $row=$select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)
                ||$row['quote_digest']!==$digest
                ||$row['selection_hash']!==$selectionHash
                ||$row['catalog_version']!==$quote['catalog_version']
                ||$row['revoked_at']!==null
                ||!in_array($row['alive'],[true,'t','1',1],true)) {
                throw new InvalidArgumentException(
                    'F351: replay mismatch or revoked/expired draft');
            }
            $this->db->commit();
            return [
                'draftId'=>$row['id'],'replayed'=>!$fresh,
                'releaseStatus'=>'HOLD_TENANT_AUTHN_PSP_AND_HUMAN_REVIEW'
            ];
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            // Never interpolate SQL or quote details into the message.
            if($e instanceof InvalidArgumentException)throw $e;
            throw new RuntimeException('F351: isolated draft write failed');
        }
    }
}
