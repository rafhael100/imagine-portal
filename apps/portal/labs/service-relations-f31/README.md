# PORTAL-F31 — Revogação sintética com piso externo entre duas instâncias PostgreSQL

**09/11/2026 · Exclusivamente laboratório PHP/Laravel, sem deploy, clientes ou homologação de segurança.**

## Problema concreto

As etapas F24–F30 negam sessões revogadas enquanto sua fonte PostgreSQL
contém o estado atualizado. Porém, se a sessão, o vínculo e suas revisões
forem restaurados para um snapshot antigo, uma prova fictícia anterior pode
parecer válida novamente. Travas advisory, triggers, RLS e a assinatura HMAC
não impedem **rollback conjunto do estado que fundamenta a autorização**.

## O que F31 demonstra (e o que NÃO demonstra)

O workflow de integração inicia **duas instâncias PostgreSQL 18
distintas e efêmeras** no mesmo runner GitHub Actions:

1. **Primária**, com dados fictícios de sessão, membership e serviços
   F21/F24/F25; usa os controles já testados até F30.
2. **Testemunha**, com seu próprio diretório Docker, usuário SQL
   `f31_witness_reader` somente SELECT, senha aleatória SCRAM e
   schema `f31.revocation_floor`; nada é montado de uma instância
   na outra. Porta mapeada somente para `127.0.0.1` em número aleatório
   distinto. Ao terminar o CI ambas são removidas com os volumes.

O banco testemunha guarda para cada organização fictícia
`session_floor`, `membership_floor` e `sequence_number`:
valores mínimos de revisão que uma sessão assinada precisa alcançar.
Um trigger impede que operações SQL normais reduzam qualquer piso,
repitam ou diminuam a sequência. A role leitora não tem escrita.

`App\Labs\F31\F31RollbackAwareLabReader` é uma **fachada
estritamente experimental**, não registrada em service providers,
rotas, API ou filas:

- Verifica a prova HMAC de fixture F24 e correspondência de empresa.
- Consulta a testemunha em um **PDO separado** de role
  `f31_witness_reader` antes da leitura primária, exige
  `srev >= session_floor` e `mrev >= membership_floor`.
- Se a testemunha estiver indisponível, com sessão SQL incorreta,
  trigger monotônico/RLS desativado ou revisões antigas, devolve
  bloqueio com **zero linhas, totais e permissões**.
- Executa então a leitura primária F24/F25/F28/F29/F30, que exige
  sessão/membership ativos no banco da autoridade, revisões iguais,
  trava cooperativa e RLS.
- Consulta a testemunha **novamente** antes de devolver o resultado.
  Qualquer mudança de piso ou sequência, ou indisponibilidade durante
  o processamento, descarta o resultado completo e nega.

## Cenários de integração

A suíte `F31IndependentRevocationFloorTest.php` usa duas conexões SQL
administrativas **somente dentro dos containers temporários** para
simular a seguinte história, sem executar `pg_restore`:

1. Sessão A de revisão 1 e piso 1: prévia fictícia é preparada, sem
   direito a acesso HTTP ou contratos.
2. Revoga sessão A e aumenta sua revisão para 2 na primária;
   avança o piso da testemunha para 2. A prova antiga já é negada.
3. **Simula restauração de snapshot antigo** reescrevendo a linha da
   primária para ativa/revisão 1, **sem tocar na testemunha**.
4. Comprova que o leitor F24 original, propositalmente sem piso externo
   e não registrado no Portal, poderia aceitar a prova antiga nesse
   cenário artificial; F31 **nega** a mesma prova como
   `stale_external_witness`.
5. Recupera a primária em revisão 2 e emite nova **prova de teste**,
   que pode produzir apenas prévia não autoritativa.
6. Repete o experimento para revisão de membership, indisponibilidade,
   papel SQL de witness incorreto, trigger monotônico desativado,
   tentativa de diminuição de piso e token adulterado.
7. Cada teste restaura o banco de laboratório. Para limpar o piso após
   um caso, usa **admin do container** para desativar o trigger e zerar
   a fixture — demonstra explicitamente que um administrador
   da testemunha ainda pode contornar esse protocolo.

## Gaps e riscos expressos

- **Duas instâncias no mesmo runner NÃO são um testemunho independente
  de produção.** O operador do runner administra ambos os processos,
  seus backups, chaves e redes. Não há custódia externa, testemunha
  imparcial, durabilidade, WORM, anti-tamper ou SLA. Esta PoC não
  homologa SEC-R03/Z4 nem D03-RLS.
- O teste realiza apenas **reescrita de linhas para simular restore**,
  não `pg_dump/pg_restore`, PITR, failover, recuperação de disco,
  backup atrasado, imagens do servidor ou ataque de superusuário.
- A testemunha não prova que **toda revogação real seja publicada** nela.
  Não existe outbox transacional nem protocolo que garanta a ordem
  entre os dois bancos, retries, idempotência ou recuperação após
  falha parcial. Se a primária restaurar antes do commit na testemunha,
  o risco persiste.
- As consultas antes/depois reduzem a janela de inconsistência,
  **mas não garantem revogação linearizável**: uma mudança depois da
  segunda checagem pode não ser observada. Leituras em trânsito ou
  respostas já entregues não podem ser desfeitas.
- A chave HMAC F24 e o token são artificiais, não são JWT do Portal
  nem o emissor Ed25519 da ponte específica Portal–Performance.
  Os métodos de autorização do DTO continuam sempre **false**.
- Implementação real exigirá uma autoridade separada do domínio
  de backup/rollback (aprovação AppSec e de operações), autenticador
  Portal-only, identidade da empresa e sessão vinculadas, políticas
  seguras de escrita, rate limiting, MFA, criptografia, auditoria,
  RLS verdadeiro, testes de restauração, falhas de rede e consenso
  sobre política de recuperação.
- Não conectar esses módulos a Laravel HTTP/console, CRM, Sites,
  Universidade, Performance, n8n ou gateways externos. Não aplicar
  schema F31 a VPS ou banco existente. Nada foi liberado em
  `dev`/`main`.

**Status:** PR DRAFT, CI efêmero, sem gastos novos com fornecedores;
GitHub Actions pode usar minutos conforme plano. P0/D02/D03,
SEC-R03-X01..X24, T3/OPEN, AppSec/DBA **permanecem abertos**.
