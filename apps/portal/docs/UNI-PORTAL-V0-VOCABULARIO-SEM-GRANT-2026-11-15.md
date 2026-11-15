# Portal — vocabulário inicial Universidade Imagine (sem grants)

**15/11/2026 · candidato em revisão · NO-GO.**

## Objetivo
Preparar exclusivamente o vocabulário que a Universidade Imagine usará na integração futura com o Portal:

- university:view — visualizar catálogo elegível e registrar **somente o próprio progresso**;
- university:manage — administrar o acervo editorial da Imagine, sem herdar university:view automaticamente.

Os códigos pertencem ao enum fechado de permissões do Portal; não são prova de elegibilidade, identidade ou sessão real.

## Implementação desta branch
- Dois novos casos tipados no enum Permission, preservando todos os códigos existentes.
- Atualizados os testes de congelamento de vocabulário/GSC para reconhecer os dois novos valores **fora do subconjunto elegível para grants PostgreSQL**.
- Novo teste PortalUniversityVocabularyNoGrantTest para codificação exata, ausência de wildcard, proprietário sem grant negado e verificação de que provider, rotas e migrations não habilitam acesso.

## Restrições de segurança
- **Nenhuma migração altera o CHECK de grants**. Nenhum INSERT/UPDATE concede a permissão a algum usuário.
- **Nenhum endpoint, sessão, middleware operacional, SSO ou serviço de elegibilidade foi implementado ou ativado**.
- AppServiceProvider permanece ligado a DenyingPortalAuthorities. Não modificar o default-deny.
- O escopo editorial da Imagine precisa de identidade oficial; não conceder university:manage aos clientes nem por papel owner.
- O Portal precisa definir fonte confiável do **último serviço elegível ativo** e revogação no mesmo limite de confiança que autoriza a consulta/mutação do recurso.
- O preflight de permissão não prova propriedade do recurso; a autorização final deve validar vínculo da sessão business, tenant do recurso e política transacional sob RLS, com testes de concorrência.
- A integração do sistema Universidade continua na PR #19 do repositório imagine-universidade; esta branch **não** torna o produto utilizável.

## Bloqueios para etapa seguinte
- Aprovar schema de grants e migração com rollback seguro, sem autoconcessão;
- Aprovar organização editorial Imagine, RBAC e elegibilidade por serviço;
- Implementar emissor/verificador confiável de sessão e autorização real com revogação;
- Executar PHPunit, PG18, testes multiempresa e análise independente SEG/DBA/LGPD;
- Autorizar homologação controlada e depois eventual deploy separado.

**Nenhuma mudança de main, merge, deploy, custo externo, dados reais ou credenciais nesta entrega.**
