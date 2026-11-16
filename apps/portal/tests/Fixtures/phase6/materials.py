"""Disposable startup smoke materials ONLY, not operational PKI/enrollment."""
import base64,json,os,pathlib,secrets,subprocess
assert os.environ.get('GITHUB_ACTIONS')=='true' and os.environ.get('ADMIN_PHASE6_SMOKE_CI')=='1'
p=pathlib.Path(os.environ['P6_CI_MATERIAL']);p.mkdir(mode=0o755)
def run(args):subprocess.run(args,cwd=p,check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=SYNTHETIC Phase6 smoke CA','-keyout','ca.key','-out','ca.pem'])
for name,san,eku in [('portal-db','DNS:portal-db','serverAuth'),('performance-db','DNS:performance-db','serverAuth'),('performance','DNS:performance.internal','serverAuth'),('portal','IP:127.0.0.1,DNS:phase6.example.invalid','serverAuth'),('client','DNS:portal-client','clientAuth')]:
 run(['openssl','req','-newkey','rsa:2048','-nodes','-subj','/CN='+name,'-keyout',name+'.key','-out',name+'.csr']);(p/'ext').write_text('subjectAltName='+san+'\nextendedKeyUsage='+eku+'\n')
 run(['openssl','x509','-req','-in',name+'.csr','-CA','ca.pem','-CAkey','ca.key','-CAcreateserial','-days','1','-extfile','ext','-out',name+'.pem'])
(p/'index').write_text('');(p/'crlnumber').write_text('1000\n');(p/'crl.cnf').write_text('[ca]\ndefault_ca=local\n[local]\ndatabase=index\ncertificate=ca.pem\nprivate_key=ca.key\ndefault_md=sha256\ndefault_crl_days=1\ncrlnumber=crlnumber\n')
run(['openssl','ca','-gencrl','-batch','-config','crl.cnf','-out','clients.crl'])
for name,uid in [('portal-secrets',33),('portal-pki',33),('web-pki',101),('portal-pg',999),('performance-pg',999),('receiver-private',33),('receiver-tls',101)]:
 d=p/name;d.mkdir();os.chown(d,uid,uid);d.chmod(0o700)
def write(dir,name,data,uid,public=False):
 f=p/dir/name;f.write_bytes(data if isinstance(data,bytes) else data.encode());os.chown(f,uid,uid);f.chmod(0o644 if public else 0o400)
def copy(dir,name,source,uid,public=False):write(dir,name,(p/source).read_bytes(),uid,public)
for d,host in [('portal-pg','portal-db'),('performance-pg','performance-db')]:
 write(d,'bootstrap_password',secrets.token_hex(32),999);copy(d,'server.pem',host+'.pem',999,True);copy(d,'server.key',host+'.key',999)
 write(d,'pg_hba.conf','local all all peer\nhostssl all all 0.0.0.0/0 scram-sha-256\n',999,True)
for n in ['portal_db_password','portal_authority_password']:write('portal-secrets',n,secrets.token_hex(32),33)
write('portal-secrets','portal_app_key','base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),33)
write('portal-secrets','portal_service_identity','{}',33)
for name in ['portal_db_ca.pem','performance_ca.pem']:copy('portal-pki',name,'ca.pem',33,True)
copy('portal-pki','portal_client.pem','client.pem',33,True);copy('portal-pki','portal_client.key','client.key',33)
copy('web-pki','tls_certificate','portal.pem',101,True);copy('web-pki','tls_private_key','portal.key',101)
password=secrets.token_hex(24);hashed=subprocess.check_output(['openssl','passwd','-apr1','-stdin'],input=password+'\n',text=True).strip();write('web-pki','access_passwords','smoke:'+hashed+'\n',101)
write('receiver-private','db-password',secrets.token_hex(32),33);write('receiver-private','service-keyring.json','{}',33);copy('receiver-private','db-ca.pem','ca.pem',33,True)
for name,source in [('server.pem','performance.pem'),('server.key','performance.key'),('clients-ca.pem','ca.pem'),('clients.crl','clients.crl')]:copy('receiver-tls',name,source,101,name!='server.key')
f=p/'curl-auth';f.write_text('user = "smoke:'+password+'"\n');os.chown(f,int(os.environ.get('SUDO_UID','1001')),int(os.environ.get('SUDO_GID','1001')));f.chmod(0o600)
for name in ['ca.key','portal.key','client.key','performance.key','portal-db.key','performance-db.key']:(p/name).chmod(0o600)
