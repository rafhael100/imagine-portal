# Portal SEO F119 — prova cruzada Engine F114 → Portal PHP

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Complementa a F118.

O [SEO Engine RC44](https://github.com/rafhael100/imagine-seo-engine/pull/173) é obtido no GitHub Actions pelo **commit imutável** `2a153cedd0b5fc8ad3d5d26ce103a2043be38a72`. O Node gera arquivos HTML sintéticos locais e chama a função real `reviewMunicipalStaticBuild` do Engine. O JSON de saída original é entregue diretamente ao adaptador PHP F118, sem dados de clientes e sem URLs no resultado sanitizado.

Quatro cenários são exigidos:
1. `clean`: rota/idioma corretos; resposta só permite revisão humana, não publicação.
2. `missing`: caminho municipal ausente; bloqueio no PHP.
3. `wrong-locale`: rota presente mas declarada em idioma divergente; bloqueio.
4. `private-preview`: prévia editorial privada dentro do build publicável; bloqueio.

O PHP recalcula o estado, rejeita flags de publicação e verifica que nenhum caminho ou identificador do fixture escapou para a resposta segura.

CI: `.github/workflows/portal-seo-f119-pinned-build-contract.yml`, matriz PHP 8.3/8.4 e Node 22, sem Composer, banco, serviços externos, credenciais, OAuth, VPS ou deploy. Os arquivos HTML são temporários e apagados imediatamente após cada cenário.

## Limitações

A execução é uma **prova técnica de compatibilidade de contratos**, não uma sessão real autenticada do Portal, nem prova de publicação. A leitura de relatório por empresa exige emissor confiável, MFA, binding tenant, origem de relatório assinada/verificada, banco e staging. O resultado local jamais autoriza exibição a clientes. A versão do Engine está fixada nesta revisão; atualizações requerem nova análise e reexecução de CI.
