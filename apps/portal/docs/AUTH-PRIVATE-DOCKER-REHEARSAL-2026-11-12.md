# Ensaio privado reproduzível de autenticação na VPS

O projeto Compose imagine-browser-auth-lab é separado dos contêineres Portal/CRM e de suas prévias HTTPS. Não publica portas. O PostgreSQL é descartável em tmpfs, em rede interna; o runner compartilha seu namespace para atender às validações de localhost dos testes. O runner é não-root, read-only, sem capabilities e sem aumento de privilégios. Somente diretórios efêmeros de cache/storage/tmp são graváveis. Credenciais constantes pertencem exclusivamente ao banco de teste isolado, nunca a um banco real.

A imagem copia uma lista explícita de diretórios e arquivos, sem .env, chaves, arquivos locais, .git ou diretórios de dados do host. O build baixa apenas pacotes das imagens/registries oficiais e dependências do composer.lock; a execução não depende de rede externa. Cada execução cria APP_KEY efêmera sem a imprimir e sem arquivo de segredo persistente.

O roteiro instala o esquema no banco portal_browser_lab, aplica os instaladores candidatos e habilita LOGIN apenas para papéis desse banco descartável. Executa autenticação SQL, sessão/envelope, transporte HTTP e bindings negativos, exigindo sucesso sem testes skipped/risky. Confere ao final que somente /up está registrado.

Em um checkout isolado e fixado na SHA revisada, a partir da raiz do repositório: docker compose -f apps/portal/infra/browser-auth-lab/compose.yaml up --build --abort-on-container-exit --exit-code-from runner. Ao terminar, docker compose -f apps/portal/infra/browser-auth-lab/compose.yaml down --volumes --remove-orphans. Esse down atua somente no projeto de ensaio; o nome deve ser preservado e não deve receber configurações de produção por override.

A workflow valida rede interna, ausência de portas e volumes persistentes, executa o ensaio e remove seus próprios recursos no finally. Um resultado GitHub não comprova execução na VPS. A execução na VPS requer acesso autenticado e capacidade Docker; não altera Nginx, DNS, configuração de prévias ou serviços existentes e não homologa login de clientes. Não usar --env-file de serviços reais ou montar dados/segredos operacionais neste ensaio.
