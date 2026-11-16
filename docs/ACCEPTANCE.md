# Acceptance evidence

## Implemented local capabilities

The application provides SQL migrations and idempotent metadata/report seeding; first-run institution/admin setup; password login/session/logout/change; seven server-authorized role experiences; 51 seeded RecordType presenters plus 14 standalone shared schemas; 13 connected workspaces with the reference’s 78 tabs; module collections; dashboard aggregates; typed forms, related drawers, search/filter/pagination and responsive navigation.

Guided atomic editors create purchases/items, warehouse sales/items, trips/stops, payroll/items and stocktakes/count lines. Administrator role permissions are revisioned, audited and scoped by institution; payroll privacy and administrator recovery privileges remain enforced. Stock reservations maintain available quantity without changing on-hand stock, are consumed by the owning order and can be released. Customer statements reconcile invoices, deposits, applications, receipts, returns and refunds with period opening/closing balances and CSV/XLSX export. Delivery proof supports a mouse/touch/pen signature uploaded as a scoped attachment.

The transactional service layer covers purchase totals and funding, immutable funded/loaded terms, quantity adjustments, mixed customer/warehouse loading, partial clearance, dated tier pricing and snapshots, invoice receipts/refunds, ownership-triggered revenue/cost, FIFO/weighted stock costing, transfers, damages, stocktakes, delivery proof, trip cost totals, incentives, payroll and bounded payouts, shared Cooperative balances/deductions, balanced journals, fiscal close and bank reconciliation. Every batch runs under one SQLite writer transaction, and request IDs bind exact payloads.

The 20 seeded reports compute from persistent records and ledgers. Generated snapshots have their own authorization, can be exported without regeneration, and can be scheduled by timezone/period. CSV and XLSX exports are supported; PDF uses browser Print. Import preview, stable per-row identities, row outcomes and monthly upload history are implemented for CSV and the first XLSX worksheet. Attachments are MIME/size checked, stored outside public/, scoped and bound to their records.

## Checks

- `composer test`: domain and proxy tests. Tests exercise the purchase→mixed loading→clearance→sale→receipt flow, persistence, rollback, request replay, overloading, locked terms, tenant/role denial, stock ownership and valuation, partial delivery recognition, stocktake variances, payroll/Cooperative deductions, bank reconciliation, refunds, damages, schedules, XLSX safety and metadata validation. Additional regressions cover stale versions, tombstones, fiscal close and snapshot/driver scope.
- `npm test`: registry activation/override/rejection, exact offline replay, CSV parsing/conversion and static field-code access scanning.
- `npm run check:registry`: all 51 archived PHP Fields mappings equal the 652 committed aliases, backend semantic map and original catalogs match, and all 190 archive checksums verify.
- `npm run test:browser`: a temporary SQL-backed installation, real UI supplier creation/editing, guided purchase/item and sale/item batches, purchase funding, allocated warehouse loading, stock reservation and invoice consumption, partial receipt and statement reconciliation, drawn/uploaded delivery signature, stocktake variance posting, payroll salary snapshot/tax calculation, role-permission editing, reload persistence, all 65 collection routes and 78 tabs, all seven logins, unauthorized route/CSRF denial, real attachment requests, metadata-only changes to field names and RecordType IDs, nested allocation payload keys, IndexedDB ordering/tombstones and pending-request preservation across scopes, plus desktop/mobile screenshots and overflow checks.
- `php spark migrate`, `php spark db:seed CementAppSeeder`, `php spark routes`: CLI bootstrap, repeatable import and route discovery.

The rendering sweep proves routes/tabs mount, not that every possible combination of business inputs has been exhaustively tested. This is a substantial new application and needs business-owner review with representative institutional data before production use.

Local validation completed: **42 PHP tests / 151 assertions**, **7 JavaScript tests**, the seeder integrity check, install/migration/seeding/route commands, and the browser regression suite. Browser checks cover **65 collections, 10 module dashboards, 78 workspace tabs and 7 role logins**. These are local results; the GitHub Actions workflow has been added but has not been observed running.

## Integration and operating limits

- The validated standalone database is SQLite. A MySQL storage adapter has not been implemented.
- The uploaded MetaForge source was installed against an isolated MariaDB database and passed native bootstrap, session login, authorized metadata, sync, invalid-mutation rejection and logout. Its native protocol differs from the current Cement-compatible gateway contract and it contains no Cement runtime handlers. Complete Cement posting through native MetaForge remains unimplemented; see [source validation](METAFORGE_UPLOAD_VALIDATION.md). The deployed URL and secure credentials remain necessary for live verification.
- SMTP and real recipient delivery require deployment configuration and have not been live-tested. Disabled transport leaves delivery pending. A claimed send interrupted by a crash requires operator review.
- Local accounting uses one base currency. Foreign-currency posting requires an explicit conversion adapter.
- Imports support quoted/multiline CSV and the first XLSX worksheet. XLSX formulas are rejected; supply calculated values. XLSX output and browser PDF printing are supported.
- The versioned offline queue retains immutable request payloads. Cross-revision migration requires explicit backend-aware mapping/review; the application does not invent a mapping or a parallel authoritative ledger.
