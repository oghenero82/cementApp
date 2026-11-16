# Native MetaForge integration: current scope

The `native` protocol now supports server-held cookies, rotating CSRF, context checks, login/logout, password/role continuation, explicit semantic metadata, grouped incremental sync, lookups and version-checked single catalog mutations through a registered native service. TLS verification stays enabled; `METAFORGE_CA_BUNDLE` can trust a private CA. It never posts through local SQLite as a fallback.

**This is a catalog integration, not a complete native Cement transaction runtime.** Native purchase/loading/sales/stock/payroll/ledger posting, connected batches, shared Accounting/Cooperative schema translation, driver assignment scoping, native attachments/imports and reports remain unfinished. The interface displays this limitation and transaction writes remain disabled. The standalone SQL implementation continues to provide its tested workflows.

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
