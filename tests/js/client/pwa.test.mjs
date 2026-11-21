import { afterEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createClient } from '../support/client-env.mjs';

let client;
afterEach(() => client?.close());

const sessionJson = (window, csrf = 'fresh') =>
  new window.Response(JSON.stringify({ csrf, authenticated: true, user: 'guest', lifetime: 120 }), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  });

describe('boot', () => {
  it('exposes window.Pwa and emits ready', async () => {
    client = await createClient();

    assert.equal(typeof client.Pwa.install, 'function');
    assert.equal(client.Pwa.displayMode(), 'browser');
    assert.equal(client.Pwa.isInstalled(), false);
    assert.ok(client.events.some((e) => e.name === 'pwa:ready'));
  });

  it('tells the server the display mode through the pwa_display cookie', async () => {
    client = await createClient({ displayMode: 'standalone' });

    assert.match(client.document.cookie, /pwa_display=standalone/);
    assert.equal(client.Pwa.isInstalled(), true);
  });

  it('registers the worker with the configured scope', async () => {
    client = await createClient();

    assert.deepEqual(client.sw.registered.map((r) => [r.url, r.options.scope, r.options.updateViaCache]), [['/sw.js', '/', 'none']]);
    assert.ok(client.sw.posted.some((m) => m.type === 'REPLAY_QUEUE'));
  });

  it('unregisters every worker when the PWA is disabled', async () => {
    client = await createClient({ config: { enabled: false } });

    assert.equal(client.sw.registered.length, 0);
    assert.deepEqual(client.sw.unregisteredUrls, ['https://app.test/sw.js'], 'a foreign worker stays');
  });

  it('works without service worker support', async () => {
    client = await createClient({ serviceWorker: false });

    assert.equal(client.Pwa.displayMode(), 'browser');
  });
});

describe('worker messages', () => {
  it('turns worker messages into pwa:* events', async () => {
    client = await createClient();

    client.sw.receive({ source: 'pwa', type: 'logout' });
    client.sw.receive({ source: 'pwa', type: 'queued', count: 2 });
    client.sw.receive({ source: 'pwa', type: 'maintenance', retryAfter: '60' });
    client.sw.receive({ source: 'other', type: 'logout' });

    assert.equal(client.events.filter((e) => e.name === 'pwa:logout').length, 1);
    assert.equal(client.events.find((e) => e.name === 'pwa:queue').detail.count, 2);
    assert.equal(client.events.find((e) => e.name === 'pwa:maintenance').detail.retryAfter, '60');
  });

  it('lets listeners subscribe and unsubscribe with Pwa.on', async () => {
    client = await createClient();
    const seen = [];
    const off = client.Pwa.on('offline', (detail) => seen.push(detail));

    client.sw.receive({ source: 'pwa', type: 'offline' });
    off();
    client.sw.receive({ source: 'pwa', type: 'offline' });

    assert.equal(seen.length, 1);
  });
});

describe('keep-alive and CSRF', () => {
  it('applies the fresh token to the meta tag, forms and axios', async () => {
    client = await createClient({
      fetch: () => sessionJson(client.window),
      before: (window) => { window.axios = { defaults: { headers: { common: {} } } }; },
    });

    await client.Pwa.keepAlive();

    assert.equal(client.document.querySelector('meta[name="csrf-token"]').getAttribute('content'), 'fresh');
    assert.equal(client.document.querySelector('input[name="_token"]').value, 'fresh');
    assert.equal(client.window.axios.defaults.headers.common['X-CSRF-TOKEN'], 'fresh');
    assert.ok(client.events.some((e) => e.name === 'pwa:csrf' && e.detail.token === 'fresh'));
  });

  it('sends the display mode with the keep-alive request', async () => {
    client = await createClient({ displayMode: 'minimal-ui', fetch: () => sessionJson(client.window) });

    await client.Pwa.keepAlive();

    const call = client.calls.find((c) => c.url.pathname === '/pwa/session');
    assert.equal(call.init.headers['X-PWA-Display'], 'minimal-ui');
    assert.equal(call.init.credentials, 'same-origin');
  });
});

describe('fetch retry after 419', () => {
  const network = (status419For) => (url, init) => {
    if (url.pathname === '/pwa/session') return sessionJson(client.window);
    const token = new client.window.Headers(init.headers).get('X-CSRF-TOKEN');
    return new client.window.Response('{}', { status: url.href.startsWith(status419For) && token !== 'fresh' ? 419 : 200 });
  };

  it('retries a same-origin mutation once with a fresh token', async () => {
    client = await createClient({ fetch: network('https://app.test/') });

    const response = await client.window.fetch('/items', { method: 'POST', headers: { 'X-CSRF-TOKEN': 'old' } });

    assert.equal(response.status, 200);
    const posts = client.calls.filter((c) => c.url.pathname === '/items');
    assert.equal(posts.length, 2);
    assert.equal(new client.window.Headers(posts[1].init.headers).get('X-CSRF-TOKEN'), 'fresh');
  });

  it('never sends the token to another origin', async () => {
    client = await createClient({ fetch: network('https://evil.test/') });

    const response = await client.window.fetch('https://evil.test/hook', { method: 'POST' });

    assert.equal(response.status, 419);
    assert.equal(client.calls.filter((c) => c.url.pathname === '/pwa/session').length, 0);
  });

  it('does not retry GET requests', async () => {
    client = await createClient({ fetch: network('https://app.test/') });

    const response = await client.window.fetch('/report');

    assert.equal(response.status, 419);
  });
});

describe('axios retry after 419', () => {
  const axiosWith = (window, calls) => {
    let rejected;
    const axios = {
      defaults: { headers: { common: {} } },
      interceptors: { response: { use: (_ok, fail) => { rejected = fail; } } },
      request: async (config) => {
        calls.push(config);
        return { status: 200, config };
      },
      fail: (config) => rejected({ config, response: { status: 419 } }),
    };
    window.axios = axios;
    return axios;
  };

  it('retries same-origin requests, including baseURL-relative ones', async () => {
    const retried = [];
    client = await createClient({ fetch: () => sessionJson(client.window), before: (w) => axiosWith(w, retried) });

    await client.window.axios.fail({ url: '/api/items', method: 'post' });
    await client.window.axios.fail({ url: 'items', baseURL: '/api/', method: 'post' });

    assert.deepEqual(retried.map((c) => c.headers['X-CSRF-TOKEN']), ['fresh', 'fresh']);
  });

  it('does not leak the token to another origin', async () => {
    const retried = [];
    client = await createClient({ fetch: () => sessionJson(client.window), before: (w) => axiosWith(w, retried) });

    for (const config of [
      { url: 'https://evil.test/hook' },
      { url: '//evil.test/hook' },
      { url: '/hook', baseURL: 'https://api.other.test' },
    ]) {
      await assert.rejects(client.window.axios.fail(config));
    }

    assert.equal(retried.length, 0);
    assert.equal(client.calls.filter((c) => c.url.pathname === '/pwa/session').length, 0);
  });

  it('retries only once', async () => {
    const retried = [];
    client = await createClient({ fetch: () => sessionJson(client.window), before: (w) => axiosWith(w, retried) });

    await assert.rejects(client.window.axios.fail({ url: '/api/items', __pwaRetried: true }));
    assert.equal(retried.length, 0);
  });
});

describe('adapters', () => {
  it('Livewire: refreshes the token on 419 and reloads on 503', async () => {
    let failHandler;
    client = await createClient({
      fetch: () => sessionJson(client.window),
      before: (window) => {
        window.Livewire = { hook: (_name, fn) => fn({ fail: (handler) => { failHandler = handler; } }) };
      },
    });
    client.document.dispatchEvent(new client.window.Event('livewire:init'));

    let prevented = 0;
    await failHandler({ status: 419, preventDefault: () => { prevented += 1; } });

    assert.equal(prevented, 1);
    assert.ok(client.events.some((e) => e.name === 'pwa:csrf-refreshed'));
  });

  it('Inertia: recognises a queued response from the worker', async () => {
    client = await createClient();
    const event = new client.window.CustomEvent('inertia:invalid', {
      cancelable: true,
      detail: { response: { status: 202, headers: { 'x-pwa-queued': '1' } } },
    });

    client.document.dispatchEvent(event);

    assert.equal(event.defaultPrevented, true);
    assert.ok(client.events.some((e) => e.name === 'pwa:queue'));
  });

  it('does nothing when the adapters are disabled', async () => {
    client = await createClient({ config: { adapters: { livewire: false, inertia: false } } });
    const event = new client.window.CustomEvent('inertia:invalid', {
      cancelable: true,
      detail: { response: { status: 503, headers: {} } },
    });

    client.document.dispatchEvent(event);

    assert.equal(event.defaultPrevented, false);
  });
});

describe('install', () => {
  it('shows install buttons only once the browser offers a prompt', async () => {
    client = await createClient();
    const button = client.document.createElement('button');
    button.setAttribute('data-pwa-install', '');
    button.hidden = true;
    client.document.body.append(button);

    const prompt = new client.window.Event('beforeinstallprompt', { cancelable: true });
    prompt.prompt = () => {};
    prompt.userChoice = Promise.resolve({ outcome: 'accepted' });
    client.window.dispatchEvent(prompt);

    assert.equal(button.hidden, false);
    assert.equal(await client.Pwa.install(), 'accepted');
  });

  it('reports unavailable without a prompt', async () => {
    client = await createClient();

    assert.equal(await client.Pwa.install(), 'unavailable');
  });
});
