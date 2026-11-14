<?php

declare(strict_types=1);

namespace App\Presentation;

/**
 * F399: an INERT, local HTML-only review of the planned Imagine Commercial
 * -> Client Portal experience. Never an order, payment, login or entitlement.
 * The hyperlinks deliberately target only pages from the existing private
 * 11-file DEMO export manifest.
 */
final class DemoCommercialPortalJourney
{
    /** @return list<array{title:string,description:string,href:string}> */
    public static function steps(): array
    {
        return [
            [
                'title'=>'1. Conhecer os serviços no site Imagine',
                'description'=>'Somente apresentação do catálogo. O site comercial, preços e checkout reais não são consultados nesta prévia.',
                'href'=>'available.html',
            ],
            [
                'title'=>'2. Revisar o pedido ilustrativo',
                'description'=>'Ilustração da relação entre serviço e solicitações. Nenhuma contratação, cobrança ou provisionamento é realizado.',
                'href'=>'requests.html',
            ],
            [
                'title'=>'3. Entrar no espaço do cliente fictício',
                'description'=>'Visão geral da Empresa Aurora demonstrativa. Não existe login ou sessão de cliente nesta prévia.',
                'href'=>'overview.html',
            ],
            [
                'title'=>'4. Acompanhar serviços e contratos de exemplo',
                'description'=>'Veja serviços e contratos apenas como elementos visuais, sem vínculo jurídico, pagamento ou acesso real.',
                'href'=>'services.html',
            ],
        ];
    }

    public static function authorizesCommercialCheckout(): bool { return false; }
    public static function authorizesPortalLogin(): bool { return false; }
    public static function authorizesAccountPurchase(): bool { return false; }
    public static function transfersCustomerData(): bool { return false; }
}
