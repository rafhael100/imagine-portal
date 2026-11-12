'use strict';
(() => {
  const root = document.querySelector('#auth');
  if (!root) return;
  const organization = root.dataset.organization;
  const passwordForm = document.querySelector('#password-form');
  const mfaForm = document.querySelector('#mfa-form');
  const authenticated = document.querySelector('#authenticated');
  const status = document.querySelector('#status');
  const email = document.querySelector('#email');
  const password = document.querySelector('#password');
  const code = document.querySelector('#code');
  let busy = false;
  let phase = 'password';
  const message = (text, error = false) => {
    status.textContent = text;
    status.dataset.error = String(error);
    status.hidden = !text;
  };
  const state = (value) => {
    phase = value;
    passwordForm.hidden = value !== 'password';
    mfaForm.hidden = value !== 'mfa';
    authenticated.hidden = value !== 'authenticated';
    if (value === 'mfa') code.focus();
    else if (value === 'password' && organization) email.focus();
    else if (value === 'authenticated') document.querySelector('#success-title').focus();
  };
  const call = async (path, body) => {
    const options = {credentials:'same-origin', cache:'no-store', redirect:'error',
      headers:{Accept:'application/json'}, signal:AbortSignal.timeout(10000)};
    if (body !== undefined) {
      const csrf = await fetch('/_development/auth/csrf', options);
      if (!csrf.ok) throw new Error('unavailable');
      const token = (await csrf.json()).csrf_token;
      if (typeof token !== 'string') throw new Error('unavailable');
      options.method = 'POST';
      options.headers = {...options.headers,'Content-Type':'application/json','X-CSRF-TOKEN':token};
      options.body = JSON.stringify(body);
    }
    return fetch('/_development/auth/' + path, options);
  };
  const perform = async (action) => {
    if (busy) return;
    busy = true;
    root.setAttribute('aria-busy','true');
    root.querySelectorAll('button').forEach(button => {button.disabled=true;});
    try { await action(); }
    catch { message('Não foi possível conectar. Tente novamente em instantes.', true); }
    finally {
      busy=false;
      root.removeAttribute('aria-busy');
      root.querySelectorAll('button').forEach(button => {button.disabled=false;});
    }
  };
  if (!organization) return;
  passwordForm.addEventListener('submit', event => {
    event.preventDefault();
    if (!passwordForm.reportValidity()) return;
    perform(async () => {
      message('');
      try {
        const response = await call('password',{email:email.value.trim(),password:password.value,organization});
        if (response.status === 202) {state('mfa');message('Confirme o código do seu aplicativo.');}
        else {state('password');message(response.status === 401?'Não foi possível confirmar os dados informados.':'Não foi possível entrar. Tente novamente.',true);}
      } finally {password.value='';code.value='';}
    });
  });
  mfaForm.addEventListener('submit', event => {
    event.preventDefault();
    if (!mfaForm.reportValidity()) return;
    perform(async () => {
      let response;
      try {response = await call('mfa',{code:code.value});}
      finally {code.value='';}
      if (response.ok && (await response.json()).authenticated === true) {
        const current=await call('session');
        if (current.ok && (await current.json()).authenticated === true) {state('authenticated');message('');return;}
      }
      message('Não foi possível confirmar o código. Confira o aplicativo ou volte para entrar novamente.',true);
    });
  });
  const leave = () => perform(async () => {
    // Invalidate the presentation before any network call. An uncertain logout
    // MUST NOT leave an old "authenticated" claim visible.
    state('locked');
    message('Confirmando a saída…');
    password.value='';code.value='';
    try {
      const response = await call('logout',{});
      if (response.status === 204) {state('password');message('Você saiu com segurança.');}
      else message('Não foi possível confirmar a saída. Feche esta página e tente novamente.',true);
    } catch {
      message('Não foi possível confirmar a saída. Feche esta página e tente novamente.',true);
    }
  });
  // On returning to the tab, an old success screen must be revalidated
  // against the server; it never grants access to business resources.
  const revalidateVisibleSession = () => {
    if (busy || phase !== 'authenticated' || document.visibilityState === 'hidden') return;
    state('locked');
    message('Conferindo sua sessão…');
    perform(async () => {
      try {
        const response = await call('session');
        if (response.ok && (await response.json()).authenticated === true) {
          state('authenticated');message('');return;
        }
        if (response.status === 401) {
          state('password');message('Sua sessão terminou. Entre novamente.',true);return;
        }
      } catch { /* Remain locked on network or parsing errors. */ }
      message('Não foi possível verificar a sessão. Atualize a página para tentar novamente.',true);
    });
  };
  document.addEventListener('visibilitychange', revalidateVisibleSession);
  // Restored back/forward-cache pages may not emit a visibilitychange event.
  window.addEventListener('pageshow', event => {
    if (event.persisted) revalidateVisibleSession();
  });
  document.querySelector('#back').addEventListener('click',leave);
  document.querySelector('#logout').addEventListener('click',leave);
  perform(async () => {
    const response=await call('session');
    if (response.ok && (await response.json()).authenticated === true) state('authenticated');
    else if (response.status !== 401) message('O acesso está temporariamente indisponível.',true);
  });
})();
