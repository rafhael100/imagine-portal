# Configuração privada preparatória

Leitor de arquivo de credenciais e chaves, sem conexão, registro de driver ou habilitação de rotas. Deve receber o caminho absoluto e a origem HTTPS esperada da configuração confiável do servidor, nunca de parâmetros HTTP. Aceita somente arquivo regular, proprietário igual ao processo, sem leitura para grupo/outros, sem hardlink, até 8192 bytes e diretório imediato sem escrita de grupo/outros. Confere inode/device após abrir para evitar troca do alvo entre stat e open.

Esquema JSON exato: origin, host, port (inteiro), database, identity_password, envelope_password, proof_key (64 hex), totp_key (32 bytes em base64 canônico) e scope_pepper (64 hex). Senhas dos papéis devem ser distintas; chaves e pepper também. Host/database/port são validados antes de qualquer futura composição de DSN. O leitor recusa campos desconhecidos, incluindo um role fornecido pelo arquivo. A futura factory deve manter os papéis fixos já exigidos pelos adaptadores, não aceitar roles dinâmicos.

Não versionar arquivo operacional nem copiar para imagem, backup público, argumentos de comando ou logs. O método value é exclusivamente para composição interna confiável, não para serialização ou respostas HTTP. Debug e serialização são protegidos; isso não impede código comprometido de ler a memória do processo. Gestão e rotação de chaves, permissões de diretórios ancestrais, custódia e revisão operacional continuam necessárias. O leitor não autoriza implantação.

Os testes criam somente segredos sintéticos em diretórios efêmeros e verificam permissões, links, origem, esquema, independência de segredos e componentes DSN. Sem alteração da VPS ou uso de credenciais reais.
