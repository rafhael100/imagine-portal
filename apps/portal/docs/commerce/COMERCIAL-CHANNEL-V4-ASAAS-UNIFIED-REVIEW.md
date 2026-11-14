# P0 — unificar canal Comercial com o checkout v4 e candidato Asaas

## Origem e seleção de código

Base: PR Portal #745 (HEAD `9a269c7b9af889b61ac367884890834688ef8e69`), que reuniu checkout aprovado, catálogo v4 e fontes financeiras Asaas F387–F392. As alterações de contexto Google/Meta derivam exclusivamente da PR Portal #746 (HEAD `7a91f3acb16ed7b454030ab9d7fa47bf1bb2b422`). Esta branch **não fez merge de histórico** nem sobrescreveu o catálogo v4, sua classe de reconciliação, provedor Asaas, webhooks, worker, evidências ou controles de valor.

## Resultado técnico

- `CustomerPreviewHandoff` aceita somente `onepage`, `landing-pages`, `performance`, e canais `google_ads` / `meta_ads` exclusivamente quando o produto é Performance.
- Cadastro e login individual de homologação preservam o contexto até a tela de escolha.
- A tela mantém os campos de mídia **vazios**: a origem do CTA nunca autoriza valor, contrato, pagamento, desconto ou titularidade. O preço é recalculado pelo catálogo PHP do Portal, com snapshot/versionamento.
- O texto financeiro de Performance é o **v4**, não o da PR #746 mais antiga: 20% de gestão sobre mídia planejada com antecipação e acerto pelo gasto efetivo conforme decisão aprovada na linha #744/#745. A mídia vai diretamente ao Google/Meta, não é receita Imagine.
- Testes PHP e Chromium estendidos para contexto de canal, cadastro, login, orçamento vazio, rejeição de canal inválido, sem substituir os testes financeiros existentes.

## Gates de promoção

- Revisão independente de diffs dos dois projetos, incluindo a divergência paralela da PR Portal #742, cujo conteúdo NÃO foi integrado automaticamente aqui.
- CI de checkout/PostgreSQL18, preços v4, F390/F391/F392 e autenticação na mesma SHA; não basta verde em branches ancestrais.
- Compatibilidade dos links do Comercial #379/#380 com esta HEAD fixada e teste de HTTP local. A linha #380 inicialmente usa a pinagem de #746 e deve ser atualizada e revalidada antes de chamar o conjunto de unificado.
- Operação real depende de credenciais privadas de sandbox Asaas, ledger/cliente PSP, revisão LGPD/AppSec/DBA, IAM real, contrato, fiscal, backups e revogação independente.

**Status: DRAFT / NO-GO / NO-MERGE / NO-DEPLOY / NO-CHARGE.** Sem instalação VPS ou chaves PSP.
