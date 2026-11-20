'use strict';
const assert = require('node:assert/strict');
const {safeSessionDiagnostic, hasSessionCookie} = require('./safe-session-diagnostic.cjs');
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
const signals = safeSessionDiagnostic('restored_beta', 302, '/portal-admin/login', false,
  {sourceCookie: true, restoredCookie: false, sourceHeading: true, redirected: true});
expect(signals, 'PHASE8_RECOVERY_SESSION_DIAGNOSTIC stage=restored_beta status=302 route=login heading=no'
  + ' source_cookie=yes restored_cookie=no source_heading=yes redirected=yes');
const sanitized = safeSessionDiagnostic('restored_beta', 200,
  '/portal-admin/performance-private/company_beta', false,
  {sourceCookie: 'PRIVATE_COOKIE_VALUE_DO_NOT_LOG', restoredCookie: null,
    sourceHeading: 'secret', redirected: false, access_token: 'PRIVATE_BEARER_DO_NOT_LOG'});
expect(sanitized, 'PHASE8_RECOVERY_SESSION_DIAGNOSTIC stage=restored_beta status=200 route=private heading=no'
  + ' source_cookie=unknown restored_cookie=unknown source_heading=unknown redirected=no');
assert(!sanitized.includes('PRIVATE_COOKIE_VALUE_DO_NOT_LOG')); checks++;
assert(!sanitized.includes('PRIVATE_BEARER_DO_NOT_LOG')); checks++;
// Browser-derived signals are reduced to strict booleans before formatting.
// No cookie jar, cookie value, redirects, URL or exception may reach stdout.
(async () => {
  const cookieJar = [
    {name:'unrelated_cookie', value:'PRIVATE_UNRELATED_VALUE'},
    {name:'imagine_performance_p6_session', value:'PRIVATE_SESSION_VALUE', domain:'127.0.0.1'},
  ];
  expect(await hasSessionCookie({cookies: async () => cookieJar}), true);
  expect(await hasSessionCookie({cookies: async () => [cookieJar[0]]}), false);
  expect(await hasSessionCookie({cookies: async () => 'SECRET_INVALID_JAR'}), null);
  expect(await hasSessionCookie({cookies: async () => {throw new Error('PRIVATE_INTERNAL_TOKEN');}}), null);
  expect(await hasSessionCookie({}), null);
  const liveDerived = safeSessionDiagnostic('restored_beta', 200, '/portal-admin/login', false,
    {sourceCookie: await hasSessionCookie({cookies: async () => cookieJar}),
      restoredCookie: await hasSessionCookie({cookies: async () => [cookieJar[0]]}),
      sourceHeading: true, redirected: true, cookieJar});
  expect(liveDerived, 'PHASE8_RECOVERY_SESSION_DIAGNOSTIC stage=restored_beta'
    + ' status=200 route=login heading=no source_cookie=yes restored_cookie=no'
    + ' source_heading=yes redirected=yes');
  assert(!liveDerived.includes('PRIVATE_SESSION_VALUE')
    && !liveDerived.includes('PRIVATE_UNRELATED_VALUE')
    && !liveDerived.includes('imagine_performance_p6_session')); checks++;
  console.log('PHASE8_RECOVERY_SAFE_DIAGNOSTIC_TEST_PASS: ' + checks + ' checks');
})().catch(() => {
  console.error('PHASE8_RECOVERY_SAFE_DIAGNOSTIC_TEST_FAILED');
  process.exitCode = 1;
});
