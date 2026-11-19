'use strict';
const assert = require('node:assert/strict');
const {safeSessionDiagnostic} = require('./safe-session-diagnostic.cjs');
let checks = 0;
const expect = (actual, expected) => {assert.equal(actual, expected); checks++;};
expect(safeSessionDiagnostic('restored_beta', 200, '/portal-admin/performance-private/company_beta', true),
  'PHASE8_RECOVERY_SESSION_DIAGNOSTIC stage=restored_beta status=200 route=private heading=yes');
expect(safeSessionDiagnostic('restored_beta', 200, '/portal-admin/login', false),
  'PHASE8_RECOVERY_SESSION_DIAGNOSTIC stage=restored_beta status=200 route=login heading=no');
expect(safeSessionDiagnostic('restored_beta', 403, '/portal-admin/performance-private/company_beta', false),
  'PHASE8_RECOVERY_SESSION_DIAGNOSTIC stage=restored_beta status=403 route=private heading=no');
expect(safeSessionDiagnostic('restored_beta', 302, '/portal-admin/mfa', false),
  'PHASE8_RECOVERY_SESSION_DIAGNOSTIC stage=restored_beta status=302 route=mfa heading=no');
expect(safeSessionDiagnostic('hijacked:secret', NaN, '/portal-admin/performance-private/company_beta?secret=1', true),
  'PHASE8_RECOVERY_SESSION_DIAGNOSTIC stage=unknown status=invalid route=other heading=yes');
expect(safeSessionDiagnostic('restored_beta', 200, '/portal-admin/login/secret-session-cookie', false),
  'PHASE8_RECOVERY_SESSION_DIAGNOSTIC stage=restored_beta status=200 route=other heading=no');
const hostile = safeSessionDiagnostic('restored_beta', 9999,
  '/portal-admin/performance-private/company_beta%0Asecret-token', 'sensitive');
expect(hostile,
  'PHASE8_RECOVERY_SESSION_DIAGNOSTIC stage=restored_beta status=invalid route=other heading=unknown');
assert(!hostile.includes('secret-token')); checks++;
console.log('PHASE8_RECOVERY_SAFE_DIAGNOSTIC_TEST_PASS: ' + checks + ' checks');
