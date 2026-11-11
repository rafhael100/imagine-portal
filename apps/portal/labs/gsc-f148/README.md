# GSC-F148 — Transição controlada do contrato paginado v1 para v2

O Portal aceita agora os envelopes paginados SEO-F59 **v1** e **v2** sob a mesma fronteira de teste. A versão v2 adiciona `pageSize` e `lastPageRows` e o consumidor PHP exige a igualdade:

`rowsReceived = (pagesReceived - 1) * pageSize + lastPageRows`

Também exige `mayHaveMore = (lastPageRows === pageSize)`, limites (1–4 páginas, 1–1.000 linhas por página), `maxPagesReached` coerente e `nextStartRow` correto. Campos adicionais ou alegações falsas de OAuth/publicação continuam bloqueados.

A CI usa o SEO Engine RC27 fixado por SHA `86ba0e361b0f24bb1f567dcbb5a1e31ef950ff6e`, valida o contrato v2 Node→PHP, testa a compatibilidade v1 e renderiza a prévia HTML já existente. Dados apenas sintéticos, sem Google HTTP, clientes, token vault, rotas de produção ou custos novos. **HOLD** até homologação e revisão independente SEG/DBA/LGPD; sem deploy, DNS, 301 ou merge main.
