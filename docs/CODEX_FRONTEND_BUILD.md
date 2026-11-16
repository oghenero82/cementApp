# CODEX BUILD SPECIFICATION — Complete Cement App Frontend + CI4 Local/MetaForge Integration

**Repository:** `oghenero82/cementApp`

**Primary user instruction:** Build the entire **professional responsive Cement App frontend** directly from the attached interactive HTML and the uploaded Cement MetaForge 4 seeder catalog. The finished app must work without MetaForge using a real CI4-managed SQL database. Do not build just a dashboard or mock pages. Commit real code to this repository and verify full workflows.

## Step 0. Read both original assets

1. Read and open `reference/Cement_App_Full_Requirements_Interactive_Frontend_Guide.html` in a browser. Recreate its layout language: navy collapsible left sidebar, topbar, responsive dashboard KPI cards, charts, status badges, alerts, advanced collections, drawer editors, connected operational workspaces and reports. Do not embed the original monolithic demo as the production frontend.
2. Unzip `seeders/Cement_App_MetaForge4_Complete_Seeders_2026-11-16.zip`; inspect PHP **all Field classes, RecordType/Module/Route/Portal/Service registration catalogs and policy defaults**. Keep `purchaseItem` casing as seeded. Service registrations do NOT implement the handlers.
3. Read `AGENTS.md` and `docs/FIELD_CODE_REGISTRY_CONTRACT.md`. **The frontend never hardcodes backend field codes**; all form names, mutation keys, synced-record reads and table columns resolve through the seeded-and-overridable field registry.

## Architecture: frontend independent, metadata compatible

Use CodeIgniter 4 with a modular JS frontend (vanilla JS ES modules with classes, or comparable lightweight architecture), no tight binding to MetaForge frontend libraries. Frontend only calls Cement CI4. Each RecordType has a dedicated presenter/collection/detail/form class; classes should **compose shared HTML render helpers**, not independently rewrite tables and forms.

Proposed project layout (adjust to CI4 conventions as needed):

```
app/Controllers/Api/{Auth,Metadata,Mutation,Sync,Report,Import,Upload}.php
app/Libraries/Cement/{Auth,Mutation,Storage,Metadata,Services,Reporting,Importer}/...
app/Database/Migrations/...             # real local SQL mode
app/Database/Seeds/...                  # wrap / import uploaded seeder metadata
public/assets/cement/
  app.js                                # bootstraps session -> registry -> portal -> sync -> UI
  registry/{seededFieldRegistry,RecordFieldRegistry}.js
  core/{ApiClient,MutationClient,SyncClient,IndexedDbStore,RecordDataProcessor}.js
  renderers/{CardRenderer,TableRenderer,FormRenderer,WorkspaceRenderer}.js
  records/{PurchasePresenter,LoadingPresenter,CustomerPresenter,...}.js
  workspaces/{PurchaseWorkspace,LoadingWorkspace,CustomerWorkspace,...}.js
  styles/{tokens,layout,dashboard,forms,tables,mobile}.css
tests/...
```

The existing `frontend/src/registry/` files are usable scaffolding; adapt/copy them to CI4 public assets as part of the finished build. Establish one global accessible registry instance rather than duplicating data structures per module.

### Backend modes

- **Local-first initial installation**: if MetaForge is not configured, configure SQLite or MySQL, create schema via CI4 migrations, initialize RecordTypes/field registry, bootstrap admin user/role, and save all actual transactions in local SQL. Do not use IndexedDB as the canonical datastore. Implement business validations, service handlers, journal posting, stock valuation and sync in local mode, transactionally.
- **MetaForge configured**: CI4 calls MetaForge auth/session, mutation and sync and invokes its registered services when they exist. No frontend rewrite and no duplicated MetaForge business processing.
- **MetaForge temporarily unreachable**: maintain safely queued pending client requests with idempotency, but do NOT automatically book local journals/stock changes as a second authority. Controlled migration/cutover is required to switch authoritative databases.
- **Migration into MetaForge**: design a verifiable import/ID mapping/cursor migration plan; do not assume silently that local IDs equal MetaForge IDs.

Required CI4 endpoints include `GET /api/auth/session`, `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/meta/field-registry`, `GET /api/portal/navigation`, `POST /api/mutation`, `GET /api/sync`, report generation/export and file-upload/import history. Configure CSRF/session/CORS correctly for CI4. All input is validated and authorized by CI4.

### Auth, navigation, layouts

- First-run institution / branch / admin user / roles / portal onboarding; protected login, password reset/change as supported, session restoration and logout.
- Server provides user **authorized** routes, permitted RecordTypes, portal navigation and dashboard defaults after login; sidebar is built from payload. Do **not** reuse HTML preview role-switcher as auth and do not authorize based on hidden menu items.
- Seven role UX patterns: System Admin, Admin, Accountant, Sales Manager, Store Keeper, Data Entry and Driver. Make role workspaces and actions appropriately limited.
- Portal navigation should show module dashboard, collections, individual-record workspaces, System Admin importer, records sync status and current backend mode.
- Support dark/navy styling, tasteful charts, responsive dashboard cards, keyboard search/command palette, filters, pagination, detail drawers, tables, accessible forms and mobile navigation.

### Field registry: zero raw field-code strings outside registry

- `seededFieldRegistry.js` reflects all 51 RecordType Field PHP constants and their 652 mappings. Use `recordFieldRegistry.fields(type).FIELD_SEMANTIC_ALIAS` to render form input names and read synced rows.
- The same registry must control tabular column mappings, lookup selectors, aggregations, reference IDs, related-record filters and mutation envelopes.
- Upon MetaForge availability, CI4 resolves actual authorized metadata; dynamic mapping may override seeded values by registry revision. Support schema mismatch and historic outbox revision handling.
- Treat semantic keys as stable frontend aliases. Never infer a field code from a label, camel-case converter, or arbitrary string.
- No hardcoded numeric RecordType IDs, no `recordTypeId=5` assumptions. Resolve IDs from backend metadata.
- Include automatic registry drift and static scanner tests.

### IndexedDB + mutation

- IndexedDB has unified `record` store keyed by `[recordTypeId,recordId]`, `mutationId`, physicalTableId and deleted marker; outbox stores UUID requestId and metadata revision.
- Fetch authorized sync rows and apply ≥ cursor correctly, including deletions; one DB name across window/worker; manual sync and clear health/status UI.
- Every write through a single mutation adapter; support atomic batches, result maps for related actions, field path errors, validation/lifecycle feedback, pending/failed/confirmed states and no duplicate postings.
- Failing API should not fabricate a saved authoritative transaction. Expose explicit offline draft state and retry only with the original idempotency identity.

## Screens and connected workflows — implement every one

The original HTML includes 13 connected workspaces and ~78 tabs: use them as the base. Each workspace needs **real related-record navigation, actions, and data** rather than static narrative cards.

1. **Executive dashboard**: real sales, purchase entitlement, deliveries, stock, margin, cash/bank, receivables, pending loads/trips, activity alerts; date filters, role-specific cards and drill-down.
2. **Supplier + Purchase workspace**: supplier/depot master, supplier-product mapping, PIN/BIN/login code, purchase and root purchaseItem lines, cost snapshots, purchase payment, outstanding entitlement, loading/clearance history, incentives, ledger trace. Purchased Qty−Loaded Qty is remaining loading entitlement. Purchase posting Dr supplier prepaid/entitlement GL, Cr bank GL once. On first loaded quantity, lock product, original quantity, price and discount.
3. **Loading workspace**: select existing purchaseItem, supplier depot and PIN/BIN, allocate load among one or more customer destinations and warehouse locations, quantity reconciliation, optional warehouse, truck and driver, partial clearance, customer sales/inventory movement effects. Confirm loading through server-backed atomic mutation. Restrict total loaded to available purchase quantity; clearing ≤ loaded. Institution choice ownershipTransferPolicy = `loading-transfer` or `delivery-transfer`.
4. **Customer 360 and Sales workspace**: onboarding, customer-specific dated prices, credit control, sale with root customer_sale_item rows, price resolve/authorized override, discounts/tax snapshot, sale source loading or warehouse movement, receipt/payment matching, unpaid balance, customer statement, returns, delivery proof.
5. **Inventory + warehouse + stocktake workspace**: location and condition stocks, confirmed/effective movements only, reservations, stock transfers, damaged/bad products, verified stocktake lines and adjustments, FIFO cost layers or weighted-average valuation controlled by institution. InventoryKeepingMethod = fixed/daily/weekly/monthly/yearly/manual_stocktaken. Never expose direct stock balance editing.
6. **Trip + Fleet workspace**: vehicle, staff/person driver, loading, multiple ordered trip_destination customer drops, delivery accepted/rejected quantities, POD signatures/photos, fuel, maintenance and expenses; split trip cost/profitability by stops/sales per policy.
7. **Incentive workspace**: scheme optional; purchase/loading benefit, direct actual benefit, receipt/reconciliation and receivable/accounting.
8. **Employee 360 + Payroll workspace**: person → employment, period/month payroll upload, per-file history and row status, payroll items and payments, savings and loan product linking to shared Cooperative RecordTypes, salary deductions, approved payout/posting; payroll audit.
9. **Accounting + reconciliation workspace**: shared account headers, accounts, journals/lines, balances, bank accounts/statements/lines/reconciliations, expenses, inter-account transfers where enabled, fiscal year and reporting. In local mode provide equivalent working services and balanced ledger posting. Tie each transaction to journal and posting status.
10. **Products + pricing workspace**: reusable 5 root RTs `product_category`, `product`, `unit_of_measure`, `price_list`, `price_list_item`. Product can have many supplier references and dated price policies, conversions/packs, immutable snapshots in transactions.
11. **Reports workspace**: real filtering, charts, downloadable CSV/XLSX/PDF, report definitions, generated stored report results, email/export scheduling with history, 20 seeded reports (purchase entitlements, sales, customer aging, profit, FIFO/weighted average stock valuation, damaged stock, trip costing, incentives, bank/cash, P&L, trial balance, balance sheet).
12. **System administration**: institution settings, branches, personnel/user/roles, portal permissions, importers, monthly upload history, service registry health, mutation/sync audit, reporting settings and backend-mode status.
13. **Generic RecordType views**: all 51 seeded types get collections, forms, detail screens and appropriate CRUD/lifecycle actions as authorized. Connected workspaces take precedence over generic drawers when a business process is involved.

## Backend business completeness

Service registrations in seeder ZIP are **not implementations**. Build missing local-mode service handlers for purchased entitlement GL, loading confirmation / destinations, inventory postings and cost basis, sale snapshot, credits, payouts, supplier incentives, trip expense costing, stocktake adjustments, payroll, report generation and audit. Compose all dependent records into one atomic mutation where possible. Do not violate the locked purchase/loan/ledger posting policies. Expose validations to UI with clear field-level messages.

## Quality / acceptance

- Reproduce visual fidelity to sample (desktop/tablet/mobile), but split into maintainable modular classes and CSS. No hardcoded seeded fake statistics in production dashboard; seed optional demo content only behind an explicit demo/dev flag.
- No field-code literals in business JS/HTML. Prove field-code override test: change `purchase.SUPPLIER_PIN` actual code in mocked CI4 MetaForge metadata; all form names, read paths and data submitted change without editing view classes.
- All 51 RecordTypes usable; all seven role portals protected; all 13 workspaces usable, not merely tab labels; all twenty reports built against real data.
- Local SQL database persists after browser restart; login, 2 purchases with items, partial loading+warehouse split, sales+customer payment, delivery proof, stocktake adjustment, trip cost and payroll survive restart and remain consistent.
- Test duplicate mutation requestId, invalid partial loading, immutable purchaseItem after load, pricing snapshots, partial clear, ledger double-entry balance, FIFO/weighted cost, permissions, access cross-institution denial, offline pending and retry, changed field-code revision, record sync + soft-delete.
- Provide `composer install`, `.env.example`, `php spark migrate`, `php spark db:seed ...`, local DB setup script, test execution, CodeIgniter route list and acceptance report; record any truly unimplemented features instead of claiming complete.

## Work method for Codex

Implement the **whole application** in progressive verified commits, not a one-off mockup. Start with CI4 bootstrap, local SQL schema and registry integration, then authentic navigation, reusable renderers, all RT presenters, connected workspaces, mutation/sync, service handlers, reports, tests and documentation. Run automated tests and inspect the UI visually. Each PR/commit should keep the cumulative app runnable.

**Do not hardcode field codes. This rule overrides examples in the HTML prototype.**
