import { expect } from '@playwright/test';
import { recordFieldRegistry as registry } from '../../frontend/src/registry/RecordFieldRegistry.js';

// Business writes use visible forms. API reads independently verify durable results.
export async function businessJourney(page, origin, api) {
  const rows = async (type) =>
    (await api('sync?cursor=0')).records.filter((r) => r.recordTypeCode === type);
  const semantic = (type, row) =>
    Object.fromEntries(
      Object.keys(registry.fields(type)).map((alias) => [
        alias,
        registry.get(type, row.data, alias),
      ]),
    );
  const fill = async (type, values, host = page) => {
    for (const [alias, value] of Object.entries(values)) {
      const input = host.locator('#field-' + type + '-' + alias);
      await expect(input).toBeVisible();
      const tag = await input.evaluate((n) => n.tagName);
      if (tag === 'SELECT') await input.selectOption(String(value));
      else if ((await input.getAttribute('type')) === 'checkbox') await input.setChecked(value);
      else await input.fill(String(value));
    }
  };
  const create = async (type, values) => {
    await page.goto(origin + '/#records/' + type);
    await page.getByRole('button', { name: '+ New record', exact: true }).click();
    await fill(type, values);
    const invalid = await page
      .locator('.record-form :invalid')
      .evaluateAll((nodes) => nodes.map((n) => ({ id: n.id, message: n.validationMessage })));
    if (invalid.length)
      throw new Error('Invalid ' + type + ' controls: ' + JSON.stringify(invalid));
    await page.getByRole('button', { name: 'Save record', exact: true }).click();
    await expect(page.locator('#overlay'))
      .toBeEmpty()
      .catch(async (error) => {
        throw new Error(
          type +
            ': ' +
            (await page.locator('.form-summary').allTextContents()) +
            ' ' +
            error.message,
        );
      });
    return (await rows(type)).find((r) => semantic(type, r).CODE === values.CODE);
  };
  const edit = async (type, row, values) => {
    await page.goto(origin + '/#records/' + type);
    await page.goto(origin + '/#records/' + type + '/' + row.recordId);
    await page.getByRole('button', { name: 'Edit record', exact: true }).click();
    await fill(type, values);
    const invalid = await page
      .locator('.record-form :invalid')
      .evaluateAll((nodes) => nodes.map((n) => ({ id: n.id, message: n.validationMessage })));
    if (invalid.length)
      throw new Error('Invalid ' + type + ' controls: ' + JSON.stringify(invalid));
    await page.getByRole('button', { name: 'Save record', exact: true }).click();
    await expect(page.locator('.record-form')).toHaveCount(0);
    if (await page.getByRole('button', { name: 'Close dialog', exact: true }).count())
      await page.getByRole('button', { name: 'Close dialog', exact: true }).click();
  };
  const supplier = (await rows('supplier'))[0];
  const depot = await create('supplier_depot', {
    CODE: 'J-DEPOT',
    SUPPLIER_ID: supplier.recordId,
    NAME: 'Journey depot',
    STATUS: 'active',
  });
  const unit = await create('unit_of_measure', {
    CODE: 'J-BAG',
    NAME: 'Journey bag',
    SYMBOL: 'jbag',
    DIMENSION: 'count',
    STATUS: 'active',
  });
  const category = await create('product_category', {
    CODE: 'J-CATEGORY',
    NAME: 'Journey cement',
    STATUS: 'active',
  });
  const product = await create('product', {
    CODE: 'J-PRODUCT',
    NAME: 'Journey cement 42.5',
    CATEGORY_ID: category.recordId,
    BASE_UNIT_ID: unit.recordId,
    TRACKING_MODE: 'quantity',
    INVENTORY_TRACKED: true,
    TAXABLE: false,
    STATUS: 'active',
  });
  const customer = await create('customer', {
    CODE: 'J-CUSTOMER',
    COMPANY_NAME: 'Journey builder',
    CUSTOMER_TYPE: 'company',
    CREDIT_LIMIT: 100000,
    STATUS: 'active',
  });
  const warehouse = await create('inventory_location', {
    CODE: 'J-WAREHOUSE',
    NAME: 'Journey warehouse',
    LOCATION_TYPE: 'warehouse',
    STATUS: 'active',
  });
  const price = await create('price_list', {
    CODE: 'J-PRICE',
    NAME: 'Journey retail',
    PRICE_TYPE: 'retail',
    CURRENCY: 'NGN',
    VALID_FROM: '2026-01-01',
    STATUS: 'active',
  });
  await create('price_list_item', {
    CODE: 'J-PRICE-LINE',
    PRICE_LIST_ID: price.recordId,
    PRODUCT_ID: product.recordId,
    UNIT_ID: unit.recordId,
    MIN_QUANTITY: 1,
    UNIT_PRICE: 150,
    STATUS: 'active',
  });
  // Purchase header and line commit atomically through the guided workspace.
  await page.goto(origin + '/#workspace/purchase');
  await page.getByRole('button', { name: 'New purchase with items', exact: true }).click();
  await fill(
    'purchase',
    {
      CODE: 'J-PURCHASE',
      SUPPLIER_ID: supplier.recordId,
      SUPPLIER_DEPOT_ID: depot.recordId,
      PURCHASE_DATE: '2026-11-01',
      CURRENCY: 'NGN',
    },
    page.locator('.flow-root'),
  );
  await fill(
    'purchaseItem',
    {
      PRODUCT_ID: product.recordId,
      UNIT_ID: unit.recordId,
      ORIGINAL_QUANTITY: 100,
      UNIT_PRICE: 100,
    },
    page.locator('.flow-line'),
  );
  await page.getByRole('button', { name: 'Review batch', exact: true }).click();
  await page.getByRole('button', { name: 'Create purchase draft', exact: true }).click();
  await expect(page.locator('.business-flow')).toHaveCount(0);
  await expect(page.getByRole('dialog')).toContainText('J-PURCHASE');
  await page.getByRole('button', { name: 'Close dialog', exact: true }).click();
  const purchase = (await rows('purchase')).find(
    (r) => semantic('purchase', r).CODE === 'J-PURCHASE',
  );
  const item = (await rows('purchaseItem')).find(
    (r) => semantic('purchaseItem', r).PURCHASE_ID === purchase.recordId,
  );
  await edit('purchase', purchase, { STATUS: 'paid' });
  // Loading uses the allocation editor and creates a real warehouse receipt.
  await page.goto(origin + '/#records/cement_loading');
  await page.getByRole('button', { name: '+ New record', exact: true }).click();
  await fill('cement_loading', {
    CODE: 'J-LOAD',
    PURCHASE_ITEM_ID: item.recordId,
    SUPPLIER_DEPOT_ID: depot.recordId,
    LOADING_DATE: '2026-11-02T10:00',
    UNIT_ID: unit.recordId,
    QUANTITY: 60,
    STATUS: 'confirmed',
  });
  const allocations = page.locator('.allocation-editor');
  const allocation = allocations.locator('.allocation-row');
  // Allocation controls expose registry-derived names; field positions are not contracts.
  await allocation.getByLabel('Allocated quantity', { exact: true }).fill('60');
  await allocation.getByLabel('Destination kind', { exact: true }).selectOption('warehouse');
  await allocation
    .getByLabel('Destination', { exact: true })
    .selectOption(String(warehouse.recordId));
  await page.getByRole('button', { name: 'Save record', exact: true }).click();
  await expect(page.locator('#overlay')).toBeEmpty();
  let stock = semantic('inventory_stock', (await rows('inventory_stock'))[0]);
  expect(stock.QUANTITY_ON_HAND).toBe(60);
  await page.goto(origin + '/#workspace/sales');
  await page.getByRole('button', { name: 'New warehouse sale', exact: true }).click();
  await fill(
    'customer_sales',
    {
      CODE: 'J-SALE',
      CUSTOMER_ID: customer.recordId,
      WAREHOUSE_ID: warehouse.recordId,
      SALE_DATE: '2026-11-03',
      CURRENCY: 'NGN',
    },
    page.locator('.flow-root'),
  );
  await fill(
    'customer_sale_item',
    { PRODUCT_ID: product.recordId, UNIT_ID: unit.recordId, QUANTITY: 20, AGREED_UNIT_PRICE: 150 },
    page.locator('.flow-line'),
  );
  await page.getByRole('button', { name: 'Review batch', exact: true }).click();
  await page.getByRole('button', { name: 'Create warehouse sale draft', exact: true }).click();
  await expect(page.locator('.business-flow')).toHaveCount(0);
  await expect(page.getByRole('dialog')).toContainText('J-SALE');
  await page.getByRole('button', { name: 'Close dialog', exact: true }).click();
  const sale = (await rows('customer_sales')).find(
    (r) => semantic('customer_sales', r).CODE === 'J-SALE',
  );
  await create('inventory_reservation', {
    CODE: 'J-HOLD',
    SALE_ID: sale.recordId,
    LOCATION_ID: warehouse.recordId,
    PRODUCT_ID: product.recordId,
    QUANTITY_BASE: 20,
    STATUS: 'active',
  });
  stock = semantic('inventory_stock', (await rows('inventory_stock'))[0]);
  expect(stock.QUANTITY_AVAILABLE).toBe(40);
  expect(stock.QUANTITY_ON_HAND).toBe(60);
  await edit('customer_sales', sale, { STATUS: 'invoiced' });
  stock = semantic('inventory_stock', (await rows('inventory_stock'))[0]);
  expect(stock.QUANTITY_ON_HAND).toBe(40);
  expect(stock.QUANTITY_RESERVED).toBe(0);
  await create('customer_payment', {
    CODE: 'J-RECEIPT',
    CUSTOMER_ID: customer.recordId,
    SALE_ID: sale.recordId,
    AMOUNT: 1000,
    PAYMENT_DATE: '2026-11-04T10:00',
    PAYMENT_METHOD: 'bank',
    STATUS: 'completed',
  });
  expect(
    semantic(
      'customer_sales',
      (await rows('customer_sales')).find((r) => r.recordId === sale.recordId),
    ).BALANCE_DUE,
  ).toBe(2000);
  const statement = await api('customers/statement?customerId=' + customer.recordId);
  expect(statement.closingReceivable).toBe(2000);
  // Signed proof is drawn, uploaded, then bound by the confirmed mutation.
  await page.goto(origin + '/#records/delivery_proof');
  await page.getByRole('button', { name: '+ New record', exact: true }).click();
  await fill('delivery_proof', {
    CODE: 'J-PROOF',
    SALE_ID: sale.recordId,
    DELIVERED_DATE: '2026-11-04T12:00',
    ACCEPTED_QUANTITY: 20,
    STATUS: 'confirmed',
  });
  const canvas = page.locator('.signature-pad'),
    box = await canvas.boundingBox();
  await page.mouse.move(box.x + 20, box.y + 30);
  await page.mouse.down();
  await page.mouse.move(box.x + 120, box.y + 80, { steps: 10 });
  await page.mouse.up();
  await page.getByRole('button', { name: 'Use signature', exact: true }).click();
  await expect(page.locator('.signature-editor')).toContainText('Signature uploaded and ready');
  await page.getByRole('button', { name: 'Save record', exact: true }).click();
  await expect(page.locator('#overlay')).toBeEmpty();
  expect(semantic('delivery_proof', (await rows('delivery_proof'))[0]).SIGNATURE).toBeTruthy();
  await page.reload();
  expect(
    semantic(
      'customer_sales',
      (await rows('customer_sales')).find((r) => r.recordId === sale.recordId),
    ).BALANCE_DUE,
  ).toBe(2000);
  const batchDraft = async (workspace, buttonName, type, child, header, line, submitName) => {
    await page.goto(origin + '/#workspace/' + workspace);
    await page.getByRole('button', { name: buttonName, exact: true }).click();
    await fill(type, header, page.locator('.flow-root'));
    await fill(child, line, page.locator('.flow-line'));
    await page.getByRole('button', { name: 'Review batch', exact: true }).click();
    await page.getByRole('button', { name: submitName, exact: true }).click();
    await expect(page.locator('.business-flow')).toHaveCount(0);
    await page.getByRole('button', { name: 'Close dialog', exact: true }).click();
    return (await rows(type)).find((r) => semantic(type, r).CODE === header.CODE);
  };
  const count = await batchDraft(
    'warehouse',
    'Start stocktake with count lines',
    'inventory_stocktake',
    'inventory_stocktake_line',
    { CODE: 'J-COUNT', LOCATION_ID: warehouse.recordId, COUNT_DATE: '2026-11-05T10:00' },
    { PRODUCT_ID: product.recordId, STOCK_CONDITION: 'good', COUNTED_QTY: 38 },
    'Create physical stocktake draft',
  );
  await edit('inventory_stocktake', count, { STATUS: 'posted' });
  expect(semantic('inventory_stock', (await rows('inventory_stock'))[0]).QUANTITY_ON_HAND).toBe(38);
  const person = await create('person', {
    CODE: 'J-PERSON',
    FIRST_NAME: 'Journey',
    LAST_NAME: 'Staff',
    STATUS: 'active',
  });
  const employment = await create('staff_employment', {
    CODE: 'J-EMPLOYMENT',
    PERSON_ID: person.recordId,
    EMPLOYEE_NUMBER: 'J-EMP-1',
    HIRE_DATE: '2026-01-01',
    EMPLOYMENT_TYPE: 'full_time',
    BASIC_SALARY: 1000,
    STATUS: 'active',
  });
  const payroll = await batchDraft(
    'staff',
    'Create payroll with staff',
    'payroll',
    'payroll_item',
    {
      CODE: 'J-PAYROLL',
      PERIOD_START: '2026-11-01',
      PERIOD_END: '2026-11-30',
      PAYMENT_DATE: '2026-11-30',
      CURRENCY: 'NGN',
    },
    { EMPLOYMENT_ID: employment.recordId, TAX_AMOUNT: 100 },
    'Create payroll batch draft',
  );
  expect(
    semantic(
      'payroll',
      (await rows('payroll')).find((r) => r.recordId === payroll.recordId),
    ).TOTAL_NET,
  ).toBe(900);
  await edit('payroll', payroll, { STATUS: 'posted' });
  console.log(
    'PASS business browser: guided stocktake variance posting and payroll salary snapshot / tax calculation',
  );
  console.log(
    'PASS business browser: catalogs → atomic purchase → funding → allocated loading → warehouse sale → reservation → invoice → partial receipt → signed delivery; statement and stock verified',
  );
}
