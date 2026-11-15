<?php

declare(strict_types=1);

namespace App\Presentation;

use InvalidArgumentException;

/** Pure fictional content, not official support or a service repository. */
final readonly class DemoHelpCatalog
{
    /** @param list<HelpTopic> $topics */
    public function __construct(public array $topics = [])
    {
        if (!array_is_list($topics) || count($topics) > 24) {
            throw new InvalidArgumentException('Expected a bounded list of help topics.');
        }
        $references = [];
        foreach ($topics as $topic) {
            if (!$topic instanceof HelpTopic || isset($references[$topic->reference])) {
                throw new InvalidArgumentException('Expected typed topics with unique fictional references.');
            }
            $references[$topic->reference] = true;
        }
    }

    public static function examples(): self
    {
        return new self([
            new HelpTopic('demo_help_general', HelpCategory::General, 'O que esta Central de Ajuda apresenta?',
                'Orientações fictícias da Empresa Aurora fictícia para ilustrar o Portal. Não são respostas oficiais nem atendimento disponível. A Central de Ajuda do Portal não é um ambiente de cursos e não implanta a Universidade Imagine.'),
            new HelpTopic('demo_help_onepage', HelpCategory::Sites, 'Como poderá ser apresentado o acompanhamento de uma Onepage?',
                'No exemplo, conteúdo e etapas de uma Onepage poderão ser organizados para leitura. Sites Imagine · Onepage é um serviço independente da Landing Page. Nenhuma implantação, publicação, contratação ou prazo é confirmado.'),
            new HelpTopic('demo_help_landing', HelpCategory::Sites, 'Como poderá ser apresentado o acompanhamento de uma Landing Page?',
                'Uma Landing Page poderá ter objetivos e materiais ilustrativos próprios, separados da Onepage. Nenhum domínio, publicação, coleta de dados ou serviço real é ativado por esta orientação.'),
            new HelpTopic('demo_help_performance', HelpCategory::Performance, 'O que representa a orientação sobre Performance AI?',
                'Performance AI é um serviço próprio, com motor de execução separado do Portal. Indicadores serão apenas exemplos fictícios; não há métricas reais, GA4 ou conexão com Google Ads e Meta nesta demonstração.'),
            new HelpTopic('demo_help_requests', HelpCategory::Requests, 'Como poderão ser compreendidas as solicitações?',
                'Resumos e históricos poderão explicar situações fictícias em uma etapa futura. Esta ajuda não abre, envia, edita ou aprova chamados e não recebe anexos. Nenhum atendimento, resposta ou SLA é prometido.'),
            new HelpTopic('demo_help_contracts', HelpCategory::Contracts, 'O que significam os exemplos de contratos?',
                'Rascunhos e situações ilustrativas servem somente à apresentação visual. Nenhum contrato, assinatura, preço, pagamento, reembolso ou direito é confirmado por esta ajuda.'),
            new HelpTopic('demo_help_crm', HelpCategory::Crm, 'Esta ajuda concede acesso ao CRM Imagine?',
                'CRM Imagine é um produto independente, com identidade, login e sessão próprios, sem SSO ou compartilhamento de credenciais com o Portal. Esta orientação não fornece link de acesso nem concede permissões.'),
            new HelpTopic('demo_help_university', HelpCategory::University, 'Como é apresentada a Universidade Imagine?',
                'Universidade Imagine é um benefício de clientes elegíveis, não uma assinatura avulsa. A ajuda não determina elegibilidade nem concede acesso a cursos; a Universidade não é implementada nesta Central.'),
        ]);
    }
}
