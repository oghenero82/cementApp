# Native MetaForge integration: current scope

The `native` protocol now supports server-held cookies, rotating CSRF, context checks, login/logout, password/role continuation, explicit semantic metadata, grouped incremental sync, lookups and version-checked atomic catalog batches and linked purchase/item drafts through a registered native service. TLS verification stays enabled; `METAFORGE_CA_BUNDLE` can trust a private CA. It never posts through local SQLite as a fallback.

**This is a catalog integration, not a complete native Cement transaction runtime.** Native funding/loading/sales/stock/payroll/ledger posting, shared Accounting/Cooperative schema translation, driver assignment scoping, native attachments/imports and reports remain unfinished. The interface displays this limitation and transaction writes remain disabled. The standalone SQL implementation continues to provide its tested workflows.

## Install into a separate MetaForge checkout

Back up the target source and database first. Run from the Cement repository:

```sh
python3 scripts/install_native_extension.py /path/to/MetaForgeApp
```

Review the copied extension classes, registry registration and capabilities route. The script does not read `.env`, configure credentials or change a database. A differing extension file is rejected unless `--update` is explicitly supplied; updates retain content-addressed backups.

In that MetaForge checkout, install the schemas explicitly:

```sh
php spark cement:install-native
```

This preserves matching definitions and rejects schema drift. It does not replace shared Accounting/Cooperative definitions or enable their disabled financial services. To grant the new module/types to a specific existing role, use `--grant-role ROLE_CODE`; this also adds the module to that role's portal and institution grants. Review the selected role carefully. No default role grant is performed. Existing checked capability metadata can be migrated with `--upgrade`.

Configure the Cement server securely:

```ini
CEMENT_DRIVER = metaforge
METAFORGE_PROTOCOL = native
METAFORGE_BASE_URL = 'https://your-metaforge-host'
# Optional private CA trust:
# METAFORGE_CA_BUNDLE = '/absolute/path/to/ca-bundle.pem'
```

`gateway` remains the separate compatibility-gateway protocol. Native mode requires the extension's authenticated `/api/cement/capabilities` endpoint; it does not infer write permissions from redacted role metadata.

## Evidence

Five additional unit tests verify cookie/CSRF isolation and rotation, HTTPS enforcement, explicit field aliases/dynamic IDs, grouped sync normalization and exact native service-request identity. A local HTTPS reverse proxy with a trusted test certificate was used to exercise the actual uploaded MetaForge installation: login, 51-schema metadata, navigation, sync, persisted supplier creation, exact request replay and logout passed. This did not test the user's live deployment or native transaction posting.

## Verified native batches and purchase drafts

The extension now resolves explicitly declared earlier-record references inside one MetaForge mutation transaction. Native database-assigned IDs populate the result map. A failed child aborts the complete batch, and exact request replay returns the original result. HTTP record plans cannot submit the private binding keys; only the trusted service can produce them.

This requires a checked update to MetaForge's `RecordExecutor::serviceChildren` seam. The installer recognizes the original implementation, backs it up, adds the trusted binding executor and propagates its result map. An unrecognized implementation is rejected for review. It does not remove financial guards.

To activate purchase drafts in an existing isolated installation:

```sh
python3 scripts/install_native_extension.py /path/to/MetaForgeApp --update
# From that MetaForge checkout:
php spark cement:install-native --purchase-drafts
```

The migration compares the previous definition before changing its hooks. Existing customizations fail explicitly. Rerunning installation preserves activated draft handlers. Purchase items calculate discounted cost, base quantity and conversion snapshots; native child hooks recompute parent totals. Currency follows institution settings. Native decimal values are serialized according to MetaForge's number contract and normalized for the Cement frontend. Funding is disabled in forms/actions and rejected server-side.

The repeatable integration journey writes uniquely named test records and must target an isolated test deployment. Supply `CEMENT_NATIVE_TEST_URL` (the Cement server), `CEMENT_NATIVE_TEST_EMAIL` and `CEMENT_NATIVE_TEST_PASSWORD` through secure settings, then run `python3 tests/cement/native-workflows.py`. It verifies purchase draft calculations, linked IDs, replay, late-failure rollback, synchronization, logout and refusal to fabricate funding.

Latest checks: 51 PHP tests / 188 assertions; seven JavaScript tests; seeder integrity; standalone browser business journeys plus 65 collections, 78 tabs and seven roles; actual native integration journey. These results do not establish production deployment, native financial posting or email delivery.

## Verified shared Accounting funding

The native extension now implements purchase funding using the existing shared `journal`, `journal_line` and `account_balance` types. No parallel remote ledger types are created. The checked migration replaces the shipped disabled journal/balance guards with verified hooks while preserving other domain guards and the native journal lifecycle (`DRAFT` → `WAIT_POSTING` → `POSTED`).

To opt in after source review, run `php spark cement:install-native --purchase-funding --grant-role ROLE_CODE` in MetaForge. PHP BCMath is required. Configure active posting accounts in the institution currency and exactly one open fiscal year containing the posting date. Purchase forms select existing authorized GL accounts through label-only lookups.

Verified against the real isolated MetaForge database: balanced funding, shared GL balance updates, exact replay without duplicate postings, posted-line immutability, rejection of an unbalanced journal, and rollback of the purchase, journal, lines and balance changes when a later batch child fails. Run `tests/cement/native-funding.py` with the existing native test settings plus `CEMENT_NATIVE_DIRECT_URL` and optional `CEMENT_NATIVE_TEST_CA`; it configures uniquely named GL test records and is for isolated test installations only.

The installer also adds operation bindings to the recognized native subscription-execution seam; the original runtime exposed the operation to conditions but omitted it from service parameter bindings. Source changes are checked and backed up. Native payroll posting remains in development; see the subsequent verified inventory/sales section for newer capabilities.

## Native inventory, loading, sales and physical controls

The extension now has verified handlers for `--native-inventory`, `--native-loading`, `--native-sales`, and `--native-stock-controls`. Later flags enable their prerequisites, and rerunning installation preserves already enabled handlers. Run the installer in the MetaForge checkout, after reviewing source changes. Each schema migration verifies the shipped prior definition and rejects custom drift.

Confirmed movement history drives stock and cost layers, with FIFO or weighted-average costing, conserved transfer costs, immutable confirmation, reservation-aware availability and future-date guards. Warehouse loading consumes funded supplier entitlement and moves shared GL prepaid value into inventory. Mixed customer/warehouse allocations create linked native sales with purchase ownership and cost snapshots. Partial clearance updates supplier counters without reposting stock.

Native invoices use dated quantity tiers and customer price lists. Price overrides follow institution settings. Warehouse sales consume native stock; direct loading sales consume prepaid cost. Shared journals post revenue, tax, receivables and COGS at the snapshotted ownership event. Partial payments received before recognition stay in customer deposits; accepted delivery applies those deposits to receivables. Posted payments, invoice terms and proofs are immutable. Receipts may identify a sale or allocate across outstanding customer invoices in date order. Excess receipts remain deposits; later invoices apply only the unallocated balance, preserving every historical allocation. Journals link to receipts through authoritative source RecordType/record IDs; the optional receipt-side journal field is not fabricated or populated by an unsafe recursive mutation.

Signature images and other attachments now upload through Cement CI4 into private storage on MetaForge. Immutable blob/manifests record institution, uploader, type, digest and MIME. References are validated before mutation; download requires native type permission plus ownership or an authorized persisted record reference. Back up MetaForge's `writable/cement-attachments` with its database. No attachment or ledger fallback is created on the Cement server. Native driver access remains blocked until assignment-scoped reads are installed.

Customer statements derive recognition, receipts and deposit applications from posted shared journals, with date-period opening/closing balances and CSV/XLSX export. The narrowly scoped statement service does not give sales roles access to general ledger records. Dashboard sales totals follow recognised ownership value.

Stock controls post physical counts and approved adjustments, atomic transfers, quarantine and write-off decisions. Count lines snapshot both quantity and value; posting rejects intervening stock changes. All effects use the same native movement/ledger transaction, so later failures roll back projections and journals together.

Run `tests/cement/native-inventory.py` separately with `CEMENT_NATIVE_TEST_POLICY=fifo` or `weighted_average`; `native-loading.py`, `native-sales.py` and `native-stock-controls.py` exercise the additional journeys. They write uniquely named records and configure test institution accounts/policies: **use an isolated test deployment only**. Tests use actual HTTPS transport with verified certificates and actual shared Accounting records, not a local SQL accounting substitute. Native sales includes real signature upload/download, partial delivery recognition, deposit application, statement periods, exact replay and late-batch rollback.

Remaining native work includes returns, reservations, fleet/driver scopes, payroll/Cooperative operations, financial administration/reconciliation, all reporting schedules/imports and delegated posting permissions for operational roles. Current automated native posting approval is restricted to administrators/accountants; do not grant storekeepers general financial reads as a workaround. Production deployment and live email transport verification still require the user's deployment configuration. The standalone SQL workflows remain independently tested.

Native catalog guards also preserve a transacted product’s base unit, reject negative credit limits and invalid dated price tiers, and require active transaction products/locations/suppliers. Delivery proof rejects quantities already accepted or rejected, validates destination links, supports rejection-only proofs, and rechecks customer credit at recognition. Tax allocation follows recognised gross cents, preventing tax from advancing without gross revenue.
