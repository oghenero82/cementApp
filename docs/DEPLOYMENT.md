# Deployment and operation

## Standalone installation

Use the commands in the README. Serve **only `public/`** through an HTTPS web server and PHP-FPM in production. The included PHP development router is for local development. Set `CI_ENVIRONMENT=production`, `app.baseURL` to the HTTPS origin and `cookie.secure=true` in the deployment environment. Keep same-origin frontend/API access; no permissive CORS policy is installed. Mutations require the session’s CSRF header. Debug toolbar and page caching are disabled for API traffic.

The application currently supports **SQLite** as its standalone SQL driver. Install PDO SQLite and SQLite3; retain `writable/` between restarts and restrict it to the application service account. Back up the database and attachments together during a quiescent period or through SQLite’s backup API. Test restoring the backup. Do not expose database, sessions, logs, attachments or `.env` over HTTP. The first-run administrator is created once under a writer transaction; complete setup before making an installation public.

Local posting uses one institution base currency. Configure it before transactions; changing currency, costing or ownership after records exist requires a controlled migration. Foreign-currency conversion is rejected rather than mixed into base-currency balances.

## Scheduled reports and email

Run this command once per minute from cron or a managed scheduler, from the checkout directory:

```sh
php spark cement:reports
```

Active schedules support daily, weekly and monthly cadence in their configured IANA timezone. A schedule/period key makes generation idempotent across workers. It saves `report_result` rows and queues separate `report_delivery` records. CSV and XLSX are generated from saved rows; PDFs use the application’s browser print layout.

Email is **off by default**. Configure the standard CI4 Email transport (`email.protocol`, `email.SMTPHost`, `email.SMTPPort`, `email.SMTPUser`, `email.SMTPPass`, `email.SMTPCrypto`, `email.fromEmail`, `email.fromName`) through secure deployment settings, then set `CEMENT_REPORT_EMAIL_ENABLED=1`. Never put credentials in Git. The CLI reports `pending_configuration` while disabled and never reports delivery success without transport acceptance.

A worker claims a delivery before sending and records the result with its own mutation cursor. Failed deliveries retry at most three times. A process crash after claiming leaves a `sending` record for operator review; do not reset it automatically, because SMTP cannot guarantee exactly-once delivery across a crash. Verify provider delivery logs before an operator retries an uncertain send. Actual SMTP delivery has not been validated in this cloud environment.

## MetaForge adapter contract

Set `CEMENT_DRIVER=metaforge` and an HTTPS `METAFORGE_BASE_URL`. Optional `METAFORGE_API_PREFIX` defaults to `api`. The remote endpoint must implement the following Cement-facing contract, or provide an explicit compatibility adapter. The supplied seeder ZIP alone does not establish runtime API compatibility.

- `POST auth/login` accepts the login input and returns `user` plus `accessToken`. User contains `id`, `institution_id`, `role`, `display_name`, and `email`. The token stays in the server session and is not returned to browser code.
- Authenticated `GET auth/session`, `POST auth/logout`, and `POST auth/password` implement session restoration, revocation and password change.
- `GET meta/field-registry` returns `revision`, `fieldsByRecordType`, unique positive `recordTypeIds`, `scope`, `currency` and authorized `descriptors`. Descriptors follow the local endpoint: title, module, fields with semantic alias/type/required/target, read-only aliases, service ownership and write permission. All required semantic aliases must be present; blanks, unknown aliases, collisions and invalid IDs fail explicitly.
- `GET portal/navigation` supplies authorized modules, workspaces, reports and capability flags. The browser does not select its own role.
- `POST mutation` accepts `{requestId, registryRevision, recordTypeCode, recordTypeId, operation, recordId?, version?, data}` or an atomic `batch`. It returns `mutationId`, `results`, `resultMap`, `state`, and authorized `changes` in the sync-row shape. The same request ID must return the same result for the same payload, and reject a different payload.
- `GET sync?cursor=&afterId=` returns `records`, `cursor`, `afterId`, `hasMore`, `scope`, `registryRevision`. Rows contain dynamic type ID/code, actual record ID, mutation ID, version, physical table ID, deletion marker and exact authoritative data keys. Scope must change when permissions/assignment change. Metadata revision changes require a full snapshot in the new mapping.
- `GET lookups?recordTypeCode=&alias=` resolves a declared semantic relationship and returns authorized `{id,label}` items without exposing restricted target fields.
- The dashboard, reports, saved-result, imports, users, permissions, customer statement, settings, ledger, audit and health endpoints follow the local API response contracts. Reports return JSON rows/columns; Cement can render authorized CSV/XLSX locally without performing business posting.
- Spreadsheet preview accepts multipart `attachment` and `recordTypeCode` at `POST imports/preview`. Attachment upload accepts multipart `attachment` and `recordTypeCode` at `POST files/upload`; `GET files/{id}` must enforce institution and record permission. Return an opaque reference and a local `/api/files/{id}` download path.

HTTPS certificate verification remains enabled, redirects are disabled and server sessions carry the bearer token. A failed remote request returns an error with **no local posting fallback**. Configure remote host egress and authentication through environment settings. The adapter tests use an injected transport and validate forwarding, token isolation, TLS policy and outage behavior; the uploaded native MetaForge source has separately passed isolated bootstrap/login/sync checks. It does not implement this gateway contract; see [uploaded-source validation](METAFORGE_UPLOAD_VALIDATION.md) for the verified differences and remaining integration work.

## Controlled cutover

Do not change the driver as an outage workaround. A cutover requires a write freeze; export of records, immutable price/conversion snapshots, journals, costs and request history; dynamic RecordType/record ID mapping; reconciliation of stock, entitlements, receipts and ledger balances; migration or explicit rejection of pending client requests by revision; a clean authorized full sync; and verified rollback/backup procedures. Local IDs are never assumed equal to MetaForge IDs. Reconcile the target before enabling writes.

## Cloud task startup

Use the existing checkout. Cloud tasks already run in an isolated workspace; do not create a worktree unless requested. Retained dependencies and generated assets can be reused. Start a new server process after restoring a snapshot; running processes do not survive publication. Read the saved environment startup instructions for the current workspace-local PHP path when applicable.
