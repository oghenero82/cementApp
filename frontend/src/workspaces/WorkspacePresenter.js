import { el, button, labelOf, money, number, icon } from '../core/dom.js';
import { recordFieldRegistry as registry } from '../registry/RecordFieldRegistry.js';
import { RouteLinkBuilder as routes } from '../core/RouteLinkBuilder.js';
import { CardRenderer } from '../renderers/CardRenderer.js';
import { TableRenderer } from '../renderers/TableRenderer.js';

const tabTypes = {
  purchase: {
    'Items & entitlements': ['purchaseItem'],
    'Loading & clearance': ['cement_loading', 'loading_clearance'],
    'Payments & finance': ['supplier_purchase_payment', 'purchase_adjustment'],
    Incentives: ['supplier_incentive'],
  },
  loading: {
    Clearances: ['loading_clearance'],
    'Trip & delivery': ['trip', 'delivery_proof'],
    'Stock & sales effects': ['inventory_movement', 'customer_sales'],
  },
  customer: {
    'Sales & invoices': ['customer_sales'],
    'Receipts & balances': ['customer_payment'],
    'Deliveries / returns': ['delivery_proof', 'customer_sale_return'],
    'Price agreements': ['price_list', 'price_list_item'],
  },
  sales: {
    'Sale lines & pricing': ['customer_sale_item'],
    Receipts: ['customer_payment'],
    'Fulfilment & proof': ['delivery_proof'],
    Returns: ['customer_sale_return'],
  },
  warehouse: {
    'Stock movement': ['inventory_movement', 'inventory_reservation'],
    'Stocktake & counts': ['inventory_stocktake', 'inventory_stocktake_line', 'stock_adjustment'],
    'Quality & damaged': ['damaged_goods'],
    'Valuation / FIFO': ['inventory_cost_layer'],
  },
  trip: {
    'Route & destinations': ['trip_destination'],
    'Fuel & expenses': ['fuel_transaction', 'vehicle_maintenance', 'trip_expense'],
    'Proof of delivery': ['delivery_proof'],
    'Trip costing': ['trip_destination', 'trip_expense'],
  },
  staff: {
    'Employment & payroll': ['payroll_item', 'payroll', 'payroll_payment'],
    Deductions: ['staff_deduction'],
  },
  finance: {
    'Supplier / customer balances': ['supplier_purchase_payment', 'customer_payment'],
    'Expenses / controls': ['expense'],
  },
  supplier: {
    Depots: ['supplier_depot'],
    'Product mappings': ['supplier_product'],
    'Purchases & entitlement': ['purchase', 'purchaseItem'],
    Incentives: ['supplier_incentive'],
  },
  pricing: {
    'Price entries': ['price_list_item'],
    'Product conversion': ['product', 'unit_of_measure', 'product_category'],
    'Supplier product mapping': ['supplier_product'],
    'Override controls': ['price_list'],
  },
  incentives: {
    Schemes: ['supplier_incentive_scheme'],
    'Earned benefits': ['supplier_incentive'],
    Receipts: ['supplier_incentive_receipt'],
  },
  onboarding: {
    'Startup checklist': [
      'cement_branch',
      'supplier',
      'supplier_depot',
      'unit_of_measure',
      'product_category',
      'product',
      'customer',
    ],
    'Import & verification': [],
  },
  reporting: {
    'Generated history': ['report_result'],
    'Delivery / schedules': ['report_schedule', 'report_delivery'],
    'Finance & operations': ['report'],
  },
};
export class WorkspacePresenter {
  constructor(context, key) {
    Object.assign(this, { context, key });
    this.definition = context.navigation.workspaces[key];
    this.tab = 'Overview';
    this.selected = null;
  }
  mount(root) {
    this.root = root;
    this.render();
  }
  destroy() {
    this.root = null;
  }
  bind() {}
  selectedRow() {
    return (
      this.context.data.get(this.definition.root, this.selected) ||
      this.context.data.all(this.definition.root)[0]
    );
  }
  render() {
    const w = this.definition;
    const row = this.selectedRow();
    this.selected = row?.recordId;
    const select = el(
      'select',
      {
        'aria-label': 'Focused record',
        onChange: () => {
          this.selected = Number(select.value);
          this.render();
        },
      },
      el('option', { value: '' }, 'Choose a focused record…'),
      ...this.context.data
        .all(w.root)
        .map((r) =>
          el(
            'option',
            { value: r.recordId, selected: r.recordId === row?.recordId },
            this.context.data.label(w.root, r),
          ),
        ),
    );
    const tabs = el(
      'div',
      { class: 'ws-tabs', role: 'tablist' },
      ...w.tabs.map((tab) =>
        el(
          'button',
          {
            role: 'tab',
            'aria-selected': tab === this.tab,
            class: tab === this.tab ? 'active' : '',
            onClick: () => {
              this.tab = tab;
              this.render();
            },
          },
          tab,
        ),
      ),
    );
    const header = el(
      'div',
      { class: 'page-heading' },
      el(
        'div',
        {},
        el('div', { class: 'eyebrow' }, 'OPERATIONS / ' + this.key.toUpperCase()),
        el('h1', {}, w.title),
        el('p', {}, w.subtitle),
      ),
      el(
        'div',
        { class: 'actions' },
        this.context.metadata.descriptors[w.root]?.canWrite &&
          !this.context.metadata.descriptors[w.root].serviceOwned
          ? button(
              '+ New ' + this.context.metadata.descriptors[w.root].title,
              () =>
                this.context.flows.supports(w.root)
                  ? this.context.flows.create(w.root)
                  : this.context.presenter(w.root).edit(),
              'primary',
            )
          : null,
      ),
    );
    const hero = el(
      'div',
      { class: 'module-hero' },
      el(
        'div',
        {},
        el('strong', {}, row ? this.context.data.label(w.root, row) : 'Your workspace is ready'),
        el(
          'p',
          {},
          row
            ? 'Select a tab to work with connected records and transactions.'
            : 'Add reference data, then create your first transaction.',
        ),
      ),
      select,
    );
    const body = el('div', { class: 'workspace-body' });
    this.root.replaceChildren(
      header,
      hero,
      this.context.flows.actions(this.key, row),
      this.metrics(row),
      tabs,
      body,
    );
    if (this.tab === 'Business rules') {
      body.append(
        CardRenderer.panel(
          'Business controls',
          el(
            'ol',
            { class: 'rules' },
            ...w.rules.map((rule) =>
              el(
                'li',
                {},
                rule
                  .replace(/MetaForge services/g, 'the authoritative backend')
                  .replace(/MetaForge remains/g, 'The server remains'),
              ),
            ),
          ),
        ),
      );
      return;
    }
    if (this.tab === 'Overview') {
      body.append(
        el(
          'div',
          { class: 'workspace-overview' },
          CardRenderer.panel(
            'Operational journey',
            el(
              'ol',
              { class: 'journey' },
              ...w.steps.map((step, i) =>
                el(
                  'li',
                  {},
                  el('span', { class: 'step' }, String(i + 1).padStart(2, '0')),
                  el(
                    'div',
                    {},
                    el('strong', {}, step),
                    el('small', {}, 'Validated and recorded by the server'),
                  ),
                ),
              ),
            ),
          ),
          CardRenderer.panel(
            'Next actions',
            el(
              'div',
              { class: 'action-list' },
              ...w.actions.map(([title, type]) =>
                button(
                  title + ' →',
                  () => this.context.presenter(type).edit(null, this.prefill(type, row)),
                  'action-link',
                ),
              ),
            ),
          ),
        ),
      );
      if (row) body.append(this.context.presenter(w.root).collection([row]));
      for (const [, type] of w.links.slice(0, 2)) this.addTable(body, type, row);
      return;
    }
    if (this.tab === 'Allocation designer') {
      body.append(
        CardRenderer.panel(
          'Split this loading across destinations',
          el(
            'div',
            {},
            el(
              'p',
              { class: 'muted' },
              'Customer and warehouse allocations must equal the load. Prices resolve from effective price lists; entitlement is checked again when confirming.',
            ),
            button(
              row ? 'Edit allocations' : 'Create loading',
              () => this.context.presenter('cement_loading').edit(row),
              'primary',
            ),
          ),
        ),
      );
      if (row) this.addTable(body, 'customer_sales', row);
      return;
    }
    if (['Access / roles'].includes(this.tab)) {
      this.context.admin.users(body);
      return;
    }
    if (this.tab === 'Configuration policies') {
      this.context.admin.settings(body);
      return;
    }
    if (this.tab === 'Import & verification' || this.tab === 'Monthly upload history') {
      this.context.admin.importer(body);
      return;
    }
    if (this.tab === 'Bank reconciliation') {
      body.append(
        CardRenderer.panel(
          'Reconcile bank activity',
          el(
            'p',
            { class: 'muted' },
            'Create a bank statement, import or enter its lines, and match each line to a posted journal. Confirm a reconciliation only when the closing balance agrees with the ledger.',
          ),
        ),
      );
      for (const type of [
        'bank_account',
        'bank_statement',
        'bank_statement_line',
        'bank_reconciliation',
      ])
        this.addTable(body, type, null);
      return;
    }
    if (['Journal & account views', 'Accounting'].includes(this.tab)) {
      const ledger = el('div');
      body.append(ledger);
      this.context.admin.ledger(ledger);
      for (const type of ['account_type', 'account', 'account_balance', 'fiscal_year'])
        this.addTable(body, type, null);
      return;
    }
    if (this.tab === 'Savings & loans') {
      body.append(
        CardRenderer.panel(
          'Shared Cooperative accounts',
          el(
            'p',
            { class: 'muted' },
            'Savings, approved loans and payroll deductions use the same member accounts. Account balances change only through posted Cooperative transactions or approved payroll.',
          ),
        ),
      );
      for (const type of ['member', 'member_account', 'cooperative_transaction'])
        this.addTable(body, type, null);
      return;
    }
    if (this.tab === 'Report catalog') {
      this.context.reports(body);
      return;
    }
    const types = tabTypes[this.key]?.[this.tab] || w.links.map((link) => link[1]);
    for (const type of types) this.addTable(body, type, row);
    if (!types.length)
      body.append(CardRenderer.empty('No records for this view', 'Choose another workspace tab.'));
  }
  prefill(type, row) {
    if (!row) return {};
    const out = {};
    for (const f of this.context.metadata.descriptors[type]?.fields || [])
      if (f.target === this.definition.root) out[f.alias] = row.recordId;
    return out;
  }
  related(type, row) {
    if (!row) return this.context.data.all(type);
    const root = this.definition.root;
    const desc = this.context.metadata.descriptors[type];
    if (!desc) return [];
    if (type === root) return [row];
    const refs = desc.fields.filter((f) => f.target === root);
    if (refs.length)
      return this.context.data
        .all(type)
        .filter((r) => refs.some((f) => registry.get(type, r.data, f.alias) === row.recordId));
    // Traverse declared foreign keys, never infer field spellings or local array positions as IDs.
    const parents = new Map([[root, new Set([row.recordId])]]);
    for (let depth = 0; depth < 2; depth++)
      for (const [other, d] of Object.entries(this.context.metadata.descriptors))
        for (const f of d.fields)
          if (f.target && parents.has(f.target) && !parents.has(other)) {
            const ids = new Set(
              this.context.data
                .all(other)
                .filter((r) => parents.get(f.target).has(registry.get(other, r.data, f.alias)))
                .map((r) => r.recordId),
            );
            if (ids.size) parents.set(other, ids);
          }
    if (parents.has(type))
      return this.context.data.all(type).filter((r) => parents.get(type).has(r.recordId));
    if (root === 'inventory_stock') {
      const aliases = ['PRODUCT_ID', 'LOCATION_ID'];
      return this.context.data
        .all(type)
        .filter((r) =>
          aliases
            .filter((a) => registry.fields(type)[a])
            .every((a) => registry.get(type, r.data, a) === registry.get(root, row.data, a)),
        );
    }
    return this.context.data.all(type);
  }
  addTable(body, type, row) {
    if (!this.context.metadata.descriptors[type]) return;
    body.append(
      this.context.presenter(type).collection(this.related(type, row), this.prefill(type, row)),
    );
  }
  metrics(row) {
    const root = this.definition.root;
    if (!row) return el('div');
    const defs =
      {
        purchase: [
          ['NET_AMOUNT', 'Purchase value', true],
          ['PAID_AMOUNT', 'Funded', true],
        ],
        cement_loading: [
          ['QUANTITY', 'Loaded'],
          ['CUSTOMER_QUANTITY', 'Customer allocation'],
          ['WAREHOUSE_QUANTITY', 'Warehouse allocation'],
          ['CLEARED_QUANTITY', 'Cleared'],
        ],
        customer: [
          ['CREDIT_LIMIT', 'Credit limit', true],
          ['PAYMENT_TERMS_DAYS', 'Payment terms · days'],
        ],
        customer_sales: [
          ['NET_AMOUNT', 'Invoice amount', true],
          ['AMOUNT_PAID', 'Received', true],
          ['BALANCE_DUE', 'Outstanding', true],
          ['DELIVERY_STATUS', 'Delivery'],
        ],
        inventory_stock: [
          ['QUANTITY_ON_HAND', 'On hand'],
          ['QUANTITY_RESERVED', 'Reserved'],
          ['QUANTITY_AVAILABLE', 'Available'],
          ['TOTAL_VALUE', 'Stock value', true],
        ],
        trip: [
          ['DESTINATION_COUNT', 'Stops'],
          ['TOTAL_QUANTITY', 'Quantity'],
          ['TOTAL_TRIP_COST', 'Trip cost', true],
        ],
        supplier_incentive: [
          ['ACTUAL_AMOUNT', 'Earned', true],
          ['AMOUNT_RECEIVED', 'Received', true],
        ],
        staff_employment: [['BASIC_SALARY', 'Basic salary', true]],
      }[root] || [];
    const cards = defs
      .filter(([a]) => registry.fields(root)[a])
      .map(([a, title, currency]) =>
        CardRenderer.metric(
          title,
          currency
            ? money(registry.get(root, row.data, a))
            : typeof registry.get(root, row.data, a) === 'number'
              ? number(registry.get(root, row.data, a))
              : registry.get(root, row.data, a) || '—',
          'Authoritative record',
        ),
      );
    if (root === 'purchase') {
      const items = this.context.data.related('purchaseItem', 'PURCHASE_ID', row.recordId);
      for (const [a, title] of [
        ['ORIGINAL_QUANTITY', 'Ordered quantity'],
        ['LOADED_QTY', 'Loaded quantity'],
      ])
        cards.push(
          CardRenderer.metric(
            title,
            number(
              items.reduce(
                (sum, r) => sum + Number(registry.get('purchaseItem', r.data, a) || 0),
                0,
              ),
            ),
            'Purchase items',
          ),
        );
    }
    return el('div', { class: 'grid-stats' }, cards);
  }
}
