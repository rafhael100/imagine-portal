<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Domain\ServicePortfolio\PortfolioPreviewItem;
use App\Domain\ServicePortfolio\PortfolioProduct as Product;

/** Fictional catalog assembled without repositories, accounts or endpoints. */
final class DemoServicePortfolio
{
    /** @return list<PortfolioPreviewItem> */
    public static function items(): array
    {
        return [
            new PortfolioPreviewItem(
                Product::Onepage,
                'Projeto de página institucional independente da Landing Page. Produção externa não conectada.',
            ),
            new PortfolioPreviewItem(
                Product::LandingPage,
                'Página de conversão contratada separadamente da Onepage. Não existe editor operacional.',
            ),
            new PortfolioPreviewItem(
                Product::PerformanceAI,
                'Gestão de campanhas Google Ads e Meta por motor separado. Preços e capacidade não homologados.',
            ),
            new PortfolioPreviewItem(
                Product::ImagineCRM,
                'Serviço independente, com login e sessão próprios, sem SSO ou compartilhamento de credenciais com o Portal.',
            ),
            new PortfolioPreviewItem(
                Product::UniversidadeImagine,
                'Benefício para clientes Imagine elegíveis com serviço ativo. Não é assinatura avulsa e nenhum acesso é concedido.',
            ),
        ];
    }
}
