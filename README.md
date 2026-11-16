# Cement App

CodeIgniter 4 and an ES-module frontend for a cement dealer’s purchasing, loading, sales, inventory, fleet, payroll, Cooperative accounts, accounting and reporting.

The standalone application uses durable SQLite storage. IndexedDB is an authorized cache and pending-request queue. Setting `CEMENT_DRIVER=metaforge` selects the HTTPS proxy adapter explicitly; an outage never switches posting to SQLite.

## Requirements

- PHP 8.2+ with intl, mbstring, PDO SQLite, SQLite3, curl, fileinfo and zip; PHPUnit also needs DOM/XML/XMLWriter.
- Composer 2, Node 24, npm; Python 3 for seeder integrity checks.
- Chromium for browser regression tests. The test runner uses `/usr/bin/chromium` when installed, or Playwright’s installed browser. `CHROMIUM_PATH` overrides it.

## Install and run

```sh
cp .env.example .env
./scripts/install.sh
php -S 127.0.0.1:8080 -t public scripts/router.php
```

Open the local application and create your institution and administrator. No default login or sample business transactions are installed. Passwords require at least 12 characters. The setup imports the 20 report definitions; all dashboard values derive from actual records.

Equivalent individual commands:

```sh
composer install
npm ci
npm run build
php spark migrate
php spark db:seed CementAppSeeder
```

The default database is `writable/cement.sqlite`. `CEMENT_DB_PATH` selects another SQLite file outside the web root. The metadata and report seeder is idempotent. See [deployment](docs/DEPLOYMENT.md) for HTTPS, backups, scheduled reports and MetaForge contracts.

## First operating workflow

1. In Collections, create a supplier and depot, product category, unit, product, customer and inventory location. Activate these catalog records. Define the product’s base unit; alternate packaging requires an explicit package unit and size.
2. Create an active price list and dated price entries for the product and unit. Customer-specific lists and quantity tiers take priority. Manual price overrides require the institution policy.
3. In Purchase control, create a draft purchase and its root purchase items. Item prices and quantities compute the purchase totals. An administrator approves `paid`, or an authorized accountant records supplier payments. Funding posts prepaid entitlement against the selected payment account once.
4. In Loading, choose the existing purchase item and supplier depot. Add customer and/or warehouse allocation rows. Quantities must sum to the load. Approval atomically creates customer sales and/or stock receipts. Partial supplier clearance is independent of loading and delivery.
5. Receive customer payments against invoices. Delivery proof supports partial acceptance and attachments. With `delivery-transfer`, each accepted quantity recognizes its own revenue and cost; the order remains partially invoiced until fully accepted.
6. Inspect stock, cost layers, transfers, damaged goods and physical counts in Inventory. Counts reject stale system quantities. Approved variances create adjustment and movement records. Stock and cost layers cannot be edited directly.
7. Plan trips and ordered stops, capture fuel/maintenance/expenses, and confirm delivery proof. Complete a trip after its destinations are delivered. Cost reports allocate actual transport cost by destination quantity.
8. Create persons and employment records. Create payroll batches and lines, approve posting, then record bounded partial payments. Shared `member`, `member_account` and `cooperative_transaction` records handle staff savings, approved loan disbursement and payroll deductions.
9. Finance exposes shared accounts, journals/lines, account balances, fiscal periods, bank accounts, statements and reconciliation. A statement must match its journals and closing balance before reconciliation. Closed periods reject backdated manual postings.
10. Generate the 20 seeded reports, save snapshots, download CSV/XLSX, or use browser Print for PDF. Snapshot exports use stored rows rather than regenerating historical results.

The System importer previews CSV/XLSX before sending it through the mutation API. Headers are **semantic aliases** such as `CODE`, `SUPPLIER_ID`, `UNIT_PRICE`, not current backend spellings. Supply an upload month. Each row has a stable request identity and a recorded outcome. Re-uploading identical content for the same month is idempotent.

Admin users can provision all seven roles and link drivers to person records. Drivers receive only assigned trips, stops, vehicles and proof. Role navigation, lookup labels, report snapshots and API operations are authorized on the server. Use Account in the sidebar footer to change your password.

## Registry and architecture

- `frontend/src/registry/seededFieldRegistry.js`: unchanged initial 51 RecordTypes / 652 aliases, checked against all archived PHP Fields classes.
- `RecordFieldRegistry.js`: the single activated instance; validated, atomic metadata overrides, dynamic IDs and explicit queued-revision checks.
- `seededSharedFieldRegistry.js`: generated standalone equivalents of 13 shared Accounting/Cooperative schemas. MetaForge mode uses authorized remote definitions rather than creating duplicate remote repositories.
- `frontend/src/records/`: one presenter class for each type, composed from shared form/table/card/workspace renderers.
- `app/Libraries/Cement/`: SQL authority, permissions, transactional services, shared adapters, reports, scheduler, uploads and MetaForge proxy.
- `resources/cement/`: original seeder catalogs plus generated semantic mappings and standalone shared schemas.

Forms, reads, selectors, relationship filters, nested allocation payloads and mutation builders resolve business field codes through the registry. A metadata revision refresh clears cached records and cursors while preserving exact queued payloads. Old requests require explicit review/migration; their keys are never silently rewritten. Cache versions prevent an old replay from resurrecting a deleted row or overwriting a newer record.

## Verification

```sh
composer test
npm test
npm run check:registry
npm run build
npm run test:browser
php spark routes
```

The browser suite provisions a temporary database, signs in all seven roles, creates/edits a supplier through the UI, checks persistence, mounts all 64 collections and 78 workspace tabs, changes field codes and IDs without view changes, exercises nested allocation keys, tests CSRF/attachment access and checks desktop/mobile layouts. Screenshots go to ignored `test-results/`.

See [acceptance evidence and limits](docs/ACCEPTANCE.md). A rendering sweep is distinct from functional business-flow tests. Live MetaForge compatibility and SMTP delivery require the actual deployment configuration; they are not established by the local regression suite.

## Development specifications

Read [AGENTS.md](AGENTS.md), [the complete build specification](docs/CODEX_FRONTEND_BUILD.md), [the field registry contract](docs/FIELD_CODE_REGISTRY_CONTRACT.md), the reference HTML and the seeder ZIP before changing business behavior. The reference is a design asset, not the production frontend.
