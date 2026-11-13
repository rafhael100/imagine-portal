\set ON_ERROR_STOP on
-- F368 strictly disposable external-cluster witness. Not an operational schema.
CREATE ROLE f368_witness_reader NOLOGIN NOSUPERUSER NOBYPASSRLS
  NOCREATEDB NOCREATEROLE NOINHERIT;
CREATE TABLE public.portal_f368_independent_witness (
  organization varchar(64) NOT NULL,
  instance varchar(64) NOT NULL,
  generation_floor bigint NOT NULL CHECK (generation_floor>0),
  session_floor bigint NOT NULL CHECK (session_floor>0),
  membership_floor bigint NOT NULL CHECK (membership_floor>0),
  sequence bigint NOT NULL CHECK (sequence>0),
  PRIMARY KEY (organization,instance),
  CHECK (organization ~ '^[a-zA-Z0-9_-]{1,64}$'
     AND instance ~ '^[a-zA-Z0-9_-]{1,64}$')
);
REVOKE ALL ON public.portal_f368_independent_witness FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO f368_witness_reader;
GRANT SELECT ON public.portal_f368_independent_witness TO f368_witness_reader;
ALTER TABLE public.portal_f368_independent_witness ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.portal_f368_independent_witness FORCE ROW LEVEL SECURITY;
CREATE POLICY f368_reader ON public.portal_f368_independent_witness
 FOR SELECT TO f368_witness_reader USING(true);
CREATE FUNCTION public.portal_f368_guard_epoch() RETURNS trigger
 LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $f368$
BEGIN
  IF NEW.organization IS DISTINCT FROM OLD.organization OR
     NEW.instance IS DISTINCT FROM OLD.instance OR
     NEW.generation_floor<OLD.generation_floor OR
     NEW.session_floor<OLD.session_floor OR
     NEW.membership_floor<OLD.membership_floor OR
     NEW.sequence<>OLD.sequence+1
  THEN RAISE EXCEPTION 'Witness sequence must strictly advance'
       USING ERRCODE='42501';
  END IF;
  RETURN NEW;
END;
$f368$;
REVOKE ALL ON FUNCTION public.portal_f368_guard_epoch() FROM PUBLIC;
CREATE TRIGGER portal_f368_epoch_monotonic
 BEFORE UPDATE ON public.portal_f368_independent_witness
 FOR EACH ROW EXECUTE FUNCTION public.portal_f368_guard_epoch();
INSERT INTO public.portal_f368_independent_witness VALUES
('org_f368','instance_f368',5,3,7,12);
