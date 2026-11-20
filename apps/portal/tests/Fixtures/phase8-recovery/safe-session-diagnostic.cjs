'use strict';
// Synthetic recovery CI only. Never include cookies, URLs, query strings,
// response bodies, email, account IDs or error exceptions in diagnostics.
function safeSessionDiagnostic(stage, status, pathname, headingVisible, signals = null) {
  const safeStage = stage === 'restored_beta' ? stage : 'unknown';
  const safeStatus = Number.isInteger(status) && status >= 100 && status <= 599
    ? String(status) : 'invalid';
  let route = 'other';
  if (typeof pathname === 'string') {
    if (/^\/portal-admin\/login\/?$/.test(pathname)) route = 'login';
    else if (/^\/portal-admin\/mfa\/?$/.test(pathname)) route = 'mfa';
    else if (/^\/portal-admin\/performance-private\/[A-Za-z0-9_-]{8,96}\/?$/.test(pathname)) route = 'private';
  }
  const heading = typeof headingVisible === 'boolean'
    ? (headingVisible ? 'yes' : 'no') : 'unknown';
  // Optional strict booleans only; never emit cookie names/values, redirects,
  // URLs, exceptions, company identifiers or arbitrary signal keys/values.
  const hasSignals = signals !== null && typeof signals === 'object'
    && !Array.isArray(signals);
  const signal = key => {
    if (!hasSignals || typeof signals[key] !== 'boolean') return 'unknown';
    return signals[key] ? 'yes' : 'no';
  };
  const suffix = hasSignals
    ? ' source_cookie=' + signal('sourceCookie')
      + ' restored_cookie=' + signal('restoredCookie')
      + ' source_heading=' + signal('sourceHeading')
      + ' redirected=' + signal('redirected')
    : '';
  return 'PHASE8_RECOVERY_SESSION_DIAGNOSTIC stage=' + safeStage
    + ' status=' + safeStatus + ' route=' + route + ' heading=' + heading + suffix;
}
module.exports = {safeSessionDiagnostic};
