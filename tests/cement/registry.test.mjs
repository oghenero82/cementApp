import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile, readdir } from 'node:fs/promises';
import { RecordFieldRegistry } from '../../frontend/src/registry/RecordFieldRegistry.js';
import { RecordDataProcessor } from '../../frontend/src/core/RecordDataProcessor.js';
import { MutationClient } from '../../frontend/src/core/MutationClient.js';
import { SyncClient } from '../../frontend/src/core/SyncClient.js';
import { parseCSV, convertCSV } from '../../frontend/src/core/Importer.js';
import { scanFields } from '../../scripts/scan_fields.mjs';

test('authoritative registry changes reads, filters, lookup IDs, nested allocations and writes atomically', () => {
  const r = new RecordFieldRegistry();
  const old = r.mutationEnvelope(
    'purchase',
    'insert',
    { SUPPLIER_ID: 7, SUPPLIER_PIN: 'PIN' },
    'idempotent-1',
  );
  const fields = {
    ...r.fields('purchase'),
    SUPPLIER_ID: 'supplierAccountRef',
    SUPPLIER_PIN: 'supplierAccessRef',
  };
  r.activateMetadata({
    revision: 'mf:2',
    authoritative: true,
    fieldsByRecordType: { purchase: fields },
    recordTypeIds: { purchase: 93 },
  });
  const data = new RecordDataProcessor(r);
  data.replace([
    {
      recordTypeCode: 'purchase',
      recordId: 31,
      data: { supplierAccountRef: 7, supplierAccessRef: 'PIN' },
    },
  ]);
  assert.equal(data.related('purchase', 'SUPPLIER_ID', 7).length, 1);
  assert.equal(data.read('purchase', data.all('purchase')[0], 'SUPPLIER_PIN'), 'PIN');
  const next = r.mutationEnvelope(
    'purchase',
    'insert',
    { SUPPLIER_ID: 7, SUPPLIER_PIN: 'PIN' },
    'idempotent-2',
  );
  assert.equal(next.recordTypeId, 93);
  assert.deepEqual(next.data, { supplierAccountRef: 7, supplierAccessRef: 'PIN' });
  assert.throws(() => r.fields('payroll'));
  assert.throws(() => r.validateQueuedMutationRevision(old));
  assert.equal(old.requestId, 'idempotent-1');
  assert.equal(old.registryRevision, 'cement-seed-2026-11-16');
  const snapshot = r.fields('purchase');
  assert.throws(() =>
    r.activateMetadata({
      revision: 'bad',
      fieldsByRecordType: { purchase: { CODE: 'supplierAccountRef' } },
    }),
  );
  assert.equal(r.revision, 'mf:2');
  assert.equal(r.fields('purchase'), snapshot);
});
test('missing aliases, unknown types, invalid and duplicate IDs are rejected', () => {
  const r = new RecordFieldRegistry();
  for (const metadata of [
    { revision: 'x', fieldsByRecordType: { unknown: { CODE: 'x' } } },
    { revision: 'x', authoritative: true, fieldsByRecordType: { purchase: { CODE: 'code' } } },
    { revision: 'x', fieldsByRecordType: { purchase: { WHAT: 'x' } } },
    { revision: 'x', fieldsByRecordType: {}, recordTypeIds: { purchase: 0 } },
    { revision: 'x', fieldsByRecordType: {}, recordTypeIds: { purchase: 1, customer: 1 } },
  ])
    assert.throws(() => r.activateMetadata(metadata));
  assert.equal(r.revision, 'cement-seed-2026-11-16');
});
test('offline mutation retains exact envelope and retries original request identity', async () => {
  const drafts = new Map();
  const cache = {
    async putDraft(d) {
      drafts.set(d.requestId, structuredClone(d));
    },
    async removeDraft(id) {
      drafts.delete(id);
    },
    async applyChanges() {},
  };
  const r = new RecordFieldRegistry();
  let sent;
  let online = false;
  const api = {
    async request(path, envelope) {
      sent = structuredClone(envelope);
      if (!online) throw new TypeError('offline');
      return { state: 'confirmed', mutationId: 9 };
    },
  };
  const client = new MutationClient(api, cache, r, { lastRun: Date.now(), async run() {} });
  const envelope = r.mutationEnvelope(
    'purchase',
    'insert',
    { SUPPLIER_PIN: 'secret' },
    'stable-request-1',
  );
  await assert.rejects(client.send(envelope));
  assert.equal(drafts.get(envelope.requestId).queueState, 'pending');
  assert.deepEqual(sent, envelope);
  online = true;
  await client.retry(drafts.get(envelope.requestId));
  assert.deepEqual(sent, envelope);
  assert.equal(drafts.size, 0);
});
test('CSV handles quotes and multiline text; conversion uses current registry codes', () => {
  const rows = parseCSV('CODE,NAME,STATUS\r\nA,"Line one\nLine ""two""",active\r\n');
  assert.equal(rows[1][1], 'Line one\nLine "two"');
  const r = new RecordFieldRegistry();
  r.activateMetadata({
    revision: 'next',
    fieldsByRecordType: { supplier: { NAME: 'supplierTitle' } },
  });
  const result = convertCSV(
    rows,
    {
      fields: [
        { alias: 'CODE', type: 'string' },
        { alias: 'NAME', type: 'string' },
        { alias: 'STATUS', type: 'string' },
      ],
      readOnlyAliases: [],
    },
    r,
    'supplier',
  );
  assert.equal(result[0].supplierTitle, 'Line one\nLine "two"');
  assert.throws(() => parseCSV('"unfinished'));
  assert.throws(() =>
    convertCSV([['WRONG'], ['x']], { fields: [], readOnlyAliases: [] }, r, 'supplier'),
  );
});
test('static field scanner detects violations in reads and form names', () => {
  assert.ok(scanFields('const value=record.supplierId;').length);
  assert.ok(scanFields("form.setAttribute('name','status');").length);
  assert.ok(scanFields("const value=record['amount'];").length);
  assert.equal(
    scanFields('const F=registry.fields("purchase");const value=record[F.SUPPLIER_ID];').length,
    0,
  );
});
test('all production business modules pass static registry access scan', async () => {
  async function walk(path) {
    const found = [];
    for (const entry of await readdir(path, { withFileTypes: true })) {
      const file = path + '/' + entry.name;
      if (entry.isDirectory()) {
        if (entry.name !== 'registry') found.push(...(await walk(file)));
      } else if (file.endsWith('.js')) found.push(file);
    }
    return found;
  }
  const errors = [];
  for (const file of await walk('frontend/src'))
    errors.push(...scanFields(await readFile(file, 'utf8'), file));
  assert.deepEqual(errors, []);
});

test('sync rejects unknown data fields, mismatched dynamic IDs and invalid versions', () => {
  const registry = new RecordFieldRegistry();
  registry.activateMetadata({
    revision: 'live',
    fieldsByRecordType: {},
    recordTypeIds: { supplier: 81 },
  });
  const sync = new SyncClient(null, null, registry);
  const row = {
    recordTypeId: 81,
    recordTypeCode: 'supplier',
    recordId: 120,
    mutationId: 3,
    version: 1,
    data: registry.encode('supplier', { NAME: 'Supplier' }),
  };
  assert.doesNotThrow(() => sync.validateRows([row]));
  assert.throws(() => sync.validateRows([{ ...row, recordTypeId: 82 }]));
  assert.throws(() => sync.validateRows([{ ...row, data: { unknownBusinessField: 'value' } }]));
  assert.throws(() => sync.validateRows([{ ...row, version: 0 }]));
});
