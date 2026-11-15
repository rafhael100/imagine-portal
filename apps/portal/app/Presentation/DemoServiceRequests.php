<?php

declare(strict_types=1);

namespace App\Presentation;

/** Deterministic fictional fixtures. No repository, gateway, identity or access policy. */
final class DemoServiceRequests
{
    /** @return list<ServiceFollowUp> */
    public static function services(): array
    {
        return [
            new ServiceFollowUp('DEMO-SVC-ONEPAGE', 'Sites Imagine · Onepage', JourneyState::Preparing,
                'Projeto fictício independente da Landing Page; conteúdo apenas ilustrativo.',
                'Após a revisão ilustrativa do roteiro, sem data prevista.'),
            new ServiceFollowUp('DEMO-SVC-LANDING', 'Sites Imagine · Landing Page', JourneyState::Waiting,
                'Projeto fictício independente da Onepage; objetivos de exemplo em revisão.',
                'Após a organização fictícia dos objetivos, sem data prevista.'),
            new ServiceFollowUp('DEMO-SVC-PERFORMANCE', 'Performance AI', JourneyState::Example,
                'Serviço próprio, com motor de execução separado. Google Ads e Meta não conectados.',
                'Nova leitura dos indicadores de exemplo, sem data prevista.'),
        ];
    }

    /** @return list<RequestSummary> */
    public static function requests(): array
    {
        return [
            new RequestSummary('DEMO-REQ-ONEPAGE', 'DEMO-SVC-ONEPAGE', 'Revisão fictícia do roteiro da Onepage',
                'Exemplo de organização de conteúdo da Empresa Aurora fictícia. Nenhum material será enviado.',
                JourneyState::Preparing, [
                    new IllustrativeUpdate('Registro ilustrativo 1', 'Roteiro de exemplo organizado',
                        'Seções fictícias reunidas apenas para apresentação.'),
                    new IllustrativeUpdate('Registro ilustrativo 2', 'Revisão fictícia em preparação',
                        'Nenhum chamado foi aberto, enviado ou atribuído.'),
                ]),
            new RequestSummary('DEMO-REQ-LANDING', 'DEMO-SVC-LANDING', 'Objetivos fictícios da Landing Page',
                'Pendência de exemplo, sem aprovação, coleta de dados ou consentimento real.',
                JourneyState::Waiting, [
                    new IllustrativeUpdate('Registro ilustrativo 1', 'Objetivo de exemplo descrito',
                        'A narrativa aguarda complemento fictício, sem prazo de atendimento.'),
                ]),
            new RequestSummary('DEMO-REQ-PERFORMANCE', 'DEMO-SVC-PERFORMANCE', 'Leitura ilustrativa de Performance AI',
                'Etapa ilustrada no exemplo; nenhum resultado real, campanha ou provisão executada.',
                JourneyState::Illustrated),
        ];
    }
}
