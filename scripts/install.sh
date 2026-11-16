#!/usr/bin/env sh
set -eu
cd "$(dirname "$0")/.."
php -r 'foreach (["intl","mbstring","pdo_sqlite","sqlite3","curl","fileinfo","zip","dom","xml","xmlwriter","ctype"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR,"Missing PHP extension: $extension\n"); exit(1); } }'
composer install --no-interaction --prefer-dist
npm ci --no-audit --no-fund
npm run check:registry
npm run build
php spark migrate
php spark db:seed CementAppSeeder
