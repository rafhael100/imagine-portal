# Portal F140 — Central de Ajuda e Central de Avisos na prévia offline

As telas Laravel `portal.demo.help` e `portal.demo.notices` já existiam, com conteúdo fictício, controles de escape de HTML e testes de apresentação, mas **não eram incluídas na exportação navegável** de nove páginas. Agora o pacote tem **onze páginas HTML**, acessíveis pelo índice e pelo menu interno: as sete áreas originais, revisão técnica de entrega, Ajuda, Avisos e índice.

## Implementação

- `DemoPortalOfflineExtras` registra as duas páginas adicionais exclusivamente para exportação estática. **Não** adiciona valores a `ClientSection`, direitos de cliente, links do Portal autenticado nem rotas.
- `DemoPortalStaticExporter` usa os Blade existentes, gera links locais, define `aria-current="page"` corretamente e escreve os arquivos com permissão `0600` no estágio privado F134/F139.
- `DemoPortalArtifactManifest` exige inventário fechado de onze páginas e respectivos SHA-256. `DemoPortalNavigationAudit` aplica CSP offline, landmarks, link de salto, `noindex`, navegação somente local e um único link ativo por página.
- O CI em PHP 8.3 e 8.4 continua executando PHPUnit. Na matriz de exportação PHP 8.3, exige `help.html`, `notices.html`, onze HTML, `php artisan portal:demo-verify` e disponibiliza o pacote HTML **com manifesto** como artefato de curta retenção.
- Teste Feature exige a cópia real de exemplos fictícios, links entre as onze páginas, ausência de ações operacionais e que `ClientSection::tryFrom('help'|'notices') === null`. Testes pré-existentes de Ajuda e Avisos permanecem válidos.

## Limites e bloqueios

Ajuda não abre chamados nem presta suporte. Avisos não são notificações entregues; não há leitura persistida ou disparos por email/SMS/WhatsApp. Nenhuma destas páginas concede uso de CRM, Sites, Universidade, Performance ou outros serviços. Os valores são apenas conteúdo sintético.

**Draft / NO-GO:** a demonstração é local/testing, não serve clientes e não deve ser hospedada. Não há autenticação de pessoas, integração de dados, cobrança, deployment, VPS, DNS ou despesas. Revisão de AppSec/DBA/LGPD, identidade confiável e homologação seguem pendentes antes de qualquer ativação operacional.
