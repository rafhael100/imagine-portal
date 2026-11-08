<?php

declare(strict_types=1);

namespace App\Presentation;

use InvalidArgumentException;

/** Deterministic, in-memory presentation content. Never a notification service. */
final readonly class DemoNoticeCatalog
{
    /** @param list<DemoNotice> $notices */
    public function __construct(public array $notices = [])
    {
        if (!array_is_list($notices) || count($notices) > 32) {
            throw new InvalidArgumentException('Expected a bounded list of illustrative notices.');
        }
        $references = [];
        foreach ($notices as $notice) {
            if (!$notice instanceof DemoNotice || isset($references[$notice->reference])) {
                throw new InvalidArgumentException('Expected typed notices with unique fictional references.');
            }
            $references[$notice->reference] = true;
        }
    }

    public static function examples(): self
    {
        return new self([
            new DemoNotice('demo_notice_platform', NoticeCategory::General, 'Organização ilustrativa da plataforma',
                'Cenário da Empresa Aurora fictícia: orientações de serviços poderão ser reunidas para leitura. Isto não informa disponibilidade real, infraestrutura ativa ou notificação enviada.'),
            new DemoNotice('demo_notice_onepage', NoticeCategory::Sites, 'Conteúdo de exemplo de uma Onepage',
                'Sites Imagine · Onepage é independente da Landing Page. A narrativa ilustra uma possível revisão de conteúdo, sem serviço contratado, domínio, publicação ou prazo confirmado.'),
            new DemoNotice('demo_notice_landing', NoticeCategory::Sites, 'Objetivos fictícios de uma Landing Page',
                'Uma Landing Page poderá ter materiais próprios, separados da Onepage. Este aviso não ativa site, coleta, contratação ou infraestrutura.'),
            new DemoNotice('demo_notice_request', NoticeCategory::Requests, 'Acompanhamento fictício de uma solicitação',
                'Um histórico de exemplo poderá explicar situações ilustrativas. Nenhum chamado foi aberto, enviado, atribuído ou aprovado e nenhum atendimento ou SLA é prometido.'),
            new DemoNotice('demo_notice_performance', NoticeCategory::Performance, 'Campanhas apenas ilustradas em Performance AI',
                'Performance AI é um serviço próprio, com motor de execução separado do Portal. Nenhuma campanha é ativada; não há métricas reais ou conexão com Google Ads, Meta ou GA4.'),
            new DemoNotice('demo_notice_media', NoticeCategory::MediaCredit, 'Recarga de mídia · possibilidade futura',
                'Este aviso ilustrativo de recarga de mídia pertence ao conceito futuro de Performance AI. Não representa pagamento, saldo, valor, calendário, recarga agendada ou disparo. Regras posteriores dependem de decisões próprias do produto; não há envio por SMS, WhatsApp ou e-mail.'),
            new DemoNotice('demo_notice_university', NoticeCategory::University, 'Possível benefício da Universidade Imagine',
                'Universidade Imagine é benefício de clientes elegíveis, não assinatura avulsa. Esta orientação não determina elegibilidade nem concede acesso a cursos.'),
            new DemoNotice('demo_notice_crm', NoticeCategory::Crm, 'Separação dos avisos do CRM Imagine',
                'CRM Imagine mantém identidade, login e sessão próprios, sem SSO com o Portal. Esta Central não replica notificações do CRM nem fornece link de acesso ou permissões.'),
        ]);
    }
}
