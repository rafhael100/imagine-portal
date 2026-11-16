# Imagine Performance AI — fase 2, execução técnica

Data: 16/11/2026 UTC (início em 15/11). DRAFT / NO-MERGE / NO-DEPLOY / NO-CHARGE / NO-ADS-WRITE.

A Central está mais preparada para homologação, mas NÃO é operação real homologada. Nenhuma alteração na VPS, DNS, produção, cobrança ou campanha foi executada nesta rodada. Não existe novo endereço de demonstração implantado por esta entrega.

## Bases verificadas

| Referência | HEAD inspecionada antes das alterações |
|---|---|
| Portal887 RC49 | 82624818832ee04b64111154fd551e2cca34c27e |
| Portal888 Docker | 7d02860e0d331699df4700b305999e738f760859 |
| Portal889 Central | bf6b22ba90cd69617087745b5c6476dc2bd41f42 |
| Performance410 V7 | 3b34173ea3aa368b7db3b748bdae099f2540102e |
| Performance412 motor | 238bcb026aca0a02d90c0ebff38fb2d4f35d8e1f |

As PRs estavam abertas em DRAFT; não foram presumidas incorporadas à main.

## Entregas e PRs

| PR DRAFT | Conteúdo |
|---|---|
| [Portal890](https://github.com/rafhael100/imagine-portal/pull/890) | Correções específicas da interface, relatórios, contexto e ações elegíveis |
| [Performance413](https://github.com/rafhael100/imagine-performance-ai/pull/413) | Consumo exige aprovador identificado, positivo e diferente do executor |
| [Portal891](https://github.com/rafhael100/imagine-portal/pull/891) | Candidata integrada RC49/Docker/Central, modalidade privada em staging, runtime restrito e evidências |
| [Performance414](https://github.com/rafhael100/imagine-performance-ai/pull/414) | Esquemas separados multiempresa, envelope S2S, inbox persistente, contratos financeiros e restore |
| [Portal892](https://github.com/rafhael100/imagine-portal/pull/892) | Cliente S2S candidato, desligado e sem ligação automática com a UI, contrato pinado |

As branches são empilhadas para revisão. Nenhum merge entre main/dev ou deploy foi realizado.

## Funcionalidades implementadas

1. `approval.consume` aparece somente para internal_note com aprovação íntegra, vigente, aprovador distinto e papel/MFA elegíveis. Backend revalida no POST. Incidentes sem evidência não oferecem resolução. Removida ramificação duplicada de relatórios; abas levam aos módulos reais preservando empresa, filtros e retorno.
2. Cache renumerado para000017, sem conflito com laboratório000016; função de lock em000018. Runtime recebe variáveis explícitas, sem senha do proprietário do banco. Concessões não permitem alterar vínculos, papéis ou credenciais. Migrações continuam separadas, com proprietário.
3. APP_ENV=staging com modo private_staging, política estrita, dados sintéticos e flags desligadas por padrão. Nginx sem root/capabilities, TLS e acesso nominal adicional, porta loopback obrigatória. Aplicação e banco em redes internas; ingresso TLS separado. Aplicação sem rota padrão de saída. Segredos externos e excluídos da imagem/Git.
4. Modelo SQL candidato separado para empresas, serviços, operadores, seis papéis, vínculos expirantes/revogáveis, contas autorizadas e auditoria append-only. RLS usa session_user nativo, sem confiar em GUC do navegador. organization_ref compatível com string100 do Portal. Não há promoção de tabelas lab-alfa/lab-beta nem ponte de identidade operacional habilitada.
5. Envelope S2S v1 com HMAC, audience, empresa/operação, TTL, nonce, hash do corpo e correlação. Inbox PostgreSQL transacional e idempotência persistente, revalidação de revogação antes do recibo, timeout de lock e rollback em falha. Operações aceitas são exclusivamente de leitura administrativa.
6. Cliente Portal candidato exige HTTPS verificado, sem redirects, limites connect2s/read5s, duas tentativas, nonce novo e idempotência estável. Não encaminha Authorization/Cookie humano. Schema fechado recusa tokens/sessões no corpo. Respostas permanecem synthetic/unavailable; cobrança e operações externas falsas.
7. Contratos de revisão financeira reutilizam V7 (implantação490/canal;20%; mínimo170 no mês cheio; mídia separada). Dados ausentes não viram métricas zero. Parcial e mudanças de canal bloqueiam fechamento até política aceita versionada; nova revisão exige nova conferência. Nenhuma cobrança é autorizada.

## Evidências executadas

| Verificação | Resultado comprovado |
|---|---|
| Motor administrativo | 43 assertions aprovadas |
| Correção Portal890 | 12 testes,180 assertions; browser105 nesta PR |
| Central integrada891 | 14 testes,198 assertions |
| Central+cliente candidato892 | 16 testes,213 assertions |
| Browser | 120 assertions; senha+TOTP reais em CI, dez módulos, larguras320/390/768/1440, pausa persistida, filtros e CSRF |
| Acessibilidade automatizada | Dez arquivos axe, zero violações nos módulos analisados, tags WCAG2A/AA e2.1A/AA; não substitui revisão humana |
| Contratos novos Performance | 24 assertions de protocolo/sessão/financeiro |
| PostgreSQL18 candidato | 22 assertions, duas identidades nativas, acesso cruzado, vínculos revogados/expirados, auditoria imutável, runtime restrito, replay, idempotência e disputa por lock |
| Backup/restauração CI | Dump cifrado, detecção de adulteração, restore em banco separado; auditoria, recibos, revogação/expiração e replay preservados |
| Docker/staging HTTPS | Aprovado no runner descartável: build, migrations, runtime restrito, flags OFF, TLS verificado, acesso adicional401, senha/TOTP, sessões DB cifradas, browser120, dez módulos/axe e app sem rota padrão de saída. |

Falhas encontradas durante execução foram corrigidas: ordem de migrations; credenciais de proprietário herdadas por env_file; disponibilidade inicial do ingresso; prefixo/gates do seed CI e precedência de variáveis; contexto de errors no render Blade direto; browser.newPage incompatível com a análise multipágina do axe; necessidade de rede própria do ingresso para manter app sem egress. O teste de recibo foi corrigido para comparação semântica de JSONB (ordem de chaves não é significado). Nenhuma verificação de segurança foi desligada para passar testes.

## Real versus sintético

São execuções reais de infraestrutura descartável: Laravel, password/MFA, sessões, autorização do servidor, PostgreSQL18, RLS, locks, auditoria, persistência, TLS CI e restauração. Os registros de negócio, empresas lab, campanhas, agentes, aprovações e números exibidos são sintéticos, explicitamente rotulados. Não há conexão operacional com clientes/contratos, provedores de anúncios, agentes externos, extratos ou gateways.

Google/Meta não foram consultados. ChatGPT Ads permanece indisponível sem API/acesso homologados. Asaas/C6 sandbox NÃO foi executado. O cliente HTTP foi testado com HTTP fake; o inbox foi testado em PostgreSQL, separadamente. Isso não constitui comunicação remota Portal↔Performance homologada nem prova de identidade de serviço operacional.

## Bloqueios e checklist de publicação

- [ ] Revisão independente AppSec/DBA/LGPD/UX: a revisão desta rodada é do autor, sem alegação de independência.
- [ ] Ponte de identidade e elegibilidade Performance por empresa: concessões atuais do Portal são seo.view e não autorizam Performance. Não substituir essa ausência por flags de sucesso.
- [ ] Receiver privado, identidade de serviço operacional, cofre/rotação e homologação S2S ponta a ponta com HTTPS confiável; não encaminhar tokens humanos.
- [ ] Conectar fontes oficiais read-only, evidências verificáveis e reconciliação; preservar N/D quando ausentes.
- [ ] Definir/aceitar política versionada para primeiro mês parcial e alterações de canais; credenciais e execução dos gateways em sandbox.
- [ ] Inventário da VPS, porta livre e Compose exclusivo, domínio/TLS real, contas nominais/MFA/restrição de rede. VPS não acessada ou modificada nesta rodada.
- [ ] Fixar imagens/dependências de release e verificar permissões/segredos reais. Provar backup/restauração e rollback de imagem na VPS isolada; restore CI não prova backup operacional.
- [ ] Reexecutar os fluxos no staging privado autorizado, inclusive concorrência interprocessos e revogação durante operação. Dois clientes PG/lock timeout e revisões obsoletas não equivalem a um teste de carga operacional.
- [ ] Obter autorização específica de deploy. Manter NO-MERGE/NO-DEPLOY/NO-CHARGE/NO-ADS-WRITE até liberação formal.

Próxima prioridade objetiva: revisão independente da candidata integrada e da ponte de identidade, seguida de receiver S2S privado e homologação com fontes somente leitura. Não liberar dados reais com base apenas neste laboratório.

## Links das execuções verificadas

- Central integrada: https://github.com/rafhael100/imagine-portal/actions/runs/43778694082
- Central com cliente candidato: https://github.com/rafhael100/imagine-portal/actions/runs/43778697530
- Docker/TLS privado candidato: https://github.com/rafhael100/imagine-portal/actions/runs/43778697798
- Contratos/PG18/restore: https://github.com/rafhael100/imagine-performance-ai/actions/runs/43777946551

HEAD funcional testada de Portal892:242419fc2fd34b405aa48c6b364864655dbc2dfe; Performance414:99300bb0259b557d0580674e37fdffe51e00d103. O commit que acrescenta este relatório é documental; as evidências acima correspondem ao código funcional indicado. Artefatos de telas/axe estão nas execuções, com retenção de7 dias, sem telas de senha/MFA.

