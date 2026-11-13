"""Prepare ONLY a new isolated synthetic purchase database; never reuse identity DB."""
import base64, json, os, pathlib, secrets
if os.geteuid() != 0:
    raise SystemExit('Run as root to assign separate container file ownership.')
root = pathlib.Path(__file__).resolve().parent / '.private'
root.mkdir(mode=0o700, exist_ok=False)
password = secrets.token_hex(32)
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
GRANT CONNECT ON DATABASE portal_purchase_rehearsal TO purchase_rehearsal_a;
GRANT USAGE ON SCHEMA purchase_rehearsal TO purchase_rehearsal_a;
GRANT SELECT,INSERT,UPDATE ON purchase_rehearsal.orders TO purchase_rehearsal_a;
ALTER TABLE purchase_rehearsal.orders ENABLE ROW LEVEL SECURITY;
ALTER TABLE purchase_rehearsal.orders FORCE ROW LEVEL SECURITY;
CREATE POLICY preview_scope ON purchase_rehearsal.orders FOR ALL
 USING (tenant='test-org-a' AND current_user='purchase_rehearsal_a')
 WITH CHECK (tenant='test-org-a' AND current_user='purchase_rehearsal_a');
""" % password
for name,content,uid in [('runtime.json',json.dumps({'database_password':password,'app_key':key}),82),('admin-password',admin,70),('init.sql',sql,70)]:
    file = root / name
    file.write_text(content)
    file.chmod(0o400)
    os.chown(file,uid,uid)
print('Prepared new synthetic workspace; no existing database or account changed.')
