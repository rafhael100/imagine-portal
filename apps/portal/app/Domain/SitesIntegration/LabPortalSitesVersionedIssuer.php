<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use DomainException;
use Throwable;

/**
 * Portal F1-13: bind the candidate v2 envelope's synthetic secret and
 * generation to an independent default-deny source rather than request
 * parameters. No HTTP, IAM, queue, operational key storage or deploy.
 */
final class LabPortalSitesVersionedIssuer
{
    public function __construct(
        private readonly SitesCreateProjectEncoder $encoder =
            new SitesCreateProjectEncoder(),
        private readonly PortalSitesSigningMaterialSource $materialSource =
            new DenyingPortalSitesSigningMaterialSource(),
    ) {}

    /** @return array<string,mixed> */
    public function issue(
        string $orderRef,
        string $operationId,
        string $idempotencyKey,
        string $nonce,
        int $issuedAt,
        int $expiresAt,
    ): array {
        // Source of the commercial order is separately default-deny.
        // A caller cannot provide its own eligible tenant or signer key.
        $command=$this->encoder->build($orderRef,$operationId,$idempotencyKey);
        $original=$this->readMaterial();
        $signed=SyntheticSitesVersionedEnvelopeSigner::sign(
            $command,$original->secret,$nonce,$issuedAt,$expiresAt,$original->keyEpoch
        );
        // A changed key or epoch mid-emission must not produce an outgoing
        // envelope. This is a cooperative lab check, not atomic issuance.
        $current=$this->readMaterial();
        if ($original->keyEpoch!==$current->keyEpoch ||
            !hash_equals(
                hash('sha256',$original->secret),
                hash('sha256',$current->secret)
            )) {
            throw new DomainException('candidate_portal_signing_material_changed');
        }
        return $signed;
    }

    private function readMaterial(): CandidatePortalSitesSigningMaterial
    {
        try {
            $material=$this->materialSource->currentFor('imagine-portal','imagine-sites');
        } catch (Throwable $e) {
            throw new DomainException('candidate_portal_signing_material_unavailable',0,$e);
        }
        if (!$material instanceof CandidatePortalSitesSigningMaterial ||
            $material->issuer!=='imagine-portal' ||
            $material->audience!=='imagine-sites' ||
            strlen($material->secret)<32 || $material->keyEpoch<1) {
            throw new DomainException('candidate_portal_signing_material_unavailable');
        }
        return $material;
    }
}
