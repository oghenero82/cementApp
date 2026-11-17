import { datetimeInput, datetimeInstant } from '../core/datetime.js';
import { SignaturePad } from '../core/SignaturePad.js';
import { el, button, labelOf, display } from '../core/dom.js';
import { recordFieldRegistry as registry } from '../registry/RecordFieldRegistry.js';
export class FormRenderer {
  constructor(context, type, row, prefill = {}, options = {}) {
    Object.assign(this, { context, type, row, prefill, options });
    this.controls = new Map();
  }
  render(onSubmit) {
    const descriptor = this.context.metadata.descriptors[this.type];
    this.form = el('form', {
      class: 'record-form',
      onSubmit: async (event) => {
        event.preventDefault();
        this.showErrors({});
        try {
          await onSubmit(this.values());
        } catch (error) {
          this.showErrors(error.fields || {});
          this.summary.textContent = error.message;
          this.summary.focus();
          if (error.ambiguous) {
            this.form.querySelector('button[type=submit]').disabled = true;
            this.summary.append(
              el(
                'p',
                {},
                'This request is awaiting confirmation. Retry it from Synchronization with its original request ID.',
              ),
              button('Open Synchronization', () => this.context.syncPanel()),
            );
          }
        }
      },
    });
    this.summary = el('div', { class: 'form-summary', role: 'alert', tabIndex: -1 });
    this.form.append(this.summary);
    const fields = el('div', { class: 'form-grid' });
    const readonly = new Set(descriptor.readOnlyAliases || []);
    for (const field of descriptor.fields) {
      if (this.options.aliases && !this.options.aliases.includes(field.alias)) continue;
      const { alias } = field;
      if (readonly.has(alias)) continue;
      const code = registry.field(this.type, alias);
      const initial = this.row?.data[code] ?? this.prefill[alias] ?? field.default;
      const wrap = el('div', { class: 'form-field' });
      const id = 'field-' + this.type + '-' + alias;
      const label = el(
        'label',
        { htmlFor: id },
        labelOf(alias),
        field.required ? el('span', { class: 'required' }, ' *') : null,
      );
      let control;
      if (alias === 'SIGNATURE') {
        const pad = new SignaturePad(this.context.api, this.type, initial);
        const fallback = this.attachment(initial, false);
        control = el(
          'div',
          {},
          pad.render(),
          el('details', {}, el('summary', {}, 'Upload an existing signed document'), fallback),
        );
        control.customRead = () =>
          pad.cleared || pad.dirty || pad.drawn ? pad.value() : fallback.customRead();
        wrap.classList.add('full');
      } else if (['SIGNATURE', 'PHOTOS', 'PHOTO_EVIDENCE', 'SUPPLIER_DOCUMENT'].includes(alias)) {
        control = this.attachment(initial, field.type === 'array');
        wrap.classList.add('full');
      } else if (this.type === 'cement_loading' && alias === 'ALLOCATION_ROWS') {
        control = this.allocations(initial || []);
        wrap.classList.add('full');
      } else if (field.multiple && field.target) {
        control = el('select', { id, name: code, multiple: true, size: 5 });
        const initialIds = new Set((initial || []).map(String));
        if (this.context.metadata.descriptors[field.target])
          for (const row of this.context.data.all(field.target))
            control.append(
              el(
                'option',
                { value: row.recordId, selected: initialIds.has(String(row.recordId)) },
                this.context.data.label(field.target, row),
              ),
            );
        control.customRead = () =>
          [...control.selectedOptions].map((option) => Number(option.value));
        this.context.api
          .request('lookups', undefined, { recordTypeCode: this.type, alias })
          .then((result) => {
            const selected = new Set([...control.selectedOptions].map((option) => option.value));
            control.replaceChildren(
              ...result.items.map((item) =>
                el(
                  'option',
                  { value: item.id, selected: selected.has(String(item.id)) },
                  item.label,
                ),
              ),
            );
          })
          .catch((error) => {
            wrap.querySelector('.field-error').textContent = error.message;
          });
      } else if (alias === 'STATUS') {
        const states = this.states();
        control = el(
          'select',
          {},
          ...states.map((v) => el('option', { value: v, selected: v === (initial || 'draft') }, v)),
        );
      } else if (field.type === 'reference') {
        control = el('select', {}, el('option', { value: '' }, `Select ${labelOf(alias)}…`));
        if (this.context.metadata.descriptors[field.target])
          for (const row of this.context.data.all(field.target))
            control.append(
              el(
                'option',
                { value: row.recordId, selected: String(row.recordId) === String(initial) },
                this.context.data.label(field.target, row),
              ),
            );
        else if (initial !== undefined) {
          control.append(el('option', { value: initial, selected: true }, '#' + initial));
        }
        // Resolve reference labels through a permission-scoped lookup, including shared targets.
        this.context.api
          .request('lookups', undefined, { recordTypeCode: this.type, alias })
          .then((result) => {
            const selected = control.value;
            control.replaceChildren(
              el('option', { value: '' }, `Select ${labelOf(alias)}…`),
              ...result.items.map((item) =>
                el(
                  'option',
                  { value: item.id, selected: String(item.id) === String(selected) },
                  item.label,
                ),
              ),
            );
          })
          .catch((error) => {
            wrap.querySelector('.field-error').textContent = error.message;
          });
      } else if (Array.isArray(field.options) && field.options.length) {
        control = el(
          'select',
          {},
          ...(field.required ? [] : [el('option', { value: '' }, 'Select…')]),
          ...field.options.map((value) =>
            el('option', { value, selected: String(value) === String(initial) }, String(value)),
          ),
        );
      } else if (field.type === 'boolean') {
        control = el('input', { type: 'checkbox', checked: initial === true });
      } else if (['array', 'object'].includes(field.type)) {
        control = el('textarea', {
          rows: 4,
          value: initial === undefined ? '' : JSON.stringify(initial, null, 2),
          placeholder: field.type === 'array' ? '[]' : '{}',
        });
        wrap.classList.add('full');
      } else {
        control = el('input', {
          type: ['number', 'integer'].includes(field.type)
            ? 'number'
            : field.type === 'date'
              ? 'date'
              : field.type === 'datetime'
                ? 'datetime-local'
                : 'text',
          step: field.type === 'integer' ? '1' : 'any',
          value:
            initial === undefined
              ? ''
              : field.type === 'datetime'
                ? datetimeInput(initial)
                : display(initial),
        });
      }
      if (!control.customRead) {
        control.id = id;
        control.name = code;
        control.required =
          field.required &&
          field.type !== 'boolean' &&
          !control.disabled &&
          !this.options.optional?.includes(alias);
      }
      this.controls.set(alias, { control, field, wrap });
      wrap.append(label, control, el('small', { class: 'field-error', id: id + '-error' }));
      if (field.description) wrap.append(el('small', { class: 'field-help' }, field.description));
      fields.append(wrap);
    }
    this.form.append(
      fields,
      el(
        'div',
        { class: 'form-footer' },
        el(
          'p',
          { class: 'muted' },
          'Changes are confirmed only after the server validates and commits them.',
        ),
        el('button', { class: 'btn primary', type: 'submit' }, 'Save record'),
      ),
    );
    return this.form;
  }
  states() {
    const allowed = this.context.metadata.descriptors[this.type]?.allowedStates;
    if (allowed) return allowed;
    return (
      {
        inventory_reservation: ['active', 'released'],
        damaged_goods: ['draft', 'confirmed'],
        purchase_adjustment: ['draft', 'posted'],
        fiscal_year: ['draft', 'active', 'closed'],
        journal: ['draft', 'posted'],
        journal_line: ['draft'],
        bank_reconciliation: ['draft', 'confirmed'],
        cooperative_transaction: ['draft', 'posted'],
        purchaseItem: ['draft'],
        customer_sale_item: ['draft'],
        payroll_item: ['draft'],
        purchase: ['draft', 'paid'],
        cement_loading: ['draft', 'pending', 'confirmed'],
        loading_clearance: ['draft', 'verified'],
        supplier_purchase_payment: ['draft', 'completed'],
        customer_sales: ['draft', 'invoiced'],
        customer_payment: ['draft', 'completed'],
        customer_sale_return: ['draft', 'confirmed'],
        delivery_proof: ['draft', 'confirmed'],
        inventory_movement: ['draft', 'confirmed'],
        inventory_stocktake: ['draft', 'posted'],
        stock_adjustment: ['draft', 'confirmed'],
        inventory_transfer: ['draft', 'received'],
        trip: ['draft', 'pending', 'completed'],
        fuel_transaction: ['draft', 'posted'],
        vehicle_maintenance: ['draft', 'posted'],
        trip_expense: ['draft', 'posted'],
        expense: ['draft', 'posted'],
        supplier_incentive: ['draft', 'verified'],
        supplier_incentive_receipt: ['draft', 'completed'],
        payroll: ['draft', 'posted'],
        payroll_payment: ['draft', 'completed'],
      }[this.type] || ['draft', 'active', 'inactive']
    );
  }
  values() {
    const values = {};
    for (const [alias, { control, field }] of this.controls) {
      if (control.disabled) continue;
      let value;
      if (control.customRead) {
        value = control.customRead();
        if (value === undefined) continue;
      } else if (field.type === 'boolean') value = control.checked;
      else if (control.value === '') continue;
      else if (['number', 'integer', 'reference'].includes(field.type))
        value = Number(control.value);
      else if (field.type === 'datetime') value = datetimeInstant(control.value);
      else if (['array', 'object'].includes(field.type)) value = JSON.parse(control.value);
      else value = control.value;
      values[alias] = value;
    }
    return values;
  }
  showErrors(errors) {
    for (const [alias, { control, wrap }] of this.controls) {
      const message = errors[registry.field(this.type, alias)] || '';
      wrap.querySelector('.field-error').textContent = message;
      control.setAttribute('aria-invalid', String(!!message));
    }
  }
  attachment(initial, multiple) {
    const host = el('div', { class: 'attachment-editor' });
    let refs = multiple ? initial || [] : initial ? [initial] : [];
    const list = el('div');
    const upload = el('input', {
      type: 'file',
      accept: 'image/png,image/jpeg,image/webp,application/pdf',
      multiple,
      'aria-label': 'Upload attachment',
    });
    const draw = () =>
      list.replaceChildren(
        ...refs.map((ref) =>
          el(
            'a',
            {
              class: 'mini-link',
              href: '/api/files/' + ref.attachmentId,
              target: '_blank',
              rel: 'noopener',
            },
            ref.filename || 'Attachment',
          ),
        ),
      );
    draw();
    upload.addEventListener('change', async () => {
      upload.disabled = true;
      try {
        const uploaded = [];
        for (const file of upload.files) {
          const result = await this.context.api.upload(file, this.type);
          uploaded.push(result.reference);
        }
        refs = multiple ? [...refs, ...uploaded] : uploaded.slice(0, 1);
        draw();
      } catch (error) {
        this.summary.textContent = error.message;
      } finally {
        upload.disabled = false;
      }
    });
    host.append(list, upload);
    host.customRead = () => (multiple ? refs : refs[0]);
    return host;
  }
  allocations(initial) {
    const host = el('div', { class: 'allocation-editor' });
    const list = el('div');
    const entries = [];
    const add = (row) => {
      const cf = registry.fields('customer_sales'),
        lf = registry.fields('cement_loading'),
        sf = registry.fields('customer_sale_item');
      const kind = el(
        'select',
        { 'aria-label': 'Destination kind' },
        el('option', { value: 'customer' }, 'Customer'),
        el('option', { value: 'warehouse' }, 'Warehouse'),
      );
      const target = el('select', { 'aria-label': 'Destination' });
      const quantity = el('input', {
        type: 'number',
        min: '0.000001',
        step: 'any',
        placeholder: 'Quantity',
        'aria-label': 'Allocated quantity',
        value: row?.[lf.QUANTITY] ?? '',
      });
      const price = el('input', {
        type: 'number',
        min: 0,
        step: '0.01',
        placeholder: 'Agreed unit price',
        'aria-label': 'Agreed unit price',
        value: row?.[sf.AGREED_UNIT_PRICE] ?? '',
      });
      kind.value = row?.[cf.WAREHOUSE_ID] ? 'warehouse' : 'customer';
      const choices = () => {
        target.replaceChildren(el('option', { value: '' }, 'Choose destination…'));
        const type = kind.value === 'customer' ? 'customer' : 'inventory_location';
        for (const r of this.context.data.all(type))
          target.append(el('option', { value: r.recordId }, this.context.data.label(type, r)));
        target.value = row?.[kind.value === 'customer' ? cf.CUSTOMER_ID : cf.WAREHOUSE_ID] ?? '';
        price.hidden = kind.value !== 'customer';
      };
      kind.addEventListener('change', choices);
      choices();
      const entry = { kind, target, quantity, price };
      entries.push(entry);
      const item = el(
        'div',
        { class: 'allocation-row' },
        kind,
        target,
        quantity,
        price,
        button(
          'Remove',
          () => {
            entries.splice(entries.indexOf(entry), 1);
            item.remove();
          },
          'text-btn',
        ),
      );
      list.append(item);
    };
    for (const row of initial) add(row);
    if (!initial.length) add();
    host.append(
      list,
      button('+ Add destination', () => add(), 'secondary'),
      el(
        'small',
        { class: 'field-help' },
        'Allocate the entire load. Customer prices resolve from dated price lists; manual overrides follow institution policy.',
      ),
    );
    host.customRead = () =>
      entries.map((e) => {
        const data = {
          [registry.field('cement_loading', 'QUANTITY')]: Number(e.quantity.value),
          [registry.field(
            'customer_sales',
            e.kind.value === 'customer' ? 'CUSTOMER_ID' : 'WAREHOUSE_ID',
          )]: Number(e.target.value),
        };
        if (e.kind.value === 'customer' && e.price.value !== '')
          data[registry.field('customer_sale_item', 'AGREED_UNIT_PRICE')] = Number(e.price.value);
        return data;
      });
    return host;
  }
}
