# F432 — ensaio de restauração e proteção antirreplay em PostgreSQL18

## Ensaio executável em GitHub Actions

Este teste cria **dois contêineres Docker PostgreSQL18 inteiramente descartáveis,
sem rede, portas publicadas ou dados de clientes**. Ele só roda quando o runner
é hospedado pelo GitHub e `PORTAL_F432_DISPOSABLE_ONLY=1`.

1. Cria uma revisão mensal sintética F427 e consome-a exatamente uma vez no
   banco de origem.
2. Faz `pg_dump -Fc`, valida a lista do arquivo e compara checksum SHA256.
   Um arquivo inválido é rejeitado.
3. Inicializa um **segundo cluster** com outro banco vazio. Reconstitui
   as três roles com **NOLOGIN** e revoga CONNECT de PUBLIC antes de restaurar.
4. Restaura o arquivo com `pg_restore --exit-on-error --no-owner`.
5. Verifica `FORCE RLS`, MAC, hash e valor, estado `consumed` e negação
   de CONNECT ao worker. Uma nova tentativa de consumir o mesmo mês retorna
   falso; o banco de origem permanece intacto.
6. Apaga os dois contêineres, seu conteúdo e a cópia do dump ao finalizar
   (inclusive diante de falha de teste).

## O que isto NÃO comprova

- Não acessa a VPS Leaseweb, não migra banco real nem realiza rollback
  operacional de serviço Linux/HTTPS/Docker/Nginx. A validação de
  publicação+rollback na VPS continua **pendente**.
- Não configura backup Restic/R2, não substitui a retenção aprovada nem
  fornece garantia de recuperação diante de pane física. Restore real
  precisa de acesso, custodiante de chaves e testes com dados controlados.
- A restauração é **quarentenada**, com roles NOLOGIN, e o exercício como
  worker ocorre em uma sessão temporária de superusuário para testar apenas
  o predicado SQL. Não é um atestado de segregação AppSec independente.
- Nenhuma fatura, PSP, C6, Google Ads, Meta Ads, webhook, produção ou
  informação pessoal é utilizada.

**Status:** DRAFT / NO-MERGE / NO-DEPLOY / NO-CHARGE. Rastreado em #802.
