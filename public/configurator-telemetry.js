(() => {
  'use strict';

  const endpoint = '/api/public/configurator/events';
  const originalFetch = window.fetch.bind(window);
  const state = {
    plan: null,
    vertical: null,
    cycle: 'monthly',
    addons: new Set(),
    step: 'plan',
    lastOutcome: null,
    abandonmentSent: false,
  };

  function context(event, extra = {}) {
    const payload = { event };
    for (const key of ['plan', 'vertical', 'cycle', 'addon', 'step']) {
      const value = extra[key];
      if (typeof value === 'string' && value !== '') {
        payload[key] = value;
      }
    }
    return payload;
  }

  function emit(event, extra = {}) {
    try {
      const body = JSON.stringify(context(event, extra));
      void originalFetch(endpoint, {
        method: 'POST',
        credentials: 'omit',
        keepalive: true,
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
        body,
      }).catch(() => {});
    } catch {
      // Best-effort: telemetry never changes the configurator flow.
    }
  }

  function requestMeta(input, init) {
    try {
      let rawUrl;
      if (typeof input === 'string') {
        rawUrl = input;
      } else if (input instanceof Request) {
        rawUrl = input.url;
      } else {
        rawUrl = String(input);
      }
      const url = new URL(rawUrl, window.location.origin);
      const method = String(
        init?.method ?? (input instanceof Request ? input.method : 'GET'),
      ).toUpperCase();
      return { url, method };
    } catch {
      return null;
    }
  }

  async function requestPayload(input, init) {
    try {
      if (typeof init?.body === 'string') {
        const parsed = JSON.parse(init.body);
        return parsed && typeof parsed === 'object' ? parsed : null;
      }
      if (input instanceof Request) {
        const raw = await input.clone().text();
        if (raw === '') {
          return null;
        }
        const parsed = JSON.parse(raw);
        return parsed && typeof parsed === 'object' ? parsed : null;
      }
    } catch {
      return null;
    }
    return null;
  }

  function updateSelection(plan, vertical) {
    if (typeof plan === 'string' && plan !== '') {
      let event = null;
      if (state.plan === null) {
        event = 'plan_selected';
      } else if (state.plan !== plan) {
        event = 'plan_changed';
      }
      if (event !== null) {
        emit(event, {
          plan,
          vertical: typeof vertical === 'string' ? vertical : undefined,
          step: 'plan',
        });
      }
      state.plan = plan;
    }

    if (
      typeof vertical === 'string'
      && vertical !== ''
      && !Object.is(state.vertical, vertical)
    ) {
      state.vertical = vertical;
      emit('vertical', {
        plan: state.plan ?? undefined,
        vertical,
        step: 'vertical',
      });
    }
  }

  function observeAddons(nextAddons) {
    if (!Array.isArray(nextAddons)) {
      return;
    }

    const current = new Set(
      nextAddons.filter(value => typeof value === 'string' && value !== ''),
    );
    for (const addon of current) {
      if (!state.addons.has(addon)) {
        emit('addon', {
          plan: state.plan ?? undefined,
          vertical: state.vertical ?? undefined,
          cycle: state.cycle,
          addon,
          step: 'addons',
        });
      }
    }
    state.addons = current;
  }

  async function observe(meta, response, payloadPromise) {
    if (meta === null || !response.ok) {
      return;
    }

    try {
      if (
        meta.method === 'GET'
        && meta.url.pathname === '/api/public/configurator/options'
      ) {
        const plan = meta.url.searchParams.get('plan');
        const vertical = meta.url.searchParams.get('vertical');
        updateSelection(plan, vertical);
        state.step = 'scale';
        return;
      }

      if (
        meta.method !== 'POST'
        || meta.url.pathname !== '/api/public/configurator/quote'
      ) {
        return;
      }

      const payload = await payloadPromise;
      if (payload === null) {
        return;
      }

      updateSelection(payload.plan, payload.vertical);
      if (
        typeof payload.cycle === 'string'
        && (payload.cycle === 'monthly' || payload.cycle === 'annual')
      ) {
        state.cycle = payload.cycle;
      }
      observeAddons(payload.addons);

      const responsePayload = await response.json();
      const proposal = responsePayload?.quote?.proposal_required === true;
      const event = proposal ? 'proposal' : 'completion';
      const signature = [
        event,
        state.plan ?? '',
        state.vertical ?? '',
        state.cycle,
        [...state.addons].sort((left, right) => left.localeCompare(right)).join(','),
      ].join('|');

      if (!Object.is(state.lastOutcome, signature)) {
        state.lastOutcome = signature;
        state.step = 'summary';
        emit(event, {
          plan: state.plan ?? undefined,
          vertical: state.vertical ?? undefined,
          cycle: state.cycle,
          step: 'summary',
        });
      }
    } catch {
      // Observing a response is never allowed to affect the original fetch.
    }
  }

  window.fetch = function instrumentedFetch(input, init) {
    const meta = requestMeta(input, init);
    const payloadPromise = requestPayload(input, init);
    const responsePromise = originalFetch(input, init);

    void responsePromise.then(
      response => observe(meta, response.clone(), payloadPromise),
      () => undefined,
    ).catch(() => {});

    return responsePromise;
  };

  function abandon() {
    if (state.abandonmentSent || state.lastOutcome !== null) {
      return;
    }
    state.abandonmentSent = true;

    try {
      const body = JSON.stringify(context('abandonment', {
        plan: state.plan ?? undefined,
        vertical: state.vertical ?? undefined,
        cycle: state.cycle,
        step: state.step,
      }));
      navigator.sendBeacon(
        endpoint,
        new Blob([body], { type: 'application/json' }),
      );
    } catch {
      // Navigation must never be delayed or cancelled by telemetry.
    }
  }

  window.addEventListener('pagehide', abandon, { once: true });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
      abandon();
    }
  });

  emit('start', { step: 'plan' });
})();
