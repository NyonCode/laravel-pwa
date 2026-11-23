// The outbox in the client: Pwa.outbox(), the <x-pwa::outbox> markup filled from the worker's
// refused entries, Try again / Discard, and the bar when a change could not be saved.
import { afterEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createClient } from '../support/client-env.mjs';

let client;
afterEach(() => client?.close());

const OUTBOX = `
  <section data-pwa-outbox hidden data-pwa-outbox-refused="Refused (:status)" data-pwa-outbox-expired="Too old">
    <ul data-pwa-outbox-list></ul>
    <template data-pwa-outbox-item>
      <li><span data-pwa-outbox-field="method"></span> <span data-pwa-outbox-field="path"></span>
        <span data-pwa-outbox-field="reason"></span><ul data-pwa-outbox-errors hidden></ul>
        <button data-pwa-outbox-retry>Retry</button><button data-pwa-outbox-discard>Discard</button></li>
    </template>
  </section>`;

const refused = { id: 7, url: 'https://app.test/orders', method: 'POST', status: 422, reason: 'refused', message: null, errors: { name: ['The name is taken.'], note: 'Too long.' }, data: { name: 'Bread' } };
const expired = { id: 8, url: 'https://app.test/notes?draft=1', method: 'PUT', status: 0, reason: 'expired', message: null, errors: null, data: null };

/** A worker holding `items` in its outbox; RETRY/DISCARD remove them. */
const outboxWorker = (items) => (message) => {
  if (message.type === 'OUTBOX') return { items };
  const index = items.findIndex((item) => item.id === message.id);
  if (index >= 0) items.splice(index, 1);
  return { ok: index >= 0 };
};

const tick = () => new Promise((resolve) => setTimeout(resolve, 10));
const click = (el) => el.dispatchEvent(new client.window.MouseEvent('click', { bubbles: true }));
const $ = (selector) => client.document.querySelector(selector);
const $$ = (selector) => [...client.document.querySelectorAll(selector)];

describe('outbox', () => {
  it('lists the refused changes with retry and discard', async () => {
    client = await createClient({ before: (window) => { window.navigator.serviceWorker.answer = outboxWorker([{ ...refused }]); } });
    const items = await client.Pwa.outbox();

    assert.equal(items.length, 1);
    assert.equal(items[0].path, '/orders');
    assert.deepEqual(items[0].data, { name: 'Bread' });
    assert.deepEqual(await items[0].discard(), { ok: true });
    assert.deepEqual(client.sw.posted.filter((m) => m.type === 'DISCARD'), [{ type: 'DISCARD', id: 7 }]);
    assert.deepEqual(await client.Pwa.outbox(), []);
  });

  it('is empty without a worker', async () => {
    client = await createClient({ serviceWorker: false });
    assert.deepEqual(await client.Pwa.outbox(), []);
  });

  it('fills the component and hides it once empty', async () => {
    client = await createClient({ body: OUTBOX, before: (window) => { window.navigator.serviceWorker.answer = outboxWorker([{ ...refused }, { ...expired }]); } });
    await tick();

    assert.equal($('[data-pwa-outbox]').hidden, false);
    const rows = $$('[data-pwa-outbox-list] > li');
    assert.equal(rows.length, 2);
    assert.match(rows[0].textContent, /POST \/orders/);
    assert.match(rows[0].querySelector('[data-pwa-outbox-field="reason"]').textContent, /Refused \(422\)/);
    assert.deepEqual([...rows[0].querySelectorAll('[data-pwa-outbox-errors] li')].map((li) => li.textContent), ['The name is taken.', 'Too long.']);
    assert.equal(rows[1].querySelector('[data-pwa-outbox-field="reason"]').textContent, 'Too old');
    assert.equal(rows[1].querySelector('[data-pwa-outbox-errors]').hidden, true);

    click(rows[0].querySelector('[data-pwa-outbox-retry]'));
    await tick();
    assert.deepEqual(client.sw.posted.filter((m) => m.type === 'RETRY'), [{ type: 'RETRY', id: 7 }]);
    assert.equal($$('[data-pwa-outbox-list] > li').length, 1);

    click($('[data-pwa-outbox-discard]'));
    await tick();
    assert.equal($('[data-pwa-outbox]').hidden, true);
  });

  it('prefers the server\'s message to the generic reason', async () => {
    client = await createClient({ body: OUTBOX, before: (window) => { window.navigator.serviceWorker.answer = outboxWorker([{ ...refused, status: 403, message: 'This action is unauthorized.' }]); } });
    await tick();
    assert.equal($('[data-pwa-outbox-field="reason"]').textContent, 'This action is unauthorized.');
  });

  it('shows the bar when a change was refused and no outbox is on the page', async () => {
    client = await createClient();
    client.sw.receive({ source: 'pwa', type: 'replay-failed', id: 7, status: 422, kept: true });

    assert.match($('.pwa-bar').textContent, /could not be saved/);
    assert.ok(client.events.some((e) => e.name === 'pwa:replay-failed' && e.detail.status === 422));
  });

  it('updates the outbox instead of the bar when it is on the page', async () => {
    const items = [];
    client = await createClient({ body: OUTBOX, before: (window) => { window.navigator.serviceWorker.answer = outboxWorker(items); } });
    await tick();
    assert.equal($('[data-pwa-outbox]').hidden, true);

    items.push({ ...refused });
    client.sw.receive({ source: 'pwa', type: 'replay-failed', id: 7, status: 422, kept: true });
    await tick();

    assert.ok(!$('.pwa-bar') || $('.pwa-bar').hidden);
    assert.equal($('[data-pwa-outbox]').hidden, false);
  });

  it('passes the queue counts on and says when the queue is full', async () => {
    client = await createClient();
    client.sw.receive({ source: 'pwa', type: 'queue', count: 2, failed: 1 });
    assert.deepEqual(client.events.find((e) => e.name === 'pwa:queue').detail, { count: 2, failed: 1 });

    client.sw.receive({ source: 'pwa', type: 'queue-full', count: 20 });
    assert.match($('.pwa-bar').textContent, /too many changes/);
    assert.ok(client.events.some((e) => e.name === 'pwa:queue-full'));
  });
});
