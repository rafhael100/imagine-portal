-- Additive candidate after sites-sandbox-bindings-candidate.sql. No seed or automatic migration.
-- Authenticated native Portal order/contract/approved-offer producer must own these rows.
CREATE TABLE portal_sites_sandbox.installation_release_terms (
 order_ref text PRIMARY KEY,tenant_ref text NOT NULL,contract_ref text NOT NULL,contract_revision integer NOT NULL CHECK(contract_revision>0),
 product_code text NOT NULL CHECK(product_code IN ('onepage','landing_page')),offer_sha256 text NOT NULL CHECK(offer_sha256 ~ '^[a-f0-9]{64}$'),
 policy_sha256 text NOT NULL CHECK(policy_sha256 ~ '^[a-f0-9]{64}$'),
 policy text NOT NULL CHECK(policy IN ('unapproved','full_installation_settled')),
 total_amount_cents bigint NOT NULL CHECK(total_amount_cents BETWEEN 1 AND 999999999),
 installment_count integer NOT NULL CHECK(installment_count=3),installment_cents bigint NOT NULL CHECK(installment_cents*installment_count=total_amount_cents),
 expires_at timestamptz NOT NULL,
 FOREIGN KEY(order_ref,tenant_ref) REFERENCES portal_sites_sandbox.installation_bindings(order_ref,tenant_ref)
);
REVOKE ALL ON portal_sites_sandbox.installation_release_terms FROM PUBLIC;
ALTER TABLE portal_sites_sandbox.installation_release_terms ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_sites_sandbox.installation_release_terms FORCE ROW LEVEL SECURITY;
CREATE POLICY release_scope ON portal_sites_sandbox.installation_release_terms
 USING(expires_at>statement_timestamp() AND portal_sites_sandbox.can_read_binding(tenant_ref,order_ref));
CREATE TRIGGER release_terms_immutable BEFORE UPDATE OR DELETE ON portal_sites_sandbox.installation_release_terms
 FOR EACH ROW EXECUTE FUNCTION portal_sites_sandbox.reject_change();
CREATE TRIGGER release_terms_audit AFTER INSERT ON portal_sites_sandbox.installation_release_terms
 FOR EACH ROW EXECUTE FUNCTION portal_sites_sandbox.audit_change();
-- Runtime: explicit SELECT only; owner must approve versioned commercial policy separately.
