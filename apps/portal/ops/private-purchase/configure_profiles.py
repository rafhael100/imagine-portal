"""Operator enters only synthetic profile passwords locally; never print them."""
import getpass, json, os, pathlib, subprocess, sys
if os.geteuid()!=0: raise SystemExit('Run as root on the private preview host.')
root=(pathlib.Path(sys.argv[1]).resolve() if len(sys.argv)>1 else pathlib.Path(__file__).resolve().parent)/'.private'
if not (root/'runtime.json').is_file(): raise SystemExit('Prepare the isolated preview first.')
accounts={}
for username,role in [('cliente.teste','client'),('administrador.teste','admin')]:
    first=getpass.getpass(f'Senha de teste para {username} (8 a 72 bytes): ')
    second=getpass.getpass('Confirme a senha: ')
    if first!=second or not 8<=len(first.encode())<=72: raise SystemExit('Senhas diferentes ou tamanho invalido; nada alterado.')
    result=subprocess.run(['docker','compose','exec','-T','application','php','-r','echo password_hash(stream_get_contents(STDIN), PASSWORD_DEFAULT);'],input=first.encode(),stdout=subprocess.PIPE,check=True,cwd=root.parent)
    accounts[username]={'role':role,'hash':result.stdout.decode()}
    del first,second
profiles=root/'profiles'
profiles.mkdir(mode=0o700,exist_ok=True)
os.chown(profiles,82,82)
file=profiles/'users.json'
temp=profiles/'users.new'
with temp.open('x') as out: json.dump({'accounts':accounts},out)
temp.chmod(0o400);os.chown(temp,82,82);temp.replace(file)
print('Perfis de teste configurados. Nenhuma conta real foi criada.')
print('Cliente: cliente.teste | Administrador: administrador.teste')
