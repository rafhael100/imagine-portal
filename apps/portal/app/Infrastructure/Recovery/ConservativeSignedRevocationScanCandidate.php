<?php
declare(strict_types=1);

namespace App\Infrastructure\Recovery;

/**
 * F398: crash-replayable, read-only, bounded scan of signed independent
 * revocation intents. No persistent checkpoint and NO skip of unresolved
 * work. A cursor is never automatically committed to a queue.
 */
final readonly class ConservativeSignedRevocationScanCandidate
{
    public function __construct(
        private SignedRevocationRecoveryTriageCandidate $triage,
    ) {}

    /**
     * Each item is a trusted server-side fetch result:
     * cursor, cursorNonce, optional lease/leaseDigest/leaseNonce,
     * optional ack/ackNonce. The caller supplies a trusted current clock.
     *
     * This code never performs network calls, leases, ACK writes, SQL writes,
     * authentication, notification, scheduling or cross-host checkpointing.
     */
    public function inspectBatch(array $pages,int $now):array
    {
        if($now<1||count($pages)>16){
            return self::no('SCAN_LIMIT_OR_CLOCK_DENIED',0,0);
        }
        if(count($pages)===0){
            return self::no('SCAN_NO_INPUT_NOT_AUTHORITY',0,0);
        }
        $after=0;
        $observed=0;
        foreach($pages as $item){
            if(!is_array($item)
                ||array_keys($item)!==[
                    'cursor','cursorNonce','lease','leaseDigest',
                    'leaseNonce','ack','ackNonce',
                ]
                ||!is_array($item['cursor'])
                ||!is_string($item['cursorNonce'])
                ||($item['lease']!==null&&!is_array($item['lease']))
                ||($item['leaseDigest']!==null&&!is_string($item['leaseDigest']))
                ||($item['leaseNonce']!==null&&!is_string($item['leaseNonce']))
                ||($item['ack']!==null&&!is_array($item['ack']))
                ||($item['ackNonce']!==null&&!is_string($item['ackNonce']))){
                return self::no('SCAN_INPUT_CONTRACT_DENIED',$observed,$after);
            }
            $result=$this->triage->inspect(
                $item['cursor'],$after,$item['cursorNonce'],
                $item['lease'],$item['leaseDigest'],$item['leaseNonce'],
                $item['ack'],$item['ackNonce'],$now
            );
            $status=$result['reasonCode']??null;
            foreach([
                'canAuthorizeHttp','canAuthorizeBusiness','canIssueSession',
                'canRecoverFromRestore','canDeploy','canClaimPostgresRevoked',
                'canClaimQueueFullyProcessed','canSkipReconciliation',
            ]as$flag){
                if(($result[$flag]??null)!==false){
                    return self::no('SCAN_TRIAGE_AUTHORITY_VIOLATION',$observed,$after);
                }
            }
            if($status==='NO_NEXT_INTENT_NOT_QUEUE_COMPLETE'){
                return self::no('SCAN_SIGNED_EMPTY_NOT_QUEUE_COMPLETE',$observed,$after);
            }
            // Only an independently signed ACK + restricted *local* PG
            // revoked row permit diagnostic advancement to the next item.
            // This remains volatile and is NOT a durable progress checkpoint.
            if($status!=='SQL_REVOKED_WITH_ACK_CLAIM_STILL_NO_SERVING'){
                return self::no('SCAN_HALTED_UNRESOLVED',$observed,$after);
            }
            $sequence=$item['cursor']['intent_sequence']??null;
            if(!is_int($sequence)||$sequence<=$after||$sequence>1000000000){
                return self::no('SCAN_SEQUENCE_DENIED',$observed,$after);
            }
            $after=$sequence;
            $observed++;
        }
        return self::no('SCAN_MORE_SIGNED_PAGES_REQUIRED',$observed,$after);
    }

    private static function no(string $reason,int $observed,int $last):array
    {
        return [
            'schema'=>'imagine.portal.f398.conservative-scan.v1',
            'reasonCode'=>$reason,
            'verifiedLocalRowsObserved'=>$observed,
            'lastVolatileObservedSequence'=>$last,
            'isDurableCheckpoint'=>false,
            'canAuthorizeHttp'=>false,
            'canAuthorizeBusiness'=>false,
            'canIssueSession'=>false,
            'canRecoverFromRestore'=>false,
            'canDeploy'=>false,
            'canClaimPostgresRevoked'=>false,
            'canClaimQueueFullyProcessed'=>false,
            'canSkipReconciliation'=>false,
        ];
    }
}
