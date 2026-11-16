import { chromium, expect } from '@playwright/test';
import { spawn } from 'node:child_process';
import { mkdtemp, rm, mkdir } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { existsSync } from 'node:fs';
import { recordFieldRegistry as registry } from '../../frontend/src/registry/RecordFieldRegistry.js';
const work = await mkdtemp(tmpdir() + '/cement-browser-');
const port = Number(process.env.CEMENT_TEST_PORT || 8092);
const origin = 'http://127.0.0.1:' + port;
const server = spawn(
  process.env.PHP_BINARY || 'php',
  ['-S', '127.0.0.1:' + port, '-t', 'public', 'scripts/router.php'],
  {
    env: {
      ...process.env,
      CEMENT_DB_PATH: work + '/app.sqlite',
      CEMENT_ATTACHMENTS_PATH: work + '/attachments',
    },
    stdio: ['ignore', 'pipe', 'pipe'],
  },
);
let logs = '';
server.stderr.on('data', (b) => (logs += b));
let browser;
try {
  for (let n = 0; n < 100; n++) {
    try {
      await fetch(origin + '/api/auth/session');
      break;
    } catch {
      await new Promise((r) => setTimeout(r, 100));
      if (n === 99) throw new Error('Server startup failed: ' + logs);
    }
  }
  browser = await chromium.launch({
    executablePath:
      process.env.CHROMIUM_PATH ||
      (existsSync('/usr/bin/chromium') ? '/usr/bin/chromium' : undefined),
    headless: true,
    args: ['--no-sandbox'],
  });
  const context = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
    reducedMotion: 'reduce',
  });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto(origin);
  await page.getByRole('heading', { name: 'Set up your business' }).waitFor();
  await page.locator('#institution').fill('Browser Test Cement');
  await page.locator('#display-name').fill('Test Administrator');
  await page.locator('#login-email').fill('admin@example.test');
  await page.locator('#login-password').fill('browser-test-password');
  await page.getByRole('button', { name: 'Create institution' }).click();
  await page.getByRole('heading', { name: 'Business overview', exact: true }).waitFor();
  const session = await (await context.request.get(origin + '/api/auth/session')).json();
  let csrf = session.csrfToken;
  const api = async (path, body) => {
    const response =
      body === undefined
        ? await context.request.get(origin + '/api/' + path)
        : await context.request.post(origin + '/api/' + path, {
            data: body,
            headers: { 'X-Cement-CSRF': csrf },
          });
    const result = await response.json();
    if (!response.ok()) throw new Error(path + ': ' + JSON.stringify(result));
    return result;
  };
  const meta = await api('meta/field-registry');
  registry.activateMetadata(meta);
  const nav = await api('portal/navigation');
  // A real browser form creates, reads, edits, and filters a persistent supplier.
  await page.goto(origin + '/#records/supplier');
  await page.getByRole('button', { name: '+ New record' }).click();
  await page.getByLabel(/^Code/).fill('BROWSER-SUPPLIER');
  await page.getByLabel(/^Name/).fill('Browser supplier');
  await page.getByLabel(/^Status/).selectOption('active');
  await page.getByRole('button', { name: 'Save record', exact: true }).click();
  await expect(page.locator('#overlay')).toBeEmpty();
  await expect(page.locator('main .panel-table')).toContainText('Browser supplier');
  await page.getByRole('button', { name: 'View →' }).click();
  await expect(page.getByRole('dialog')).toContainText('Browser supplier');
  await page.getByRole('button', { name: 'Edit record', exact: true }).click();
  await page.getByLabel(/^Name/).fill('Updated supplier');
  await page.getByRole('button', { name: 'Save record', exact: true }).click();
  await expect(page.locator('#overlay')).toBeEmpty();
  await page.reload();
  await expect(page.locator('main .panel-table')).toContainText('Updated supplier');
  // All 51 collections and all 78 workspace tabs mount without JavaScript failures.
  let collections = 0,
    tabs = 0;
  for (const type of Object.keys(meta.descriptors)) {
    await page.goto(origin + '/#records/' + type);
    await expect(page.locator('main .card-head h3')).toHaveText(meta.descriptors[type].title);
    collections++;
  }
  for (const module of nav.modules) {
    await page.goto(origin + '/#module/' + module.code);
    await expect(page.locator('main h1')).toHaveText(module.name);
  }
  for (const [key, w] of Object.entries(nav.workspaces)) {
    await page.goto(origin + '/#workspace/' + key);
    await expect(page.locator('main h1')).toHaveText(w.title);
    for (const tab of w.tabs) {
      await page.getByRole('tab', { name: tab, exact: true }).click();
      await expect(page.getByRole('tab', { name: tab, exact: true })).toHaveAttribute(
        'aria-selected',
        'true',
      );
      tabs++;
    }
  }
  // Metadata-only rename changes real browser form names, table readers, filters, and payload keys.
  const supplierRow = (await api('sync?cursor=0')).records.find(
    (r) => r.recordTypeCode === 'supplier',
  );
  await api(
    'mutation',
    registry.mutationEnvelope(
      'purchase',
      'insert',
      {
        CODE: 'OVERRIDE-PO',
        SUPPLIER_ID: supplierRow.recordId,
        SUPPLIER_PIN: 'ORIGINAL-PIN',
        PURCHASE_DATE: '2026-11-01',
        CURRENCY: 'NGN',
        STATUS: 'draft',
      },
      'browser-override-purchase',
    ),
  );
  const changed = structuredClone(meta);
  changed.revision = 'mf-browser-override';
  changed.fieldsByRecordType.purchase.SUPPLIER_PIN = 'supplierAccessRef';
  changed.fieldsByRecordType.purchase.SUPPLIER_ID = 'supplierAccountRef';
  changed.recordTypeIds.purchase = 98765;
  changed.fieldsByRecordType.cement_loading.QUANTITY = 'loadQuantityRef';
  changed.fieldsByRecordType.customer_sales.CUSTOMER_ID = 'customerAccountRef';
  changed.fieldsByRecordType.customer_sales.WAREHOUSE_ID = 'destinationWarehouseRef';
  changed.fieldsByRecordType.customer_sale_item.AGREED_UNIT_PRICE = 'saleUnitPriceRef';
  await page.route('**/api/meta/field-registry', (route) => route.fulfill({ json: changed }));
  await page.route('**/api/sync?*', async (route) => {
    const response = await route.fetch();
    const payload = await response.json();
    payload.registryRevision = changed.revision;
    for (const row of payload.records) {
      const next = {};
      for (const [alias, oldKey] of Object.entries(meta.fieldsByRecordType[row.recordTypeCode]))
        if (row.data[oldKey] !== undefined)
          next[changed.fieldsByRecordType[row.recordTypeCode][alias]] = row.data[oldKey];
      row.data = next;
      row.recordTypeId = changed.recordTypeIds[row.recordTypeCode];
    }
    await route.fulfill({ json: payload });
  });
  await page.goto(origin + '/#records/purchase');
  await page.reload();
  await expect(page.locator('main .panel-table')).toContainText('ORIGINAL-PIN');
  await expect(page.locator('main th[data-field="supplierAccessRef"]')).toHaveText('Supplier Pin');
  await page.getByRole('button', { name: 'View →' }).click();
  await page.getByRole('button', { name: 'Edit record', exact: true }).click();
  await expect(page.getByLabel(/^Supplier Pin/)).toHaveAttribute('name', 'supplierAccessRef');
  await expect(page.getByLabel(/^Supplier Id/)).toHaveAttribute('name', 'supplierAccountRef');
  await page.getByLabel(/^Supplier Pin/).fill('NEW-PIN');
  let submitted;
  await page.route('**/api/mutation', async (route) => {
    submitted = route.request().postDataJSON();
    await route.fulfill({ json: { state: 'confirmed', mutationId: 999, changes: [] } });
  });
  await page.getByRole('button', { name: 'Save record', exact: true }).click();
  await expect(page.locator('#overlay')).toBeEmpty();
  expect(submitted.data.supplierAccessRef).toBe('NEW-PIN');
  expect(submitted.data.supplierAccountRef).toBe(supplierRow.recordId);
  expect(submitted.recordTypeId).toBe(98765);
  expect(submitted.registryRevision).toBe(changed.revision);
  await page.unroute('**/api/mutation');
  const nested = await page.evaluate(async () => {
    const { FormRenderer } = await import('/assets/cement/renderers/FormRenderer.js');
    const { recordFieldRegistry: r } = await import(
      '/assets/cement/registry/RecordFieldRegistry.js'
    );
    const metadata = await (await fetch('/api/meta/field-registry')).json();
    const context = {
      metadata,
      api: {
        async request() {
          return { items: [] };
        },
      },
      data: {
        all(type) {
          return [{ recordId: type === 'customer' ? 2 : 1, data: {} }];
        },
        label() {
          return 'test';
        },
      },
    };
    const lf = r.fields('cement_loading'),
      cf = r.fields('customer_sales'),
      sf = r.fields('customer_sale_item');
    const form = new FormRenderer(context, 'cement_loading', {
      data: r.encode('cement_loading', {
        CODE: 'LOAD',
        PURCHASE_ITEM_ID: 1,
        SUPPLIER_DEPOT_ID: 1,
        LOADING_DATE: '2026-11-01T10:00',
        UNIT_ID: 1,
        QUANTITY: 10,
        STATUS: 'draft',
        ALLOCATION_ROWS: [{ [lf.QUANTITY]: 10, [cf.CUSTOMER_ID]: 2, [sf.AGREED_UNIT_PRICE]: 150 }],
      }),
    });
    const host = form.render(() => {});
    document.body.append(host);
    const values = form.values();
    const name = host.querySelector('#field-cement_loading-QUANTITY').getAttribute('name');
    host.remove();
    return {
      name,
      payload: r.mutationEnvelope('cement_loading', 'insert', values, 'nested-override-test').data,
    };
  });
  expect(nested.name).toBe('loadQuantityRef');
  expect(nested.payload.allocationRows[0].customerAccountRef).toBe(2);
  expect(nested.payload.allocationRows[0].saleUnitPriceRef).toBe(150);
  expect(nested.payload.allocationRows[0].loadQuantityRef).toBe(10);
  await page.unroute('**/api/meta/field-registry');
  await page.unroute('**/api/sync?*');
  await page.reload();
  // CSRF and role checks are enforced on the server, independently of navigation.
  const rejected = await context.request.post(origin + '/api/mutation', { data: {} });
  expect(rejected.status()).toBe(419);
  const image = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aIioAAAAASUVORK5CYII=',
    'base64',
  );
  const upload = await context.request.post(origin + '/api/files/upload', {
    headers: { 'X-Cement-CSRF': csrf },
    multipart: {
      recordTypeCode: 'delivery_proof',
      attachment: { name: 'proof.png', mimeType: 'image/png', buffer: image },
    },
  });
  expect(upload.status()).toBe(200);
  const file = await upload.json();
  expect((await context.request.get(origin + file.downloadPath)).status()).toBe(200);
  const anonymous = await browser.newContext();
  expect((await anonymous.request.get(origin + file.downloadPath)).status()).toBe(401);
  await anonymous.close();
  for (const [role, policy] of Object.entries(
    JSON.parse(
      await (
        await import('node:fs/promises')
      ).readFile('resources/cement/role_templates.json', 'utf8'),
    ),
  )) {
    if (role === 'cement_system_admin') continue;
    const email = role + '@example.test';
    await api('users', { email, displayName: policy.name, password: 'role-test-password', role });
    const other = await browser.newContext();
    const p = await other.newPage();
    await p.goto(origin);
    await p.locator('#login-email').fill(email);
    await p.locator('#login-password').fill('role-test-password');
    await p.getByRole('button', { name: 'Sign in', exact: true }).click();
    await p.getByRole('heading', { name: 'Business overview', exact: true }).waitFor();
    if (role === 'cement_driver') {
      const response = await other.request.get(origin + '/api/meta/field-registry');
      const d = await response.json();
      expect(d.fieldsByRecordType.payroll).toBeUndefined();
      await p.goto(origin + '/#records/payroll');
      await expect(p.locator('main')).toContainText('Page unavailable');
    }
    await other.close();
  }
  await page.goto(origin + '/#dashboard');
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.getByRole('heading', { name: 'Business overview', exact: true }).waitFor();
  await mkdir('test-results', { recursive: true });
  await page.screenshot({ path: 'test-results/desktop.png', fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.waitForTimeout(300);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
  await page.screenshot({ path: 'test-results/mobile.png', fullPage: true });
  await page.getByRole('button', { name: 'Toggle navigation' }).click();
  await expect(page.locator('#app')).toHaveClass(/sidebar-open/);
  const cacheResult = await page.evaluate(async () => {
    const { IndexedDbStore } = await import('/assets/cement/core/IndexedDbStore.js');
    const cache = new IndexedDbStore();
    await cache.open('scope-a', 'regression-cache');
    await cache.putDraft({ requestId: 'preserved-pending', registryRevision: 'old', data: {} });
    const newer = {
      recordTypeId: 1,
      recordId: 1,
      recordTypeCode: 'supplier',
      version: 2,
      mutationId: 2,
      data: { name: 'new' },
    };
    await cache.applyChanges([newer]);
    await cache.applyChanges([{ ...newer, version: 1, mutationId: 1, data: { name: 'old' } }]);
    const retained = (await cache.all())[0].data.name;
    await cache.applyChanges([{ ...newer, version: 3, mutationId: 3, deleted: true }]);
    await cache.applyChanges([newer]);
    const deleted = (await cache.all()).length;
    await cache.open('scope-b', 'regression-cache');
    const outbox = (await cache.all('outbox')).length;
    cache.close();
    return { retained, deleted, outbox };
  });
  expect(cacheResult).toEqual({ retained: 'new', deleted: 0, outbox: 1 });
  expect(errors).toEqual([]);
  console.log(
    `PASS browser: first-run auth, supplier create/edit/persistence, ${collections} collections, ${tabs} tabs, field-name override, CSRF, 7 roles, desktop/mobile layout`,
  );
} finally {
  await browser?.close();
  server.kill('SIGTERM');
  await rm(work, { recursive: true, force: true });
}
