# AGENTS.md — Cement App (binding instructions for Codex)

**Mission:** Build a complete, production-quality CodeIgniter 4 Cement Dealer application in this repository, visually guided by the single HTML prototype and semantically guided by the complete MetaForge 4 seeder. Do not stop at a pretty demo, a metadata catalog or generic CRUD.

## Mandatory source reading

- `reference/Cement_App_Full_Requirements_Interactive_Frontend_Guide.html` — visual/interaction blueprint; 13 business workspaces, 78 tabs, 51 RecordType collection and form previews. Its dashboard figures and login role-switcher are illustrative only.
- `seeders/Cement_App_MetaForge4_Complete_Seeders_2026-11-16.zip` — extract and inspect **every applicable PHP Fields and RecordType/route/module/service seeder class** before implementing any business screen. Its field codes are the starting contract, not separately imagined properties.
- `docs/FIELD_CODE_REGISTRY_CONTRACT.md` — field-code and metadata synchronization rules.
- `docs/CODEX_FRONTEND_BUILD.md` — full build plan and acceptance conditions.
- `frontend/src/registry/seededFieldRegistry.js` — initial, generated field-name mapping, checked against ZIP.

## Non-negotiable frontend field registry rule

**DO NOT hardcode literal backend field codes in any form, table, filter, data reader, mutation builder, nested payload, validation, search or synchronization code.**

Use semantic **registry constants** and one *shared* registry instance. For example:

```js
const F = registry.fields('purchaseItem');   // initial values from seeder
formInput.name = F.UNIT_PRICE;                // not a string such as 'unitPrice'
const unitPrice = record[F.UNIT_PRICE];       // same key when reading synced rows
payload.data[F.UNIT_PRICE] = amount;          // same key when mutating
```

Even when HTML prototypes contain raw registry code strings, production screens are prohibited from embedding those strings. Literal business field codes are permitted **only** inside generated registries, signed/validated backend metadata, fixtures and registry-specific tests.

The **seeder ZIP defines the initial values**. Later, **the authoritative MetaForge RecordType metadata may update these field-code values**. Cement CI4 must expose an authorized `GET /api/meta/field-registry` contract; the frontend merges/replaces the registry atomically by revision after validation. Never silently change a queued mutation’s keys; retain the registry revision used when it was drafted and require mapping/migration for schema changes. Validate that the actual authorized metadata contains all required semantic aliases; fail explicitly rather than guessing or submitting unknown fields. Distinguish RecordType *code* from server-resolved RecordType *ID*. Dynamically resolve IDs, including for lookups.

In `data-name`, `name`, `data-field`, table column selectors, filters and relation builders: **all names derived from the registry**. An individual RecordType presenter may define **semantic alias names** (`SUPPLIER_ID`, `STATUS`), but not their corresponding backend spellings.

## Architecture

- Frontend: HTML/CSS/JS component/presenter classes, **one class per RecordType**, reusable `CardRenderer`, `TableRenderer`, `FormRenderer`, `WorkspaceRenderer`, `PageManager`, `RouteLinkBuilder`; mount/bind/destroy, responsive design; use the provided HTML for appearance.
- UI calls **only Cement CI4 endpoints** for auth, session, metadata, mutation, sync, reports, files and services. Never call MetaForge directly from browser.
- CI4 has configurable **local SQL** (SQLite or MySQL) and **MetaForge proxy** drivers. If no MetaForge was configured for initial setup, create tables/migrations, persist full data locally, run transactional, idempotent business services and expose same API shape. Local SQL is durable and authoritative in local mode.
- A temporary MetaForge outage is **not** a reason to start parallel authoritative ledger posting in local SQL. Queue only safely replayable unsent client requests, retain requestId, and require controlled cutover/migration for switching authoritative backends.
- Frontend caches synced authorized records in IndexedDB keyed by `[recordTypeId, recordId]`, with mutation cursor and soft-delete application. No optimistic `POSTED` or `CONFIRMED` state before backend acknowledgement.
- CI4 owns server-side permissions. Portal navigation is produced by authenticated backend context, not the demo hardcoded role menu.

## Business flows / all required screens

1. First-run: local SQL creation/migrations, institution setup, roles/users, initial registry, seed import, portal navigation.
2. Shared catalogs: persons, suppliers, supplier depots/codes/PIN/BIN, customers, products/categories/UOM, supplier products, dated prices and customer-specific prices, branches, bank/GL mappings.
3. Purchases: purchase root + purchaseItem roots, payment/entitlement posting (Dr prepaid, Cr bank once), quantity counters, immutable product/unit/price/discount/original quantity after first loading, supplier incentives and adjustments.
4. Loading: link existing purchaseItem, partial loading/clearing, split allocations to customer(s) and/or warehouse(s), quantity validations, supplier PIN/BIN, truck/driver, ownership at loading vs delivery as configured, duplicate-posting prevention.
5. Sales: customer sale + root sale items, agreed price snapshots, discounts/tax, order-to-cash, partial payment, returns, credit limits, delivery proof and customer statement.
6. Inventory: location, stock, effective movement, stock condition and bad goods, stocktakes/count lines, approved adjustments, transfers, FIFO/weighted average valuation and cost layers. Never manually mutate on-hand stock from forms.
7. Fleet: vehicle/driver, trips with multiple ordered trip_destination records, fuel/maintenance/expenses, signed delivery proof, trip allocations and profitability.
8. Staff: persons/employment, payroll batches/items/payments, monthly salary upload **history**, deductions, approved savings and loans **reusing Cooperative RecordTypes** (no parallel duplicate loan engine).
9. Accounting: shared MetaForge Accounting RecordTypes when available; local equivalent when standalone; journals, account balances, reconciliations, balanced posting, reporting, controls and audit.
10. Reports: implement the 20 seeded report definitions, saved results, schedules, CSV/XLSX/PDF where supported, filters, dashboards. Replace sample values with real aggregates.
11. System Admin: importer in sidebar with spreadsheet/CSV preview, validation, idempotent per-row request IDs, monthly upload history and manual sync; policies, authorization, audit and health status.
12. Roles: system admin, admin, accountant, sales manager, store keeper, data entry and driver. Actual screens/routes/actions filtered **server-side**.

## Safety and data correctness

- All money movements, inventory postings, cost changes, approval transitions, invariants and lifecycle state transitions are enforced **server-side**, inside one database transaction per batch.
- Seeder service *registrations* are not runtime handlers: implement local service handlers and tests; when MetaForge is active delegate only to supported authoritative services.
- Never invent successful posting or mark locally queued/failed requests posted.
- Idempotency by institution + requestId; atomic multi-record mutations; reference resolution; searchable sync; auditing.
- Keep backward-compatible field maps and migration strategy, never silently rewrite historical transaction snapshots.

## Definition of done

A functional CI4 project (composer requirements, migrations, env example, seed importers, services, APIs, test suite and deploy guide), complete independent responsive frontend reproducing the sample, connected workspace transactions, one authoritative field-code registry (seeded then MetaForge-overridable), real local SQL mode, real auth and sync, reliable MetaForge adapter, tests proving all locked flows, documented installation and regression steps. Do not present a static prototype as completed development.

Use small, verifiable commits and a PR summarizing functionality + test output. Keep a single cumulative working application; no scattered patches.
