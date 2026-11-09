<?php

declare(strict_types=1);

namespace App\Domain\CommercialPublishing;

/**
 * Ponto de entrada de LABORATÓRIO: encadeia revisão de publicação e assinatura.
 * Nenhuma autorização pode ser considerada confiável até que roles/tenant,
 * aprovação e histórico sejam lidos do Portal real em transação persistente.
 */
final class ApprovedPublicCatalogIssuer
{
    public static function issue(
        array $snapshot,
        array $request,
        array $approval,
        array $dispatch,
        string $keyId,
        string $secretKey,
        array $usedRequestIds = [],
        array $publishedReleaseIds = [],
    ): array {
        PublicationApprovalGate::evaluate(
            $request, $approval, $dispatch, $snapshot,
            $usedRequestIds, $publishedReleaseIds
        );
        return PublicCatalogSigner::sign($snapshot, $keyId, $secretKey);
    }
}
