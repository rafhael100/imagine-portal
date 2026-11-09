<?php

declare(strict_types=1);

namespace App\Domain\SiteIntake;

use App\Domain\ServicePortfolio\PortfolioProduct;

/**
 * F50: exact candidate vocabulary already documented by imagine-sites.
 *
 * Mapping of names and directions only, not an authenticator, client,
 * endpoint, contract validator or authority.
 *
 * The metadata-only F48/F49 preview IS NOT an existing Sites protocol.
 */
final class SiteEngineContractVocabulary
{
    public const PORTAL_LOCAL_PREVIEW='imagine.portal.sites-handoff-preview.v1';
    public const SITES_CREATE_PROJECT_V1='1';
    public const SITES_REVIEW_PROPOSAL_V2='sites.synthetic.review-handoff.v2';

    public const PORTAL_TO_SITES='portal_to_sites';
    public const SITES_TO_PORTAL='sites_to_portal';
    public const INTERNAL_ONLY='internal_preview_no_transport';

    /**
     * The visible PortfolioProduct codes are intentionally different from
     * candidate Sites CreateProject productCode vocabulary. Never forward
     * PortfolioProduct::value directly as a wire product code.
     */
    public static function createProjectProductCode(PortfolioProduct $product): ?string
    {
        return match ($product) {
            PortfolioProduct::Onepage=>'onepage',
            PortfolioProduct::LandingPage=>'landing_page',
            default=>null,
        };
    }

    public static function productFromCreateProjectCode(string $code): ?PortfolioProduct
    {
        return match ($code) {
            'onepage'=>PortfolioProduct::Onepage,
            'landing_page'=>PortfolioProduct::LandingPage,
            default=>null,
        };
    }

    /** Inventory labels, NOT incoming-message routing or validation. */
    public static function candidateDirection(string $contractName): ?string
    {
        return match ($contractName) {
            'create_project_v1'=>self::PORTAL_TO_SITES,
            'review_handoff_epoch_v2'=>self::SITES_TO_PORTAL,
            'portal_local_preview_v1'=>self::INTERNAL_ONLY,
            default=>null,
        };
    }

    /** @return list<string> */
    public static function createProjectV1Keys(): array
    {
        return [
            'schemaVersion','operationId','tenantRef','orderRef',
            'contractRef','contractRevision','productCode',
            'idempotencyKey',
        ];
    }

    /** @return list<string> */
    public static function reviewProposalV2Keys(): array
    {
        return [
            'schemaVersion','issuer','audience','issuedAt','expiresAt',
            'nonce','authorizationRevision','candidate','mac',
        ];
    }

    public static function mayInvokeOperationalSitesEngine(): bool
    {
        return false;
    }

    public static function mayAcceptCustomerApproval(): bool
    {
        return false;
    }

    public static function mayRequestPublication(): bool
    {
        return false;
    }
}
