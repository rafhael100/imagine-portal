<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use PDO;
use Throwable;

/**
 * R — transactionally couple *local* grant revocation and its metadata-only
 * security event. The two collaborators MUST share the exact same PDO.
 * Nothing here authenticates a Portal user or revokes a Google refresh token.
 */
final class GoogleSearchConsoleAuditedGrantRevocation
{
    private readonly GoogleSearchConsolePropertyGrantRevocation $grants;
    private readonly GoogleSearchConsoleSecurityEventAudit $audit;

    public function __construct(private readonly PDO $pdo)
    {
        $this->grants=new GoogleSearchConsolePropertyGrantRevocation($pdo);
        $this->audit=new GoogleSearchConsoleSecurityEventAudit($pdo);
    }

    /**
     * Returns true only if both revocation AND event insert commit.
     */
    public function revokeExact(
        mixed $trustedTenant,
        mixed $trustedActor,
        mixed $siteUrl,
        mixed $trustedNow
    ): bool {
        // Invalid properties are denied before starting a transaction.
        $hash=GoogleSearchConsolePropertyIdentifier::fingerprint($siteUrl);
        if ($hash===null || $this->pdo->inTransaction()) return false;
        try {
            if (!$this->pdo->beginTransaction())return false;
            if (!$this->grants->revokeExact(
                $trustedTenant,$trustedActor,$siteUrl,$trustedNow
            )) {
                $this->pdo->rollBack();
                return false;
            }
            if (!$this->audit->record(
                'property_grant_revoked',$trustedTenant,
                $trustedActor,$hash,$trustedNow
            )) {
                $this->pdo->rollBack();
                return false;
            }
            return $this->pdo->commit();
        }catch(Throwable) {
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            return false;
        }
    }

    /**
     * Revokes a bounded actor batch and records one suspension event.
     * 0 means no active grants were changed; no audit event is generated.
     */
    public function revokeActorBatch(
        mixed $trustedTenant,
        mixed $trustedActor,
        mixed $trustedNow,
        mixed $limit=100
    ): ?int {
        if($this->pdo->inTransaction())return null;
        try {
            if(!$this->pdo->beginTransaction())return null;
            $changed=$this->grants->revokeActorBatch(
                $trustedTenant,$trustedActor,$trustedNow,$limit
            );
            if($changed===null){
                $this->pdo->rollBack();
                return null;
            }
            if($changed===0){
                $this->pdo->rollBack();
                return 0;
            }
            if(!$this->audit->record(
                'property_access_suspended',$trustedTenant,
                $trustedActor,null,$trustedNow
            )){
                $this->pdo->rollBack();
                return null;
            }
            if(!$this->pdo->commit())return null;
            return $changed;
        }catch(Throwable){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            return null;
        }
    }

    public static function authorizesCustomerDisplay(): bool{return false;}
    public static function authorizesGoogleHttp(): bool{return false;}
}
