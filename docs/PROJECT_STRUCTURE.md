# Where to find the Cement App

The implementation is on GitHub branch `feat/issue-1-cement-application`. Opening `main` shows the earlier starting point until the implementation is merged.

| Folder | Purpose |
| --- | --- |
| `frontend/src/` | Browser screens, navigation and styles |
| `frontend/src/records/` | A presenter for each kind of business record |
| `frontend/src/workspaces/` | Connected purchase, sales, stock and other workflows |
| `frontend/src/renderers/` | Shared forms, tables and cards |
| `frontend/src/registry/` | Central field-code mappings |
| `app/Controllers/` | CodeIgniter request endpoints |
| `app/Libraries/Cement/` | Business rules, persistence, accounting and integrations |
| `public/` | Website entry point; configure the web server to serve this directory |
| `resources/cement/` | Metadata, roles, field mappings and report definitions |
| `writable/` | Private runtime database, attachments, sessions and logs |
| `integrations/metaforge/` | Extension source installed into a separate MetaForge checkout |
| `reference/` and `seeders/` | Original design and metadata inputs |
| `tests/` | Automated checks |
| `scripts/` | Installation, build and verification commands |
| `docs/` | Setup, architecture, test evidence and known limits |

A browser screen calls the Cement CodeIgniter backend. That backend uses either its local SQL authority or the configured remote MetaForge service. The browser does not connect directly to MetaForge or the database.

`MetaForgeApp.zip` contains a separate backend application. Its files are not intended to replace Cement's `app/` directory.

For local use of the prebuilt standalone ZIP, install PHP 8.2+ with the required extensions, then run `php scripts/start.php`. Open http://127.0.0.1:8080 and create the first institution and administrator. The launcher uses local SQLite and binds only to this computer. Production hosting needs the deployment configuration described in `DEPLOYMENT.md`.

## Ready-to-run CI4 distribution

The [CI4 ZIP](../downloads/CementApp-CI4.zip?raw=true) groups the runtime into standard CI4 directories, with production `vendor/`, built `public/assets/cement/` and empty `writable/` folders. See [the package guide](CI4_PACKAGE.md) for the complete layout and launch instructions. Development-only source and tests remain in the repository.
