// Web push in the worker (pwa.push with handle_in_worker): every push shows a notification, a
// device where someone else is signed in never shows the content, clicks stay on this origin.
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createWorker, json, ORIGIN } from '../support/sw-env.mjs';

const ALICE = 'aaaaaaaaaaaaaaaa';
const BOB = 'bbbbbbbbbbbbbbbb';
const KEY = 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM';

const push = (overrides = {}) => ({
  key: KEY,
  url: '/pwa/push/subscriptions',
  name: 'Shop',
  icon: '/pwa/icons/icon-192.png',
  badge: null,
  fallback: 'You have a new notification. Open the app to see it.',
  ...overrides,
});

const signedIn = async (worker, user = ALICE) => worker.message({ type: 'IDENTITY', user });

const message = (overrides = {}) => ({
  pwa: 1,
  title: 'Order shipped',
  user: ALICE,
  options: { body: 'Order 1001 is on its way.', tag: 'order-1001', actions: [], data: { url: '/orders/1001', actions: {} } },
  ...overrides,
});

describe('push', () => {
  it('registers no listeners without the push config', async () => {
    const worker = createWorker();

    await worker.push(message());

    assert.equal(worker.notifications.length, 0);
  });

  it('shows the notification with the default icon and tells the page', async () => {
    const worker = createWorker({ config: { push: push() } });
    await signedIn(worker);

    await worker.push(message());

    assert.equal(worker.notifications.length, 1);
    const [{ title, options }] = worker.notifications;
    assert.equal(title, 'Order shipped');
    assert.equal(options.body, 'Order 1001 is on its way.');
    assert.equal(options.icon, '/pwa/icons/icon-192.png');
    assert.equal(options.badge, undefined);
    assert.equal(options.data.url, '/orders/1001');
    assert.deepEqual({ ...worker.messages.find((m) => m.type === 'push-received') }, { source: 'pwa', type: 'push-received', tag: 'order-1001' });
  });

  it('always shows something, even for an empty or plain text push', async () => {
    const worker = createWorker({ config: { push: push() } });

    await worker.push();
    await worker.push('not json');

    assert.deepEqual(worker.notifications.map((n) => n.title), ['Shop', 'not json']);
  });

  it('hides the content on a device where another user is signed in', async (t) => {
    for (const [device, label] of [[BOB, 'another user'], ['guest', 'signed out']]) {
      await t.test(label, async () => {
        const worker = createWorker({ config: { push: push() } });
        await signedIn(worker, device);

        await worker.push(message());

        const [{ title, options }] = worker.notifications;
        assert.equal(title, 'Shop');
        assert.equal(options.body, 'You have a new notification. Open the app to see it.');
        assert.equal(options.tag, 'pwa-private');
        assert.equal(options.data.url, `${ORIGIN}/`);
        assert.ok(!JSON.stringify(worker.notifications).includes('1001'));
      });
    }
  });

  it('shows public messages (no user) to anyone', async () => {
    const worker = createWorker({ config: { push: push() } });
    await signedIn(worker, 'guest');

    await worker.push(message({ user: undefined, title: 'Maintenance tonight' }));

    assert.equal(worker.notifications[0].title, 'Maintenance tonight');
  });

  it('reads Declarative Web Push payloads', async () => {
    const worker = createWorker({ config: { push: push() } });

    await worker.push({
      web_push: 8030,
      notification: { title: 'Shipped', body: 'On its way.', navigate: `${ORIGIN}/orders/7`, actions: [{ action: 'track', title: 'Track', navigate: `${ORIGIN}/orders/7/track` }] },
      app_badge: '2',
      mutable: true,
    });

    const [{ title, options }] = worker.notifications;
    assert.equal(title, 'Shipped');
    assert.equal(options.data.url, `${ORIGIN}/orders/7`);
    assert.deepEqual([...options.actions].map((a) => ({ ...a })), [{ action: 'track', title: 'Track' }]);
    assert.equal(options.data.actions.track, `${ORIGIN}/orders/7/track`);
    assert.deepEqual(worker.badges, [2]);
  });

  it('sets and clears the app badge', async () => {
    const worker = createWorker({ config: { push: push() } });
    await signedIn(worker);

    await worker.push(message({ appBadge: 5 }));
    await worker.push(message({ appBadge: 0 }));

    assert.deepEqual(worker.badges, [5, 0]);
  });
});

describe('notification clicks', () => {
  const shown = async (worker, data) => {
    await worker.push(message({ options: { data } }));
    return worker.notifications.at(-1);
  };

  it('opens the message URL and closes the notification', async () => {
    const worker = createWorker({ config: { push: push() }, windows: [] });
    await signedIn(worker);

    const { closed } = await worker.click(await shown(worker, { url: '/orders/1001', actions: {} }));

    assert.ok(closed);
    assert.deepEqual(worker.opened, [`${ORIGIN}/orders/1001`]);
  });

  it('opens the button URL for an action', async () => {
    const worker = createWorker({ config: { push: push() }, windows: [] });
    await signedIn(worker);

    await worker.click(await shown(worker, { url: '/orders/1001', actions: { track: '/orders/1001/track' } }), 'track');

    assert.deepEqual(worker.opened, [`${ORIGIN}/orders/1001/track`]);
  });

  it('never leaves the origin', async () => {
    const worker = createWorker({ config: { push: push() }, windows: [] });
    await signedIn(worker);

    await worker.click(await shown(worker, { url: 'https://evil.test/login', actions: {} }));
    await worker.click(await shown(worker, { url: 'javascript:alert(1)', actions: {} }));

    assert.deepEqual(worker.opened, [`${ORIGIN}/`, `${ORIGIN}/`]);
  });

  it('focuses a window that already shows the page, or navigates one of the app', async () => {
    const calls = [];
    const exact = { url: `${ORIGIN}/orders/1001`, focus: async () => calls.push('focus exact'), postMessage: () => {} };
    const other = {
      url: `${ORIGIN}/dashboard`,
      postMessage: () => {},
      navigate: async (url) => { calls.push(`navigate ${url}`); return { focus: async () => calls.push('focus navigated') }; },
    };
    const worker = createWorker({ config: { push: push() }, windows: [other, exact] });
    await signedIn(worker);

    await worker.click(await shown(worker, { url: '/orders/1001', actions: {} }));
    await worker.click(await shown(worker, { url: '/orders/2002', actions: {} }));

    assert.deepEqual(calls, ['focus exact', `navigate ${ORIGIN}/orders/2002`, 'focus navigated']);
    assert.deepEqual(worker.opened, []);
  });
});

describe('subscription renewal', () => {
  const renewed = { endpoint: 'https://fcm.googleapis.com/fcm/send/new', toJSON: () => ({ endpoint: 'https://fcm.googleapis.com/fcm/send/new', keys: { p256dh: 'p', auth: 'a' } }) };

  it('stores the new subscription in place of the old one, with a fresh CSRF token', async () => {
    const worker = createWorker({ config: { push: push() } });
    worker.network((request) => (new URL(request.url).pathname === '/pwa/session' ? json({ csrf: 'fresh' }) : json({ subscribed: true }, {}, 201)));

    await worker.dispatch('pushsubscriptionchange', { oldSubscription: { endpoint: 'https://fcm.googleapis.com/fcm/send/old' }, newSubscription: renewed });

    const post = worker.requests.find((r) => r.method === 'POST');
    assert.equal(new URL(post.url).pathname, '/pwa/push/subscriptions');
    assert.equal(post.headers.get('X-CSRF-TOKEN'), 'fresh');
    const body = await post.clone().json();
    assert.equal(body.endpoint, 'https://fcm.googleapis.com/fcm/send/new');
    assert.equal(body.replaces, 'https://fcm.googleapis.com/fcm/send/old');
    assert.ok(worker.messages.some((m) => m.type === 'push-renewed'));
  });

  it('subscribes again with the configured key when the browser gives no new subscription', async () => {
    let options = null;
    const worker = createWorker({
      config: { push: push() },
      registration: { pushManager: { subscribe: async (o) => { options = o; return renewed; } } },
    });
    worker.network(() => json({ csrf: 'fresh' }));

    await worker.dispatch('pushsubscriptionchange', { oldSubscription: { endpoint: 'https://fcm.googleapis.com/fcm/send/old' } });

    assert.equal(options.userVisibleOnly, true);
    assert.equal(options.applicationServerKey.length, 65);
    assert.equal(options.applicationServerKey[0], 4);
  });

  it('does nothing without the old endpoint (the server could not tell whose it is)', async () => {
    const worker = createWorker({ config: { push: push() } });
    worker.network(() => json({ csrf: 'fresh' }));

    await worker.dispatch('pushsubscriptionchange', { newSubscription: renewed });

    assert.ok(!worker.requests.some((r) => r.method === 'POST'));
  });
});

describe('push analytics (pwa.push.analytics)', () => {
  const events = (worker) => worker.requests
    .filter((request) => new URL(request.url).pathname === '/pwa/push/events')
    .map((request) => request);

  it('reports shown, click and close without cookies', async () => {
    const worker = createWorker({ config: { push: push({ events: '/pwa/push/events' }) }, fetch: () => new Response(null, { status: 202 }) });
    await signedIn(worker);

    await worker.push(message({ options: { ...message().options, data: { url: '/orders/1001', actions: {}, id: 'campaign-7' } } }));
    const [shown] = worker.notifications;
    await worker.click(shown, 'track');
    await worker.close(shown);

    const sent = events(worker);
    assert.equal(sent.length, 3);
    assert.ok(sent.every((request) => request.credentials === 'omit'));
    const bodies = await Promise.all(sent.map((request) => request.clone().json()));
    assert.deepEqual(bodies.map((body) => body.events[0]), [
      { type: 'shown', id: 'campaign-7', tag: 'order-1001', action: null },
      { type: 'click', id: 'campaign-7', tag: 'order-1001', action: 'track' },
      { type: 'close', id: 'campaign-7', tag: 'order-1001', action: null },
    ]);
  });

  it('sends nothing when analytics is off', async () => {
    const worker = createWorker({ config: { push: push() }, fetch: () => new Response(null, { status: 202 }) });
    await signedIn(worker);

    await worker.push(message());
    await worker.click(worker.notifications[0]);

    assert.equal(events(worker).length, 0);
  });
});
