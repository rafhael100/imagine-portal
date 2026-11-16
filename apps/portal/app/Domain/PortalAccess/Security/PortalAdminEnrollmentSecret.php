<?php
declare(strict_types=1);

namespace App\Domain\PortalAccess\Security;

/**
 * Generate an RFC4648 Base32 enrollment secret. The TOTP binary secret
 * itself must be generated with random_bytes(20) by the trusted operator.
 */
final class PortalAdminEnrollmentSecret
{
    public static function base32(string $binary):string
    {
        if(strlen($binary)!==20)
            throw new \InvalidArgumentException('TOTP enrollment secret length');
        $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $output='';
        $buffer=0;
        $bits=0;
        for($i=0;$i<strlen($binary);$i++){
            $buffer=($buffer<<8)|ord($binary[$i]);
            $bits+=8;
            while($bits>=5){
                $bits-=5;
                $output.=$alphabet[($buffer>>$bits)&31];
            }
            $buffer&=(1<<$bits)-1;
        }
        if($bits>0)
            $output.=$alphabet[($buffer<<(5-$bits))&31];
        if(strlen($output)!==32)
            throw new \RuntimeException('TOTP Base32 encoding failed');
        return $output;
    }

    public static function enrollmentText(string $email,string $binary):string
    {
        $secret=self::base32($binary);
        $issuer='Imagine Portal';
        $url='otpauth://totp/'.rawurlencode($issuer.':'.$email).
            '?secret='.$secret.
            '&issuer='.rawurlencode($issuer).
            '&algorithm=SHA1&digits=6&period=30';
        return "CREDENCIAL PRIVADA DE SEGUNDO FATOR — IMAGINE PORTAL\n".
            "Conta: ".$email."\n".
            "Código Base32: ".$secret."\n".
            "URI do autenticador: ".$url."\n".
            "Guarde este arquivo fora de pastas públicas e elimine-o".
            " após configurar o aplicativo autenticador.\n";
    }
}
