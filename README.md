# Cement App — CodeIgniter 4 + MetaForge 4

This repository is the canonical source for building **the complete Cement App frontend and operational application**. The uploaded sample HTML is the **visual and interaction reference**. The seeder ZIP is the **initial RecordType, module, field-code, route and service metadata contract**. Neither file is the finished production application.

## Start here (Codex)

1. **Read [AGENTS.md](AGENTS.md) and [docs/CODEX_FRONTEND_BUILD.md](docs/CODEX_FRONTEND_BUILD.md) completely.**
2. Open **[reference/Cement_App_Full_Requirements_Interactive_Frontend_Guide.html](reference/Cement_App_Full_Requirements_Interactive_Frontend_Guide.html)** locally in a browser, and reproduce its professional responsive design, dashboard cards, workspaces, detail drawers, tables, forms, reports, mobile navigation and connected operations.
3. Extract **[seeders/Cement_App_MetaForge4_Complete_Seeders_2026-11-16.zip](seeders/Cement_App_MetaForge4_Complete_Seeders_2026-11-16.zip)** and inspect the PHP RecordType Field classes and catalogs. Use them to generate **one central frontend field-code registry**. **No business component may hardcode a backend field-code string.** See [docs/FIELD_CODE_REGISTRY_CONTRACT.md](docs/FIELD_CODE_REGISTRY_CONTRACT.md).
4. Implement CI4 backend APIs consumed exclusively by the Cement frontend. Local SQL mode must work without MetaForge (persistent real data, auth, mutations, sync and business services). When connected, the CI4 adapter delegates to MetaForge Auth/Mutation/Sync. Never silently swap authoritative systems during a MetaForge outage.
5. Implement all connected business flows, test them, and ship a functioning app rather than a mockup.

## Mandatory field-code policy

- The seeder's field codes are **development-time defaults**, not literals scattered throughout screens.
- UI classes identify fields by stable *semantic registry property names*, e.g. `Fields.purchase.SUPPLIER_ID`, and get the actual field code from the registry.
- Input `name`, mutation `data` keys, synced-record reads, filters, sorting and relationships all resolve through the **same registry**.
- **MetaForge is allowed to supply a new authoritative mapping** after connection. CI4 delivers the authenticated and validated mapping with a revision; frontend activates it atomically and detects incompatible/missing fields. Do not blindly overwrite a pending mutation's schema snapshot.
- MetaForge numeric RecordType IDs are resolved by backend metadata; never hardcode them.

## Source materials

- `reference/Cement_App_Full_Requirements_Interactive_Frontend_Guide.html`: visual guidance and 13 connected workspaces with 78 tabs.
- `seeders/Cement_App_MetaForge4_Complete_Seeders_2026-11-16.zip`: 51 seeded RecordTypes, 10 modules, 7 portal definitions, 220 route definitions and 28 service registrations.
- `AGENTS.md`: project-wide Codex requirements and done criteria.
- `docs/CODEX_FRONTEND_BUILD.md`: implementation roadmap, local SQL mode and complete functional expectations.
- `docs/FIELD_CODE_REGISTRY_CONTRACT.md`: registry implementation details and acceptance tests.

**No seeder service registration should be mistaken for a working runtime handler**. Build, integrate and test the required handlers in local mode; use registered MetaForge services when available.

## Development task

See the tracked [Codex implementation issue](https://github.com/oghenero82/cementApp/issues) for an actionable checklist. Start in this repository, commit the actual CI4 application source code, implement tests, and give a repeatable setup guide.
