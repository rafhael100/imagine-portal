<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use DomainException;
use Throwable;

/**
 * F1-395 inert Portal-side consistency check before composing two independently
 * authenticated Ed25519 statements:
 *  - financial/contract entitlement for a particular Sites order;
 *  - express, current MFA-backed editorial acceptance for the EXACT release.
 *
 * This does NOT authorize publishing. Sites must independently validate both
 * signatures, PSP/contract revocation, current acceptance/revocation, and all
 * infrastructure, security, privacy, domain and transaction gates.
 *
 * No HTTP route, application provider, real keys or operational data source.
 */
final readonly class PortalSitesReleaseProofBundleBuilder
{
    public function __construct(
        private PortalCurrentSiteReleaseAssignmentSource $assignments =
            new DenyingPortalSiteReleaseAssignmentSource(),
        private PortalEd25519EntitlementIssuer $commercial =
            new PortalEd25519EntitlementIssuer(),
        private PortalEd25519ClientAcceptanceIssuer $clientAcceptance =
            new PortalEd25519ClientAcceptanceIssuer(),
    ) {}

    /**
     * Two distinct secret keys must be loaded from a protected key manager by
     * the trusted caller. Inputs here are opaque lookup references, never a
     * user-provided approval status, tenant, contract or artifact hash.
     *
     * @return array{commercialProof:array<string,int|string>,clientAcceptanceReceipt:array<string,int|string>}
     */
    public function build(
        string $orderRef,
        string $siteRef,
        string $releaseRef,
        int $editorialRevision,
        string $operationId,
        string $idempotencyKey,
        int $now,
        string $commercialSecretKey,
        string $acceptanceSecretKey,
    ): array {
        if ($editorialRevision<1 || $now<1 ||
            strlen($commercialSecretKey)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES ||
            strlen($acceptanceSecretKey)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES ||
            hash_equals($commercialSecretKey,$acceptanceSecretKey)) {
            throw new DomainException('Release proof bundle unavailable.');
        }
        try {
            $before=$this->assignments->readCurrent(
                $orderRef,$siteRef,$releaseRef,$editorialRevision
            );
            if (!$before instanceof PortalSitesReleaseAssignment ||
                $before->orderRef!==$orderRef ||
                $before->siteRef!==$siteRef ||
                $before->releaseRef!==$releaseRef ||
                $before->editorialRevision!==$editorialRevision) {
                throw new DomainException('Untrusted release mapping.');
            }
            $commercial=$this->commercial->issue(
                $orderRef,$operationId,$idempotencyKey,$now,$commercialSecretKey
            );
            $acceptance=$this->clientAcceptance->issue(
                $siteRef,$releaseRef,$editorialRevision,$now,$acceptanceSecretKey
            );
            // Both issuers use separate current sources. Neither one's
            // response can designate the other one's tenant, order or Site.
            if ($commercial['tenantRef']!==$before->tenantRef ||
                $commercial['orderRef']!==$before->orderRef ||
                $commercial['contractRef']!==$before->contractRef ||
                $commercial['contractRevision']!==$before->contractRevision ||
                $commercial['productCode']!==$before->productCode ||
                $acceptance['tenantRef']!==$before->tenantRef ||
                $acceptance['siteRef']!==$before->siteRef ||
                $acceptance['releaseRef']!==$before->releaseRef ||
                $acceptance['artifactDigest']!==$before->artifactDigest ||
                $acceptance['editorialRevision']!==$before->editorialRevision ||
                $commercial['issuedAt']!==$now ||
                $acceptance['issuedAt']!==$now ||
                $commercial['expiresAt']<=$now ||
                $acceptance['expiresAt']<=$now) {
                throw new DomainException('Release mapping mismatch.');
            }
            // Detect version changes while signing. Sites must still re-check
            // independent authorities at consumption time after this method.
            $after=$this->assignments->readCurrent(
                $orderRef,$siteRef,$releaseRef,$editorialRevision
            );
            if (!$after instanceof PortalSitesReleaseAssignment ||
                $after!=$before) {
                throw new DomainException('Release association changed.');
            }
            return [
                'commercialProof'=>$commercial,
                'clientAcceptanceReceipt'=>$acceptance,
            ];
        } catch (Throwable) {
            throw new DomainException('Release proof bundle unavailable.');
        }
    }
}
