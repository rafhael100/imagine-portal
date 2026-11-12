<?php

declare(strict_types=1);

namespace App\Presentation;

/**
 * F141: internal review order for existing synthetic pages, not a
 * customer workflow, service state, SLA or authorization map.
 */
final class DemoPortalReviewWalkthrough
{
    /** @return list<array{href:string,title:string,description:string}> */
    public static function steps(): array
    {
        return [
            ['href'=>'overview.html','title'=>'Visão geral',
                'description'=>'Verifique a apresentação inicial da empresa fictícia.'],
            ['href'=>'services.html','title'=>'Meus serviços',
                'description'=>'Revise como serviços simulados são organizados.'],
            ['href'=>'available.html','title'=>'Catálogo ilustrativo',
                'description'=>'Compare a apresentação de Onepage e Landing Page, sem compra.'],
            ['href'=>'contracts.html','title'=>'Contratos demonstrativos',
                'description'=>'Avalie os exemplos sem assinaturas ou cobranças reais.'],
            ['href'=>'modules.html','title'=>'Módulos Imagine',
                'description'=>'Confira os limites entre Portal e produtos independentes.'],
            ['href'=>'journey.html','title'=>'Jornada de entrega',
                'description'=>'Observe etapas fictícias, sem prazo ou aprovação confirmada.'],
            ['href'=>'requests.html','title'=>'Solicitações de exemplo',
                'description'=>'Conheça históricos ilustrativos, sem abrir chamados.'],
            ['href'=>'help.html','title'=>'Central de Ajuda',
                'description'=>'Consulte orientações fictícias, sem suporte ativo.'],
            ['href'=>'notices.html','title'=>'Central de Avisos',
                'description'=>'Veja avisos simulados, sem envio ou leitura persistida.'],
            ['href'=>'delivery-review.html','title'=>'Revisão técnica de entregas',
                'description'=>'Entenda bloqueios de reenvio e reconciliação, sem conectar o CRM.'],
        ];
    }

    public static function authorizesAction(): bool
    {
        return false;
    }
}
