#!/usr/bin/env bash
set -e

cd /var/www/html

# Bootstrap .env on first run
if [ ! -f .env ]; then
    cp .env.example .env
fi

# Install PHP dependencies first (vendor/ must exist before any artisan call)
composer install --no-interaction --ansi

# Generate app key if empty (requires vendor/)
if grep -qE "^APP_KEY=$" .env; then
    php artisan key:generate --ansi
fi

# Install Node dependencies and compile assets
if [ ! -d node_modules ]; then
    npm install --silent
    npm run build
fi

# Run pending migrations
php artisan migrate --force --ansi

exec php artisan serve --host=0.0.0.0 --port=8000
