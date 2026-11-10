<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use InvalidArgumentException;

/**
 * F1-16 laboratory HMAC v3: authenticate the commercial authorization
 * generation in addition to the signing-key generation. No live issuer.
 */
final class SyntheticSitesCommercialEpochSignedEnvelopeSigner
{
    private const FLAGS=JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES;

    /** @param array<string,mixed> $command
     *  @return array<string,mixed>
     */
    public static function sign(
        array $command, string $key, string $nonce,
        int $issuedAt, int $expiresAt, int $keyEpoch, int $commercialEpoch
    ): array {
        if ($commercialEpoch < 1) {
            throw new InvalidArgumentException('Invalid commercial authorization generation.');
        }
        // Validate everything else with the independent strict Portal v2
        // signer; the v3 MAC also covers the new generation explicitly.
        $v2=SyntheticSitesVersionedEnvelopeSigner::sign(
            $command,$key,$nonce,$issuedAt,$expiresAt,$keyEpoch
        );
        $unsigned=array_slice($v2,0,-1,true);
        $unsigned['schemaVersion']='3';
        $unsigned['commercialEpoch']=$commercialEpoch;
        $unsigned['mac']=hash_hmac(
            'sha256',json_encode($unsigned,self::FLAGS),$key
        );
        return $unsigned;
    }
}
