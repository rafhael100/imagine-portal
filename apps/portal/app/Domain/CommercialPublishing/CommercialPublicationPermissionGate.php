<?php

declare(strict_types=1);

namespace App\Domain\CommercialPublishing;

use App\Domain\Authorization\AuthorizationContext;
use App\Domain\Authorization\AuthorizationDecision;
use App\Domain\Authorization\OrganizationAuthorizationService;
use App\Domain\Authorization\Permission;
use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\OrganizationId;
use App\Domain\Organizations\OrganizationMembership;

/**
 * F9 — avaliacao tipada SEM I/O. Nao autentica, nao atualiza grants,
 * nem pode conceder permissao de publicacao operacional por si so.
 *
 * Caller MUST supply Portal-only verified session identity + fresh/current
 * membership/grants from its trusted authority (not client-supplied data).
 * Revalidate privileges at effect time with a revision fence in transaction.
 */
final readonly class CommercialPublicationPermissionGate
{
    public function __construct(
        private OrganizationAuthorizationService $authorizer = new OrganizationAuthorizationService(),
    ) {
    }

    public function evaluate(
        OrganizationId $resourceOrganization,
        ?PortalUserId $editor,
        ?OrganizationMembership $editorMembership,
        ?PortalUserId $reviewer,
        ?OrganizationMembership $reviewerMembership,
        ?PortalUserId $publisher,
        ?OrganizationMembership $publisherMembership,
    ): AuthorizationDecision {
        if ($editor === null || $reviewer === null || $publisher === null ||
            $editor->equals($reviewer) || $editor->equals($publisher) ||
            $reviewer->equals($publisher)) {
            return AuthorizationDecision::deny();
        }

        foreach ([
            [$editor, $editorMembership, Permission::CommercialEditCatalog],
            [$reviewer, $reviewerMembership, Permission::CommercialApproveCatalog],
            [$publisher, $publisherMembership, Permission::CommercialReservePublish],
        ] as [$actor, $membership, $permission]) {
            $context = new AuthorizationContext(
                actorId: $actor,
                organizationId: $resourceOrganization,
                resourceOrganizationId: $resourceOrganization,
                requestedPermission: $permission->value,
            );
            if (! $this->authorizer->decide($context, $membership)->allowed) {
                return AuthorizationDecision::deny();
            }
        }

        return AuthorizationDecision::allow();
    }
}
