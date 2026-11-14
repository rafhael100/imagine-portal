# P0 — desativar checkout compartilhado na prévia privada da VPS

## Problema resolvido na imagem candidata

O arquivo `router.php` permitia duas famílias de rotas na imagem isolada: `/_preview/portal` (login de cliente/admin e pedido próprio) **e** `/_rehearsal/purchase` (recurso antigo de compra fictícia, sem propriedade individual). O fluxo antigo era necessário para testes da runner, mas não deve estar disponível a qualquer operador ou visitante da prévia privada. Isso não habilita clientes reais.

A partir desta entrega, `PrivatePreviewRouteGate::allows()` permite:
- `/_preview/portal` e seus descendentes, ainda sujeitos à sessão e autorização do Portal;
- `/_rehearsal/purchase` **somente** quando o entrypoint detecta `GITHUB_ACTIONS=true` **e** `PORTAL_PREVIEW_LOOPBACK_TEST=1`. A configuração de CI exige ainda `APP_URL=http://127.0.0.1:18083`, enquanto a configuração da imagem de VPS exige `APP_URL=https://portal-dev.imaginecomunica.com.br`.

Na execução de VPS o checkout antigo retorna HTTP **404** com `Cache-Control: no-store, private` e `X-Robots-Tag: noindex, nofollow`. A biblioteca de decisão é **copiada junto com o router para a imagem Docker**, evitando a falha clássica de teste local aprovado com arquivo ausente no contêiner.

## Provas

O comando sem rede `php apps/portal/ops/private-purchase/test_route_gate.php` verifica rotas de cadastro, painel, clientes, admin, URLs malformadas e a distinção CI x não-CI. A CI também compila o PHP, cria a imagem isolada, executa o navegador com checkout antigo **apenas no ambiente efêmero**, valida os novos fluxos individuais e se desfaz dos bancos após o teste.

## Verificação de staging sem mutação

O antigo `smoke.py` gravava pedidos e marcava pagamentos fictícios na VPS. O novo `smoke.py` é **somente leitura** e exige modo explícito:

```bash
python3 apps/portal/ops/private-purchase/smoke.py --self-test
python3 apps/portal/ops/private-purchase/smoke.py --probe
```

O `--probe` é executável **somente na máquina que contém o gateway privado**, fazendo GET para `http://127.0.0.1:18083`, nunca para um domínio externo. Confere login, cadastro sintético, redirecionamento seguro dos painéis sem sessão, acesso à compra individual negado sem login e 404 para o checkout compartilhado antigo. Não envia credenciais, não cria contas, pedidos, pagamentos, briefings ou sessões autenticadas. A verificação de HTTPS/Basic Auth no endereço de desenvolvimento é **separada**, através de `check_public_boundary.py --probe`, e também não envia credenciais.

## Bloqueios restantes

Antes de ativar preview privado na Leaseweb: operador deve configurar segredos sintéticos e Basic Auth/HTTPS válidos fora da aplicação, restringir firewall, executar backup/restore e plano de rollback, validar `compose config`, roteamento loopback, RLS e logins, e confirmar revogação antirrollback, IAM/MFA e propriedade individual. **Não executar deploy automaticamente** a partir desta PR. A prova está isolada no GitHub, sem conexão SSH disponível, DNS ou serviço público alterado.

**Estado: DRAFT / NO-GO para produção e captação real.**
