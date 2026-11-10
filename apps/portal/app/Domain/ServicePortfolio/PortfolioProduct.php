<?php

declare(strict_types=1);

namespace App\Domain\ServicePortfolio;

/**
 * Closed preview vocabulary for the five visible offerings. It must not be
 * confused with an actual product catalog, published price or entitlement.
 */
enum PortfolioProduct: string
{
    case Onepage = 'sites_onepage';
    case LandingPage = 'sites_landing_page';
    case PerformanceAI = 'performance_ai';
    case ImagineCRM = 'imagine_crm';
    case UniversidadeImagine = 'universidade_imagine';

    public function label(): string
    {
        return match ($this) {
            self::Onepage => 'Sites Imagine · Onepage',
            self::LandingPage => 'Sites Imagine · Landing Page',
            self::PerformanceAI => 'Performance AI',
            self::ImagineCRM => 'Imagine CRM',
            self::UniversidadeImagine => 'Universidade Imagine',
        };
    }

    public function kind(): PortfolioKind
    {
        return match ($this) {
            self::Onepage, self::LandingPage, self::PerformanceAI => PortfolioKind::IndependentService,
            self::ImagineCRM => PortfolioKind::IndependentAccount,
            self::UniversidadeImagine => PortfolioKind::IncludedBenefit,
        };
    }

    public function requiresOwnLogin(): bool
    {
        return $this === self::ImagineCRM;
    }

    /**
     * Only a business-policy distinction: the benefit cannot be sold alone.
     * Does NOT mean that other products may be purchased in this preview.
     */
    public function isStandaloneServiceByPolicy(): bool
    {
        return $this !== self::UniversidadeImagine;
    }
}
