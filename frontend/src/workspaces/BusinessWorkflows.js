import { el, button, labelOf, money, notify } from '../core/dom.js';
import { recordFieldRegistry as registry } from '../registry/RecordFieldRegistry.js';
import { FormRenderer } from '../renderers/FormRenderer.js';
import { CardRenderer } from '../renderers/CardRenderer.js';
const definitions = {
  purchase: {
    title: 'Purchase',
    child: 'purchaseItem',
    parent: 'PURCHASE_ID',
    root: [
      'CODE',
      'SUPPLIER_ID',
      'SUPPLIER_DEPOT_ID',
      'PURCHASE_DATE',
      'SUPPLIER_PIN',
      'SUPPLIER_BIN',
      'PAYMENT_ACCOUNT_ID',
      'PREPAID_ACCOUNT_ID',
      'CURRENCY',
    ],
    lines: [
      'PRODUCT_ID',
      'UNIT_ID',
      'ORIGINAL_QUANTITY',
      'UNIT_PRICE',
      'DISCOUNT_TYPE',
      'DISCOUNT_VALUE',
    ],
  },
  customer_sales: {
    title: 'Warehouse sale',
    child: 'customer_sale_item',
    parent: 'SALE_ID',
    root: ['CODE', 'CUSTOMER_ID', 'SALE_DATE', 'WAREHOUSE_ID', 'CURRENCY'],
    lines: [
      'PRODUCT_ID',
      'UNIT_ID',
      'QUANTITY',
      'AGREED_UNIT_PRICE',
      'DISCOUNT_TYPE',
      'DISCOUNT_VALUE',
      'TAX_RATE',
    ],
    optional: ['AGREED_UNIT_PRICE'],
  },
  trip: {
    title: 'Multi-stop trip',
    child: 'trip_destination',
    parent: 'TRIP_ID',
    root: ['CODE', 'VEHICLE_ID', 'DRIVER_ID', 'LOADING_ID', 'SCHEDULED_DATE'],
    lines: ['SALE_ID', 'PRODUCT_ID', 'QUANTITY', 'UNIT_ID', 'DELIVERY_ADDRESS'],
  },
  payroll: {
    title: 'Payroll batch',
    child: 'payroll_item',
    parent: 'PAYROLL_ID',
    root: ['CODE', 'PERIOD_START', 'PERIOD_END', 'PAYMENT_DATE', 'CURRENCY'],
    lines: [
      'EMPLOYMENT_ID',
      'BASIC_SALARY',
      'OTHER_EARNINGS',
      'TAX_AMOUNT',
      'PENSION_AMOUNT',
      'SAVINGS_DEDUCTION',
      'LOAN_DEDUCTION',
    ],
    optional: ['BASIC_SALARY'],
  },
  inventory_stocktake: {
    title: 'Physical stocktake',
    child: 'inventory_stocktake_line',
    parent: 'STOCKTAKE_ID',
    root: ['CODE', 'LOCATION_ID', 'COUNT_DATE'],
    lines: ['PRODUCT_ID', 'STOCK_CONDITION', 'COUNTED_QTY', 'NOTES'],
  },
};
export class BusinessWorkflows {
  constructor(context) {
    this.context = context;
  }
  supports(type) {
    const d = definitions[type];
    return (
      !!d &&
      this.context.metadata.descriptors[type]?.canWrite &&
      this.context.metadata.descriptors[d.child]?.canWrite
    );
  }
  create(type, prefill = {}) {
    const def = definitions[type];
    if (!this.supports(type)) return;
    const today = new Date().toISOString().slice(0, 10);
    const code = type.slice(0, 4).toUpperCase() + '-' + crypto.randomUUID().slice(0, 8);
    const defaults = {
      CODE: code,
      CURRENCY: this.context.metadata.currency || 'NGN',
      PURCHASE_DATE: today,
      SALE_DATE: today,
      SCHEDULED_DATE: today,
      COUNT_DATE: today + 'T09:00',
      PERIOD_START: today.slice(0, 7) + '-01',
      PERIOD_END: today,
      PAYMENT_DATE: today,
      ...prefill,
    };
    const root = new FormRenderer(this.context, type, null, defaults, { aliases: def.root });
    const rootElement = root.render(() => {});
    rootElement.querySelector('.form-footer').remove();
    rootElement.classList.add('flow-root');
    const lines = el('div', { class: 'flow-lines' });
    const entries = [];
    const error = el('p', { role: 'alert', class: 'error-text' });
    const review = el('div', { class: 'batch-review' });
    const actions = el('div', { class: 'actions' });
    const add = () => {
      const form = new FormRenderer(
        this.context,
        def.child,
        null,
        def.child === 'inventory_stocktake_line' ? { STOCK_CONDITION: 'good' } : {},
        { aliases: def.lines, optional: def.optional },
      );
      const node = form.render(() => {});
      node.querySelector('.form-footer').remove();
      const item = { form };
      const card = el(
        'section',
        { class: 'card card-pad flow-line' },
        el(
          'div',
          { class: 'card-head' },
          el('h3', {}, 'Line / stop ' + (entries.length + 1)),
          button('Remove line', () => {
            entries.splice(entries.indexOf(item), 1);
            card.remove();
            review.replaceChildren();
          }),
        ),
        node,
      );
      entries.push(item);
      lines.append(card);
      review.replaceChildren();
    };
    const save = async () => {
      error.textContent = '';
      try {
        if (!rootElement.reportValidity()) return;
        for (const entry of entries) if (!entry.form.form.reportValidity()) return;
        if (!entries.length) throw new Error('Add at least one line');
        const header = { STATUS: 'draft', ...root.values() };
        const rows = entries.map((entry, index) => ({
          CODE: header.CODE + '-' + (index + 1),
          STATUS: 'draft',
          ...entry.form.values(),
          [def.parent]: { $ref: 'root' },
          ...(def.child === 'trip_destination' ? { DESTINATION_ORDER: index + 1 } : {}),
        }));
        review.replaceChildren(
          el('h3', {}, 'Review before creating the draft'),
          el('p', {}, def.title + ' ' + header.CODE + ' · ' + rows.length + ' connected records'),
          el(
            'p',
            { class: 'muted' },
            'The server will validate and save the entire batch in one transaction. Funding and posting require a separate confirmation.',
          ),
          button(
            'Create ' + def.title.toLowerCase() + ' draft',
            async () => {
              try {
                const result = await this.context.mutations.batch([
                  { type, semantic: header, clientRef: 'root' },
                  ...rows.map((semantic) => ({ type: def.child, semantic })),
                ]);
                this.context.closeDrawer();
                notify(def.title + ' draft confirmed');
                await this.context.refresh();
                location.hash = '#records/' + type + '/' + result.resultMap.root;
              } catch (e) {
                error.textContent = e.message;
                if (e.ambiguous)
                  review.replaceChildren(
                    el(
                      'p',
                      {},
                      'Request awaiting confirmation; retry its original identity in Synchronization.',
                    ),
                  );
              }
            },
            'primary',
          ),
        );
      } catch (e) {
        error.textContent = e.message;
      }
    };
    actions.append(
      button('+ Add line / stop', add, 'secondary'),
      button('Review batch', save, 'primary'),
    );
    add();
    this.context.drawer(
      'New ' + def.title.toLowerCase(),
      el(
        'div',
        { class: 'business-flow' },
        el('p', { class: 'muted' }, 'Create the parent and its connected records together.'),
        rootElement,
        el('h3', {}, 'Connected lines / destinations'),
        lines,
        error,
        actions,
        review,
      ),
    );
  }
  actions(key, row) {
    const host = el('div', { class: 'operational-actions' });
    const root = this.context.navigation.workspaces[key].root;
    const add = (label, type, prefill = {}) => {
      if (this.context.metadata.descriptors[type]?.canWrite)
        host.append(
          button(label, () => this.context.presenter(type).edit(null, prefill), 'secondary'),
        );
    };
    const create = (label, type, prefill = {}) => {
      if (this.supports(type))
        host.append(button(label, () => this.create(type, prefill), 'primary'));
    };
    if (key === 'purchase') create('New purchase with items', 'purchase');
    if (key === 'sales' || key === 'customer')
      create(
        'New warehouse sale',
        'customer_sales',
        key === 'customer' && row ? { CUSTOMER_ID: row.recordId } : {},
      );
    if (key === 'trip') create('Plan trip with stops', 'trip');
    if (key === 'staff') create('Create payroll with staff', 'payroll');
    if (key === 'warehouse') {
      create(
        'Start stocktake with count lines',
        'inventory_stocktake',
        row ? { LOCATION_ID: registry.get(root, row.data, 'LOCATION_ID') } : {},
      );
      add('Reserve stock for an order', 'inventory_reservation', { STATUS: 'active' });
    }
    if (!row) return host;
    if (key === 'customer') {
      host.append(button('Customer statement', () => this.statement(row.recordId), 'primary'));
      add('Receive customer payment', 'customer_payment', {
        CUSTOMER_ID: row.recordId,
        STATUS: 'completed',
      });
    }
    const transition = {
      purchase: ['Fund purchase', 'paid'],
      loading: ['Confirm loading', 'confirmed'],
      sales: ['Invoice sale', 'invoiced'],
      trip: ['Complete trip', 'completed'],
      incentives: ['Verify benefit', 'verified'],
    }[key];
    if (
      transition &&
      this.context.metadata.descriptors[root].postingAvailable !== false &&
      this.context.metadata.descriptors[root].canWrite &&
      registry.get(root, row.data, 'STATUS') === 'draft'
    )
      host.append(
        button(
          transition[0],
          () => this.transition(root, row, transition[1], transition[0]),
          'primary',
        ),
      );
    if (key === 'sales') {
      add('Receive sale payment', 'customer_payment', {
        SALE_ID: row.recordId,
        CUSTOMER_ID: registry.get(root, row.data, 'CUSTOMER_ID'),
        STATUS: 'completed',
      });
      add('Reserve this order', 'inventory_reservation', {
        SALE_ID: row.recordId,
        LOCATION_ID: registry.get(root, row.data, 'WAREHOUSE_ID'),
        STATUS: 'active',
      });
      add('Capture delivery proof', 'delivery_proof', {
        SALE_ID: row.recordId,
        STATUS: 'confirmed',
      });
    }
    if (key === 'purchase') add('Load this purchase', 'cement_loading', {});
    return host;
  }
  transition(type, row, state, label) {
    const code = this.context.data.label(type, row);
    const amount = registry.fields(type).NET_AMOUNT
      ? registry.get(type, row.data, 'NET_AMOUNT')
      : null;
    this.context.confirm(
      label + '?',
      code +
        (amount !== null ? ' · ' + money(amount) : '') +
        '. This operation is validated and posted by the server.',
      async () => {
        await this.context.mutations.save(type, 'update', { STATUS: state }, row);
        this.context.closeDrawer();
        notify(label + ' confirmed');
        await this.context.refresh();
      },
    );
  }
  async statement(customerId) {
    const root = el('div');
    this.context.drawer('Customer statement', root);
    const from = el('input', { type: 'date', 'aria-label': 'Statement from' }),
      to = el('input', { type: 'date', 'aria-label': 'Statement to' });
    const body = el('div');
    const load = async () => {
      try {
        const statement = await this.context.api.request('customers/statement', undefined, {
          customerId,
          from: from.value,
          to: to.value,
        });
        body.replaceChildren(
          el(
            'div',
            { class: 'grid-stats' },
            CardRenderer.metric('Opening receivable', money(statement.openingReceivable)),
            CardRenderer.metric('Closing receivable', money(statement.closingReceivable)),
            CardRenderer.metric('Opening deposit', money(statement.openingDeposit)),
            CardRenderer.metric('Remaining deposit', money(statement.closingDeposit)),
          ),
          this.context.admin.plainTable(statement.rows, statement.columns),
        );
      } catch (error) {
        body.replaceChildren(el('p', { role: 'alert' }, error.message));
      }
    };
    root.append(
      el(
        'div',
        { class: 'actions' },
        from,
        to,
        button('Apply statement period', load),
        ...['csv', 'xlsx'].map((format) =>
          button('Statement ' + format.toUpperCase(), () => {
            const q = new URLSearchParams({ customerId, from: from.value, to: to.value, format });
            location.assign('/api/customers/statement?' + q);
          }),
        ),
      ),
      body,
    );
    await load();
  }
}
