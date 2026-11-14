<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use InvalidArgumentException;

/**
 * Portal F1-12: independent PHP candidate of the Sites F1-118 HMAC v2
 * envelope. Entirely synthetic and offline, not IAM or a live signer.
 * A caller-supplied key epoch is trusted ONLY in these laboratory tests.
 */
final class SyntheticSitesVersionedEnvelopeSigner
{
    private const FLAGS=JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES;

    /**
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public static function sign(
        array $command,
        string $syntheticKey,
        string $nonce,
        int $issuedAt,
        int $expiresAt,
        int $keyEpoch
    ): array {
        if ($keyEpoch < 1) {
            throw new InvalidArgumentException('Invalid synthetic signing generation.');
        }

        // Keep the trusted input shape, TTL, nonce and key length rules
        // identical to the independent Portal F1-11 v1 candidate.
        $v1=SyntheticSitesEnvelopeSigner::sign(
            $command,$syntheticKey,$nonce,$issuedAt,$expiresAt
        );
        $unsigned=array_slice($v1,0,-1,true);
        $unsigned['schemaVersion']='2';
        $unsigned['keyEpoch']=$keyEpoch;
        $unsigned['mac']=hash_hmac(
            'sha256',json_encode($unsigned,self::FLAGS),$syntheticKey
        );
        return $unsigned;
    }
}
