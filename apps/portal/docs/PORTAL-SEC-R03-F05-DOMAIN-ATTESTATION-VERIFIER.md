# SEC-R03-F05 — Verificação criptográfica de testemunha no domínio do Portal

**Estado:** componente PHP reutilizável, sem integração operacional, sem autoridade de restore e sem deploy.

## Mudança de arquitetura

A classe `App\\Domain\\RestoreSafety\\PortalWitnessAttestationVerifier` centraliza a verificação **Ed25519** dos comprovantes de testemunha. A lógica que estava duplicada no leitor fictício `Tests\\Support\\DisposableSignedWitnessReader` passou a chamá-la, de modo que o laboratório exercite a mesma regra criptográfica que uma futura implementação do Portal poderá revisar.

A verificação exige: chave pública Ed25519 de tamanho exato e fixada anteriormente; desafio aleatório de 256 bits emitido pelo solicitante; envelope JSON limitado a 2048 bytes, chaves exatas e em ordem canônica; versão 1; desafio igual ao solicitado; empresa igual à solicitada; sequências/pisos inteiros; assinatura detached válida; conversão a objeto `LabExternalWitnessFloor` com limites validados. Qualquer divergência, formato malformado ou exceção retorna `null`, sem exibir detalhes de chave, token ou empresa.

A suíte `PortalWitnessAttestationVerifierTest` adiciona testes adversariais de replay de nonce, alteração de qualquer campo assinado, chave/tenant incompatível, JSON malformado, assinatura ausente ou modificada, campos extras, valores inválidos mesmo quando assinados e ausência de autorização operacional.

## Limite incontornável

**Verificar uma assinatura não equivale a verificar a durabilidade ou atualidade do piso.** Uma autoridade restaurada junto com o seu registro de revisão pode assinar uma geração antiga usando um nonce inteiramente novo. A nova classe devolve apenas uma **observação sintética tipada**, não um atestado de linearizabilidade. O método `canAuthorizeProductionServing()` é sempre `false`.

A implementação ainda não possui serviço remoto de testemunha, prova de independência do domínio de restore PostgreSQL, confirmação durável de revogação, rede/HTTPS/identidade de máquina, binding em service provider, middleware para permitir sessões, registro de rota, segredo de cliente ou chaves operacionais. A issue [#64](https://github.com/rafhael100/imagine-portal/issues/64) continua bloqueante. Nenhuma conta real, deploy, ativação, custo novo, alteração em main/dev ou VPS.

Revisão AppSec/DBA e homologação isolada serão necessárias antes de implementar uma autoridade monotônica externa e protocolo de revogação operacional. **PR DRAFT / NO-GO**.
