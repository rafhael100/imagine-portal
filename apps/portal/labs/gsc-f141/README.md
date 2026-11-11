# GSC-F141 — Integração agregada de consultas por página

O Portal consome o novo contrato `seo-gsc-query-page-overlap-offline-v1` por um validador PHP de laboratório. A CI obtém o SEO Engine RC23 por SHA fixo, executa a F57 com dados sintéticos, confirma filtragem de consulta pessoal e transfere somente os totais para PHP. O Portal rejeita campos extras, permissões falsificadas, outra empresa/propriedade, métricas inconsistentes e revisão que contrarie a evidência de paginação.

Nenhum termo de pesquisa, URL original ou hash de termo é exibido ou registrado na saída. A presença de uma consulta em mais de uma página é apenas um sinal a avaliar, não prova de canibalização ou motivo automático para redirects.

**NO-GO:** só PHP CLI no ambiente de teste com `PORTAL_GSC_OVERLAP_LAB=1`. Sem rota HTTP, Google OAuth próprio, tokens, consentimento, concessão IAM verificada, banco operacional, DNS, produção, deploy ou merge `main`. A conta Search Console conectada no OpenSEO não habilita a autenticação do Portal. Revisão SEG/DBA/LGPD e homologação permanecem gates.
