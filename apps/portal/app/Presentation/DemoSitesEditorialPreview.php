<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServicePortfolio\PortfolioProduct;
use App\Domain\SiteIntake\DemoSiteReviewJournal;
use App\Domain\SiteIntake\SiteBriefDraft;
use App\Domain\SiteIntake\SiteContentCandidate;
use App\Domain\SiteIntake\SiteReviewCommand;
use App\Domain\SiteIntake\SiteReviewDecision;

/**
 * F47: two deterministic, local-only editorial fixtures for the existing
 * Portal Requests Blade preview. These rows are NEVER a real site order,
 * customer identity, editorial sign-off, contract, or publishing permission.
 */
final class DemoSitesEditorialPreview
{
    /** @return list<SiteContentCandidate> */
    public static function candidates(): array
    {
        $org=new OrganizationId('org-a');
        return [
            new SiteContentCandidate(
                new SiteBriefDraft(
                    'DEMO-SITE-REQ-ONEPAGE',
                    $org,
                    PortfolioProduct::Onepage,
                    'Apresentar serviços e informações do negócio com clareza.',
                    'Pequenas empresas locais',
                    'Solicitar uma apresentação de serviços',
                    1,
                ),
                2,
                'Uma presença digital para sua empresa',
                'Página institucional ilustrativa para reunir informações sobre serviços, benefícios e contato, sem dados reais.',
                'Conhecer os serviços',
            ),
            new SiteContentCandidate(
                new SiteBriefDraft(
                    'DEMO-SITE-REQ-LANDING',
                    $org,
                    PortfolioProduct::LandingPage,
                    'Apresentar uma oferta em página independente de conversão.',
                    'Visitantes interessados em serviços digitais',
                    'Solicitar uma proposta ilustrativa',
                    0,
                ),
                1,
                'Transforme visitas em oportunidades',
                'Exemplo de página de conversão com conteúdo simulado e decisão editorial ainda aguardando revisão.',
                'Solicitar proposta',
            ),
        ];
    }

    /** @return list<array{candidate:SiteContentCandidate,status:string}> */
    public static function snapshots(): array
    {
        [$onepage,$landingPage]=self::candidates();
        $review=new DemoSiteReviewJournal($onepage);
        $decision=$review->apply(new SiteReviewCommand(
            'DEMO-REVIEW-CMD-PREVIEW',
            $onepage->brief->organizationId,
            $onepage->brief->reference,
            $onepage->fingerprint,
            'DEMO-REVIEWER-A',
            0,
            SiteReviewDecision::Approve,
            1800000030,
        ));
        if (!$decision->isSimulatedApproval()
            || $decision->mayPublishSite()
            || $review->canPublishSite()) {
            return [];
        }

        return [
            ['candidate'=>$onepage,'status'=>'Aprovação simulada — não é aceite do cliente'],
            ['candidate'=>$landingPage,'status'=>'Aguardando revisão fictícia'],
        ];
    }

    /** @return list<list<string>> */
    public static function rows(): array
    {
        $rows=[];
        foreach (self::snapshots() as $entry) {
            $candidate=$entry['candidate'];
            $rows[]=[
                $candidate->brief->reference,
                $candidate->brief->product->label(),
                $candidate->headline,
                'Briefing '.$candidate->brief->revision
                    .' · Conteúdo '.$candidate->contentRevision,
                $entry['status'],
            ];
        }
        return $rows;
    }

    public static function mayPublish(): bool
    {
        return false;
    }

    public static function isAuthenticatedApproval(): bool
    {
        return false;
    }
}
