# RF-PEND-01 — Matriz inicial de fontes, lacunas e diferenças documentais

**Projeto:** Imagine Sites (Onepage/Landing Page, Portal Imagine e integração com CRM Imagine).  
**Estado:** CONFERÊNCIA PARCIAL, NÃO ENCERRADA. Documento de trabalho para reconciliar aprovações expressas, versões e trechos históricos.  
**Origem:** leitura autorizada dos repositórios GitHub e aprovações documentais de 03/11/2026.  
**Autorização de escrita:** somente para **documentação de decisões já aprovadas**, nunca código, testes, produção, homologações ou automações.  
**Restrições:** RF-PEND-01 aberta; RF-PEND-04 não homologada; rollback automático AGI-11-RB01 desabilitado.

## 1. Repositórios e escopo observado

| Repositório | Branch | Estado observado | Limitação |
|---|---|---|---|
| [rafhael100/imagine-portal](https://github.com/rafhael100/imagine-portal) | `main` | Repositório privado acessível; contém `docs/` de Portal, jornada Onepage, ofertas e vários módulos; árvore inicial consultada | Não comprova cobertura integral Imagine Sites |
| [rafhael100/imagine-crm](https://github.com/rafhael100/imagine-crm) | `main` | Repositório privado acessível; contém `docs/planejamento/IN-124` a `IN-149` e políticas PORTAL | Documentos históricos podem estar desatualizados frente a aprovações recentes |
| [rafhael100/imagine-crm](https://github.com/rafhael100/imagine-crm) | `docs/integracao-site-crm-basico` | Branch histórica encontrada; `docs/decisoes.md` lido parcialmente | Comparação integral com main não realizada |

Na árvore principal inicialmente consultada, não foi localizada uma série completa de arquivos canônicos AGI-RF01-R01 a R07, AGI-P09 a P18 ou CLD. Isso é uma **lacuna de localização na árvore examinada**, não prova de inexistência global.

A criação posterior deste conjunto de documentos altera a fotografia do repositório; não apagar ou reescrever o achado histórico.

## 2. Rastreabilidade das fontes lidas (amostragem)

| Fonte histórica no CRM | Blob SHA lido | Tema |
|---|---|---|
| [IN-124](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-124-integracao-inteligente-formularios-leads.md) | `e66f3d065f25efac373c86d23e5c410712b12fd8` | Recepção durável, APIs e destino |
| [IN-125](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-125-gestao-inteligente-formularios.md) | `0d3ec62c84d3790d4ca09af66a2fa8d8fba5f12d` | Formulários e LGPD |
| [IN-127](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-127-retencao-seguranca-filas-leads.md) | `fec6772d641a2800dbbe06c9fb11c55cc7c07da5` | Prazo e filas |
| [IN-128](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-128-limpeza-segura-auditoria-filas-leads.md) | `f2d5de2f5109603629e72c87a301fa55a53fb3c4` | Limpeza, aviso e auditoria |
| [IN-147](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-147-exclusao-leads-nao-entregues-apos-18-dias.md) | `cb1fe1985e51c59a27e8238f88cb2130271f05fd` | Exclusão sem quarentena |
| [IN-148](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-148-reconciliacao-inteligente-leads.md) | `d3be1ea0d05abc9936cfcc7678579b80e1245a9b` | Reconciliação |
| [IN-149](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-149-protocolo-entrega-confirmacao-leads.md) | `f1161ac2c1140d70bc9ad74e8494afd0bd969761` | Confirmação CRM |
| [PORTAL-D04](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-D04-cobranca-recorrente-sites.md) | `ed2fe5ee3136b266e22ddb64a7720ac0f5e61e43` | Pagamentos recorrentes |
| [PORTAL-COM-03](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-03-motor-precos-promocoes-pix-contadores.md) | `08ca39b14be6116a7c54f5ca2b8ea3f045507cee` | Política de descontos histórica |
| [PORTAL-01](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-01-matriz-pendencias-plataforma-sites.md) | `24666bfcb9964cba3ec6de34cb89fd00e6eed66f` | Visão e pendências históricas |
| [PORTAL-COM-14](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-14-lgpd-aceites-privacidade-consentimentos-checklist.md) | `79cd1034af440e6720f4fbd0b1d33084d626f0c3` | Privacidade e avisos |
| [PORTAL-COM-15](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-15-central-documentos-legais-consentimentos.md) | `be4da55eadfdc836a8833361ade730108d545069` | Documentos legais |
| [docs/decisoes.md — branch histórica](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/decisoes.md) | `0e05c23551b46da4b9b93d73028ab55d549b3374` | Histórico de decisões |

O blob SHA refere-se ao conteúdo consultado, não constitui prova de homologação. As fontes não foram todas examinadas integralmente linha a linha.

## 3. Diferenças documentais e saneamento proposto — IN-127

| Código | Fonte | Observação verificada | Tratamento necessário | Situação |
|---|---|---|---|---|
| DIF-IN01 | IN-127 | Registra 3+15 dias; não traz todas as definições R01/R02 de 72h+360h | Referenciar explicitamente T0, 72h, 432h e não reinício | Ratificação R01/R02 salva em adendo |
| DIF-IN02 | IN-128 | Registra 90 dias de auditoria; marco e teto exatos da R04 não estão consolidados | Aplicar limite de **até** 90×24h desde evento terminal; sem reinício tardio | Adendo; texto histórico pendente de revisão |
| DIF-IN03 | IN-147 | Admite descarte automático após 18 dias, com ressalvas | Harmonizar com obrigação R03 de encerrar e excluir payload ativo/temporário, respeitando exceções | Adendo |
| DIF-IN04 | IN-148 | Já prevê idempotência/reconciliação; falta enunciar plenamente o pós-vencimento da R05 | Reforçar consulta sem novo envio após 432h e prazo de auditoria | Adendo |
| DIF-IN05 | IN-149 | Reconhece confirmação e resultado incerto, sem explicitar todas as restrições ratificadas R05 | Diferenciar momento real da entrega e momento da confirmação; sem reabrir fila | Adendo |
| DIF-IN06 | IN-125 | Prevê privacidade e integração; não consolida R06 por inteiro | Site estático permitido sem CRM; formulário sem destino autorizado não recebe; bloquear backend | Adendo |
| DIF-IN07 | IN-124 | Prevê autenticação de destino e confirmação durável | Explicitamente proteger contra requisições de páginas antigas/cache | Adendo |
| DIF-IN08 | IN-124 a 149 | Contratos e estados espalhados | Referência transversal às seis ratificações | Adendo criado no CRM |

**Fonte normativa posterior já documentada:** [IN-127-R01-R06-ratificacoes-2026-11-03.md](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-127-R01-R06-ratificacoes-2026-11-03.md).

## 4. Regras antigas independentes a preservar

- IN-128: limpar temporários **24h após confirmação de entrega ao CRM**, sem excluir o registro CRM.
- IN-128/IN-147: aviso preventivo **72h antes do vencimento**, isto é, T0+360h segundo R02; alerta crítico no limite.
- IN-147: não criar quarentena adicional de 30 dias.
- IN-124/148/149: comprovação efetiva do CRM, idempotência e não mesclagem automática de envios comerciais legítimos.
- IN-125 e COM-14/15: política de privacidade padrão para site simples tem regime específico; jamais confundir ciência da política, consentimento de marketing e **aceite expresso do artefato do site**.
- A verificação final de elegibilidade de descarte precisa conviver com o encerramento às 432h, sem nova entrega após prazo e sem conservação informal de payload.

## 5. Divergências comerciais históricas verificadas

| Código | Fonte anterior | Texto/status histórico | Decisão posterior do usuário | Classificação |
|---|---|---|---|---|
| DIF-COM01 | PORTAL-COM-03 | 20% de desconto adicional no Pix de implantação descritos como diretriz em 02/11 | **5% Pix à vista** | Candidato a atualização por substituição posterior |
| DIF-COM02 | PORTAL-D04 | 10% de desconto no Pix Automático em 02/11 | **5% Pix Automático** | Candidato a atualização por substituição posterior |
| DIF-COM03 | PORTAL-COM-02 | Estudo de 20% no Pix / cartão 4×, sinalizado como proposta | **Pix à vista 5% / cartão até 3×**, sem Pix parcelado | Manter estudo com aviso de histórico |
| DIF-COM04 | PORTAL-01 | Convivência de etapas antigas, propostas e regras aprovadas | Precedência do acordo comercial posterior | Exige índice de decisões vigentes |

**Política posterior aprovada:** implantação Pix à vista 5% ou cartão até 3× sem desconto; mensalidade Pix Automático 5% ou cartão recorrente sem desconto Pix; Pix parcelado não é modalidade comercial. O preço-base final por produto e a cumulatividade de campanhas continuam sujeitos a revisão própria.

**Não** reescrever as aprovações antigas como se nunca tivessem existido. Marcar sua substituição por data, motivo e referência verificável.

## 6. Fontes e séries ainda incompletas

| Corpus | Situação |
|---|---|
| AGI-RF01-R01 a R06 | Aprovações documentais conhecidas, mas textos integrais de vários blocos **ainda não reconstruídos nem comparados** com fontes originais |
| AGI-RF01-R07.01 a .48 | Títulos/objetos aprovados registrados no documento de R07; decisão dentro de cada objeto não ganha aprovação automática |
| AGI-P09 a P18 e AGI-P1 | Resumos de aprovações conhecidos; corpus técnico integral não conferido |
| CLD e P0 | Contratos e requisitos críticos precisam de inventário completo |
| IN-091 a IN-115 | Parte registrada em documentação histórica e conversas, com diferentes branches e épocas |
| Branch `docs/integracao-site-crm-basico` | Encontrada, `docs/decisoes.md` localizado; diff integral ainda não realizado |
| Integrações de fornecedores | Candidatos e planos não homologados |

**Não atribuir status “completamente salvo” a requisito cuja redação completa ainda não esteja verificada.**

## 7. Critérios para encerramento de RF-PEND-01

- Corpus completo de repositórios/branches/documentos identificado e inventariado.
- Aprovações expressas ligadas a versões e fontes verificáveis.
- Requisitos críticos comparados individualmente, inclusive ressalvas.
- Divergências e substituições resolvidas, sem apagar histórico.
- Documentação final revisada e autorizada.
- Evidências e commits de alterações documentais registrados.
- Parecer final de reconciliação aprovado pelo responsável.

**Atualmente: NÃO atendidos integralmente.** Ratificação documental IN-127 (RF-PEND-04) não é homologação técnica.

## 8. Proibição de efeitos operacionais

Nenhum trecho deste documento autoriza execução de testes, instalação, deploy, contratação, homologação de Cloudflare ou fornecedores, escrita em arquivos de código, mudança em workflows, habilitação do rollback automático **AGI-11-RB01** ou início de programação.

**A escrita permitida pelo usuário limita-se à documentação de decisões já aprovadas.**
