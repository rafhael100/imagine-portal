import {migrateBriefing} from './briefing.mjs';
import {DatabaseSync} from 'node:sqlite';
import {mkdirSync} from 'node:fs';
import {dirname} from 'node:path';
export function openStore(path){if(path!==':memory:')mkdirSync(dirname(path),{recursive:true,mode:0o700});const db=new DatabaseSync(path);db.exec(`
PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;
CREATE TABLE IF NOT EXISTS schema_version(version INTEGER PRIMARY KEY);
INSERT OR IGNORE INTO schema_version VALUES(1);
CREATE TABLE IF NOT EXISTS users(id TEXT PRIMARY KEY,name TEXT NOT NULL,email TEXT NOT NULL UNIQUE,password_hash TEXT NOT NULL,role TEXT NOT NULL CHECK(role IN ('client','admin')),created_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS sessions(hash TEXT PRIMARY KEY,user_id TEXT NOT NULL REFERENCES users(id),csrf TEXT NOT NULL,expires_at INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS idx_sessions_user_expiry ON sessions(user_id,expires_at DESC);
CREATE TABLE IF NOT EXISTS products(id TEXT PRIMARY KEY,title TEXT NOT NULL,price_cents INTEGER,scope TEXT NOT NULL DEFAULT '',delivery TEXT NOT NULL DEFAULT '',enabled INTEGER NOT NULL DEFAULT 0,version INTEGER NOT NULL DEFAULT 1);
CREATE TABLE IF NOT EXISTS orders(id TEXT PRIMARY KEY,user_id TEXT NOT NULL REFERENCES users(id),product_id TEXT NOT NULL REFERENCES products(id),title TEXT NOT NULL,brief TEXT NOT NULL,price_cents INTEGER,scope TEXT NOT NULL,delivery TEXT NOT NULL,terms_version INTEGER NOT NULL DEFAULT 1,phase TEXT NOT NULL DEFAULT 'solicitado',payment_status TEXT NOT NULL DEFAULT 'NOT_CREATED',billing_state TEXT NOT NULL DEFAULT 'idle',customer_id TEXT,payment_id TEXT UNIQUE,invoice_url TEXT,accepted_at TEXT,maintenance_opt_in INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL,updated_at TEXT NOT NULL);
CREATE INDEX IF NOT EXISTS idx_orders_user_created ON orders(user_id,created_at DESC);
CREATE TABLE IF NOT EXISTS maintenance(order_id TEXT PRIMARY KEY REFERENCES orders(id),checkout_id TEXT UNIQUE,checkout_url TEXT,checkout_expires_at INTEGER,subscription_id TEXT UNIQUE,status TEXT NOT NULL DEFAULT 'creating',first_due TEXT NOT NULL,cancel_requested_at TEXT,canceled_at TEXT,created_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS payments(id TEXT PRIMARY KEY,order_id TEXT NOT NULL REFERENCES orders(id),kind TEXT NOT NULL,status TEXT NOT NULL,value_cents INTEGER NOT NULL,net_cents INTEGER,due_date TEXT,paid_at TEXT,invoice_url TEXT,updated_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS messages(id INTEGER PRIMARY KEY AUTOINCREMENT,order_id TEXT NOT NULL REFERENCES orders(id),user_id TEXT NOT NULL REFERENCES users(id),body TEXT NOT NULL,created_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS events(id TEXT PRIMARY KEY,type TEXT NOT NULL,resource_id TEXT,context TEXT NOT NULL DEFAULT '{}',attempts INTEGER NOT NULL DEFAULT 0,next_at INTEGER NOT NULL DEFAULT 0,done INTEGER NOT NULL DEFAULT 0,error TEXT,created_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS audit(id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id TEXT,action TEXT NOT NULL,resource_id TEXT,created_at TEXT NOT NULL);
CREATE INDEX IF NOT EXISTS idx_audit_actor_id_id ON audit(actor_id,id DESC);
CREATE TABLE IF NOT EXISTS rate_limits(key TEXT PRIMARY KEY,count INTEGER NOT NULL,reset_at INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS idx_rate_limits_reset_at ON rate_limits(reset_at);
`);migrateBriefing(db);for(const [id,title,price] of [['site','Site Onepage',99000],['sistema','Desenvolvimento de sistema',null],['app','Desenvolvimento de aplicativo',null],['ia','Desenvolvimento de agente de IA',null]])db.prepare('INSERT OR IGNORE INTO products(id,title,price_cents) VALUES(?,?,?)').run(id,title,price);return db}
export const now=()=>new Date().toISOString();
export function audit(db,actor,action,resource){db.prepare('INSERT INTO audit(actor_id,action,resource_id,created_at) VALUES(?,?,?,?)').run(actor||null,action,resource||null,now())}
