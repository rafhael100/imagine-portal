BEGIN;
DO $$ BEGIN IF current_database()<>'portal_purchase_rehearsal' THEN RAISE EXCEPTION 'Wrong database'; END IF; END $$;
ALTER TABLE purchase_rehearsal.customer_orders ADD COLUMN IF NOT EXISTS performance_report_version integer NOT NULL DEFAULT 0 CHECK(performance_report_version BETWEEN 0 AND 20);
CREATE TABLE IF NOT EXISTS purchase_rehearsal.performance_settlement_reports (
 order_id text NOT NULL REFERENCES purchase_rehearsal.customer_orders(id),version integer NOT NULL CHECK(version BETWEEN 1 AND 20),
 report jsonb NOT NULL CHECK(jsonb_typeof(report)='object'),
 decision text NOT NULL DEFAULT 'pending' CHECK(decision IN ('pending','acknowledged','disputed')),
 client_note text CHECK(length(client_note)<=1000),created_at timestamptz NOT NULL DEFAULT now(),decided_at timestamptz,
 PRIMARY KEY(order_id,version),CHECK((decision='pending')=(decided_at IS NULL))
);
ALTER TABLE purchase_rehearsal.performance_settlement_reports ENABLE ROW LEVEL SECURITY;
ALTER TABLE purchase_rehearsal.performance_settlement_reports FORCE ROW LEVEL SECURITY;
DO $$ BEGIN IF NOT EXISTS(SELECT FROM pg_policies WHERE schemaname='purchase_rehearsal' AND tablename='performance_settlement_reports' AND policyname='performance_report_scope') THEN
CREATE POLICY performance_report_scope ON purchase_rehearsal.performance_settlement_reports FOR ALL USING(current_user='purchase_rehearsal_a') WITH CHECK(current_user='purchase_rehearsal_a');
END IF; END $$;
GRANT SELECT ON purchase_rehearsal.performance_settlement_reports TO purchase_rehearsal_a;
GRANT INSERT(order_id,version,report) ON purchase_rehearsal.performance_settlement_reports TO purchase_rehearsal_a;
GRANT UPDATE(decision,client_note,decided_at) ON purchase_rehearsal.performance_settlement_reports TO purchase_rehearsal_a;
COMMIT;
