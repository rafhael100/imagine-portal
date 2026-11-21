<?php
declare(strict_types=1);

use Closure;
use InvalidArgumentException;
use Throwable;

/**
 * STRICTLY CI-only adapter for an actual Google-only Portal order.
 * The Portal buyer's approved SQL revision is read by a separate authenticated
 * worker. This is not Google authorization, plan approval, a campaign draft or
 * a replacement for Performance's two-channel fixture (#458).
 */
final class F423GoogleOnlyReadiness
{
    private static function hasKeys(array $v,array $expected): bool
    {
        $keys=array_keys($v);
        sort($keys,SORT_STRING);
        sort($expected,SORT_STRING);
        return $keys===$expected;
    }

    /** @param Closure(string,string,string):(?string) $leaseForAccount */
    public static function decide(array $order,array $briefing,Closure $leaseForAccount,array &$ledger): array
    {
        if (!self::hasKeys($order,[
            'order_id','company_id','purchase_state','purchase_evidence','catalog_version',
            'activation_total_cents','channels','briefing_revision','briefing_sha256'
        ]) || !self::hasKeys($briefing,['order_id','company_id','state','revision','sha256'])
            || !is_string($order['order_id'])
            || preg_match('/\Aorder_[a-f0-9]{32}\z/D',$order['order_id'])!==1
            || !is_string($order['company_id'])
            || preg_match('/\Ademo-[a-f0-9]{32}\z/D',$order['company_id'])!==1
            || $order['purchase_state']!=='synthetic_confirmed'
            || $order['purchase_evidence']!=='fake_provider_callback_only'
            || $order['catalog_version']!=='performance-f423-v5-lab'
            || $order['activation_total_cents']!==49000
            || !is_int($order['briefing_revision']) || $order['briefing_revision']<1
            || !is_string($order['briefing_sha256'])
            || preg_match('/\A[a-f0-9]{64}\z/D',$order['briefing_sha256'])!==1
            || $briefing['company_id']!==$order['company_id']
            || $briefing['order_id']!==$order['order_id']
            || $briefing['state']!=='synthetic_review_approved'
            || $briefing['revision']!==$order['briefing_revision']
            || $briefing['sha256']!==$order['briefing_sha256']
            || !is_array($order['channels'])
            || !self::hasKeys($order['channels'],['google_ads'])
            || !is_array($order['channels']['google_ads'])
            || !self::hasKeys($order['channels']['google_ads'],['account_id','media_cents','activation_cents'])
            || $order['channels']['google_ads']['account_id']!=='1234567890'
            || $order['channels']['google_ads']['media_cents']!==100000
            || $order['channels']['google_ads']['activation_cents']!==49000) {
            throw new InvalidArgumentException('Google-only CI contract or exact buyer approval mismatch');
        }

        $blocked=['state'=>'blocked','channels'=>[
            'google_ads'=>['state'=>'blocked','reason'=>'mandate_or_s2s_denied',
                'media_cents'=>100000,'campaign_state'=>'not_created']
        ],'portal_http_read_proven'=>false,'operational_enabled'=>false,
          'ads_write_enabled'=>false,'billable'=>false];
        try {
            // Actual Performance mandate gate callback, synthetically supplied.
            $lease=$leaseForAccount($order['company_id'],'google_ads','1234567890');
        } catch (Throwable) {
            $lease=null;
        }
        if(!is_string($lease)||preg_match('/\A[a-f0-9]{64}\z/D',$lease)!==1)
            return $blocked;

        $fingerprint=hash('sha256',implode("\0",[
            $order['company_id'],$order['order_id'],(string)$order['briefing_revision'],
            $order['briefing_sha256'],'google_ads','1234567890','100000'
        ]));
        $ledgerKey=hash('sha256',implode("\0",[
            $order['company_id'],$order['order_id'],(string)$order['briefing_revision'],'google_ads'
        ]));
        if(isset($ledger[$ledgerKey])&&$ledger[$ledgerKey]!==$fingerprint)
            return $blocked;
        $ledger[$ledgerKey]??=$fingerprint;
        return ['state'=>'internal_review_only','channels'=>[
            'google_ads'=>['state'=>'internal_review_only','media_cents'=>100000,
                'campaign_state'=>'not_created','connection_state'=>'synthetic_candidate']
        ],'portal_http_read_proven'=>false,'operational_enabled'=>false,
          'ads_write_enabled'=>false,'billable'=>false];
    }
}
