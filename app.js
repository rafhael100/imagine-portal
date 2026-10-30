// Entrada padrão do Passenger no cPanel. Node.js 22.16+ ou 24+.
const {existsSync}=require('node:fs');
const {join}=require('node:path');
const envPath=join(__dirname,'.env');
if(existsSync(envPath))process.loadEnvFile(envPath);
import('./server/index.mjs').catch(()=>{console.error('Falha ao iniciar o portal. Confira as variáveis de ambiente e o runtime Node.js.');process.exit(1)});
