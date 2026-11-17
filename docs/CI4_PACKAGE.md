# Cement App — CodeIgniter 4 package

Extract **CementApp-CI4.zip**. The `CementApp-CI4/` folder is the application root; do not copy its files into another CI4 application's `app/` folder. Production framework dependencies and built browser assets are included. Composer and Node are not required to run this ZIP.

## Folder layout

```text
CementApp-CI4/
  app/                 CI4 controllers, configuration, migrations, models and views
  public/              Website root: index.php and built frontend assets
  writable/            Empty private folders for database, sessions, logs and uploads
  vendor/              CI4 framework and production Composer dependencies
  resources/cement/    Field registry, roles, metadata and report definitions
  integrations/        Optional extension for a separate MetaForge installation
  scripts/             Local launcher, router and MetaForge extension installer
  docs/                Deployment guide, API contracts and current capability limits
  spark                CI4 command-line entry point
  composer.json
  composer.lock
  .env.example         Configuration template; contains no credentials
  start-cement.bat     Windows launcher
  start-cement.sh      Linux/macOS launcher
  PACKAGE.json         Source revision for this distribution
```

CI4's `system/` folder is supplied by Composer at `vendor/codeigniter4/framework/system/`, as configured in `app/Config/Paths.php`.

## Run locally

Install PHP 8.2 or newer with intl, mbstring, PDO SQLite, SQLite3, curl, fileinfo and zip enabled. In the extracted application folder run:

```sh
php scripts/start.php
```

Alternatively, use `start-cement.bat` on Windows or `sh start-cement.sh` on Linux/macOS. Open **http://127.0.0.1:8080**. Create your institution and first administrator; no default login or sample transactions are included. Keep the terminal open while using the app. The local launcher selects standalone SQLite mode. Data is stored privately under `writable/`.

## Deploy to hosting

Copy `.env.example` to `.env`, configure the actual URL and production settings, and make `writable/` writable by PHP. Configure the web server's document root as **`CementApp-CI4/public/`**. Do not expose the application root. Follow [the deployment guide](docs/DEPLOYMENT.md) for HTTPS, backups and scheduled reports. The PHP development launcher is for local use.

The standalone application and native MetaForge integration have different completion levels. See [acceptance evidence](docs/ACCEPTANCE.md) and [native integration status](docs/NATIVE_METAFORGE.md) before choosing your deployment mode. MetaForge is a separate service; its uploaded source and credentials are not bundled here.

For frontend source, tests and development tools, use the GitHub repository's `feat/issue-1-cement-application` branch. This ZIP is the ready-to-run CI4 distribution.
