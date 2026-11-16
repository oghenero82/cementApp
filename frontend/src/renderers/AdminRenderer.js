import { el, button, notify, labelOf, display, money } from '../core/dom.js';
import { parseCSV, convertCSV } from '../core/Importer.js';
import { recordFieldRegistry as registry } from '../registry/RecordFieldRegistry.js';
import { CardRenderer } from './CardRenderer.js';
export class AdminRenderer {
  constructor(context) {
    this.context = context;
  }
  async load(root, fn) {
    root.replaceChildren(el('p', { class: 'muted' }, 'Loading…'));
    try {
      root.replaceChildren(await fn());
    } catch (e) {
      root.replaceChildren(el('p', { role: 'alert', class: 'error-text' }, e.message));
    }
  }
  async users(root) {
    return this.load(root, async () => {
      const result = await this.context.api.request('users');
      const email = el('input', {
        type: 'email',
        required: true,
        placeholder: 'Email',
        'aria-label': 'New user email',
      });
      const name = el('input', {
        required: true,
        placeholder: 'Display name',
        'aria-label': 'Display name',
      });
      const password = el('input', {
        type: 'password',
        required: true,
        minLength: 12,
        placeholder: 'Temporary password · 12+ characters',
        'aria-label': 'Temporary password',
        autoComplete: 'new-password',
      });
      const role = el(
        'select',
        { 'aria-label': 'Role' },
        ...Object.entries(result.roles).map(([key, value]) =>
          el('option', { value: key }, value.name),
        ),
      );
      const person = el(
        'select',
        { 'aria-label': 'Linked driver person' },
        el('option', { value: '' }, 'Linked person (for driver access)…'),
        ...this.context.data
          .all('person')
          .map((row) =>
            el('option', { value: row.recordId }, this.context.data.label('person', row)),
          ),
      );
      const form = el(
        'form',
        {
          class: 'inline-form',
          onSubmit: async (e) => {
            e.preventDefault();
            try {
              await this.context.api.request('users', {
                email: email.value,
                displayName: name.value,
                password: password.value,
                role: role.value,
                personId: person.value ? Number(person.value) : null,
              });
              notify('User created');
              this.users(root);
            } catch (error) {
              notify(error.message, true);
            }
          },
        },
        name,
        email,
        password,
        role,
        person,
        el('button', { class: 'btn primary' }, 'Create user'),
      );
      return CardRenderer.panel(
        'Users & role access',
        el(
          'div',
          {},
          this.plainTable(result.users, ['display_name', 'email', 'role', 'active']),
          form,
          button('Edit role permissions', () => this.permissions(root), 'secondary'),
        ),
      );
    });
  }
  async permissions(root) {
    return this.load(root, async () => {
      let policy = await this.context.api.request('permissions');
      const selector = el(
        'select',
        { 'aria-label': 'Permission role' },
        ...Object.entries(policy.roles).map(([code, role]) =>
          el('option', { value: code }, role.title),
        ),
      );
      const editor = el('div');
      const render = () => {
        const role = policy.roles[selector.value],
          rights = {},
          modules = {};
        const table = el(
          'table',
          { class: 'data-table' },
          el(
            'thead',
            {},
            el('tr', {}, ...['Collection', 'Read', 'Write'].map((t) => el('th', {}, t))),
          ),
        );
        const body = el('tbody');
        for (const [type, grant] of Object.entries(role.permissions)) {
          const locked = role.locked || (grant.private && selector.value !== 'cement_accountant');
          const read = el('input', {
            type: 'checkbox',
            checked: grant.read,
            disabled: locked,
            'aria-label': grant.title + ' read',
          });
          const write = el('input', {
            type: 'checkbox',
            checked: grant.write,
            disabled: locked || grant.computed,
            'aria-label': grant.title + ' write',
          });
          write.addEventListener('change', () => {
            if (write.checked) read.checked = true;
          });
          read.addEventListener('change', () => {
            if (!read.checked) write.checked = false;
          });
          rights[type] = { read, write };
          body.append(
            el('tr', {}, el('td', {}, grant.title), el('td', {}, read), el('td', {}, write)),
          );
        }
        table.append(body);
        const moduleList = el('div', { class: 'actions' });
        for (const module of policy.modules) {
          const input = el('input', {
            type: 'checkbox',
            checked: role.modules.includes(module.code),
            disabled: role.locked,
          });
          modules[module.code] = input;
          moduleList.append(el('label', {}, input, module.name));
        }
        editor.replaceChildren(
          el(
            'p',
            { class: 'muted' },
            'Administrator recovery privileges and confidential payroll restrictions are enforced by the server.',
          ),
          moduleList,
          el('div', { class: 'table-scroll' }, table),
        );
        if (!role.locked)
          editor.append(
            button(
              'Save role permissions',
              async () => {
                try {
                  policy = await this.context.api.request('permissions', {
                    revision: policy.revision,
                    role: selector.value,
                    permissions: Object.fromEntries(
                      Object.entries(rights).map(([type, grant]) => [
                        type,
                        { read: grant.read.checked, write: grant.write.checked },
                      ]),
                    ),
                    modules: Object.keys(modules).filter((key) => modules[key].checked),
                  });
                  notify(
                    'Role permissions saved; affected sessions reload their authorized scope on refresh.',
                  );
                  render();
                } catch (error) {
                  notify(error.message, true);
                }
              },
              'primary',
            ),
          );
      };
      selector.addEventListener('change', render);
      render();
      return CardRenderer.panel(
        'Role permissions',
        el(
          'div',
          {},
          button('Back to users', () => this.users(root)),
          selector,
          editor,
        ),
      );
    });
  }
  async settings(root) {
    return this.load(root, async () => {
      const result = await this.context.api.request('settings');
      const controls = {};
      const form = el('form', {
        class: 'form-grid',
        onSubmit: async (e) => {
          e.preventDefault();
          const values = {};
          for (const [key, { input, def }] of Object.entries(controls))
            values[key] = def.type === 'boolean' ? input.checked : input.value;
          try {
            await this.context.api.request('settings', values);
            notify('Policies saved');
          } catch (error) {
            notify(error.message, true);
          }
        },
      });
      for (const [key, def] of Object.entries(result.definitions)) {
        if (def.type === 'array') continue;
        const input = def.enum
          ? el(
              'select',
              {},
              ...def.enum.map((v) =>
                el('option', { value: v, selected: v === result.values[key] }, v),
              ),
            )
          : def.type === 'boolean'
            ? el('input', { type: 'checkbox', checked: result.values[key] })
            : el('input', { value: result.values[key] });
        controls[key] = { input, def };
        const id = 'policy-' + key;
        input.id = id;
        form.append(
          el(
            'div',
            { class: 'form-field' },
            el('label', { htmlFor: id }, key.replace(/([A-Z])/g, ' $1')),
            input,
          ),
        );
      }
      form.append(el('button', { class: 'btn primary' }, 'Save policies'));
      return CardRenderer.panel('Institution policies', form);
    });
  }
  async ledger(root) {
    return this.load(root, async () => {
      const result = await this.context.api.request('ledger');
      return CardRenderer.panel(
        'General ledger · paired postings',
        el(
          'div',
          {},
          el(
            'div',
            { class: 'grid-stats' },
            CardRenderer.metric('Total debits', money(result.totalDebits)),
            CardRenderer.metric('Total credits', money(result.totalCredits)),
            CardRenderer.metric('Difference', money(result.difference)),
          ),
          this.context.presenter('journal').collection(),
          this.context.presenter('journal_line').collection(),
        ),
      );
    });
  }
  async audit(root) {
    return this.load(root, async () => {
      const result = await this.context.api.request('audit');
      return CardRenderer.panel(
        'Mutation audit',
        this.plainTable(result.events, ['created_at', 'actor_id', 'request_id', 'event']),
      );
    });
  }
  async importer(root) {
    return this.load(root, async () => {
      const history = await this.context.api.request('imports');
      const selector = el(
        'select',
        { 'aria-label': 'Import RecordType' },
        ...Object.entries(this.context.metadata.descriptors)
          .filter(([, d]) => d.canWrite && !d.serviceOwned)
          .map(([t, d]) => el('option', { value: t }, d.title)),
      );
      const period = el('input', {
        type: 'month',
        required: true,
        'aria-label': 'Upload period',
        value: new Date().toISOString().slice(0, 7),
      });
      const file = el('input', {
        type: 'file',
        accept:
          '.csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        required: true,
        'aria-label': 'CSV or XLSX file',
      });
      const preview = el('div');
      const headers = el('p', { class: 'field-help' });
      let rows = null;
      let digestFile = null;
      let sourceFileDigest = null;
      const hint = () => {
        rows = null;
        preview.replaceChildren();
        const d = this.context.metadata.descriptors[selector.value];
        headers.textContent =
          'CSV headers use semantic aliases: ' +
          d.fields
            .filter((f) => !d.readOnlyAliases.includes(f.alias))
            .map((f) => f.alias)
            .join(', ');
      };
      selector.addEventListener('change', hint);
      hint();
      const review = async () => {
        try {
          if (!file.files[0]) throw new Error('Choose a CSV or XLSX file');
          if (file.files[0].size > 2 * 1024 * 1024) throw new Error('Spreadsheet exceeds 2 MB');
          const raw = file.files[0].name.toLowerCase().endsWith('.xlsx')
            ? (await this.context.api.upload(file.files[0], selector.value, 'imports/preview')).rows
            : parseCSV(await file.files[0].text());
          rows = convertCSV(
            raw,
            this.context.metadata.descriptors[selector.value],
            registry,
            selector.value,
          );
          if (rows.length > 100) throw new Error('Import at most 100 rows per upload');
          digestFile = file.files[0].name;
          sourceFileDigest = [
            ...new Uint8Array(
              await crypto.subtle.digest('SHA-256', await file.files[0].arrayBuffer()),
            ),
          ]
            .map((b) => b.toString(16).padStart(2, '0'))
            .join('');
          preview.replaceChildren(
            el('h3', {}, 'Review ' + rows.length + ' rows'),
            this.plainTable(rows.slice(0, 10), Object.keys(rows[0] || {})),
            button(
              'Confirm import',
              async () => {
                try {
                  const result = await this.context.api.request('imports', {
                    recordTypeCode: selector.value,
                    registryRevision: registry.revision,
                    period: period.value,
                    filename: digestFile,
                    sourceFileDigest,
                    rows,
                  });
                  notify(
                    result.outcomes.filter((r) => r.state === 'confirmed').length +
                      ' rows confirmed',
                  );
                  preview.replaceChildren(
                    this.plainTable(result.outcomes, ['row', 'state', 'message']),
                  );
                  await this.context.sync.run();
                  this.context.data.replace(await this.context.cache.all());
                } catch (error) {
                  notify(error.message, true);
                }
              },
              'primary',
            ),
          );
        } catch (error) {
          notify(error.message, true);
        }
      };
      file.addEventListener('change', () => {
        rows = null;
        preview.replaceChildren();
      });
      return CardRenderer.panel(
        'System importer & monthly upload history',
        el(
          'div',
          {},
          el(
            'p',
            { class: 'muted' },
            'Preview CSV or first-worksheet XLSX values before importing. Each row uses a deterministic request ID. Re-uploading identical rows for the same period is idempotent.',
          ),
          el(
            'div',
            { class: 'import-tools' },
            selector,
            period,
            file,
            button('Preview & validate', review, 'secondary'),
          ),
          headers,
          preview,
          el('h3', {}, 'Upload history'),
          this.plainTable(history.uploads, [
            'period',
            'filename',
            'source_file_digest',
            'digest',
            'outcomes',
            'created_at',
          ]),
        ),
      );
    });
  }
  plainTable(rows, keys) {
    if (!rows.length)
      return CardRenderer.empty('No activity yet', 'Confirmed activity will appear here.');
    return el(
      'div',
      { class: 'table-overflow' },
      el(
        'table',
        { class: 'panel-table' },
        el('thead', {}, el('tr', {}, ...keys.map((key) => el('th', {}, labelOf(key))))),
        el(
          'tbody',
          {},
          ...rows.map((row) => el('tr', {}, ...keys.map((key) => el('td', {}, display(row[key]))))),
        ),
      ),
    );
  }
}
