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
  await mkdir('test-results', { recursive: true });
  await page.screenshot({ path: 'test-results/native-reservations-desktop.png', fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.screenshot({ path: 'test-results/native-reservations-mobile.png', fullPage: true });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  expect(errors).toEqual([]);
  console.log(
    'PASS native browser login, real supplier create/edit/persistence, transaction collections, reservation action metadata and desktop/mobile layout',
  );
} finally {
  await browser.close();
}
