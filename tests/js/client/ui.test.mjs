import { afterEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createClient } from '../support/client-env.mjs';

let client;
afterEach(() => client?.close());

const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

const TOAST = `
  <div data-pwa-update hidden>
    <button data-pwa-update-dismiss>Later</button>
    <button data-pwa-update-apply>Update</button>
  </div>`;

const TOGGLE = `
  <div data-pwa-push hidden>
    <button data-pwa-push-toggle aria-pressed="false">
      <span data-pwa-push-label="unsubscribed">On</span>
      <span data-pwa-push-label="subscribed" hidden>Off</span>
      <span data-pwa-push-label="denied" hidden>On</span>
      <span data-pwa-push-label="install-required" hidden>On</span>
    </button>
    <small data-pwa-push-label="denied" hidden>Blocked</small>
    <small data-pwa-push-label="install-required" hidden>Add to Home Screen</small>
  </div>`;

const click = (el) => el.dispatchEvent(new client.window.MouseEvent('click', { bubbles: true }));
const $ = (selector) => client.document.querySelector(selector);

describe('updates', () => {
  it('shows the built-in bar and reloads only after the user accepts', async () => {
    client = await createClient();
    const worker = client.sw.installUpdate();

    assert.equal(client.Pwa.updateAvailable(), true);
    assert.ok(client.events.some((e) => e.name === 'pwa:update-available'));
    const bar = $('.pwa-bar');
    assert.ok(bar && !bar.hidden);

    client.sw.takeControl(); // another tab updated: no reload here
    assert.equal(client.reloads.count, 0);

    click(bar.querySelector('button'));
    assert.deepEqual(worker.posted, [{ type: 'SKIP_WAITING' }]);
    client.sw.takeControl();
    assert.equal(client.reloads.count, 1);
  });

  it('Pwa.applyUpdate() activates the waiting worker and reloads', async () => {
    client = await createClient();
    assert.equal(client.Pwa.applyUpdate(), false);

    const worker = client.sw.installUpdate();
    assert.equal(client.Pwa.applyUpdate(), true);
    client.sw.takeControl();

    assert.deepEqual(worker.posted, [{ type: 'SKIP_WAITING' }]);
    assert.equal(client.reloads.count, 1);
  });

  it('uses the update toast instead of the bar', async () => {
    client = await createClient({ body: TOAST });
    const toast = $('[data-pwa-update]');
    assert.equal(toast.hidden, true);

    const worker = client.sw.installUpdate();

    assert.equal(toast.hidden, false);
    assert.equal($('.pwa-bar'), null);
    click($('[data-pwa-update-apply]'));
    assert.deepEqual(worker.posted, [{ type: 'SKIP_WAITING' }]);
  });

  it('hides the toast on Later', async () => {
    client = await createClient({ body: TOAST });
    client.sw.installUpdate();

    click($('[data-pwa-update-dismiss]'));

    assert.equal($('[data-pwa-update]').hidden, true);
    assert.equal(client.Pwa.updateAvailable(), true);
  });

  it('skips the bar with updates.bar = false', async () => {
    client = await createClient({ config: { updates: { bar: false } } });
    client.sw.installUpdate();

    assert.equal($('.pwa-bar'), null);
    assert.ok(client.events.some((e) => e.name === 'pwa:update-available'));
  });

  it('offers a worker that was already waiting at load', async () => {
    client = await createClient({
      body: TOAST,
      before: (window) => {
        window.navigator.serviceWorker.registration.waiting = window.navigator.serviceWorker.fakeWorker('installed');
      },
    });

    assert.equal($('[data-pwa-update]').hidden, false);
  });
});

describe('install UI', () => {
  it('reports canInstall() and emits installable once the prompt arrives', async () => {
    client = await createClient();
    assert.equal(client.Pwa.canInstall(), false);

    const prompt = new client.window.Event('beforeinstallprompt', { cancelable: true });
    prompt.prompt = () => {};
    prompt.userChoice = Promise.resolve({ outcome: 'accepted' });
    client.window.dispatchEvent(prompt);

    assert.equal(client.Pwa.canInstall(), true);
    const installable = client.events.filter((e) => e.name === 'pwa:installable');
    assert.deepEqual(installable.map((e) => e.detail), [{ prompt: true, ios: false }]);
  });

  it('shows the iOS instructions in Safari on iPhone', async () => {
    client = await createClient({ userAgent: IPHONE, body: '<div data-pwa-ios-hint hidden></div>' });

    assert.equal($('[data-pwa-ios-hint]').hidden, false);
    assert.equal(client.Pwa.canInstall(), true);
    assert.ok(client.events.some((e) => e.name === 'pwa:installable' && e.detail.ios === true));
  });

  it('never offers installation inside the installed app', async () => {
    client = await createClient({ userAgent: IPHONE, displayMode: 'standalone', body: '<div data-pwa-ios-hint hidden></div>' });

    assert.equal($('[data-pwa-ios-hint]').hidden, true);
    assert.equal(client.Pwa.canInstall(), false);
  });

  it('picks up elements added after boot on Livewire navigation and refreshUi()', async () => {
    client = await createClient({ userAgent: IPHONE });
    const hint = client.document.createElement('div');
    hint.setAttribute('data-pwa-ios-hint', '');
    hint.hidden = true;
    client.document.body.append(hint);

    client.document.dispatchEvent(new client.window.Event('livewire:navigated'));
    assert.equal(hint.hidden, false);

    hint.hidden = true;
    client.Pwa.refreshUi();
    assert.equal(hint.hidden, false);
  });
});

describe('push toggle', () => {
  const pushConfig = { push: { key: 'BAAA', url: '/pwa/push/subscriptions' } };

  it('stays hidden when push is off on the server', async () => {
    client = await createClient({ body: TOGGLE });
    await client.flush();

    assert.equal($('[data-pwa-push]').hidden, true);
    assert.equal($('[data-pwa-push]').getAttribute('data-pwa-push-state'), 'unsupported');
  });

  it('asks iPhone Safari users to add the app to the Home Screen', async () => {
    client = await createClient({ userAgent: IPHONE, config: pushConfig, body: TOGGLE });
    await client.flush();

    const root = $('[data-pwa-push]');
    assert.equal(root.hidden, false);
    assert.equal(root.getAttribute('data-pwa-push-state'), 'install-required');
    assert.equal($('[data-pwa-push-toggle]').disabled, true);
    const visible = [...root.querySelectorAll('[data-pwa-push-label]')].filter((el) => !el.hidden).map((el) => el.textContent);
    assert.deepEqual(visible, ['On', 'Add to Home Screen']);
  });

  it('subscribes on click and switches the label', async () => {
    const subscription = {
      endpoint: 'https://fcm.googleapis.com/fcm/send/abc',
      options: { applicationServerKey: null },
      toJSON: () => ({ endpoint: 'https://fcm.googleapis.com/fcm/send/abc', keys: { p256dh: 'p', auth: 'a' } }),
      unsubscribe: async () => true,
    };
    let current = null;
    client = await createClient({
      config: pushConfig,
      body: TOGGLE,
      fetch: () => new client.window.Response(JSON.stringify({ subscribed: true, user: 'u1' }), { status: 201 }),
      before: (window) => {
        window.PushManager = function PushManager() {};
        window.Notification = { permission: 'default', requestPermission: async () => { window.Notification.permission = 'granted'; return 'granted'; } };
        window.navigator.serviceWorker.registration.pushManager = {
          getSubscription: async () => current,
          subscribe: async () => { current = subscription; return subscription; },
        };
      },
    });
    await client.flush();
    const root = $('[data-pwa-push]');
    assert.equal(root.getAttribute('data-pwa-push-state'), 'unsubscribed');

    click($('[data-pwa-push-toggle]'));
    for (let i = 0; i < 10; i += 1) await client.flush();

    assert.equal(root.getAttribute('data-pwa-push-state'), 'subscribed');
    assert.equal($('[data-pwa-push-toggle]').getAttribute('aria-pressed'), 'true');
    assert.equal($('[data-pwa-push-label="subscribed"]').hidden, false);
    assert.equal($('[data-pwa-push-label="unsubscribed"]').hidden, true);
  });
});
