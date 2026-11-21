// Pwa.push: subscribing after consent, keeping the server in step, and never handing one user's
// subscription to the next person on the device.
import { afterEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createClient } from '../support/client-env.mjs';

const ALICE = 'aaaaaaaaaaaaaaaa';
const BOB = 'bbbbbbbbbbbbbbbb';
const KEY = 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM';
const OTHER_KEY = 'BOtherKeyOtherKeyOtherKeyOtherKeyOtherKeyOtherKeyOtherKeyOtherKeyOtherKeyOtherKeyOtherKe';
const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/device';
const PUSH = { push: { key: KEY, url: '/pwa/push/subscriptions' } };

const bytes = (base64url) => Uint8Array.from(atob(base64url.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(base64url.length / 4) * 4, '=')), (c) => c.charCodeAt(0));

let client;
afterEach(() => client?.close());

/** Notification and PushManager of a browser that supports push. */
const browser = ({ permission = 'default', answer = 'granted', subscription = null, ua } = {}) => (w) => {
  if (ua) Object.defineProperty(w.navigator, 'userAgent', { value: ua, configurable: true });
  w.Notification = {
    permission,
    requestPermission: async () => { w.Notification.permission = answer; return answer; },
  };
  w.PushManager = { supportedContentEncodings: ['aes128gcm', 'aesgcm'] };
  const manager = {
    current: null,
    subscribed: [],
    getSubscription: async () => manager.current,
    subscribe: async (options) => {
      manager.subscribed.push(options);
      manager.current = fakeSubscription(manager, `${ENDPOINT}-${manager.subscribed.length}`, options.applicationServerKey);
      return manager.current;
    },
  };
  if (subscription) manager.current = fakeSubscription(manager, subscription.endpoint ?? ENDPOINT, bytes(subscription.key ?? KEY));
  w.navigator.serviceWorker.registration.pushManager = manager;
};

const fakeSubscription = (manager, endpoint, key) => {
  const subscription = {
    endpoint,
    options: { applicationServerKey: key.buffer },
    unsubscribed: false,
    toJSON: () => ({ endpoint, expirationTime: null, keys: { p256dh: 'p256', auth: 'auth' } }),
    unsubscribe: async () => {
      subscription.unsubscribed = true;
      if (manager.current === subscription) manager.current = null;
      return true;
    },
  };
  return subscription;
};

const respond = (window, data, status = 200) =>
  new window.Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } });

/** Server: the session endpoint and the subscriptions route. */
const server = ({ user = ALICE, store = 201 } = {}) => (url, init) => {
  if (url.pathname === '/pwa/session') return respond(client.window, { csrf: 'fresh', authenticated: user !== 'guest', user, lifetime: 120, warm: [] });
  if (url.pathname === '/pwa/push/subscriptions') {
    if (init.method === 'DELETE') return new client.window.Response(null, { status: 204 });
    return respond(client.window, store < 300 ? { subscribed: true, user } : { message: 'invalid' }, store);
  }
  return respond(client.window, {});
};

const pushCalls = () => client.calls.filter((c) => c.url.pathname === '/pwa/push/subscriptions');
const manager = () => client.sw.registration.pushManager;
const saved = () => JSON.parse(client.window.localStorage.getItem('pwa:push'));
const remember = (value) => client.window.localStorage.setItem('pwa:push', JSON.stringify(value));
const pushEvents = () => client.events.filter((e) => e.name === 'pwa:push').map((e) => e.detail);

describe('Pwa.push support', () => {
  it('is unsupported without the push config or the browser APIs', async () => {
    client = await createClient({ before: browser() });
    assert.equal(client.Pwa.push.supported(), false);
    assert.equal(await client.Pwa.push.state(), 'unsupported');
    client.close();

    client = await createClient({ config: PUSH });
    assert.equal(client.Pwa.push.supported(), false);
    assert.equal(client.Pwa.push.permission(), 'unsupported');
  });

  it('asks iPhone users to install the app first', async () => {
    client = await createClient({ config: PUSH, before: (w) => Object.defineProperty(w.navigator, 'userAgent', { value: 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_0 like Mac OS X)', configurable: true }) });

    assert.equal(await client.Pwa.push.state(), 'install-required');
  });

  it('reports denied permission', async () => {
    client = await createClient({ config: PUSH, before: browser({ permission: 'denied' }) });

    assert.equal(await client.Pwa.push.state(), 'denied');
  });
});

describe('Pwa.push.subscribe', () => {
  it('asks for permission, subscribes with the VAPID key and stores the subscription', async () => {
    client = await createClient({ config: PUSH, fetch: server(), before: browser() });

    const subscription = await client.Pwa.push.subscribe();

    assert.equal(subscription.endpoint, `${ENDPOINT}-1`);
    assert.equal(manager().subscribed[0].userVisibleOnly, true);
    assert.deepEqual([...manager().subscribed[0].applicationServerKey], [...bytes(KEY)]);

    const [call] = pushCalls();
    assert.equal(call.init.method, 'POST');
    assert.equal(call.init.headers['X-CSRF-TOKEN'], 'old');
    assert.equal(call.init.credentials, 'same-origin');
    assert.deepEqual(JSON.parse(call.init.body), { endpoint: `${ENDPOINT}-1`, expirationTime: null, keys: { p256dh: 'p256', auth: 'auth' }, contentEncoding: 'aes128gcm' });

    assert.equal(saved().user, ALICE);
    assert.equal(saved().endpoint, `${ENDPOINT}-1`);
    assert.deepEqual(pushEvents(), [{ state: 'subscribed' }]);
    assert.equal(await client.Pwa.push.state(), 'subscribed');
  });

  it('does nothing when the user declines', async () => {
    client = await createClient({ config: PUSH, fetch: server(), before: browser({ answer: 'denied' }) });

    assert.equal(await client.Pwa.push.subscribe(), null);
    assert.equal(manager().subscribed.length, 0);
    assert.equal(pushCalls().length, 0);
    assert.deepEqual(pushEvents(), [{ state: 'denied' }]);
  });

  it('drops the browser subscription when the server refuses it', async () => {
    client = await createClient({ config: PUSH, fetch: server({ store: 422 }), before: browser() });

    assert.equal(await client.Pwa.push.subscribe(), null);
    assert.equal(manager().current, null);
    assert.equal(saved(), null);
  });

  it('replaces a subscription made with another key', async () => {
    client = await createClient({ config: PUSH, fetch: server(), before: browser({ permission: 'granted', subscription: { key: OTHER_KEY } }) });
    const old = manager().current;

    await client.Pwa.push.subscribe();

    assert.ok(old.unsubscribed);
    assert.equal(manager().subscribed.length, 1);
  });

  it('retries once with a fresh CSRF token after 419', async () => {
    let attempts = 0;
    client = await createClient({
      config: PUSH,
      before: browser(),
      fetch: (url, init) => {
        if (url.pathname === '/pwa/push/subscriptions' && attempts++ === 0) return respond(client.window, {}, 419);
        return server()(url, init);
      },
    });

    assert.ok(await client.Pwa.push.subscribe());
    assert.deepEqual(pushCalls().map((c) => c.init.headers['X-CSRF-TOKEN']), ['old', 'fresh']);
  });
});

describe('Pwa.push.unsubscribe', () => {
  it('removes the subscription on the server and in the browser', async () => {
    client = await createClient({ config: PUSH, fetch: server(), before: browser({ permission: 'granted', subscription: {} }) });
    remember({ endpoint: ENDPOINT, user: ALICE, at: Date.now() });
    const subscription = manager().current;

    assert.equal(await client.Pwa.push.unsubscribe(), true);

    assert.equal(pushCalls()[0].init.method, 'DELETE');
    assert.deepEqual(JSON.parse(pushCalls()[0].init.body), { endpoint: ENDPOINT });
    assert.ok(subscription.unsubscribed);
    assert.equal(saved(), null);
    assert.equal(await client.Pwa.push.state(), 'unsubscribed');
  });
});

describe('push on a shared device', () => {
  it('drops the subscription in the browser on logout', async () => {
    client = await createClient({ config: PUSH, fetch: server(), before: browser({ permission: 'granted', subscription: {} }) });
    remember({ endpoint: ENDPOINT, user: ALICE, at: Date.now() });
    const subscription = manager().current;

    client.sw.receive({ source: 'pwa', type: 'logout' });
    await client.flush();
    await client.flush();

    assert.ok(subscription.unsubscribed);
    assert.equal(saved(), null);
    assert.equal(pushCalls().length, 0, 'the server already removed it on logout');
  });

  it('drops it when another user signs in, keeps it for the same user', async () => {
    client = await createClient({ config: PUSH, fetch: server(), before: browser({ permission: 'granted', subscription: {} }) });
    remember({ endpoint: ENDPOINT, user: ALICE, at: Date.now() });
    const subscription = manager().current;

    client.sw.receive({ source: 'pwa', type: 'identity', user: ALICE });
    await client.flush();
    assert.equal(subscription.unsubscribed, false);

    client.sw.receive({ source: 'pwa', type: 'identity', user: BOB });
    await client.flush();
    await client.flush();
    assert.ok(subscription.unsubscribed);
  });

  it('never adopts a subscription for a user who did not subscribe on this device', async () => {
    client = await createClient({ config: PUSH, fetch: server({ user: BOB }), before: browser({ permission: 'granted', subscription: {} }) });
    remember({ endpoint: ENDPOINT, user: ALICE, at: 0 });

    await client.Pwa.keepAlive();
    await client.flush();
    await client.flush();

    assert.equal(pushCalls().length, 0);
    assert.equal(manager().current, null);
  });
});

describe('keep-alive sync', () => {
  it('confirms the subscription with the server once a day', async () => {
    client = await createClient({ config: PUSH, fetch: server(), before: browser({ permission: 'granted', subscription: {} }) });
    remember({ endpoint: ENDPOINT, user: ALICE, at: Date.now() });

    await client.Pwa.keepAlive();
    await client.flush();
    assert.equal(pushCalls().length, 0, 'confirmed recently');

    remember({ endpoint: ENDPOINT, user: ALICE, at: Date.now() - 25 * 3600_000 });
    await client.Pwa.keepAlive();
    await client.flush();
    await client.flush();
    assert.equal(pushCalls().length, 1);
    assert.ok(saved().at > Date.now() - 60_000);
  });

  it('resubscribes after a VAPID key change', async () => {
    client = await createClient({ config: PUSH, fetch: server(), before: browser({ permission: 'granted', subscription: { key: OTHER_KEY } }) });
    remember({ endpoint: ENDPOINT, user: ALICE, at: Date.now() });

    await client.Pwa.keepAlive();
    for (let i = 0; i < 4; i++) await client.flush();

    assert.equal(manager().subscribed.length, 1);
    assert.equal(JSON.parse(pushCalls()[0].init.body).endpoint, `${ENDPOINT}-1`);
  });

  it('notices when the browser dropped the subscription', async () => {
    client = await createClient({ config: PUSH, fetch: server(), before: browser({ permission: 'granted' }) });
    remember({ endpoint: ENDPOINT, user: ALICE, at: Date.now() });

    await client.Pwa.keepAlive();
    await client.flush();
    await client.flush();

    assert.equal(saved(), null);
    assert.deepEqual(pushEvents(), [{ state: 'unsubscribed', lost: true }]);
  });
});
