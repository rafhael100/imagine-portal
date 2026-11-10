# PORTAL-F94 — Prevenção de imports laboratoriais na borda Laravel

**Data:** 10/11/2026. **Estado:** revisão isolada / NO-GO operacional.

## Objetivo
Portar a intenção de segurança da F73 para a linha de revisão F92/F93, **sem merge automático** das branches divergentes. O F94 verifica recursivamente as superfícies Laravel routes, bootstrap, public, config e app/Http (quando existir).

Rejeita referências às classes/test-only consumers do Performance e aos sinalizadores de laboratórios; imports diretos de scripts CLI por require/include; funções de execução de comando em public e bootstrap; symlinks nos caminhos verificados; entradas públicas além de index.php e .htaccess; arquivos inesperados ou grandes.

## Verificações
- Runner de teste F94 em Node 22 e 24, incluindo execução conjunta do F93.
- Casos negativos criam uma cópia temporária dos diretórios reais do repositório e injetam URLs de ferramenta, flags de teste em configuração, classes App\\Labs em bootstrap/controllers, PHP adicional em public, comandos, symlinks e alterações em .htaccess.
- Ao reverter cada injeção, o baseline é revalidado; sem dados de clientes, segredos ou integrações externas.

## O que esta etapa NÃO garante
- Não é exame de imagem Docker montada, runtime de PHP, análise dinâmica de dependências ou revisão AppSec.
- Referências geradas dinamicamente podem escapar de uma busca textual; o gate reforça, mas não substitui, o smoke privado F64 e o contrato de IAM/autorizações.
- F94 não introduz login, recuperação, cobrança, CRM, Sites, Google/Meta, servidor operacional, contas reais ou rotas públicas.
- Mesmo com CI aprovado, não há testemunha externa antirrollback SEC-R03, KMS, infraestrutura homologada nem aceite LGPD/DBA.
- As branches F73 e F92 continuam divergentes. Reconciliar apenas após revisão de diff completo e dependências.

**Sem merge na main, sem deploy, sem ativação de serviços ou novos custos.**
