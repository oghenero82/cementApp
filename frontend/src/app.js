import { BusinessWorkflows } from './workspaces/BusinessWorkflows.js';
import { recordFieldRegistry as registry } from './registry/RecordFieldRegistry.js';
import { ApiClient } from './core/ApiClient.js';
import { IndexedDbStore } from './core/IndexedDbStore.js';
import { SyncClient } from './core/SyncClient.js';
import { MutationClient } from './core/MutationClient.js';
import { RecordDataProcessor } from './core/RecordDataProcessor.js';
import { PageManager } from './core/PageManager.js';
import { RouteLinkBuilder as routes } from './core/RouteLinkBuilder.js';
import { el, button, icon, notify, money, number, setCurrency } from './core/dom.js';
import { CardRenderer } from './renderers/CardRenderer.js';
import { AdminRenderer } from './renderers/AdminRenderer.js';
import { WorkspacePresenter } from './workspaces/WorkspacePresenter.js';
import { presenters } from './records/index.js';

class CementApplication {
  constructor() {
    this.api = new ApiClient();
    this.cache = new IndexedDbStore();
    this.data = new RecordDataProcessor(registry);
    this.admin = new AdminRenderer(this);
    this.flows = new BusinessWorkflows(this);
    this.app = document.querySelector('#app');
    this.overlay = document.querySelector('#overlay');
    this.drawerFocus = null;
    this.boundKey = (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        this.command();
      }
      if (e.key === 'Escape') {
        this.closeDrawer();
        this.app.classList.remove('sidebar-open');
      }
    };
    document.addEventListener('keydown', this.boundKey);
  }
  async boot() {
    try {
      const session = await this.api.request('auth/session');
      this.mode = session.backendMode;
      if (!session.user && session.next) {
        this.authStep(session);
        return;
      }
      if (!session.user) {
        this.login(session.needsSetup);
        return;
      }
      await this.connect(session.user);
    } catch (error) {
      this.app.replaceChildren(
        el(
          'div',
          { class: 'boot' },
          el('h1', {}, 'Unable to connect'),
          el('p', { role: 'alert' }, error.message),
          button('Retry', () => this.boot(), 'primary'),
        ),
      );
    }
  }
  login(setup = false) {
    this.manager?.destroy();
    this.sync?.stop();
    this.cache.close();
    this.app.className = '';
    const email = el('input', {
      type: 'email',
      required: true,
      autoComplete: 'username',
      id: 'login-email',
    });
    const password = el('input', {
      type: 'password',
      required: true,
      minLength: setup ? 12 : 1,
      autoComplete: setup ? 'new-password' : 'current-password',
      id: 'login-password',
    });
    const institution = el('input', { required: true, id: 'institution' });
    const name = el('input', { required: true, id: 'display-name' });
    const error = el('p', { class: 'error-text', role: 'alert' });
    const submit = el(
      'button',
      { type: 'submit', class: 'btn primary' },
      setup ? 'Create institution' : 'Sign in',
    );
    const field = (label, input) =>
      el('div', { class: 'form-field' }, el('label', { htmlFor: input.id }, label), input);
    const form = el(
      'form',
      {
        class: 'login-card card',
        onSubmit: async (e) => {
          e.preventDefault();
          submit.disabled = true;
          error.textContent = '';
          try {
            const result = await this.api.request(setup ? 'auth/setup' : 'auth/login', {
              email: email.value,
              password: password.value,
              ...(setup ? { institution: institution.value, displayName: name.value } : {}),
            });
            password.value = '';
            if (result.user) await this.connect(result.user);
            else this.authStep(result);
          } catch (ex) {
            error.textContent = ex.message;
            submit.disabled = false;
          }
        },
      },
      el('div', { class: 'brand-mark' }, icon('box')),
      el('div', { class: 'eyebrow' }, 'CEMENT APP'),
      el('h1', {}, setup ? 'Set up your business' : 'Welcome back'),
      el(
        'p',
        { class: 'muted' },
        setup
          ? 'Create your institution and administrator. Your operations stay in a persistent SQL database.'
          : 'Sign in to your authorized operations workspace.',
      ),
      setup ? [field('Institution name', institution), field('Your name', name)] : null,
      field('Email address', email),
      field(setup ? 'Password · at least 12 characters' : 'Password', password),
      error,
      submit,
      el('small', { class: 'muted' }, 'Backend: ' + this.mode),
    );
    this.app.replaceChildren(
      el(
        'main',
        { class: 'login-page' },
        el(
          'div',
          { class: 'login-story' },
          el('span', { class: 'eyebrow' }, 'BUILT FOR YOUR BUSINESS'),
          el('h2', {}, 'From purchase to proof of delivery.'),
          el('p', {}, 'One connected workspace for your stock, fleet, customers and finances.'),
          el('div', { class: 'login-lines' }, icon('truck'), icon('box'), icon('chart')),
        ),
        form,
      ),
    );
  }
  authStep(session) {
    this.app.className = '';
    const card = el('form', { class: 'login-card card' }),
      error = el('p', { role: 'alert', class: 'error-text' });
    const title =
      session.next === 'select_role'
        ? 'Choose your MetaForge role'
        : session.next === 'change_default_password'
          ? 'Change your temporary password'
          : 'Complete MetaForge authentication';
    card.append(el('h1', {}, title));
    const finish = async (path, values) => {
      try {
        const result = await this.api.request(path, values);
        if (result.user) await this.connect(result.user);
        else this.authStep(result);
      } catch (e) {
        error.textContent = e.message;
      }
    };
    if (session.next === 'select_role') {
      const role = el(
        'select',
        { 'aria-label': 'MetaForge role', required: true },
        ...(session.availableRoles || []).map((r) => el('option', { value: r.id }, r.name)),
      );
      card.append(
        role,
        el('button', { class: 'btn primary', type: 'submit' }, 'Continue with role'),
      );
      card.addEventListener('submit', (e) => {
        e.preventDefault();
        finish('auth/role', { roleId: Number(role.value) });
      });
    } else if (['change_default_password', 'reauthenticate'].includes(session.next)) {
      const current = el('input', {
        type: 'password',
        required: true,
        autoComplete: 'current-password',
        'aria-label': 'Current MetaForge password',
      });
      const replacement = el('input', {
        type: 'password',
        required: true,
        minLength: 12,
        autoComplete: 'new-password',
        'aria-label': 'New MetaForge password',
      });
      card.append(el('label', {}, 'Current password', current));
      if (session.next === 'change_default_password')
        card.append(el('label', {}, 'New password', replacement));
      card.append(el('button', { type: 'submit', class: 'btn primary' }, 'Continue'));
      card.addEventListener('submit', (e) => {
        e.preventDefault();
        const values =
          session.next === 'reauthenticate'
            ? { password: current.value }
            : { current: current.value, replacement: replacement.value, initial: true };
        current.value = '';
        replacement.value = '';
        finish(session.next === 'reauthenticate' ? 'auth/reauthenticate' : 'auth/password', values);
      });
    } else
      card.append(el('p', {}, 'Complete email verification in MetaForge, then sign in again.'));
    card.append(
      error,
      button('Back to sign in', async () => {
        try {
          await this.api.request('auth/logout', {});
          this.login();
        } catch (e) {
          error.textContent = e.message;
        }
      }),
    );
    this.app.replaceChildren(el('div', { class: 'login-screen' }, card));
  }
  async connect(user) {
    this.user = user;
    this.metadata = await this.api.request('meta/field-registry');
    registry.activateMetadata(this.metadata);
    setCurrency(this.metadata.currency || 'NGN');
    this.navigation = await this.api.request('portal/navigation');
    await this.cache.open(
      this.metadata.scope || user.institution_id + '-' + user.id + '-' + user.role,
      user.institution_id + ':' + user.id,
    );
    if ((await this.cache.getMeta('registryRevision')) !== registry.revision)
      await this.cache.resetRecords(registry.revision);
    this.sync = new SyncClient(this.api, this.cache, registry);
    this.mutations = new MutationClient(this.api, this.cache, registry, this.sync);
    this.data.replace(await this.cache.all());
    await this.sync.run();
    this.data.replace(await this.cache.all());
    this.shell();
    this.sync.start(
      async () => {
        this.data.replace(await this.cache.all());
        this.updateSyncBadge();
      },
      (e) => this.syncError(e),
    );
    this.manager = new PageManager(this, this.content);
    await this.manager.render();
  }
  shell() {
    this.app.className = 'app';
    const brand = el(
      'a',
      { class: 'brand', href: '#dashboard' },
      el('span', { class: 'brand-mark' }, icon('box')),
      el(
        'div',
        { class: 'brand-text' },
        el('div', { class: 'brand-name' }, 'Cement', el('span', {}, 'App')),
        el('div', { class: 'brand-caption' }, 'OPERATIONS SUITE'),
      ),
    );
    const nav = el(
      'nav',
      { class: 'sidebar-scroll', 'aria-label': 'Main navigation' },
      el('div', { class: 'nav-overline' }, 'WORKSPACE'),
      this.navlink('Executive overview', '#dashboard', 'chart'),
    );
    for (const [key, w] of Object.entries(this.navigation.workspaces))
      nav.append(
        this.navlink(w.title.replace(/ workspace| control/g, ''), routes.workspace(key), w.icon),
      );
    nav.append(el('div', { class: 'nav-overline' }, 'COLLECTIONS'));
    for (const module of this.navigation.modules) {
      const group = el(
        'details',
        { class: 'nav-group' },
        el('summary', {}, icon('grid'), el('span', {}, module.name)),
      );
      group.append(this.navlink('Overview', '#module/' + module.code, 'chart', 'nav-child'));
      for (const type of module.types)
        group.append(this.navlink(type.title, routes.collection(type.type), '', 'nav-child'));
      nav.append(group);
    }
    if (this.navigation.reports.length)
      nav.append(this.navlink('Reports & exports', '#reports', 'chart'));
    if (this.navigation.canAdmin)
      nav.append(
        el('div', { class: 'nav-overline' }, 'ADMINISTRATION'),
        this.navlink('System importer', '#importer', 'sync'),
        this.navlink('Users & roles', '#users', 'users'),
        this.navlink('Institution policies', '#settings', 'settings'),
        this.navlink('Audit trail', '#audit', 'grid'),
      );
    if (this.navigation.canFinance) nav.append(this.navlink('General ledger', '#ledger', 'wallet'));
    const sidebar = el(
      'aside',
      { class: 'sidebar' },
      brand,
      nav,
      el(
        'div',
        { class: 'sidebar-footer' },
        el(
          'div',
          { class: 'help-block' },
          el('strong', {}, this.navigation.roleTitle),
          el('p', {}, 'Your access is managed by the server.'),
        ),
        el(
          'div',
          { class: 'side-bottom' },
          el('span', {}, '● ' + this.mode.toUpperCase()),
          button('Account', () => this.account(), 'text-btn'),
          button('Sign out', () => this.signout(), 'text-btn'),
        ),
      ),
    );
    const menu = button(
      '☰',
      () => {
        this.app.classList.toggle(innerWidth < 861 ? 'sidebar-open' : 'collapsed');
      },
      'icon-btn',
    );
    menu.setAttribute('aria-label', 'Toggle navigation');
    this.syncBadge = button('Synced', () => this.syncPanel(), 'sync-button');
    const header = el(
      'header',
      { class: 'topbar' },
      menu,
      el(
        'div',
        { class: 'crumb' },
        'Workspace',
        el('span', {}, '/'),
        el('strong', { id: 'page-crumb' }, 'Overview'),
      ),
      el('div', { class: 'top-spacer' }),
      button('Search anything…   ⌘ K', () => this.command(), 'search-top'),
      this.syncBadge,
      el('span', { class: 'avatar' }, this.user.display_name.slice(0, 2).toUpperCase()),
      el(
        'div',
        { class: 'profile' },
        el('strong', {}, this.user.display_name),
        el('small', {}, this.navigation.roleTitle),
      ),
    );
    this.content = el('main', { class: 'content', id: 'main-content', tabIndex: -1 });
    const shade = el('button', {
      class: 'nav-shade',
      'aria-label': 'Close navigation',
      onClick: () => this.app.classList.remove('sidebar-open'),
    });
    this.app.replaceChildren(
      sidebar,
      shade,
      el(
        'div',
        { class: 'main' },
        header,
        this.navigation.notice
          ? el('div', { class: 'card card-pad', role: 'status' }, this.navigation.notice)
          : null,
        this.content,
      ),
    );
    this.updateSyncBadge();
  }
  navlink(title, href, kind, klass = 'nav-action') {
    return el(
      'a',
      { class: klass, href, onClick: () => this.app.classList.remove('sidebar-open') },
      kind ? icon(kind) : null,
      el('span', {}, title),
    );
  }
  presenter(type) {
    const Presenter = presenters[type];
    if (!Presenter || !this.metadata.descriptors[type])
      throw new Error('RecordType is not authorized');
    return new Presenter(this);
  }
  async route(path, root) {
    this.closeDrawer();
    for (const link of this.app.querySelectorAll('nav a'))
      link.classList.toggle('active', link.hash === '#' + path);
    root.replaceChildren();
    const [page, key, id] = path.split('/');
    document.querySelector('#page-crumb').textContent =
      page === 'workspace'
        ? this.navigation.workspaces[key]?.title || 'Workspace'
        : page === 'records'
          ? this.metadata.descriptors[key]?.title || 'Records'
          : page.charAt(0).toUpperCase() + page.slice(1);
    try {
      if (page === 'dashboard') await this.dashboard(root);
      else if (page === 'module' && this.navigation.modules.some((m) => m.code === key)) {
        this.moduleDashboard(root, key);
      } else if (page === 'workspace' && this.navigation.workspaces[key]) {
        const presenter = new WorkspacePresenter(this, key);
        this.manager.current = presenter;
        presenter.mount(root);
      } else if (page === 'records' && this.metadata.descriptors[key]) {
        const p = this.presenter(key);
        this.manager.current = p;
        root.append(
          this.heading(
            this.metadata.descriptors[key].title,
            'Search, inspect and work with authorized records.',
          ),
        );
        p.mount(el('div'));
        root.append(p.root);
        if (id) {
          const row = this.data.get(key, id);
          if (row) this.openRecord(key, row);
          else notify('Record not available in your authorized cache', true);
        }
      } else if (page === 'reports') await this.reports(root);
      else if (
        ['users', 'settings', 'importer', 'audit'].includes(page) &&
        this.navigation.canAdmin
      ) {
        root.append(
          this.heading(
            {
              users: 'Users & roles',
              settings: 'Institution policies',
              importer: 'System importer',
              audit: 'Audit trail',
            }[page],
          ),
        );
        const body = el('div');
        root.append(body);
        await this.admin[page](body);
      } else if (page === 'ledger' && this.navigation.canFinance) {
        root.append(
          this.heading(
            'Accounting & general ledger',
            'Every posting has an equal debit and credit.',
          ),
        );
        const body = el('div');
        root.append(body);
        await this.admin.ledger(body);
      } else
        root.append(
          CardRenderer.empty(
            'Page unavailable',
            'This route is unavailable for your current role.',
            el('a', { class: 'btn primary', href: '#dashboard' }, 'Return to overview'),
          ),
        );
    } catch (error) {
      root.append(el('p', { class: 'error-text', role: 'alert' }, error.message));
    }
  }
  heading(title, subtitle = '', action) {
    return el(
      'div',
      { class: 'page-heading' },
      el(
        'div',
        {},
        el('div', { class: 'eyebrow' }, 'CEMENT APP / OPERATIONS'),
        el('h1', {}, title),
        el('p', {}, subtitle),
      ),
      action,
    );
  }
  moduleDashboard(root, key) {
    const module = this.navigation.modules.find((m) => m.code === key);
    const rows = module.types.flatMap((t) => this.data.all(t.type));
    let drafts = 0,
      confirmed = 0;
    for (const row of rows) {
      const fields = registry.fields(row.recordTypeCode);
      const state = fields.STATUS ? row.data[fields.STATUS] : null;
      if (['draft', 'pending'].includes(state)) drafts++;
      if (
        [
          'paid',
          'confirmed',
          'verified',
          'posted',
          'invoiced',
          'completed',
          'received',
          'reconciled',
          'closed',
        ].includes(state)
      )
        confirmed++;
    }
    root.append(
      this.heading(module.name, module.description),
      el(
        'div',
        { class: 'grid-stats' },
        CardRenderer.metric('Records', number(rows.length)),
        CardRenderer.metric('Drafts / pending', number(drafts)),
        CardRenderer.metric('Finalized', number(confirmed)),
        CardRenderer.metric('Collections', number(module.types.length)),
      ),
      el(
        'div',
        { class: 'quick-grid' },
        ...module.types.map((t) =>
          el(
            'a',
            { class: 'card quick-card', href: routes.collection(t.type) },
            el('span', { class: 'stat-icon' }, icon('box')),
            el('h3', {}, t.title),
            el('p', {}, number(this.data.all(t.type).length) + ' authorized records'),
            el('span', { class: 'link' }, 'Open collection →'),
          ),
        ),
      ),
    );
  }
  async dashboard(root) {
    const from = el('input', { type: 'date', 'aria-label': 'Start date' }),
      to = el('input', { type: 'date', 'aria-label': 'End date' });
    const body = el('div');
    const controls = el(
      'div',
      { class: 'actions' },
      from,
      to,
      button('Apply period', () => load(), 'secondary'),
    );
    root.append(
      this.heading(
        'Business overview',
        'A clear view of your operations, from entitlement to collection.',
        controls,
      ),
      body,
    );
    const load = async () => {
      try {
        const result = await this.api.request('dashboard', undefined, {
          from: from.value,
          to: to.value,
        });
        const t = result.totals;
        const definitions = [
          ['Confirmed sales', 'customer_sales.NET_AMOUNT', true, 'chart', '#workspace/sales'],
          ['Receivables', 'customer_sales.BALANCE_DUE', true, 'wallet', '#workspace/customer'],
          [
            'Stock on hand',
            'inventory_stock.QUANTITY_ON_HAND',
            false,
            'box',
            '#workspace/warehouse',
          ],
          ['Supplier funding', 'purchase.PAID_AMOUNT', true, 'truck', '#workspace/purchase'],
        ];
        const cards = definitions
          .filter(([, k]) => this.metadata.descriptors[k.split('.')[0]])
          .map(([title, key, currency, kind, href]) =>
            el(
              'a',
              { href },
              CardRenderer.metric(
                title,
                currency ? money(t[key]) : number(t[key]),
                'Current period',
                kind,
              ),
            ),
          );
        const chart = this.chart(result.trend);
        const quick = el(
          'div',
          { class: 'quick-grid' },
          ...Object.entries(this.navigation.workspaces)
            .slice(0, 6)
            .map(([key, w]) =>
              el(
                'a',
                { class: 'card quick-card', href: routes.workspace(key) },
                el('span', { class: 'stat-icon' }, icon(w.icon)),
                el('h3', {}, w.title.replace(' workspace', '')),
                el('p', {}, w.subtitle),
                el('span', { class: 'link' }, 'Open workspace →'),
              ),
            ),
        );
        const entitlement = number(
          (t['purchaseItem.ORIGINAL_QUANTITY'] || 0) - (t['purchaseItem.LOADED_QTY'] || 0),
        );
        const operations = CardRenderer.panel(
          'Operational position',
          el(
            'div',
            { class: 'position-list' },
            el(
              'a',
              { href: '#workspace/purchase' },
              el('span', {}, 'Unloaded entitlement'),
              el('strong', {}, entitlement),
            ),
            el(
              'a',
              { href: '#workspace/warehouse' },
              el('span', {}, 'Inventory valuation'),
              el('strong', {}, money(t['inventory_stock.TOTAL_VALUE'])),
            ),
            el(
              'a',
              { href: '#workspace/trip' },
              el('span', {}, 'Fleet trips'),
              el('strong', {}, number(result.counts.trip)),
            ),
          ),
        );
        body.replaceChildren(
          el('div', { class: 'grid-stats' }, cards),
          el(
            'div',
            { class: 'dash-grid' },
            CardRenderer.panel('Sales performance', chart),
            operations,
          ),
          el(
            'div',
            { class: 'insight-banner' },
            el(
              'div',
              {},
              el('span', { class: 'banner-label' }, 'CONNECTED OPERATIONS'),
              el('h2', {}, 'Keep every load, payment and delivery in view.'),
              el('p', {}, 'Open a workspace to inspect the records behind your totals.'),
            ),
            el('a', { class: 'btn', href: '#reports' }, 'Explore reports →'),
          ),
          el('h2', { class: 'section-title' }, 'Your operational workspaces'),
          quick,
        );
      } catch (error) {
        body.replaceChildren(el('p', { role: 'alert', class: 'error-text' }, error.message));
      }
    };
    await load();
  }
  chart(points) {
    if (!points.length)
      return CardRenderer.empty(
        'No confirmed sales in this period',
        'Your chart will grow as invoices are confirmed.',
      );
    const ns = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', '0 0 700 240');
    svg.setAttribute('class', 'chart-svg');
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', 'Confirmed sales over time');
    const max = Math.max(...points.map((p) => p.value), 1);
    for (let i = 0; i < 4; i++) {
      const line = document.createElementNS(ns, 'path');
      line.setAttribute('d', `M30 ${30 + i * 55}H680`);
      line.setAttribute('class', 'chart-grid');
      svg.append(line);
    }
    const path = document.createElementNS(ns, 'path');
    path.setAttribute(
      'd',
      points
        .map(
          (p, i) =>
            `${i ? 'L' : 'M'}${30 + (i * 640) / Math.max(points.length - 1, 1)},${200 - (p.value / max) * 165}`,
        )
        .join(' '),
    );
    path.setAttribute('class', 'chart-line');
    svg.append(path);
    return el(
      'div',
      {},
      svg,
      el(
        'div',
        { class: 'chart-range' },
        points[0].day,
        el('strong', {}, money(points.reduce((n, p) => n + p.value, 0))),
        points.at(-1).day,
      ),
    );
  }
  async reports(root) {
    root.replaceChildren(
      this.heading(
        'Management reports',
        'Generate a scoped snapshot, inspect results, or download CSV.',
      ),
    );
    const select = el(
      'select',
      { 'aria-label': 'Report' },
      ...this.navigation.reports.map((r) => el('option', { value: r.code }, r.name)),
    );
    const from = el('input', { type: 'date', 'aria-label': 'Report period start' });
    const to = el('input', { type: 'date', 'aria-label': 'Report period end' });
    const body = el('div');
    let result = null;
    const generate = async () => {
      try {
        result = await this.api.request('reports', undefined, {
          code: select.value,
          from: from.value,
          to: to.value,
        });
        body.replaceChildren(
          CardRenderer.panel(result.title, this.admin.plainTable(result.rows, result.columns)),
        );
      } catch (error) {
        notify(error.message, true);
      }
    };
    root.append(
      el(
        'section',
        { class: 'card card-pad report-tools' },
        select,
        from,
        to,
        button('Generate report', generate, 'primary'),
        button('Save snapshot', async () => {
          try {
            await this.api.request('reports', {
              code: select.value,
              from: from.value,
              to: to.value,
              requestId: crypto.randomUUID(),
            });
            notify('Report snapshot saved');
          } catch (error) {
            notify(error.message, true);
          }
        }),
        button('Download CSV', () => {
          const url = new URL('/api/reports', location.origin);
          for (const [key, value] of Object.entries({
            code: select.value,
            from: from.value,
            to: to.value,
            format: 'csv',
          }))
            if (value) url.searchParams.set(key, value);
          location.assign(url);
        }),
        button('Download XLSX', () => {
          const url = new URL('/api/reports', location.origin);
          for (const [key, value] of Object.entries({
            code: select.value,
            from: from.value,
            to: to.value,
            format: 'xlsx',
          }))
            if (value) url.searchParams.set(key, value);
          location.assign(url);
        }),
        button('Print / PDF', () => window.print()),
      ),
      body,
    );
    if (this.navigation.reports.length) await generate();
  }
  drawer(title, body) {
    this.closeDrawer();
    this.drawerFocus = document.activeElement;
    const panel = el('section', {
      class: 'drawer',
      role: 'dialog',
      'aria-modal': 'true',
      'aria-labelledby': 'drawer-title',
      tabIndex: -1,
    });
    const close = button('×', () => this.closeDrawer(), 'icon-btn');
    close.setAttribute('aria-label', 'Close dialog');
    panel.append(
      el('div', { class: 'drawer-header' }, el('h2', { id: 'drawer-title' }, title), close),
      el('div', { class: 'drawer-body' }, body),
    );
    const shade = el('div', { class: 'shade', onClick: () => this.closeDrawer() });
    this.overlay.append(shade, panel);
    this.app.inert = true;
    panel.focus();
    panel.addEventListener('keydown', (event) => {
      if (event.key !== 'Tab') return;
      const nodes = [...panel.querySelectorAll('button,input,select,textarea,a[href]')].filter(
        (n) => !n.disabled && !n.hidden,
      );
      const first = nodes[0],
        last = nodes.at(-1);
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last?.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first?.focus();
      }
    });
  }
  closeDrawer() {
    this.overlay.replaceChildren();
    this.app.inert = false;
    this.drawerFocus?.focus?.();
    this.drawerFocus = null;
  }
  openRecord(type, row) {
    this.drawer(
      this.metadata.descriptors[type].title + ' · ' + this.data.label(type, row),
      this.presenter(type).detail(row),
    );
  }
  confirm(title, message, handler) {
    this.drawer(
      title,
      el(
        'div',
        {},
        el('p', {}, message),
        button(
          'Confirm',
          async () => {
            try {
              await handler();
            } catch (error) {
              notify(error.message, true);
            }
          },
          'primary',
        ),
      ),
    );
  }
  async refresh() {
    this.data.replace(await this.cache.all());
    this.updateSyncBadge();
    await this.manager.render();
  }
  async updateSyncBadge() {
    if (!this.cache.db) return;
    const pending = await this.cache.all('outbox');
    this.syncBadge.textContent = pending.length ? `${pending.length} pending` : '● Synced';
  }
  syncError(error) {
    if (error.scopeChanged) {
      this.sync.stop();
      this.manager.destroy();
      this.data.replace([]);
      this.app.replaceChildren(el('div', { class: 'boot' }, 'Refreshing access…'));
      this.boot();
      return;
    }
    this.syncBadge.textContent = 'Sync needs attention';
    notify(error.message, true);
  }
  async syncPanel() {
    const pending = await this.cache.all('outbox');
    const content = el(
      'div',
      {},
      el(
        'p',
        { class: 'muted' },
        'Pending requests retain their original request ID, exact payload and registry revision. A failed request has not been confirmed.',
      ),
      button(
        'Sync now',
        async () => {
          try {
            await this.sync.run();
            await this.refresh();
            notify('Authorized records synchronized');
          } catch (error) {
            notify(error.message, true);
          }
        },
        'primary',
      ),
    );
    for (const draft of pending)
      content.append(
        el(
          'article',
          { class: 'queue-card' },
          el('strong', {}, draft.recordTypeCode + ' · ' + draft.queueState),
          el('p', { class: 'muted' }, draft.requestId),
          el('p', {}, draft.queueError || 'Awaiting server acknowledgement'),
          el('small', {}, 'Registry: ' + draft.registryRevision),
          el(
            'div',
            { class: 'actions' },
            button('Retry unchanged request', async () => {
              try {
                await this.mutations.retry(draft);
                notify('Request confirmed');
                await this.refresh();
              } catch (error) {
                notify(error.message, true);
              }
            }),
            draft.queueState === 'failed'
              ? button('Discard failed draft', async () => {
                  await this.cache.removeDraft(draft.requestId);
                  await this.updateSyncBadge();
                  await this.syncPanel();
                })
              : null,
          ),
        ),
      );
    if (!pending.length)
      content.append(CardRenderer.empty('All caught up', 'There are no pending requests.'));
    this.drawer('Synchronization & outbox', content);
  }
  command() {
    if (!this.navigation) return;
    const input = el('input', {
      type: 'search',
      placeholder: 'Find a workspace or record…',
      'aria-label': 'Search navigation and records',
      class: 'command-input',
    });
    const results = el('div', { class: 'command-results' });
    const update = () => {
      const q = input.value.toLowerCase();
      const options = Object.entries(this.navigation.workspaces).map(([key, w]) => ({
        title: w.title,
        href: routes.workspace(key),
      }));
      for (const [type, desc] of Object.entries(this.metadata.descriptors)) {
        options.push({ title: desc.title, href: routes.collection(type) });
        if (q)
          for (const row of this.data.search(type, q).slice(0, 5))
            options.push({
              title: desc.title + ' · ' + this.data.label(type, row),
              href: routes.record(type, row.recordId),
            });
      }
      results.replaceChildren(
        ...options
          .filter((o) => o.title.toLowerCase().includes(q))
          .slice(0, 25)
          .map((o) => el('a', { href: o.href, onClick: () => this.closeDrawer() }, o.title)),
      );
    };
    input.addEventListener('input', update);
    update();
    this.drawer('Search operations', el('div', {}, input, results));
    input.focus();
  }
  account() {
    const current = el('input', {
      type: 'password',
      required: true,
      autoComplete: 'current-password',
      id: 'current-password',
    });
    const replacement = el('input', {
      type: 'password',
      required: true,
      minLength: 12,
      autoComplete: 'new-password',
      id: 'replacement-password',
    });
    const summary = el('p', { role: 'alert', class: 'error-text' });
    const form = el(
      'form',
      {
        class: 'record-form',
        onSubmit: async (event) => {
          event.preventDefault();
          try {
            await this.api.request('auth/password', {
              current: current.value,
              replacement: replacement.value,
            });
            notify('Password changed');
            this.closeDrawer();
          } catch (error) {
            summary.textContent = error.message;
          }
        },
      },
      el('p', { class: 'muted' }, this.user.email),
      el(
        'div',
        { class: 'form-grid' },
        el(
          'div',
          { class: 'form-field' },
          el('label', { htmlFor: current.id }, 'Current password'),
          current,
        ),
        el(
          'div',
          { class: 'form-field' },
          el('label', { htmlFor: replacement.id }, 'New password · 12+ characters'),
          replacement,
        ),
      ),
      summary,
      el('button', { class: 'btn primary', type: 'submit' }, 'Change password'),
    );
    this.drawer('My account', form);
  }
  async signout() {
    try {
      const pending = await this.cache.all('outbox');
      if (pending.length) {
        this.confirm(
          'Sign out with pending requests?',
          `${pending.length} requests will remain saved on this browser for this user.`,
          () => this.completeSignout(),
        );
        return;
      }
      await this.completeSignout();
    } catch (error) {
      notify(error.message, true);
    }
  }
  async completeSignout() {
    await this.api.request('auth/logout', {});
    this.closeDrawer();
    this.sync.stop();
    this.manager.destroy();
    this.cache.close();
    this.user = null;
    location.hash = '';
    await this.boot();
  }
}
new CementApplication().boot();
