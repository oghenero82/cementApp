import { el, button, labelOf, display, notify } from '../core/dom.js';
import { recordFieldRegistry as registry } from '../registry/RecordFieldRegistry.js';
import { CardRenderer } from '../renderers/CardRenderer.js';
import { TableRenderer } from '../renderers/TableRenderer.js';
import { FormRenderer } from '../renderers/FormRenderer.js';
import { RouteLinkBuilder as routes } from '../core/RouteLinkBuilder.js';
export class RecordPresenter {
  constructor(context, type) {
    Object.assign(this, { context, type });
  }
  mount(root) {
    this.root = root;
    root.replaceChildren(this.collection());
  }
  bind() {}
  destroy() {
    this.root = null;
  }
  collection(rows = this.context.data.all(this.type), prefill = {}) {
    const descriptor = this.context.metadata.descriptors[this.type];
    return CardRenderer.panel(
      descriptor.title,
      new TableRenderer(this.context, this.type, rows).render(),
      descriptor.canWrite && !descriptor.serviceOwned
        ? button('+ New record', () => this.edit(null, prefill), 'primary')
        : null,
    );
  }
  detail(row) {
    const descriptor = this.context.metadata.descriptors[this.type];
    const facts = el('dl', { class: 'facts' });
    for (const f of descriptor.fields) {
      const value = registry.get(this.type, row.data, f.alias);
      facts.append(
        el(
          'div',
          {},
          el('dt', {}, labelOf(f.alias)),
          el(
            'dd',
            {},
            f.target && value && this.context.metadata.descriptors[f.target]
              ? el(
                  'a',
                  {
                    class: 'link',
                    href: routes.record(f.target, value),
                    onClick: () => this.context.closeDrawer(),
                  },
                  this.context.data.get(f.target, value)
                    ? this.context.data.label(f.target, this.context.data.get(f.target, value))
                    : '#' + value,
                )
              : display(value),
          ),
        ),
      );
    }
    const actions = el('div', { class: 'actions' });
    const state = registry.fields(this.type).STATUS
      ? registry.get(this.type, row.data, 'STATUS')
      : null;
    if (
      descriptor.canWrite &&
      !descriptor.serviceOwned &&
      ![
        'paid',
        'confirmed',
        'verified',
        'posted',
        'invoiced',
        'completed',
        'received',
        'partially_invoiced',
        'reconciled',
        'closed',
      ].includes(state)
    )
      actions.append(
        button('Edit record', () => this.edit(row), 'primary'),
        button('Delete draft', () => this.remove(row)),
      );
    if (
      this.type === 'trip_destination' &&
      this.context.metadata.descriptors.delivery_proof?.canWrite
    )
      actions.append(
        button(
          'Record delivery proof',
          () =>
            this.context
              .presenter('delivery_proof')
              .edit(null, {
                SALE_ID: registry.get(this.type, row.data, 'SALE_ID'),
                TRIP_DESTINATION_ID: row.recordId,
              }),
          'primary',
        ),
      );
    const related = el('div', { class: 'related-links' });
    for (const [type, desc] of Object.entries(this.context.metadata.descriptors))
      for (const f of desc.fields)
        if (f.target === this.type) {
          const rows = this.context.data.related(type, f.alias, row.recordId);
          related.append(
            el(
              'a',
              {
                href: routes.collection(type),
                class: 'mini-link',
                onClick: () => this.context.closeDrawer(),
              },
              `${desc.title} · ${rows.length}`,
            ),
          );
        }
    if (this.type === 'report_result') {
      for (const format of ['csv', 'xlsx'])
        actions.append(
          el(
            'a',
            {
              class: 'btn',
              href: '/api/reports/result?recordId=' + row.recordId + '&format=' + format,
            },
            'Download snapshot ' + format.toUpperCase(),
          ),
        );
      const rows = registry.get(this.type, row.data, 'ROWS') || [];
      if (rows.length)
        facts.append(
          el('div', { class: 'full' }, this.context.admin.plainTable(rows, Object.keys(rows[0]))),
        );
    }
    return el(
      'div',
      {},
      el(
        'div',
        { class: 'record-meta' },
        el('span', { class: 'tag' }, `#${row.recordId} · Version ${row.version}`),
        el('span', { class: 'tag' }, `Mutation ${row.mutationId}`),
      ),
      facts,
      actions,
      el('h3', {}, 'Related collections'),
      related,
    );
  }
  edit(row, prefill = {}) {
    const form = new FormRenderer(this.context, this.type, row, prefill);
    this.context.drawer(
      (row ? 'Edit ' : 'New ') + this.context.metadata.descriptors[this.type].title,
      form.render(async (values) => {
        const result = await this.context.mutations.save(
          this.type,
          row ? 'update' : 'insert',
          values,
          row,
        );
        notify('Record confirmed · mutation ' + result.mutationId);
        this.context.closeDrawer();
        await this.context.refresh();
      }),
    );
  }
  async remove(row) {
    this.context.confirm(
      'Delete draft?',
      'The server will reject deletion if another record references this draft.',
      async () => {
        await this.context.mutations.save(this.type, 'delete', {}, row);
        notify('Deletion confirmed');
        this.context.closeDrawer();
        await this.context.refresh();
      },
    );
  }
}
