<?php

declare(strict_types=1);

namespace App\Domain\CommercialPublishing;

use App\Domain\Organizations\OrganizationId;
use App\Domain\Organizations\OrganizationMembership;
use InvalidArgumentException;

/**
 * F10 — fachada de LABORATORIO que exige escopo e permissoes tipadas ANTES
 * da assinatura. Memberships/identity devem vir de autoridade Portal confiavel;
 * receber objetos PHP nao autentica nem autoriza uma pessoa real.
 */
final class TenantScopedPublicCatalogIssuer
{
    public static function issue(
        array $snapshot,
        array $request,
        array $approval,
        array $dispatch,
        OrganizationId $organization,
        ?OrganizationMembership $editorMembership,
        ?OrganizationMembership $approverMembership,
        ?OrganizationMembership $publisherMembership,
        string $keyId,
        string $secretKey,
    ): array {
        $editor=$editorMembership?->actorId;
        $approver=$approverMembership?->actorId;
        $publisher=$publisherMembership?->actorId;

        if (!(new CommercialPublicationPermissionGate())->evaluate(
            $organization,$editor,$editorMembership,
            $approver,$approverMembership,
            $publisher,$publisherMembership
        )->allowed) {
            throw new InvalidArgumentException('Missing scoped editorial permission.');
        }

        foreach ([
            [$request,$editor,'request'],
            [$approval,$approver,'approval'],
            [$dispatch,$publisher,'dispatch'],
        ] as [$step,$actor,$stage]) {
            if (!is_array($step) ||
                ($step['tenantId'] ?? null) !== $organization->value ||
                ($step['actorId'] ?? null) !== $actor?->value) {
                throw new InvalidArgumentException('Untrusted '.$stage.' actor or tenant scope.');
            }
        }

        // Reconstroi claims descritivos a partir da ordem de responsabilidades
        // autorizada, sem confiar em roles declaradas em um evento de entrada.
        return ApprovedPublicCatalogIssuer::issue(
            $snapshot,
            array_replace($request,['roles'=>['editor']]),
            array_replace($approval,['roles'=>['content_approver']]),
            array_replace($dispatch,['roles'=>['publisher']]),
            $keyId,$secretKey
        );
    }
}
