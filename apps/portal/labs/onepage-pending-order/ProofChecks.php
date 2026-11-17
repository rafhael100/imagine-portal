<?php
declare(strict_types=1);
namespace App\Labs\OnepagePendingOrder;
/** Pure predicates for acceptance-test evidence, never authorization. */
final class ProofChecks {
    public static function immutableRefusal(array $info,string $before,string $after):bool {
        return ($info[0]??null)==='P0001' && is_string($info[2]??null)
            && preg_match('/(?:^|\n|ERROR:\s*)LAB_ORDER_AND_TERMS_IMMUTABLE(?:\r?\n|$)/',$info[2])===1
            && $before===$after;
    }
    public static function revokedTransition(array $before,array $after):bool {
        return ($before['status']??null)==='active' && array_key_exists('revoked_at',$before) && $before['revoked_at']===null
            && ($after['status']??null)==='revoked' && is_string($after['revoked_at']??null) && $after['revoked_at']!=='';
    }
}
