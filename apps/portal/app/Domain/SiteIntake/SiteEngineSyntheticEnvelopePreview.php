<?php

declare(strict_types=1);

namespace App\Domain\SiteIntake;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * F312: produce a deterministic SYNTHETIC envelope preview for the candidate
 * CreateProject v1 schema in imagine-sites. This is NOT a trusted Portal
 * issuer. No runtime keys, live contract authority, nonce storage, HTTP
 * transport, customer approval or Sites project creation are provided.
 */
final class SiteEngineSyntheticEnvelopePreview
{
    private const FLAGS=JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE;

    public static function compose(
        string $canonicalCandidateJson,
        #[SensitiveParameter] string $syntheticKey,
        string $nonce,
        int $issuedAt,
        int $expiresAt,
    ): string {
        if (SiteEngineCreateProjectCandidateValidator::inspect($canonicalCandidateJson)['valid'] !== true) {
            throw new InvalidArgumentException('CreateProject candidate is not canonical.');
        }
        if (strlen($syntheticKey)<32) {
            throw new InvalidArgumentException('A 256-bit-or-longer synthetic key is required.');
        }
        if (preg_match('/\\A[A-Za-z0-9._:-]{8,64}\\z/D',$nonce)!==1) {
            throw new InvalidArgumentException('Invalid synthetic nonce.');
        }
        if ($issuedAt<1 || $expiresAt<=$issuedAt || $expiresAt-$issuedAt>120) {
            throw new InvalidArgumentException('Invalid synthetic envelope lifetime.');
        }

        $payload=json_decode($canonicalCandidateJson,true,16,JSON_THROW_ON_ERROR);
        $unsigned=[
            'schemaVersion'=>'1',
            'issuer'=>'imagine-portal',
            'audience'=>'imagine-sites',
            'issuedAt'=>$issuedAt,
            'expiresAt'=>$expiresAt,
            'nonce'=>$nonce,
            'payload'=>$payload,
        ];
        // Byte-exact parity with imagine-sites SyntheticSignedCommand F1-11:
        // HMAC-SHA256 over canonical ordered fields without "mac", encoded
        // with JSON_UNESCAPED_SLASHES and JSON_UNESCAPED_UNICODE.
        $mac=hash_hmac('sha256',json_encode($unsigned,self::FLAGS),$syntheticKey);
        $unsigned['mac']=$mac;
        return json_encode($unsigned,self::FLAGS);
    }

    public static function mayDispatch():bool {return false;}
    public static function provesAuthority():bool {return false;}
    public static function mayRegisterOwnership():bool {return false;}
}
