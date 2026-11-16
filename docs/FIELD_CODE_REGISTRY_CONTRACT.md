# Field-code registry: binding contract for Codex

## Source of truth and lifecycle

1. **Seeded defaults**: unpack `seeders/Cement_App_MetaForge4_Complete_Seeders_2026-11-16.zip`. Read the 51 RecordType `Fields/*Fields.php` classes, and use their symbolic constants and string values to build `frontend/src/registry/seededFieldRegistry.js`. The committed initial registry contains 51 types / 652 fields and must be checked for drift in CI.
2. **Standalone/local SQL mode**: use the seeded registry and locally assigned numeric RecordType IDs from the Cement CI4 database metadata. Do not assume MetaForge is installed.
3. **MetaForge mode**: after authenticated session restoration, Cement CI4 queries authorized MetaForge RecordType definitions, resolves the actual codes by declared semantic alias and ID, and responds to `GET /api/meta/field-registry`. Revisions are explicit. Never fetch definitions directly from MetaForge in frontend.
4. **Consistency**: render / edit / validate / read synced records / filter / sort / build mutations with a single activated registry revision. If a required alias was renamed or removed without a mapping, show a blocking schema compatibility error rather than guessing.
5. **Offline mutation handling**: persist outbox entry with `requestId`, `registryRevision`, `recordTypeCode`, `recordTypeId` when known, `operation`, exact data field keys and creation timestamp. An old queued payload cannot be silently rewritten when its registry revision differs. Require server-aware explicit migration/review; preserve requestId only for replay of identical semantics.

## A rule for every UI class

**Allowed**:
```js
const P = recordFieldRegistry.fields('purchase');
const form = '<input name="' + P.SUPPLIER_ID + '">';
const supplierId = record[P.SUPPLIER_ID];
const payload = { [P.SUPPLIER_ID]: selectedSupplierId };
```

**Forbidden** outside generated registry/metadata import/tests:
```js
form.setAttribute('name', 'supplierId');
record.supplierId;
mutation.data = { supplierId: selectedSupplierId };
```

This rule includes `status`, `name`, `amount`, `code`, nested objects, arrays, reference fields, UI form attributes and table column names. Stable **semantic registry symbols** are okay; literal backend code strings are not.

## Runtime contract

Cement CI4 authenticated API:

`GET /api/meta/field-registry`

Example response (illustrative values only; real server resolves IDs):
```json
{
  "revision": "mf4:institution-123:definition-revision-6",
  "source": "metaforge",
  "fieldsByRecordType": {
    "purchase": {
      "SUPPLIER_ID": "supplierId",
      "SUPPLIER_PIN": "supplierPin"
    },
    "purchaseItem": {
      "PURCHASE_ID": "purchaseId",
      "UNIT_PRICE": "unitPrice"
    }
  },
  "recordTypeIds": {
    "purchase": 42,
    "purchaseItem": 43
  }
}
```

- The real API must enforce session, institution isolation and visibility. The response may include *only authorized* RecordTypes and fields. Add contract tests for schema revisions, unknown aliases, collision detection and stale queued requests.
- Backend can return full mappings. The committed `RecordFieldRegistry.js` accepts validated partial overrides for the known seed aliases, but must be extended if authorized metadata introduces new semantically mapped fields and new RecordTypes.
- Keep an explicit backend mapping from stable semantic alias to current MetaForge field code. If MetaForge renames its actual code, the alias remains stable; do not infer alias from a human label.
- Rebuilding a registry should **not** create or modify backend RecordType definitions. The seeder is only the initialization source; authorized MetaForge metadata is authoritative when connected.

## Mutation contract

- Frontend uses one `MutationClient` calling **Cement CI4** `/api/mutation`; no direct MetaForge calls.
- Use normalized envelope `{requestId, recordTypeId, operation, data}` for MetaForge adapter. Local mode may resolve by `recordTypeCode` then apply the same validation / transaction semantics. Batch holds multiple records and uses **one transaction + mutationId**.
- `data` keys are values returned by current registry. UI may use symbolic aliases internally but translate **exactly once** before sending. The same registry reads the resulting sync records.
- Query options, relationship filters, nested mapping arrays, server validations and form rendering must not bypass the registry.
- Missing fields reject explicitly and visibly; unknown field values are not accepted by the local mutation service.
- Numeric RecordType IDs are never hardcoded in screens.
- Every monetary/inventory posting action is server authoritative.

## IndexedDB sync

- Shared store `record` keyed by `[recordTypeId, recordId]`; record also tracks physicalTableId, mutationId, deleted.
- Cursor per authorized scope, fetch rows at or after last mutationId, deduplicate idempotently and remove soft-deleted records.
- Use one DB name for worker and main thread. Log conflicts, missing referenced IDs and rejections rather than swallowing them.
- Extra sync after mutation only if next scheduled sync would be more than one minute away.
- First-run local SQL mode still uses CI4 sync API. IndexedDB is a cache/outbox, **not** the authoritative database.

## Acceptance tests

- One build-time checker scans JS, HTML templates and PHP view source for raw field-code literals outside registry/config/tests and fails on violations.
- A seeder drift test extracts every Fields PHP class and matches it to the seeded JS registry.
- Build a purchase form, purchase collection, loading allocation form and customer sale editor; change actual codes in a mock metadata response (e.g. `supplierId` -> `supplierAccountRef`), verify all form names, reads, query filters and posted data keys change with **zero view edits**.
- Old offline outbox entries with old registry revision must not be replayed silently.
- Field-code collisions, unknown aliases, unrecognized MetaForge IDs and unauthorized metadata reject clearly.
