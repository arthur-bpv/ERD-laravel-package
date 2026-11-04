#!/bin/sh
set -e
cd /app

if [ ! -d vendor ]; then
    echo "==> Installing PHP dependencies (composer install)... first run only"
    composer install --no-interaction --prefer-dist
fi

if [ ! -f .env ]; then
    echo "==> Creating .env from .env.example"
    cp .env.example .env
fi

if ! grep -q '^APP_KEY=base64:' .env; then
    echo "==> Generating APP_KEY"
    php artisan key:generate --force
fi

if [ ! -f database/database.sqlite ]; then
    echo "==> Creating the SQLite database (database/database.sqlite)"
    touch database/database.sqlite
fi

echo "==> Running migrations"
php artisan migrate --force

echo ""
echo "==> Application at http://localhost:8000"
echo ""
exec php artisan serve --host=0.0.0.0 --port=8000
