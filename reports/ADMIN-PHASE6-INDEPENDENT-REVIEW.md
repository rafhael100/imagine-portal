# Fase6 — revisão por agente distinto do implementador

Responsável: agente `/root/phase6_independent_review`, solicitado expressamente pela direção. Implementador: `/root`. Isto é revisão técnica por outro agente, não parecer humano, jurídico, aprovação de infraestrutura ou autorização de deploy.

## Escopo e parecer original

Worktrees limpas verificadas por fetch: Portal#917 `83c4e37e06b6b8daefbe8f84153946856eed27b3`; Performance#419 `0fec80aa81a81a103481664dd568f79bd85ce588`. Foram inspecionados autenticação/MFA/concessões, migrations/RLS/grants, cliente/receiver/inbox, PKI/Compose e telas. Prova própria offline com libpq.PQconninfoParse, sem conexão: sslmode duplicado termina efetivamente em disable. PHP/Docker indisponíveis no executor do revisor; não reivindicou execução local das suítes.

Parecer original: **NO-GO operacional**, com achados reais:

| Achado | Evidência nas HEADs originais | Tratamento / reavaliação |
|---|---|---|
| P1 DSN ambíguo | read.php exigia substring verify-full, permitia opção posterior conflitante | Parser whitelist, duplicatas/opções extras negadas, DSN canônico e pg_stat_ssl/database/login efetivos. Fechado pelo revisor em engine d15de1a |
| P1 snapshot obsoleto | Inbox sem isolamento obrigatório; Portal podia usar savepoint herdado | Conexões independentes READ COMMITTED tanto identidade Portal quanto autoridade e receiver/inbox. Fechado para caso identificado; sem promessa de atomicidade distribuída |
| P2 grants extras | Guard não negava TRUNCATE/mutação de inbox, nem INSERT de autoridade Portal | Matriz de privilégios efetivos reforçada; teste de grants acidentais exige recusa. Fechado no código |
| P2 recusas/transporte sem trilha | Recusas precoces antes da auditoria SQL e callback HTTP vazio | Projeção JSON fixa pré-autorização + callback ativo; SQL continua segregado. Mitigado; custódia/durabilidade externa de logs ainda operacional |
| P2 fontes da jornada | Dez módulos locais e S2S unavailable eram fluxos distintos | Links/avisos distinguem explicitamente as fontes. Mitigado sem fingir que métricas locais vieram do receiver |

## Adendos independentes

Revisor confirmou HEADs novas engine#420 `d15de1ae9704feee6f9fcf4497f19721b95b5b95` e Portal#920 `f6e15bdc1fa9d2a026fe05580d3331ee700f2d3a`. Consultou independentemente CI engine43973995600, job133673139663 success:16 parser,12 serviço,9 isolamento/revogação SQL/privilégios,9 conectores. Revogação ocorre em outra conexão SQL e impede receipt após rollback; não é só flagPHP.

Novo achado do pacote: receiver herdava master FPM root com cap_dropALL, sem setuid/setgid. Corrigido user33:33 no receiver/Portal e101:101 no proxy Performance. Revisor verificou worktree limpa Portal497f0af04b874dcca5c8c418075c600cf586357e e considerou o defeito fechado no código, exigindo smoke real do pacote. Ressalvou que smoke flagsOFF não prova login/MFA/grants/S2S ou SQL TLS autenticado do pacote inteiro.

Segundo detalhe do pacote: profile Access ainda exigia variáveis de credencial na renderização base. Corrigido overlay `compose.access.yaml` separado. Revisor verificou diff/head `4a0ca2ccfff6f461aef71282806f1f2c482038b8`, confirmou seis serviços base, sem variáveis Access, overlay explícito UID65532/redes próprias/readonly/capabilities removidas e fechou P2 de opcionalidade. Configuração operacional do Access não foi verificada.

## Limites do parecer

Os adendos não constituem revisão independente de qualquer mudança posterior. A correção posterior de autenticação local da fixture de startup e seus resultados de CI devem ser registrados separadamente. Não houve acesso/mutação à VPS, GitHub, fontes ou credenciais pelo revisor. Nenhum teste de laboratório equivale a backup externo, PKI sob custódia, empresa real ou aprovação humana.

Persistem janela residual entre última verificação de revogação e commit/resposta, durabilidade de stderr, inventário atual do host, credenciais/certificados próprios, recuperação externa e aprovação específica de instalação. Revisor mantém **NO-GO operacional** até esses gates serem comprovados. Demonstração proposta é exclusivamente sintética, somente leitura externa, sem cobrança ou escrita publicitária.

## Adendo final confirmado pelo revisor

HEAD Portal3da1eaa980ee402bb4160dbcaaf2fe2a2567c777: revisor examinou diff de manifests Docker, Nginx sanitizado e Accessingress-only e consultou diretamente run43977679598/job133685755262 completed/success. Confirmou77testes380assertions, doisHTTPS1/16cada,14browserchecks e marcadores de sanitização/Compose exato. Considerou falha500 fechada emCI nessa HEAD. Smoke de boot flagsOFF não equivale à jornada completa implantada; parecer final admite submeter o pacote à decisão condicionada, mantendo NO-GOexecução por inventário/PKI/backup/acesso/autorização pendentes.

Observação de atualização: portal_bootstrap é volume persistente e pode manter manifests antigos. A versão documental publicada nesta entrega registra limpeza seletiva somente de caches PHP/Blade derivados na manutenção privada, sem apagar dados/sessões/auditorias/volumes, antes de iniciar imagem atualizada ou rollback. Não é limpeza executada na VPS.
