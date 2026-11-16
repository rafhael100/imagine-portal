# Portal SEO F125 — relatório assinado pelo próprio Engine, recebido no Portal

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Continua a [Portal RC45 #864](https://github.com/rafhael100/imagine-portal/pull/864).

## O que muda
O assinador temporário anterior F122 pertencia ao laboratório do Portal e operava sobre o JSON produzido pelo Engine. A F125 testa o fluxo correto de **separação de responsabilidades**:
1. A [SEO Engine F121 #177](https://github.com/rafhael100/imagine-seo-engine/pull/177), no commit fixo `3c75b8e7b64f4173a17f4daab45cea1b8bdc5554`, executa a auditoria F114/F117 sobre HTML local efetivo.
2. **O Engine assina** o próprio diagnóstico em envelope Ed25519 compatível com F122. A chave privada é efêmera e fica apenas na memória do runner; somente a chave pública é passada separadamente ao verificador.
3. **O Portal verifica** o envelope com sua classe PHP F122 e revalida as regras F118 antes de interpretar o diagnóstico. Identidade/tenant/run/commit vêm de contexto de CI, não de um usuário.
4. Os campos de publicação, identidade real, replay e cliente continuam falsos.

## Matriz CI
`.github/workflows/portal-seo-f125-engine-signer-ci.yml`: PHP 8.3/8.4 e Node 22 com sete casos:
- `valid`: build correto, assinatura válida, apenas revisão humana.
- `private-preview`: HTML privado vazado, relatório assinado porém bloqueado.
- `wrong-locale`: HTML em idioma diferente do manifesto, relatório assinado e bloqueado.
- `tampered`: alteração da mensagem após assinatura, recusada.
- `wrong-key`: troca da chave pública de confiança, recusada.
- `other-tenant`: assinatura de outra empresa, recusada pelo contexto esperado.
- `expired`: assinatura vencida, recusada.

Tudo em runner descartável, sem site público, DNS, VPS, cliente, Google ou serviço IBGE real.

## O que ainda falta para produção
Este pipeline comprova **interoperabilidade criptográfica de código**, não existência de um serviço de assinatura autorizado. Em produção é indispensável emissor isolado com KMS/HSM e rotação/revogação, identidade/empresa/MFA reais, canal seguro, antirreplay PostgreSQL efetivamente implantado, política LGPD de retenção, observabilidade e staging testada. F123/F124 já testaram antirreplay e expurgo em PostgreSQL real, mas ainda somente em CI.

Sem merge, deploy ou permissão de exibir dados a clientes.
