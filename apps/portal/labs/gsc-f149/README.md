# GSC-F149 — Privacidade de URLs com parâmetros (SEO-F64 → Portal)

O laboratório GSC do Portal usa o SEO Engine RC28 fixado pelo commit `ae2012305e67d0580f6e5e87cf0d174d245b217e`. Um lote sintético com quatro linhas inclui uma URL da própria propriedade contendo um token fictício na query. O SEO-F64 deve contabilizar a linha em `redactedRows` sem expor a URL, o token ou a consulta na saída. O PHP F143/F148 revalida a resposta paginada v2, o planejador F144/F146 compara offsets, e o renderer F142/F145 mostra somente contadores.

A CI também roda os testes adversariais F62/F64, mantendo a rejeição de hosts externos, aliases codificados e URLs potencialmente maliciosas. **Não são gravadas consultas reais**, não há OAuth do Portal, tokens reais, execução HTTP, publicação, deploy, DNS, redirect 301 ou merge na `main`. A revisão independente AppSec/DBA/LGPD e homologação autenticada continuam pendentes.
