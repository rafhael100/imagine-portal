<?php

declare(strict_types=1);

/*
 * PORTAL EMAIL PROOFS: OPERATIONAL NO-GO. These are deliberately fixed
 * false, not env-toggles: a credential, APP_ENV, SMTP server or deploy alone
 * cannot switch on first-account invitations or send a verification bearer.
 * Shipping a real issuer requires an independent AppSec/DBA decision,
 * external rollback witness, approved sender and a separate reviewed PR.
 */
return [
    'enabled' => false,
    'approved_operator' => false,
    'appsec_approved' => false,
    'sender_verified' => false,
    'external_witness_ready' => false,
    'tls_transport_verified' => false,
];
