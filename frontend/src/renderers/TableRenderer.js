import { el, button, labelOf, display } from '../core/dom.js';
import { recordFieldRegistry as registry } from '../registry/RecordFieldRegistry.js';
import { RouteLinkBuilder as routes } from '../core/RouteLinkBuilder.js';
import { CardRenderer } from './CardRenderer.js';
export class TableRenderer {
  constructor(context, type, rows, columns) {
    Object.assign(this, { context, type, rows });
    this.columns =
      columns ||
      context.metadata.descriptors[type].fields
        .map((f) => f.alias)
        .filter((a) => !['UNIT_CONVERSION_SNAPSHOT', 'ALLOCATION_ROWS'].includes(a))
        .slice(0, 6);
    this.page = 0;
    this.query = '';
    this.state = '';
  }
  render() {
    this.root = el('div');
    const input = el('input', {
      type: 'search',
      placeholder: 'Search records…',
      'aria-label': 'Search records',
      onInput: () => {
        this.query = input.value;
        this.page = 0;
        this.body();
      },
    });
    const filter = el(
      'select',
      {
        'aria-label': 'Filter by state',
        onChange: () => {
          this.state = filter.value;
          this.page = 0;
          this.body();
        },
      },
      el('option', { value: '' }, 'All states'),
    );
    const F = registry.fields(this.type);
    if (F.STATUS)
      for (const state of new Set(this.rows.map((r) => r.data[F.STATUS])))
        filter.append(el('option', { value: state }, state));
    this.tableHost = el('div', { class: 'table-overflow' });
    this.footer = el('div', { class: 'table-footer' });
    this.root.append(
      el(
        'div',
        { class: 'table-tools' },
        input,
        filter,
        el('span', { class: 'tag' }, `${this.rows.length} records`),
      ),
      this.tableHost,
      this.footer,
    );
    this.body();
    return this.root;
  }
  body() {
    const F = registry.fields(this.type);
    const visible = this.rows.filter(
      (r) =>
        (!this.state || r.data[F.STATUS] === this.state) &&
        Object.values(r.data).some((v) =>
          display(v).toLowerCase().includes(this.query.toLowerCase()),
        ),
    );
    const page = visible.slice(this.page * 12, this.page * 12 + 12);
    if (!page.length) {
      this.tableHost.replaceChildren(
        CardRenderer.empty(
          'No matching records',
          this.rows.length ? 'Try another search or filter.' : 'Create the first record to begin.',
        ),
      );
      this.footer.replaceChildren();
      return;
    }
    const table = el(
      'table',
      { class: 'panel-table' },
      el(
        'thead',
        {},
        el(
          'tr',
          {},
          ...this.columns.map((a) =>
            el('th', { scope: 'col', 'data-field': registry.field(this.type, a) }, labelOf(a)),
          ),
          el('th', { scope: 'col' }, 'Open'),
        ),
      ),
    );
    const body = el('tbody');
    for (const row of page) {
      const tr = el('tr');
      for (const alias of this.columns) {
        const value = row.data[registry.field(this.type, alias)];
        const descriptor = this.context.metadata.descriptors[this.type].fields.find(
          (f) => f.alias === alias,
        );
        const td = el('td', { 'data-field': registry.field(this.type, alias) });
        if (alias === 'STATUS' || alias === 'DELIVERY_STATUS')
          td.append(el('span', { class: 'pill-status' }, display(value)));
        else if (
          descriptor?.target &&
          value &&
          this.context.metadata.descriptors[descriptor.target]
        )
          td.append(
            el(
              'a',
              { class: 'link', href: routes.record(descriptor.target, value) },
              this.context.data.get(descriptor.target, value)
                ? this.context.data.label(
                    descriptor.target,
                    this.context.data.get(descriptor.target, value),
                  )
                : '#' + value,
            ),
          );
        else td.append(display(value));
        tr.append(td);
      }
      tr.append(
        el(
          'td',
          {},
          button('View →', () => this.context.openRecord(this.type, row), 'text-btn'),
        ),
      );
      body.append(tr);
    }
    table.append(body);
    this.tableHost.replaceChildren(table);
    this.footer.replaceChildren(
      el(
        'span',
        {},
        `${this.page * 12 + 1}–${Math.min((this.page + 1) * 12, visible.length)} of ${visible.length}`,
      ),
      el(
        'div',
        { class: 'actions' },
        button('Previous', () => {
          if (this.page > 0) {
            this.page--;
            this.body();
          }
        }),
        button('Next', () => {
          if ((this.page + 1) * 12 < visible.length) {
            this.page++;
            this.body();
          }
        }),
      ),
    );
  }
}
