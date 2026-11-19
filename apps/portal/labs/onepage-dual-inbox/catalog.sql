-- Read-only, after each pinned installer phase on the same attested backend.
-- The caller must execute only after its independent gate; no functions installed.
WITH
 ns AS (SELECT * FROM pg_catalog.pg_namespace WHERE nspname !~ '^pg_' AND nspname<>'information_schema'),
 rel AS (SELECT c.*,n.nspname FROM pg_catalog.pg_class c JOIN ns n ON n.oid=c.relnamespace),
 a AS (SELECT x.*,r.nspname,r.relname FROM pg_catalog.pg_attribute x JOIN rel r ON r.oid=x.attrelid
       WHERE r.relkind='r' AND x.attnum>0 AND NOT x.attisdropped),
 acl AS (
  SELECT 'schema' kind,n.nspname AS object,NULL::text AS col,x.* FROM ns n
  CROSS JOIN LATERAL pg_catalog.aclexplode(coalesce(n.nspacl,pg_catalog.acldefault('n',n.nspowner))) x
  UNION ALL
  SELECT 'relation',r.nspname||'.'||r.relname,NULL,x.* FROM rel r
  CROSS JOIN LATERAL pg_catalog.aclexplode(CASE WHEN r.relkind='r'
          THEN coalesce(r.relacl,pg_catalog.acldefault('r',r.relowner)) ELSE r.relacl END) x
  UNION ALL
  SELECT 'column',a.nspname||'.'||a.relname,a.attname,x.* FROM a
  CROSS JOIN LATERAL pg_catalog.aclexplode(a.attacl) x
  UNION ALL
  SELECT 'database',d.datname,NULL,x.* FROM pg_catalog.pg_database d
  CROSS JOIN LATERAL pg_catalog.aclexplode(coalesce(d.datacl,pg_catalog.acldefault('d',d.datdba))) x
 )
SELECT jsonb_build_object(
 'schemas',(SELECT coalesce(jsonb_agg(jsonb_build_array(nspname,pg_catalog.pg_get_userbyid(nspowner))),'[]') FROM ns),
 'relations',(SELECT coalesce(jsonb_agg(jsonb_build_array(nspname,relname,relkind,relpersistence,
    pg_catalog.pg_get_userbyid(relowner),relrowsecurity,relforcerowsecurity,relispartition,reloptions,
    relacl IS NULL)),'[]') FROM rel),
 'columns',(SELECT coalesce(jsonb_agg(jsonb_build_array(a.attnum,a.attname,
    pg_catalog.format_type(a.atttypid,a.atttypmod),a.attnotnull,a.attidentity,a.attgenerated,
    a.attcollation=t.typcollation,pg_catalog.pg_get_expr(d.adbin,d.adrelid,true)) ORDER BY a.attnum),'[]')
    FROM a JOIN pg_catalog.pg_type t ON t.oid=a.atttypid
    LEFT JOIN pg_catalog.pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum),
 'constraints',(SELECT coalesce(jsonb_agg(jsonb_build_array(c.conname,c.contype,c.convalidated,c.conenforced,
    c.condeferrable,c.condeferred,c.conislocal,c.coninhcount,
    ARRAY(SELECT a.attname FROM unnest(c.conkey) WITH ORDINALITY k(attnum,ord) JOIN a
          ON a.attrelid=c.conrelid AND a.attnum=k.attnum ORDER BY k.ord),
    pg_catalog.pg_get_expr(c.conbin,c.conrelid,true))),'[]')
    FROM pg_catalog.pg_constraint c JOIN ns n ON n.oid=c.connamespace),
 'indexes',(SELECT coalesce(jsonb_agg(jsonb_build_array(r.relname,i.indisunique,i.indisprimary,
    i.indisvalid,i.indisready,i.indislive,i.indimmediate,i.indnullsnotdistinct,am.amname,i.indnkeyatts,i.indnatts,
    i.indexprs IS NULL, ARRAY(SELECT a.attname FROM unnest(i.indkey) WITH ORDINALITY k(attnum,ord)
      JOIN a ON a.attrelid=i.indrelid AND a.attnum=k.attnum ORDER BY k.ord),
    pg_catalog.pg_get_expr(i.indpred,i.indrelid,true),
    (SELECT coalesce(bool_and(o.opt=0 AND c.coll=a.attcollation AND op.opcdefault AND op.opcintype=a.atttypid),false)
       FROM unnest(i.indkey) WITH ORDINALITY k(attnum,ord)
       JOIN unnest(i.indoption) WITH ORDINALITY o(opt,ord) USING(ord)
       JOIN unnest(i.indcollation) WITH ORDINALITY c(coll,ord) USING(ord)
       JOIN unnest(i.indclass) WITH ORDINALITY q(opc,ord) USING(ord)
       JOIN pg_catalog.pg_opclass op ON op.oid=q.opc
       JOIN a ON a.attrelid=i.indrelid AND a.attnum=k.attnum))),'[]')
   FROM pg_catalog.pg_index i JOIN rel r ON r.oid=i.indexrelid JOIN pg_catalog.pg_am am ON am.oid=r.relam),
 'policies',(SELECT coalesce(jsonb_agg(jsonb_build_array(p.polname,p.polcmd,p.polpermissive,
      ARRAY(SELECT CASE WHEN x=0 THEN 'PUBLIC' ELSE pg_catalog.pg_get_userbyid(x) END FROM unnest(p.polroles) x),
      pg_catalog.pg_get_expr(p.polqual,p.polrelid,true),pg_catalog.pg_get_expr(p.polwithcheck,p.polrelid,true))),'[]')
    FROM pg_catalog.pg_policy p JOIN rel r ON r.oid=p.polrelid),
 'acl',(SELECT coalesce(jsonb_agg(jsonb_build_array(kind,object,col,
      CASE WHEN grantee=0 THEN 'PUBLIC' ELSE pg_catalog.pg_get_userbyid(grantee) END,
      pg_catalog.pg_get_userbyid(grantor),privilege_type,is_grantable)),'[]') FROM acl),
 'databases',(SELECT coalesce(jsonb_agg(jsonb_build_array(datname,pg_catalog.pg_get_userbyid(datdba),
      datallowconn,datistemplate,datconnlimit,datacl IS NULL)),'[]') FROM pg_catalog.pg_database),
 'roles',(SELECT coalesce(jsonb_agg(jsonb_build_array(rolname,rolcanlogin,rolsuper,rolinherit,rolcreatedb,
     rolcreaterole,rolreplication,rolbypassrls)),'[]') FROM pg_catalog.pg_roles),
 'memberships',(SELECT coalesce(jsonb_agg(jsonb_build_array(pg_catalog.pg_get_userbyid(roleid),
     pg_catalog.pg_get_userbyid(member),pg_catalog.pg_get_userbyid(grantor),admin_option,inherit_option,set_option)),'[]')
     FROM pg_catalog.pg_auth_members),
 'extensions',(SELECT coalesce(jsonb_agg(jsonb_build_array(e.extname,e.extversion,
     pg_catalog.pg_get_userbyid(e.extowner),n.nspname,e.extrelocatable)),'[]')
     FROM pg_catalog.pg_extension e JOIN pg_catalog.pg_namespace n ON n.oid=e.extnamespace),
 'rows',(SELECT count(*) FROM portal_psp.asaas_inbox),
 'forbidden',jsonb_build_array(
     (SELECT count(*) FROM pg_catalog.pg_trigger t JOIN rel r ON r.oid=t.tgrelid),
     (SELECT count(*) FROM pg_catalog.pg_rewrite t JOIN rel r ON r.oid=t.ev_class),
     (SELECT count(*) FROM pg_catalog.pg_inherits t WHERE t.inhrelid IN(SELECT oid FROM rel) OR t.inhparent IN(SELECT oid FROM rel)),
     (SELECT count(*) FROM pg_catalog.pg_proc p JOIN ns n ON n.oid=p.pronamespace),
     (SELECT count(*) FROM pg_catalog.pg_default_acl),
     (SELECT count(*) FROM a WHERE a.atthasmissing AND a.attname<>'attempts'),
     (SELECT count(*) FROM pg_catalog.pg_attribute x JOIN rel r ON r.oid=x.attrelid WHERE x.attnum>0 AND x.attisdropped))
)::text AS semantic_inventory;
