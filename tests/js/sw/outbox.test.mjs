// The outbox (offline.outbox): queued requests the server refuses on replay are kept for the user
// instead of being dropped; queue limits (offline.queue_max, offline.queue_max_age).
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createWorker, json, offline } from '../support/sw-env.mjs';

const post = (worker, path = '/items', body = 'name=Bread&_token=old&password=hunter2') =>
  worker.fetchEvent(path, {
    method: 'POST',
    body,
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': 'old' },
  });

/** Sends a message with a reply port and resolves to the reply. */
const ask = async (worker, data) => {
  const replies = [];
  await worker.message(data, [{ postMessage: (m) => replies.push(m) }]);
  return JSON.parse(JSON.stringify(replies[0]));
};

const last = (worker, type) => worker.messages.filter((m) => m.type === type).at(-1);

const invalid = () => json({ message: 'The name is taken.', errors: { name: ['The name is taken.'] } }, {}, 422);

describe('outbox', () => {
  it('keeps a refused request with the server\'s answer and without secrets', async () => {
    const worker = createWorker({ config: { outbox: true } });
    await post(worker);

    worker.network(invalid);
    await worker.message({ type: 'REPLAY_QUEUE' });

    const failed = last(worker, 'replay-failed');
    assert.equal(failed.status, 422);
    assert.equal(failed.kept, true);
    assert.equal(failed.reason, 'refused');
    assert.ok(worker.messages.some((m) => m.type === 'replayed' && m.status === 422), 'replayed still reports every answer');
    assert.deepEqual({ count: last(worker, 'queue').count, failed: last(worker, 'queue').failed }, { count: 0, failed: 1 });

    const { items } = await ask(worker, { type: 'OUTBOX' });
    assert.equal(items.length, 1);
    assert.equal(items[0].method, 'POST');
    assert.equal(items[0].message, 'The name is taken.');
    assert.deepEqual(items[0].errors, { name: ['The name is taken.'] });
    assert.deepEqual(items[0].data, { name: 'Bread' }, 'no _token, no password');
    assert.equal(items[0].headers, undefined);
  });

  it('never replays a refused request by itself', async () => {
    const worker = createWorker({ config: { outbox: true } });
    await post(worker);
    worker.network(invalid);
    await worker.message({ type: 'REPLAY_QUEUE' });

    const sent = worker.requests.length;
    await worker.message({ type: 'REPLAY_QUEUE' });
    assert.equal(worker.requests.length, sent);
  });

  it('retries a refused request on demand and drops it once accepted', async () => {
    const worker = createWorker({ config: { outbox: true } });
    await post(worker);
    worker.network(invalid);
    await worker.message({ type: 'REPLAY_QUEUE' });
    const [{ id }] = (await ask(worker, { type: 'OUTBOX' })).items;

    assert.deepEqual(await ask(worker, { type: 'RETRY', id }), { ok: false, status: 422 });

    worker.network(() => json({ saved: true }));
    assert.deepEqual(await ask(worker, { type: 'RETRY', id }), { ok: true });
    assert.deepEqual((await ask(worker, { type: 'OUTBOX' })).items, []);

    worker.network(offline);
    assert.deepEqual(await ask(worker, { type: 'RETRY', id }), { ok: false, missing: true });
  });

  it('keeps a retried request in the queue while still offline', async () => {
    const worker = createWorker({ config: { outbox: true } });
    await post(worker);
    worker.network(invalid);
    await worker.message({ type: 'REPLAY_QUEUE' });
    const [{ id }] = (await ask(worker, { type: 'OUTBOX' })).items;

    worker.network(offline);
    assert.deepEqual(await ask(worker, { type: 'RETRY', id }), { ok: false, pending: true });
    assert.equal(last(worker, 'queue').count, 1);
  });

  it('discards a refused request', async () => {
    const worker = createWorker({ config: { outbox: true } });
    await post(worker);
    worker.network(invalid);
    await worker.message({ type: 'REPLAY_QUEUE' });
    const [{ id }] = (await ask(worker, { type: 'OUTBOX' })).items;

    assert.deepEqual(await ask(worker, { type: 'DISCARD', id }), { ok: true });
    assert.deepEqual((await ask(worker, { type: 'OUTBOX' })).items, []);
    assert.equal(last(worker, 'queue').failed, 0);
  });

  it('treats an Inertia answer with validation errors as refused', async () => {
    const worker = createWorker({ config: { outbox: true } });
    await post(worker);
    // Inertia redirects back after a failed validation: the replay ends with 200 and props.errors.
    worker.network(() => json({ component: 'Items/Create', props: { errors: { name: 'Required.' } }, url: '/items/create', version: '1' }, { 'X-Inertia': 'true' }));
    await worker.message({ type: 'REPLAY_QUEUE' });

    const [item] = (await ask(worker, { type: 'OUTBOX' })).items;
    assert.equal(item.status, 422);
    assert.deepEqual(item.errors, { name: 'Required.' });
  });

  it('accepts an Inertia answer without errors', async () => {
    const worker = createWorker({ config: { outbox: true } });
    await post(worker);
    worker.network(() => json({ component: 'Items/Index', props: { errors: {} }, url: '/items', version: '1' }, { 'X-Inertia': 'true' }));
    await worker.message({ type: 'REPLAY_QUEUE' });
    assert.deepEqual((await ask(worker, { type: 'OUTBOX' })).items, []);
    assert.ok(!worker.messages.some((m) => m.type === 'replay-failed'));
  });

  it('drops a refused request without the outbox, as before, and says so', async () => {
    const worker = createWorker();
    await post(worker);
    worker.network(invalid);
    await worker.message({ type: 'REPLAY_QUEUE' });

    assert.equal(last(worker, 'replay-failed').kept, false);
    assert.deepEqual((await ask(worker, { type: 'OUTBOX' })).items, []);
  });

  it('is cleared with the user\'s data on logout', async () => {
    const worker = createWorker({ config: { outbox: true } });
    await post(worker);
    worker.network(invalid);
    await worker.message({ type: 'REPLAY_QUEUE' });

    await worker.message({ type: 'CLEAR_USER' });
    assert.deepEqual((await ask(worker, { type: 'OUTBOX' })).items, []);
  });
});

describe('queue limits', () => {
  it('does not send a request older than queue_max_age, it moves to the outbox', async () => {
    const worker = createWorker({ config: { outbox: true, queueMaxAge: 1 } });
    await post(worker);
    await new Promise((resolve) => setTimeout(resolve, 5));

    worker.network(() => json({ saved: true }));
    const sent = worker.requests.length;
    await worker.message({ type: 'REPLAY_QUEUE' });

    assert.equal(worker.requests.length, sent, 'nothing sent');
    const [item] = (await ask(worker, { type: 'OUTBOX' })).items;
    assert.equal(item.reason, 'expired');
    assert.equal(item.status, 0);
  });

  it('refuses to queue beyond queue_max and fails like the network', async () => {
    const worker = createWorker({ config: { queueMax: 1 } });
    assert.equal((await post(worker)).response.status, 202);

    const second = await post(worker);
    assert.equal(second.response.type, 'error');
    assert.equal(last(worker, 'queue-full').count, 1);
    assert.equal(last(worker, 'queued').count, 1);
  });
});
