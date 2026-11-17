# Native MetaForge integration

The native protocol connects Cement CI4 to MetaForge using server-held cookies, rotating CSRF, context checks, HTTPS, explicit semantic aliases, dynamic RecordType IDs and grouped incremental sync. Verified native services implement purchase funding, warehouse/customer loading, sales, receipts/advances, delivery recognition, returns/refunds, inventory valuation, stock controls and reservations. Remaining native capabilities are listed below. Standalone SQL workflows are independently implemented and tested.

## Installation

Use a separate MetaForge checkout and back up its source/database. From the Cement repository:

```sh
python3 scripts/install_native_extension.py /path/to/MetaForgeApp
```

A differing extension file requires review and `--update`; replacements retain content-addressed backups. The script does not read `.env` or change a database. It installs extension classes, schema resources and authenticated routes, registers services, and patches two recognized native executor seams: trusted child-plan identity binding/result maps and subscription operation bindings. An unrecognized executor is rejected. Native cycle detection and HTTP private-plan validation remain enforced.

From that MetaForge checkout, explicitly enable the required services:

```sh
php spark cement:install-native --native-returns --native-reservations --native-stock-controls
# Optional, explicitly reviewed grant to one existing role:
php spark cement:install-native --grant-role ROLE_CODE
```

PHP BCMath is required for native posting. Individual flags are `--purchase-drafts`, `--purchase-funding`, `--native-inventory`, `--native-loading`, `--native-sales`, `--native-returns`, `--native-reservations` and `--native-stock-controls`. Each enables its prerequisites. Rerunning preserves activated handlers. Existing definitions are compared with the expected prior definitions; custom schema drift fails explicitly. No role is granted automatically. The grant option adds broad installed-type permissions to the selected role: use only a reviewed administrator/development role; configure operational role grants separately.

The original 51 Cement definitions retain their aliases. The reservation flag adds only the explicitly defined `inventory_reservation` addon and performs a checked module-ID migration. Existing shared Accounting and Cooperative RecordTypes are reused; duplicate financial engines are not installed.

Configure Cement securely:

```ini
CEMENT_DRIVER = metaforge
METAFORGE_PROTOCOL = native
METAFORGE_BASE_URL = 'https://your-metaforge-host'
# Optional private CA trust:
# METAFORGE_CA_BUNDLE = '/absolute/path/to/ca-bundle.pem'
```

`gateway` is a separate compatibility protocol. Native mode requires the authenticated capabilities endpoint. Authoritative write permission is resolved from native grants, not inferred from redacted bootstrap metadata. Remote failures never start local SQL posting.

## Business behavior

- Atomic linked batches use real native IDs, versions, registry revisions and the original UUID. Late failures roll back all children, stock projections and journals; exact replay returns the original result. Queued payload keys are never silently rewritten after metadata changes.
- Purchase drafts calculate discounted entitlement and unit snapshots. Funding posts once to existing shared journals and annual account balances. The native lifecycle remains `DRAFT` → `WAIT_POSTING` → `POSTED`; posted lines are immutable. Configure active posting accounts in institution currency and exactly one open fiscal year for the posting date.
- Confirmed movement history drives FIFO/weighted-average stock and cost layers. Effective confirmation order is preserved. Transfers conserve cost; future effects remain drafts. Catalog guards preserve a transacted product's base unit and require active transaction products/locations/suppliers.
- Confirmed loading consumes funded entitlement, splits quantities/costs between warehouses/customers, snapshots purchase ownership policy and creates linked sales. Warehouse receipt credits purchase prepaid. Partial clearance refreshes supplier counters without reposting stock.
- Dated quantity tiers, selected/customer price lists and override policy determine sale prices. Ownership events post revenue, tax, receivables and COGS. Delivery-based ownership supports partial accepted/rejected proofs and rechecks credit limits. Tax recognition follows recognised gross cents.
- Receipts identify a sale or allocate across existing invoices in date order. Excess remains a deposit; later invoices apply only its unallocated balance. Historical allocations cannot move to later invoices. Mixed receivable/deposit receipts apply deposits only against remaining recognised receivables. Journals link through authoritative source IDs; optional receipt-side journal backlinks are not fabricated by recursive parent mutations.
- Partial returns cannot exceed transferred quantity/value. They reverse tax, credit outstanding receivables, refund excess cash and optionally receive good/damaged stock at original transferred cost. All financial and inventory effects share the outer native transaction.
- Stocktakes snapshot quantity and value, reject intervening changes and post approved variances. Atomic transfers, quarantine and write-offs use the same verified movement/ledger services.
- Reservations hold available good stock against unfulfilled warehouse orders. They block unrelated issues and order resizing, are consumed by their own fulfilment, and can be released while retaining history. Holds do not change on-hand quantity or post journals.
- Customer statements project posted shared recognition, receipts, deposit applications and return/refund journals into period opening/closing balances, CSV and XLSX. This scoped projection does not grant sales roles general ledger access. Dashboard sales totals follow recognised ownership value.

## Attachments

Signatures/documents upload through Cement CI4 into immutable private MetaForge storage. Manifests include institution, uploader, RecordType, MIME and digest. Mutation validates references; download requires native type permission and ownership or an authorized persisted record reference. Supported files are PNG/JPEG/WebP/PDF, up to 5 MB. Signature references require an image. Back up MetaForge's `writable/cement-attachments` with its database. The Cement server creates no local attachment or accounting fallback. Driver access remains blocked until assignment scoping is installed.

## Verification

The real integration journeys use the uploaded MetaForge runtime, isolated MariaDB, HTTPS with a verified test CA and actual shared journals/balances. They create uniquely named test records and configure test institution policies/accounts: **run only against an isolated deployment**.

Supply `CEMENT_NATIVE_TEST_URL`, `CEMENT_NATIVE_TEST_EMAIL`, `CEMENT_NATIVE_TEST_PASSWORD`, `CEMENT_NATIVE_DIRECT_URL` and optional `CEMENT_NATIVE_TEST_CA` securely. Run:

```sh
python3 tests/cement/native-workflows.py
python3 tests/cement/native-funding.py
CEMENT_NATIVE_TEST_POLICY=fifo python3 tests/cement/native-inventory.py
CEMENT_NATIVE_TEST_POLICY=weighted_average python3 tests/cement/native-inventory.py
python3 tests/cement/native-loading.py
python3 tests/cement/native-sales.py
python3 tests/cement/native-returns.py
python3 tests/cement/native-stock-controls.py
python3 tests/cement/native-reservations.py
node tests/cement/native-browser.mjs
```

Returns/reservation journeys reuse the asserted sales/loading setup. Tests cover actual postings, bank mappings, real signature upload/download, deferred ownership, mixed receipts, advances, tax, statements, returns, reservations, replay, permissions/invariants and late-batch rollback. The normal suite currently has 69 PHP tests/448 assertions, eight JavaScript tests and seeder integrity checks. The standalone browser regression exercises 65 collections, 78 tabs, seven roles and connected business transactions.

## Remaining work

Native fleet/driver scopes, payroll/Cooperative operations, financial administration/reconciliation, all reporting schedules/imports and delegated posting permissions for operational roles remain unfinished. Native automatic posting approval currently requires administrator/accountant authority; do not grant storekeepers general financial reads as a workaround. Shared finance/Cooperative frontend schema translation is also pending. Production deployment and live email verification require the user's deployment configuration. These limits do not disable the independently tested standalone workflows.
