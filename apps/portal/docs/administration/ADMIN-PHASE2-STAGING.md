# Candidata integrada — fase 2

DRAFT / NO-MERGE / NO-DEPLOY / NO-CHARGE / NO-ADS-WRITE.

Bases verificadas: Portal887 82624818832ee04b64111154fd551e2cca34c27e; Portal888 7d02860e0d331699df4700b305999e738f760859; Portal889 bf6b22ba90cd69617087745b5c6476dc2bd41f42; correções890 d506621117960fb58cdaa0bb890d7b8c2ec0ba95. Engine413 434f231471a1bfcab5af26d38005c4a59253b0de pinado por commit e digest no adaptador.

## Diferenças da proposta F138

- Cache renumerado para 000017: 000016 já pertence ao laboratório; função de bloqueio em 000018.
- APP_ENV permanece staging. PERFORMANCE_ADMIN_MODE=private_staging é uma modalidade própria, sintética, condicionada à política estrita de sessões, cache, HTTPS e flags. As flags permanecem desligadas por padrão.
- Runtime recebe somente variáveis explícitas. A senha do proprietário não entra no processo PHP. Migração e concessões são tarefas separadas do operador, nunca do servidor HTTP.
- Runtime não pode alterar papéis, vínculos, senhas ou épocas de sessão. A função de bloqueio de vínculo é SECURITY DEFINER, com search_path fixo, sem DML, EXECUTE revogado de PUBLIC. Ela serve apenas ao namespace sintético; não é autorização multiempresa de produção.
- Nginx sem root/capabilities, HTTPS 8443, TLS 1.2/1.3, acesso adicional por arquivo nominal de senhas, bind somente 127.0.0.1. Sem logs de acesso que possam registrar consultas privadas. Certificados e arquivo de acesso externos, excluídos da imagem.
- Nenhuma porta padrão: operador deve identificar porta livre e projeto Compose exclusivo. Não reutilizar a porta 18081 de outros projetos. CI usa 18088 somente no runner descartável.
- Redes separadas; PostgreSQL sem publicação de portas, rede interna. Nenhum cliente real ou integração publicitária habilitada.

## Checklist de liberação — ainda pendente

1. Revisão independente AppSec/DBA/LGPD e das imagens/dependências fixadas para release.
2. Inventário da VPS, recursos/portas/redes/projetos existentes e impactos; não executado nesta entrega.
3. Provar restauração de backup cifrado em banco descartável e reversão para imagem anterior. Backup não deve reativar sessão ou vínculo revogado: expurgar sessões e reaplicar revogações antes de abrir acesso.
4. Prover contas nominais por mecanismo seguro, MFA individual, certificados confiáveis, restrição de rede/IP e acesso adicional. Não reutilizar credenciais de CI. Conferir permissões dos arquivos montados para UID101.
5. Verificar DNS/TLS reais sem alteração automática; segredo via cofre, retenção e rotação. Sem segredos em Git, imagem, relatório ou console.
6. Executar testes no staging aprovado: login/MFA, seis perfis, isolamento, expiração/revogação, concorrência, CSV, mobile e bloqueio externo. Testes de runner não equivalem a homologação operacional.
7. Autorizar explicitamente deploy privado, com plano de reversão e dono de operação. Nenhum merge/deploy automatizado está autorizado.

## Limites operacionais

Dados e motor administrativo continuam sintéticos. Clientes/contratos operacionais, Google/Meta oficiais, agentes reais, Asaas/C6 e extratos financeiros não estão conectados. ChatGPT Ads não possui provedor homologado neste caminho. Não preencher métricas ausentes com valores fictícios. O cálculo parcial depende de política comercial versionada e evidência oficial; não emitir cobrança.
