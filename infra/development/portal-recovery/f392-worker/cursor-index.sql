-- F392 review-only D1 index, NOT auto-migrated or deployed.
-- Required to keep restart enumeration bounded for many tenant intents.
-- Only install AFTER the F386 append-only revocation_intents schema.
CREATE INDEX IF NOT EXISTS revocation_intents_sequence_cursor_idx
ON revocation_intents (organization,instance,to_sequence);
