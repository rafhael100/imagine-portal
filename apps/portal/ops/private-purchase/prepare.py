"""Prepare ONLY a new isolated synthetic purchase database; never reuse identity DB."""
import base64, json, os, pathlib, secrets
if os.geteuid() != 0:
    raise SystemExit('Run as root to assign separate container file ownership.')
root = pathlib.Path(__file__).resolve().parent / '.private'
root.mkdir(mode=0o700, exist_ok=False)
password = secrets.token_hex(32)
psp_password = secrets.token_hex(32)  # Broker-only SQL login; never available in Laravel.
scope_key = secrets.token_hex(32)  # Separate signed SQL claim key; never put in logs or workflow output.
admin = secrets.token_hex(32)
key = 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode()
sql = """CREATE SCHEMA purchase_rehearsal;
CREATE TABLE purchase_rehearsal.orders (
 id text PRIMARY KEY, tenant text NOT NULL CHECK (tenant='test-org-a'), request_key text NOT NULL,
 product text NOT NULL CHECK (product IN ('onepage','landing-pages','performance')),
 payment text NOT NULL DEFAULT 'pending' CHECK (payment IN ('pending','declined','approved')),
 revision integer NOT NULL DEFAULT 0 CHECK (revision>=0), briefing jsonb,
 UNIQUE(tenant,request_key), CHECK (briefing IS NULL OR payment='approved'));
CREATE ROLE purchase_rehearsal_a LOGIN PASSWORD '%s' NOSUPERUSER NOBYPASSRLS NOINHERIT NOCREATEDB NOCREATEROLE NOREPLICATION;
CREATE ROLE purchase_rehearsal_psp LOGIN PASSWORD '%s' NOSUPERUSER NOBYPASSRLS NOINHERIT NOCREATEDB NOCREATEROLE NOREPLICATION;
GRANT CONNECT ON DATABASE portal_purchase_rehearsal TO purchase_rehearsal_a;
GRANT USAGE ON SCHEMA purchase_rehearsal TO purchase_rehearsal_a;
GRANT SELECT,INSERT,UPDATE ON purchase_rehearsal.orders TO purchase_rehearsal_a;
-- The DB alone checks customer-scoped claims; the login has no permission to read the verifier key.
CREATE TABLE purchase_rehearsal.preview_scope_secret (id integer PRIMARY KEY CHECK(id=1), key bytea NOT NULL CHECK(octet_length(key)=32));
INSERT INTO purchase_rehearsal.preview_scope_secret(id,key) VALUES (1,decode('%s','hex'));
REVOKE ALL ON purchase_rehearsal.preview_scope_secret FROM PUBLIC, purchase_rehearsal_a;
ALTER TABLE purchase_rehearsal.orders ENABLE ROW LEVEL SECURITY;
ALTER TABLE purchase_rehearsal.orders FORCE ROW LEVEL SECURITY;
CREATE POLICY preview_scope ON purchase_rehearsal.orders FOR ALL
 USING (tenant='test-org-a' AND current_user='purchase_rehearsal_a')
 WITH CHECK (tenant='test-org-a' AND current_user='purchase_rehearsal_a');
""" % (password, psp_password, scope_key)
for name,content,uid in [('runtime.json',json.dumps({'database_password':password,'app_key':key,'customer_scope_key':scope_key}),82),('admin-password',admin,70),('init.sql',sql,70),('broker.pgpass','database:5432:portal_purchase_rehearsal:purchase_rehearsal_psp:'+psp_password,70)]:
    file = root / name
    file.write_text(content)
    file.chmod(0o400)
    os.chown(file,uid,uid)
print('Prepared new synthetic workspace; no existing database or account changed.')

# Profile hashes are independent of runtime/database keys.
users={'accounts':{}}
if os.environ.get('GITHUB_ACTIONS')=='true':
    import subprocess
    for username,role,password_value in [('cliente.teste','client','ci-client-only-2026'),('administrador.teste','admin','ci-admin-only-2026')]:
        result=subprocess.run(['docker','run','--rm','-i','--entrypoint','php','imagine-purchase-preview:local','-r','echo password_hash(stream_get_contents(STDIN), PASSWORD_DEFAULT);'],input=password_value.encode(),stdout=subprocess.PIPE,check=True)
        users['accounts'][username]={'role':role,'hash':result.stdout.decode()}
profiles=root/'profiles'
profiles.mkdir(mode=0o700)
os.chown(profiles,82,82)
file=profiles/'users.json'
file.write_text(json.dumps(users))
file.chmod(0o400)
os.chown(file,82,82)

