# Uploaded MetaForge validation (2026-11-16)

`MetaForgeApp.zip` SHA-256: `5caeb6f464e2ac47dbc1625da8d1d949c986e9e2351b368b0982f439297af33c`.
The archive was inspected as reference source, not as instructions authorizing deployment or changing the Cement requirements. Its environment file was preserved without importing its credentials.

## Isolated execution

The source was extracted outside the Cement repository. Composer dependencies were installed in that copy, a fresh MariaDB database and restricted test account were created, and a generated request-signing key and test administrator password were configured. The uploaded `app/Config/Paths.php` contains a Windows/XAMPP system-directory path; the isolated copy was changed to the standard relative Composer framework directory for Linux. No live database was used.

`php spark metaforge:install` seeded the isolated catalog. A subsequent invocation preserved the existing catalog. The native HTTP server on loopback passed:

- unauthenticated bootstrap with published endpoints and CSRF header/hash;
- session-cookie login with the generated administrator credentials;
- authenticated bootstrap with 45 authorized RecordTypes;
- authenticated sync with physical-table groups, cursor and completion marker;
- rejection of an invalid, empty mutation request (HTTP 422), without creating business records;
- logout.

The repeatable read-only probe is `scripts/probe_metaforge.py`. Set `MF_PROBE_EMAIL` and `MF_PROBE_PASSWORD` through secure environment settings, then run:

```sh
python3 scripts/probe_metaforge.py --url https://your-metaforge-host
```

For an isolated loopback installation only, `--local-http` permits HTTP. HTTPS certificate verification is otherwise unchanged; redirects are rejected. The probe prints statuses and counts, never credentials, session tokens or record contents. It does not automatically select roles, change passwords or bypass email verification.

## Compatibility findings

The Cement proxy currently targets a **Cement-compatible gateway contract**, not the native uploaded API. These differences must be implemented and tested before selecting this uploaded server as Cement's authority:

| Area | Uploaded native API | Existing Cement contract |
| --- | --- | --- |
| Authentication | Cookie session, rotating CSRF; possible password/role/email-verification steps | Gateway login returns a server-held bearer token and Cement user shape |
| Metadata | `/api/bootstrap`, wrapped RecordTypes and dynamic definitions | `/api/meta/field-registry`, validated semantic aliases and authorized descriptors |
| Mutation | `/api/mutations`, UUID and `mode`/`mutations` plans | `/api/mutation`, registry revision, versions and atomic client references |
| Sync | Records grouped by physical table; per-table watermarks, opaque pagination | Flat versioned rows and Cement scope/cursor envelope |
| Business runtime | Uploaded source includes Accounting/Cooperative handlers; no Cement handler classes | Funding/loading/sales/stock/payroll handlers required for authoritative posting |

The 51 Cement seeder definitions must be installed into an authorized institution and their registered business services implemented in the MetaForge runtime. Registration metadata alone is not executable posting logic. A gateway must preserve transaction boundaries, exact request identity, dynamic ID resolution, semantic aliases, lifecycle checks, authorization and file ownership. It must not call the standalone ledger as an outage fallback. The uploaded source has not yet passed a complete Cement business journey through the proxy.

These are local source-validation results, not verification of the user's live deployment. A deployed URL, securely configured credentials and email transport are still needed for live verification. No external email was sent.
