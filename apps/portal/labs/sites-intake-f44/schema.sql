-- PORTAL-F44: transactional fake editorial decision for Sites Imagine.
-- On DISPOSABLE PostgreSQL 18 only. No customer signatures or operational API.
BEGIN;
CREATE ROLE f44_reviewer_a LOGIN NOINHERIT;
CREATE ROLE f44_reviewer_b LOGIN NOINHERIT;
CREATE SCHEMA f44;
REVOKE ALL ON SCHEMA f44 FROM PUBLIC;

CREATE TABLE f44.site_review (
    organization_id text NOT NULL CHECK (organization_id IN ('org-a','org-b')),
    site_reference text NOT NULL
      CHECK (site_reference ~ '^DEMO-SITE-REQ-[A-Z0-9-]+$'),
    content_sha256 text NOT NULL
      CHECK (content_sha256 ~ '^[a-f0-9]{64}$'),
    brief_revision integer NOT NULL CHECK (brief_revision BETWEEN 0 AND 100000),
    content_revision integer NOT NULL CHECK (content_revision BETWEEN 1 AND 100000),
    review_status text NOT NULL CHECK (review_status IN
        ('awaiting_review_demo','approved_demo',
         'changes_requested_demo','rejected_demo')),
    review_revision integer NOT NULL CHECK (review_revision BETWEEN 0 AND 100000),
    PRIMARY KEY (organization_id,site_reference)
);

CREATE TABLE f44.review_receipt (
    organization_id text NOT NULL CHECK (organization_id IN ('org-a','org-b')),
    site_reference text NOT NULL,
    command_id text NOT NULL
      CHECK (command_id ~ '^DEMO-REVIEW-CMD-[A-Z0-9-]+$'),
    command_sha256 text NOT NULL CHECK (command_sha256 ~ '^[a-f0-9]{64}$'),
    content_sha256 text NOT NULL CHECK (content_sha256 ~ '^[a-f0-9]{64}$'),
    simulated_decision text NOT NULL CHECK (simulated_decision IN
       ('approve','request_changes','reject')),
    resulting_status text NOT NULL CHECK (resulting_status IN
       ('approved_demo','changes_requested_demo','rejected_demo')),
    resulting_review_revision integer NOT NULL
       CHECK (resulting_review_revision BETWEEN 1 AND 100000),
    occurred_at bigint NOT NULL CHECK
       (occurred_at BETWEEN 1800000000 AND 1800003600),
    PRIMARY KEY (organization_id,command_id),
    FOREIGN KEY (organization_id,site_reference)
       REFERENCES f44.site_review(organization_id,site_reference)
);

ALTER TABLE f44.site_review ENABLE ROW LEVEL SECURITY;
ALTER TABLE f44.site_review FORCE ROW LEVEL SECURITY;
ALTER TABLE f44.review_receipt ENABLE ROW LEVEL SECURITY;
ALTER TABLE f44.review_receipt FORCE ROW LEVEL SECURITY;

CREATE POLICY f44_sites_a ON f44.site_review
  FOR ALL TO f44_reviewer_a
  USING (organization_id='org-a' AND session_user='f44_reviewer_a')
  WITH CHECK (organization_id='org-a' AND session_user='f44_reviewer_a');
CREATE POLICY f44_sites_b ON f44.site_review
  FOR ALL TO f44_reviewer_b
  USING (organization_id='org-b' AND session_user='f44_reviewer_b')
  WITH CHECK (organization_id='org-b' AND session_user='f44_reviewer_b');
CREATE POLICY f44_receipts_a ON f44.review_receipt
  FOR ALL TO f44_reviewer_a
  USING (organization_id='org-a' AND session_user='f44_reviewer_a')
  WITH CHECK (organization_id='org-a' AND session_user='f44_reviewer_a');
CREATE POLICY f44_receipts_b ON f44.review_receipt
  FOR ALL TO f44_reviewer_b
  USING (organization_id='org-b' AND session_user='f44_reviewer_b')
  WITH CHECK (organization_id='org-b' AND session_user='f44_reviewer_b');

GRANT USAGE ON SCHEMA f44 TO f44_reviewer_a,f44_reviewer_b;
GRANT SELECT ON f44.site_review,f44.review_receipt TO f44_reviewer_a,f44_reviewer_b;
GRANT UPDATE(review_status,review_revision) ON f44.site_review
  TO f44_reviewer_a,f44_reviewer_b;
GRANT INSERT ON f44.review_receipt TO f44_reviewer_a,f44_reviewer_b;
REVOKE ALL ON ALL TABLES IN SCHEMA f44 FROM PUBLIC;
COMMIT;
