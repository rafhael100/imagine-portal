-- Must share CREATE FUNCTION's transaction: PUBLIC never sees the default EXECUTE.
ALTER FUNCTION onepage_ingress_lab.capture_shared(text,text,text,text,text,text,text,text,text) OWNER TO onepage_ingress_owner;
ALTER FUNCTION onepage_ingress_lab.revoke(text,text,text,text) OWNER TO onepage_ingress_owner;
REVOKE ALL ON ALL FUNCTIONS IN SCHEMA onepage_ingress_lab FROM PUBLIC;
GRANT EXECUTE ON FUNCTION onepage_ingress_lab.capture_shared(text,text,text,text,text,text,text,text,text) TO onepage_ingress_capture;
GRANT EXECUTE ON FUNCTION onepage_ingress_lab.revoke(text,text,text,text) TO onepage_ingress_revoker;
