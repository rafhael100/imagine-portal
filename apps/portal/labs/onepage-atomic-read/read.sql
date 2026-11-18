-- LAB ONLY: after #922, and only after effective Laravel + Guard::identity preflight.
BEGIN;
DO $guard$
BEGIN
 IF current_database()<>'portal_onepage_buyer_ci' OR current_user<>'onepage_lab_bootstrap'
 OR session_user<>'onepage_lab_bootstrap' OR host(inet_server_addr()) IS DISTINCT FROM '127.0.0.1'
 OR inet_server_port() IS DISTINCT FROM 55439
 OR current_setting('data_directory') !~ '/onepage-pg-lab/data$'
 THEN RAISE EXCEPTION 'DISPOSABLE_IDENTITY_REQUIRED'; END IF;
END $guard$;
-- @portal-native-core READ
COMMIT;
