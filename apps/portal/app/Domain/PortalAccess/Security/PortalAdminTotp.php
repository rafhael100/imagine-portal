<?php
declare(strict_types=1);

namespace App\Domain\PortalAccess\Security;

/**
 * RFC6238 TOTP/HMAC-SHA1 verification for a 20-byte pre-provisioned secret.
 * No HTTP enrollment or recovery codes exist in F132. Admin MFA is mandatory.
 */
final class PortalAdminTotp
{
    public static function code(string $secret,int $counter):string
    {
        if(strlen($secret)!==20 || $counter<0 || $counter>4294967295)
            throw new \InvalidArgumentException('Invalid TOTP input');
        $mac=hash_hmac('sha1',pack('N2',0,$counter),$secret,true);
        $offset=ord($mac[19]) & 15;
        $value=((ord($mac[$offset]) & 127)<<24)
            | (ord($mac[$offset+1])<<16)
            | (ord($mac[$offset+2])<<8)
            | ord($mac[$offset+3]);
        return str_pad((string)($value%1000000),6,'0',STR_PAD_LEFT);
    }

    /**
     * Return the actual matched time step, never a boolean authorizing a
     * second use. Caller MUST atomically enforce a strictly increasing
     * last_totp_counter in the PostgreSQL row before issuing a session.
     */
    public static function match(
        string $hexSecret,mixed $suppliedCode,int $trustedUnixSeconds
    ):?int {
        if(!is_string($suppliedCode) ||
            preg_match('/\A[0-9]{6}\z/D',$suppliedCode)!==1 ||
            preg_match('/\A[a-f0-9]{40}\z/D',$hexSecret)!==1 ||
            $trustedUnixSeconds<1700000000 ||
            $trustedUnixSeconds>4102444800)
            return null;

        $secret=hex2bin($hexSecret);
        if(!is_string($secret)||strlen($secret)!==20)return null;
        $current=intdiv($trustedUnixSeconds,30);
        foreach([-1,0,1] as $offset){
            $counter=$current+$offset;
            if($counter>=0 && hash_equals(
                self::code($secret,$counter),$suppliedCode
            ))return $counter;
        }
        return null;
    }
}
