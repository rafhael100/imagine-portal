# GSC-F120 — Identificação exata de propriedades do Search Console

A API do Google usa o campo `siteUrl` para representar dois tipos distintos de propriedade: domínio (`sc-domain:example.com`) e prefixo de URL (`http://www.example.com/` ou `https://example.com/blog/`). A implementação deste PR introduz um validador PHP puro que aceita somente formatos canônicos restritos e gera o SHA-256 do **identificador exato**, compatível com o fingerprint usado nos laboratórios PostgreSQL F110–F114.

Os testes rejeitam alterações de esquema HTTP/HTTPS, subdomínio, prefixo de caminho e sua capitalização como se fossem a mesma propriedade, além de URLs com credenciais, portas, query, fragmentos, escapes percentuais, dot segments, hosts inválidos e nomes de domínio ambíguos. O identificador canônico é preservado literalmente: nunca se transforma uma propriedade de domínio em um prefixo URL.

**Importante:** o parser usa uma política conservadora para proteger uma futura comparação de permissões. Sites do Search Console com IDs não canônicos, caracteres internacionais literais, escapes ou caminhos especiais não são aceitos automaticamente. Esses casos precisam de revisão explícita e testes próprios em vez de normalização permissiva.

A existência de `siteUrl` ou a obtenção de seu fingerprint **não prova titularidade nem permissão** no Google ou no Portal. A fonte operacional futura deverá ser a API Google autenticada via backend (sites.list/sites.get), com credenciais segregadas em cofre, nível de permissão verificado, organização e sessão Portal autenticadas e revalidadas. O valor calculado isoladamente não autoriza nenhum acesso. `authorizesPropertyAccess()` permanece `false`.

PR draft, sem rotas HTTP, chamadas Google, consultas de dados reais, mudanças na main, migração de produção ou deploy. Revisão independente AppSec/DBA #251 continua exigida.
