WITH
 ns AS (SELECT * FROM pg_catalog.pg_namespace WHERE nspname='onepage_ingress_lab'),
 r AS (SELECT c.* FROM pg_catalog.pg_class c JOIN ns ON ns.oid=c.relnamespace),
 a AS (SELECT a.*,r.relname FROM pg_catalog.pg_attribute a JOIN r ON r.oid=a.attrelid WHERE r.relkind='r' AND a.attnum>0 AND NOT a.attisdropped),
 f AS (SELECT p.* FROM pg_catalog.pg_proc p JOIN ns ON p.pronamespace=ns.oid),
 acl AS (
  SELECT 'schema' kind,nspname target,NULL::text col,x.* FROM pg_catalog.pg_namespace n CROSS JOIN LATERAL pg_catalog.aclexplode(coalesce(n.nspacl,pg_catalog.acldefault('n',n.nspowner))) x WHERE nspname='onepage_ingress_lab' OR (nspname='portal_psp' AND x.grantee='onepage_ingress_owner'::regrole)
  UNION ALL
  SELECT 'table',n.nspname||'.'||r.relname,NULL,x.* FROM pg_catalog.pg_class r JOIN pg_catalog.pg_namespace n ON n.oid=r.relnamespace CROSS JOIN LATERAL pg_catalog.aclexplode(coalesce(r.relacl,pg_catalog.acldefault('r',r.relowner))) x WHERE r.relkind='r' AND (n.nspname='onepage_ingress_lab' OR (n.nspname='portal_psp' AND r.relname='asaas_inbox' AND x.grantee='onepage_ingress_owner'::regrole))
  UNION ALL
  SELECT 'column','onepage_ingress_lab.'||relname,attname,x.* FROM a CROSS JOIN LATERAL pg_catalog.aclexplode(attacl) x
  UNION ALL
  SELECT 'function',proname,NULL,x.* FROM f CROSS JOIN LATERAL pg_catalog.aclexplode(coalesce(proacl,pg_catalog.acldefault('f',proowner))) x
  UNION ALL
  SELECT 'database',datname,NULL,x.* FROM pg_catalog.pg_database d CROSS JOIN LATERAL pg_catalog.aclexplode(coalesce(d.datacl,pg_catalog.acldefault('d',d.datdba))) x WHERE datname='portal_asaas_ci' AND x.grantee IN ('onepage_ingress_capture'::regrole::oid,'onepage_ingress_revoker'::regrole::oid,0::oid)
 )
SELECT jsonb_build_object(
 'schemas',(SELECT coalesce(jsonb_agg(jsonb_build_array(nspname,pg_get_userbyid(nspowner))),'[]') FROM ns),
 'relations',(SELECT coalesce(jsonb_agg(jsonb_build_array(relname,relkind,pg_get_userbyid(relowner),relrowsecurity,relforcerowsecurity,relpersistence,reloptions,relispartition)),'[]') FROM r),
 'columns',(SELECT coalesce(jsonb_agg(jsonb_build_array(relname,attnum,attname,format_type(atttypid,atttypmod),attnotnull,attidentity,attgenerated,pg_get_expr(d.adbin,d.adrelid,true))),'[]') FROM a LEFT JOIN pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum),
 'constraints',(SELECT coalesce(jsonb_agg(jsonb_build_array(r.relname,conname,contype,ARRAY(SELECT a.attname FROM unnest(c.conkey) WITH ORDINALITY k(n,o) JOIN a ON a.attrelid=c.conrelid AND a.attnum=k.n ORDER BY k.o),pg_get_expr(conbin,c.conrelid,true),convalidated,conenforced,condeferrable,condeferred,conislocal,coninhcount)),'[]') FROM pg_constraint c JOIN r ON r.oid=c.conrelid),
 'indexes',(SELECT coalesce(jsonb_agg(jsonb_build_array(t.relname,r.relname,i.indisunique,i.indisprimary,i.indisvalid,i.indisready,i.indislive,i.indimmediate,i.indnullsnotdistinct,am.amname,ARRAY(SELECT a.attname FROM unnest(i.indkey) WITH ORDINALITY k(n,o) JOIN a ON a.attrelid=t.oid AND a.attnum=k.n ORDER BY k.o),pg_get_expr(i.indpred,t.oid,true),pg_get_expr(i.indexprs,t.oid,true),i.indnkeyatts,i.indnatts,
 (SELECT coalesce(bool_and(o.opt=0 AND cc.coll=a.attcollation AND op.opcdefault AND op.opcintype=a.atttypid),false)
 FROM unnest(i.indkey) WITH ORDINALITY kk(attnum,ord)
 JOIN unnest(i.indoption) WITH ORDINALITY o(opt,ord) USING(ord)
 JOIN unnest(i.indcollation) WITH ORDINALITY cc(coll,ord) USING(ord)
 JOIN unnest(i.indclass) WITH ORDINALITY q(opc,ord) USING(ord)
 JOIN pg_opclass op ON op.oid=q.opc
 JOIN a ON a.attrelid=i.indrelid AND a.attnum=kk.attnum))),'[]') FROM pg_index i JOIN r ON r.oid=i.indexrelid JOIN r t ON t.oid=i.indrelid JOIN pg_am am ON am.oid=r.relam),
 'policies',(SELECT coalesce(jsonb_agg(jsonb_build_array(n.nspname||'.'||r.relname,polname,polcmd,polpermissive,ARRAY(SELECT CASE WHEN x=0 THEN 'PUBLIC' ELSE pg_get_userbyid(x) END FROM unnest(polroles) x),pg_get_expr(polqual,polrelid,true),pg_get_expr(polwithcheck,polrelid,true))),'[]') FROM pg_policy p JOIN pg_class r ON r.oid=p.polrelid JOIN pg_namespace n ON n.oid=r.relnamespace WHERE n.nspname='onepage_ingress_lab' OR (n.nspname='portal_psp' AND r.relname='asaas_inbox' AND polname IN ('onepage_shared_select','onepage_shared_insert','onepage_shared_account'))),
 'functions',(SELECT coalesce(jsonb_agg(jsonb_build_array(proname,pg_get_function_identity_arguments(f.oid),pg_get_function_result(f.oid),l.lanname,pg_get_userbyid(proowner),prosecdef,provolatile,proparallel,proisstrict,proleakproof,proconfig,encode(sha256(convert_to(prosrc,'UTF8')),'hex'))),'[]') FROM f JOIN pg_language l ON l.oid=f.prolang),
 'acl',(SELECT coalesce(jsonb_agg(jsonb_build_array(kind,target,col,CASE WHEN grantee=0 THEN 'PUBLIC' ELSE pg_get_userbyid(grantee) END,pg_get_userbyid(grantor),privilege_type,is_grantable)),'[]') FROM acl),
 'forbidden',jsonb_build_array(
  (SELECT count(*) FROM pg_trigger WHERE tgrelid IN (SELECT oid FROM r)),
  (SELECT count(*) FROM pg_rewrite WHERE ev_class IN (SELECT oid FROM r)),
  (SELECT count(*) FROM pg_inherits WHERE inhparent IN (SELECT oid FROM r) OR inhrelid IN (SELECT oid FROM r)),
  (SELECT count(*) FROM pg_attribute WHERE attrelid IN (SELECT oid FROM r) AND attnum>0 AND (attisdropped OR atthasmissing)),
  (SELECT count(*) FROM pg_namespace n CROSS JOIN LATERAL aclexplode(n.nspacl) x WHERE n.nspname='public' AND x.grantee=0),
  (SELECT count(*) FROM onepage_ingress_lab.admissions),
  (SELECT count(*) FROM onepage_ingress_lab.inbox)
 )
)::text AS shared_delta;
