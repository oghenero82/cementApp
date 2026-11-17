import { chromium, expect } from '@playwright/test';
import { existsSync } from 'node:fs';
import { mkdir } from 'node:fs/promises';
import { randomUUID } from 'node:crypto';
const origin = process.env.CEMENT_NATIVE_TEST_URL;
if (!origin || !['127.0.0.1', 'localhost', '[::1]'].includes(new URL(origin).hostname))
  throw new Error('Run this writing browser journey against an isolated loopback deployment');
const browser = await chromium.launch({
  executablePath:
    process.env.CHROMIUM_PATH ||
    (existsSync('/usr/bin/chromium') ? '/usr/bin/chromium' : undefined),
  headless: true,
  args: ['--no-sandbox'],
});
try {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await context.newPage();
  page.setDefaultTimeout(30000);
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await page.goto(origin);
  await page.locator('#login-email').fill(process.env.CEMENT_NATIVE_TEST_EMAIL);
  await page.locator('#login-password').fill(process.env.CEMENT_NATIVE_TEST_PASSWORD);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await page
    .getByRole('heading', { name: 'Business overview', exact: true })
    .waitFor({ timeout: 60000 });
  const meta = await (await context.request.get(origin + '/api/meta/field-registry')).json();
  expect(meta.descriptors.inventory_reservation.canDelete).toBe(false);
  const name = 'Native browser supplier ' + randomUUID().slice(0, 8);
  await page.goto(origin + '/#records/supplier');
  await page.getByRole('button', { name: '+ New record' }).click();
  await page.getByLabel(/^Code/).fill('BROWSER-' + randomUUID());
  await page.getByLabel(/^Name/).fill(name);
  await page.getByLabel(/^Status/).selectOption('active');
  await page.getByRole('button', { name: 'Save record', exact: true }).click();
  await expect(page.locator('#overlay')).toBeEmpty({ timeout: 30000 });
  await page.getByRole('searchbox', { name: 'Search records', exact: true }).fill(name);
  await expect(page.locator('main .panel-table')).toContainText(name);
  await page.getByRole('button', { name: 'View →' }).click();
  await page.getByRole('button', { name: 'Edit record', exact: true }).click();
  await page.getByLabel(/^Name/).fill(name + ' updated');
  await page.getByRole('button', { name: 'Save record', exact: true }).click();
  await expect(page.locator('#overlay')).toBeEmpty({ timeout: 30000 });
  await page.reload();
  await page.getByRole('searchbox', { name: 'Search records', exact: true }).fill(name);
  await expect(page.locator('main .panel-table')).toContainText(name + ' updated');
  for (const type of [
    'purchase',
    'cement_loading',
    'customer_sales',
    'customer_payment',
    'customer_sale_return',
    'inventory_stocktake',
    'inventory_transfer',
    'inventory_reservation',
  ]) {
    await page.goto(origin + '/#records/' + type);
    await expect(
      page.getByRole('heading', { name: meta.descriptors[type].title, exact: true, level: 1 }),
    ).toBeVisible();
  }
  const schemeCode = 'BROWSER-SCHEME-' + randomUUID();
  await page.goto(origin + '/#records/supplier_incentive_scheme');
  await page.getByRole('button', { name: '+ New record' }).click();
  const field = (alias) => page.locator('#field-supplier_incentive_scheme-' + alias);
  await field('CODE').fill(schemeCode);
  await field('NAME').fill('Browser cumulative incentive');
  await expect(field('SUPPLIER_ID').locator('option')).not.toHaveCount(1);
  await field('SUPPLIER_ID').selectOption(await field('SUPPLIER_ID').locator('option').nth(1).getAttribute('value'));
  await field('BASIS').selectOption('loading_quantity');
  await field('CALCULATION_TYPE').selectOption('per_quantity');
  await field('RATE').fill('2');
  await field('VALID_FROM').fill(new Date().toISOString().slice(0, 10));
  await field('STATUS').selectOption('active');
  await expect(field('PRODUCT_IDS').locator('option')).not.toHaveCount(0);
  const productIds = await field('PRODUCT_IDS').locator('option').evaluateAll((options) => options.slice(0, 2).map((o) => o.value));
  await field('PRODUCT_IDS').selectOption(productIds);
  await page.getByRole('button', { name: 'Save record', exact: true }).click();
  await expect(page.locator('#overlay')).toBeEmpty({ timeout: 30000 });
  await page.getByRole('searchbox', { name: 'Search records', exact: true }).fill(schemeCode);
  await expect(page.locator('main .panel-table')).toContainText(schemeCode);
  await page.getByRole('button', { name: 'View →' }).click();
  await page.getByRole('button', { name: 'Edit record', exact: true }).click();
  await expect(field('PRODUCT_IDS')).toHaveValues(productIds);
  await expect(field('BASIS')).toHaveValue('loading_quantity');
  await page.keyboard.press('Escape');
  const health = await (await context.request.get(origin + '/api/health')).json();
  expect(health.businessRuntime).toBe('partial');
  await page.goto(origin + '/#reports');
  await expect(
    page.getByRole('heading', { name: 'Management reports', exact: true }),
  ).toBeVisible();
  await expect(
    page.getByRole('heading', { name: 'Trip Profitability by Destination', exact: true }),
  ).toBeVisible({ timeout: 30000 });
  await expect(page.getByRole('button', { name: 'Save snapshot', exact: true })).toHaveCount(0);
  await page.goto(origin + '/#records/inventory_reservation');
  await mkdir('test-results', { recursive: true });
  await page.screenshot({ path: 'test-results/native-reservations-desktop.png', fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.screenshot({ path: 'test-results/native-reservations-mobile.png', fullPage: true });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  expect(errors).toEqual([]);
  console.log(
    'PASS native browser login, real supplier create/edit/persistence, incentive enum/multiple-reference form persistence, transaction collections, reservation action metadata, trip profitability, health and desktop/mobile layout',
  );
} catch (error) {
  await mkdir('test-results', { recursive: true });
  const page = browser.contexts()[0]?.pages()[0];
  if (page) await page.screenshot({ path: 'test-results/native-browser-failure.png', fullPage: true }).catch(() => {});
  throw error;
} finally {
  await browser.close();
}
