# Download the CI4 application

Download [CementApp-CI4.zip](CementApp-CI4.zip?raw=true), extract it and follow the README inside. PHP 8.2+ and its required extensions must be installed. The ZIP includes CI4 production dependencies and built frontend assets, with empty private runtime folders.

The checksum is in [CementApp-CI4.zip.sha256](CementApp-CI4.zip.sha256). Build the package from the repository using:

```sh
python3 scripts/package_ci4.py
```

Use `--php /path/to/php --composer /path/to/composer.phar` if those commands are not on your PATH. Current native integration limits remain documented in `docs/NATIVE_METAFORGE.md` inside the package.
