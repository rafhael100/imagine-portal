# RV-CT01 — Alternativa B aprovada como diretriz técnica documental

**Aprovação:** resposta contextual “ok” à pergunta direta “Você aprova a RV-CT01 pela alternativa B?”, em 04/11/2026.
**Objeto:** identidade, vínculo e autorização Site × CRM para APIs de captação principal e de contingência.
**Nível de aprovação:** DIRETRIZ TÉCNICA NORMATIVA — **não** contrato de API homologado e **não** fechamento de RV-CT01 ou P0-DOC03.

## Decisão ratificada — alternativa B

Adotar **fonte oficial versionada do vínculo Site × CRM, validação autenticada e independente pelas duas APIs e bloqueio seguro na troca/revogação**.

1. A autoridade oficial liga identidades estáveis de empresa, contrato, site, formulário, conta CRM e revisão aplicável. Site, frontend, cache, visitante, agente e n8n não escolhem o destino.
2. Cada API verifica, no backend e antes de aceitar duravelmente novo envio, autorização vigente, íntegra, específica e comprovável, incluindo escopo e revisão. Não bastam token/cadastro em cache ou indicador genérico de “CRM ativo”.
3. Se uma rota não consegue comprovar validade ou frescor aplicável, deve **falhar fechada**: sem nova recepção durável, sem T0, sem encaminhamento alternativo e sem mensagem falsa de sucesso. Outra rota somente recebe se verificar sua própria autorização.
4. A API principal e a de contingência mantêm independência de recepção/persistência conforme IN-124; falhas, duplicidade entre rotas e resultados inconclusivos demandam idempotência e reconciliação, sem alegar garantias técnicas ainda não demonstradas.
5. Site estático elegível pode existir sem CRM com aceite aplicável, mas **formulários permanecem bloqueados no backend** quando não houver destino CRM autorizado, inclusive em páginas antigas/cache (IN127-R06).
6. CRM temporariamente indisponível **não é** autorização revogada: se o destino segue oficialmente autorizado, recepção durável pode ocorrer nas rotas válidas e entrega posterior segue IN127-R01–R05.
7. Troca posterior de CRM requer chamado e execução por equipe Imagine autorizada, com validação de titularidade e de ambos os destinos, revisão preparada, barreira de novas recepções inseguras nas duas APIs, ativação da nova revisão somente após condições comprovadas e conclusão auditável. A troca técnica é gratuita; migração de dados entre contas permanece a cargo do cliente.
8. Envios já recebidos conservam T0, identidade/revisão de vínculo e destino CRM originais. Entrega ao destino anterior exige autorização contextual ainda válida (AUT-P06), sem redirecionar para nova conta nem reiniciar as **432 horas contínuas**.
9. Cortesia CRM Básico, assinatura CRM paga, contrato do site, disponibilidade do CRM e direito de captar **são estados diferentes** e devem manter autoridades próprias (AUT-P02/P10).
10. Guardar evidências mínimas de versões, decisões, mudanças e falhas, com segregação multiempresa, proteção de dados e respeito à retenção específica dos leads.

**Alternativas não adotadas:** A — dependência exclusiva de consulta síncrona central como única garantia; C — aceitar novas submissões apenas por autorização antiga/cache.

## Limites não decididos

A aprovação **não escolhe** serviços, fornecedores, banco, endpoints, schemas, assinatura/credenciais, TTL, protocolo de propagação de revogação, sincronização entre APIs, ponto de linearização da recepção, bloqueio de escrita concorrente, timers, SLAs, RPO/RTO, mecanismo de idempotência, testes ou custos. Esses detalhes exigem proposta e aprovação próprias, critérios verificáveis e homologação na fase apropriada.

**Status:** AUT-P01 a AUT-P10 B aprovadas normativamente. **RV-CT01 alternativa B APROVADA SOMENTE COMO DIRETRIZ**, contrato técnico ainda aberto. **RV-CT02 a RV-CT08 pendentes. P0-DOC03 e RF-PEND-01 ABERTOS; RF-PEND-04 não homologada; AGI-11-RB01 desabilitado.** Nenhuma programação, testes, homologação, infraestrutura, contratação ou operação real autorizada.

Referências: [RV-CT01 — minuta histórica](P0-DOC03-RV-CT01-IDENTIDADE-VINCULO-AUTORIZACAO-SITE-CRM-PROPOSTA-2026-11-04.md); [RV01 — revisão consolidada](P0-DOC03-RV01-CONSOLIDACAO-AUT-P01-P10-2026-11-04.md); [IN127-R01 a R06](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-127-R01-R06-ratificacoes-2026-11-03.md).
