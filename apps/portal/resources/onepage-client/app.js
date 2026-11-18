'use strict';
(() => {
  const root = document.getElementById('onepage-client');
  if (!root) return;
  const el = (id) => document.getElementById(id);
  const base = '/_development/commerce/onepage/';
  const auth = '/_development/auth/';
  const storageKey = 'imagine.onepage.private-tab.v1';
  const validId = (s) => typeof s === 'string' && /^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/.test(s);
  const sections = ['loading','login-required','selection','draft','review','order'];
  let busy = false;
  let authenticated = false;
  let review = null;
  let currentQuote = null;
  let state = {quoteId:null,orderId:null,createKey:null,acceptKey:null,tier:null};

  // These opaque identifiers are tab-local convenience pointers, NOT authorization.
  // Each subsequent read is checked again against the native server session.
  try {
    const saved = JSON.parse(sessionStorage.getItem(storageKey) || '{}');
    if (saved && typeof saved === 'object' && !Array.isArray(saved)) {
      state.quoteId = validId(saved.quoteId) ? saved.quoteId : null;
      state.orderId = validId(saved.orderId) ? saved.orderId : null;
      for (const key of ['createKey','acceptKey']) {
        state[key] = typeof saved[key] === 'string' && /^[A-Za-z0-9_-]{1,128}$/.test(saved[key]) ? saved[key] : null;
      }
      state.tier = ['minimum','recommended'].includes(saved.tier) ? saved.tier : null;
    }
  } catch { /* Storage can be disabled; the server remains authoritative. */ }
  const save = () => {try {sessionStorage.setItem(storageKey, JSON.stringify(state));} catch {}};
  const clear = () => {
    state = {quoteId:null,orderId:null,createKey:null,acceptKey:null,tier:null};
    try {sessionStorage.removeItem(storageKey);} catch {}
  };
  const key = () => crypto.randomUUID().replaceAll('-', '');
  const show = (id) => {
    for (const section of sections) el(section).hidden = section !== id;
    if (id !== 'review') el('accept-check').checked = false;
    el('accept-button').disabled = true;
  };
  const notice = (message, error = false) => {
    el('notice').textContent = message;
    el('notice').dataset.error = String(error);
    el('notice').hidden = !message;
  };
  const money = (value) => Number.isSafeInteger(value) && value >= 0
    ? new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(value / 100) : '—';
  const readJson = async (response) => {
    const data = await response.json();
    if (!data || typeof data !== 'object' || Array.isArray(data)) throw new Error('invalid');
    return data;
  };
  const get = async (url) => fetch(url, {
    credentials:'same-origin',cache:'no-store',redirect:'error',
    headers:{Accept:'application/json'},signal:AbortSignal.timeout(12000),
  });
  const call = async (path, body) => {
    const options = {
      credentials:'same-origin',cache:'no-store',redirect:'error',
      headers:{Accept:'application/json'},signal:AbortSignal.timeout(12000),
    };
    if (body !== undefined) {
      const response = await get(auth + 'csrf');
      if (!response.ok) throw Object.assign(new Error('csrf'), {status:response.status});
      const csrf = (await readJson(response)).csrf_token;
      if (typeof csrf !== 'string' || csrf.length < 1) throw new Error('csrf');
      options.method = 'POST';
      options.headers = {'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf};
      options.body = JSON.stringify(body);
    }
    const result = await fetch(base + path, options);
    if (!result.ok) throw Object.assign(new Error('request'), {status:result.status});
    return readJson(result);
  };
  const failure = (error) => {
    if (error && error.status === 401) {
      authenticated = false; review = null; currentQuote = null;
      show('login-required'); notice('Sua sessão terminou. Entre novamente.', true);
    } else if (error && error.status === 409) {
      notice('Os dados desta tentativa não conferem com o pedido já registrado. Inicie outra cotação ou solicite suporte.',true);
    } else if (error && error.status === 404) {
      notice('A solicitação não está disponível para esta conta.',true);
    } else if (error && error.status === 422) {
      notice('Os dados da solicitação não foram aceitos. Confira a seleção ou reinicie a cotação.',true);
    } else if (error && error.status === 503) {
      notice('O pedido está temporariamente indisponível. Nenhum pagamento foi confirmado. Tente novamente.',true);
    } else {
      notice('Não foi possível conferir a solicitação. Verifique a conexão e tente novamente.',true);
    }
  };
  const perform = async (action) => {
    if (busy) return;
    busy = true;
    root.setAttribute('aria-busy','true');
    root.querySelectorAll('button').forEach((button) => {button.disabled = true;});
    try { await action(); }
    catch (error) { failure(error); }
    finally {
      busy = false; root.removeAttribute('aria-busy');
      root.querySelectorAll('button').forEach((button) => {button.disabled = false;});
      el('accept-button').disabled = !authenticated || el('review').hidden || !el('accept-check').checked;
    }
  };
  const quote = (value) => {
    if (!value || typeof value !== 'object' || Array.isArray(value)
        || value.site !== 'onepage' || value.currency !== 'BRL') throw new Error('invalid_quote');
    currentQuote = value;
    el('setup-total').textContent = money(value.site_setup_cents);
    el('monthly').textContent = money(value.site_monthly_cents);
    el('parts').textContent = Array.isArray(value.illustrative_three_parts_cents)
      && value.illustrative_three_parts_cents.length === 3
      ? value.illustrative_three_parts_cents.map(money).join(' · ') : '—';
    show('draft'); notice('');
  };
  const order = (payload) => {
    if (!payload || payload.status !== 'pending_payment' || !payload.order
      || !validId(payload.order.id)) throw new Error('invalid_order');
    state.orderId = payload.order.id; save();
    el('order-id').textContent = payload.order.id;
    const dt = new Date(payload.order.accepted_at);
    el('accepted-at').textContent = Number.isNaN(dt.getTime()) ? '—' : dt.toLocaleString('pt-BR');
    review = null; currentQuote = null;
    show('order'); notice('');
  };
  const resume = async () => {
    if (state.orderId) {
      try {
        order(await call('orders/' + encodeURIComponent(state.orderId)));
        return;
      } catch (error) {
        if (error.status === 404) {clear(); show('selection'); failure(error); return;}
        throw error;
      }
    }
    if (state.quoteId) {
      try {
        const value = await call('quotes/' + encodeURIComponent(state.quoteId));
        if (value.status !== 'draft' || value.id !== state.quoteId) throw new Error('invalid_draft');
        quote(value.quote);
        return;
      } catch (error) {
        if (error.status === 404) {clear(); show('selection'); failure(error); return;}
        throw error;
      }
    }
    show('selection'); notice('');
  };
  const refreshSession = async () => {
    // Always hide previously seen business data before revalidating a restored tab.
    authenticated = false; review = null; currentQuote = null;
    show('loading'); notice('');
    const response = await get(auth + 'session');
    if (response.status === 401) {show('login-required');return;}
    if (!response.ok) throw Object.assign(new Error('session'), {status:response.status});
    if ((await readJson(response)).authenticated !== true) throw new Error('session');
    authenticated = true;
    await resume();
  };
  el('start').addEventListener('click', () => perform(async () => {
    if (!authenticated) return;
    const tier = el('tier').value;
    if (!['minimum','recommended'].includes(tier)) return;
    if (!state.createKey || state.tier !== tier) {
      state = {quoteId:null,orderId:null,createKey:key(),acceptKey:null,tier};
      save();
    }
    const result = await call('quotes', {
      request_key:state.createKey, selection:{site:'onepage',channels:[],tier},
    });
    if (result.status !== 'draft' || !validId(result.id)) throw new Error('invalid_draft');
    state.quoteId = result.id; save();
    quote(result.quote);
  }));
  el('restart').addEventListener('click', () => {
    if (!authenticated || busy) return;
    clear(); review = null; currentQuote = null; show('selection'); notice('');
  });
  el('review-button').addEventListener('click', () => perform(async () => {
    if (!authenticated || !state.quoteId || !currentQuote) return;
    const value = await call('quotes/' + encodeURIComponent(state.quoteId) + '/review');
    if (value.status !== 'review' || !value.review || value.review.quote_id !== state.quoteId
      || typeof value.review.contract_text !== 'string' || !value.review.contract_text
      || !value.review.terms || typeof value.review.terms !== 'object') throw new Error('invalid_review');
    review = value.review;
    el('contract-text').textContent = review.contract_text;
    el('terms-text').textContent = JSON.stringify(review.terms,null,2);
    show('review'); notice('');
  }));
  el('accept-check').addEventListener('change', () => {
    el('accept-button').disabled = busy || !authenticated || !el('accept-check').checked;
  });
  el('back').addEventListener('click', () => {
    if (busy || !authenticated || !currentQuote) return;
    review = null; show('draft'); notice('');
  });
  el('accept-button').addEventListener('click', () => perform(async () => {
    if (!authenticated || !review || !el('accept-check').checked || !state.quoteId) return;
    const fields = ['quote_id','offer_digest','terms_digest','contract_ref','contract_revision','contract_digest'];
    const acceptance = {accepted:true};
    for (const field of fields) {
      if (!Object.prototype.hasOwnProperty.call(review,field)) throw new Error('invalid_review');
      acceptance[field] = review[field];
    }
    if (!state.acceptKey) {state.acceptKey = key();save();}
    const result = await call('quotes/' + encodeURIComponent(state.quoteId) + '/accept',
      {request_key:state.acceptKey,acceptance});
    if (result.status !== 'pending_payment' || !validId(result.id)) throw new Error('invalid_order');
    state.orderId = result.id;save();
    order(await call('orders/' + encodeURIComponent(result.id)));
  }));
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && !busy) perform(refreshSession);
  });
  window.addEventListener('pageshow', (event) => {
    if (event.persisted && !busy) perform(refreshSession);
  });
  perform(refreshSession);
})();
