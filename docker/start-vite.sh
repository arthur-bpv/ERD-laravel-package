#!/bin/sh
set -e
cd /app

while [ ! -d vendor/getartisanflow/wireflow ] || [ ! -f .env ]; do
    echo "==> Waiting for the app container to prepare vendor/ and .env..."
    sleep 3
done

if [ ! -d node_modules ]; then
    echo "==> Installing JavaScript dependencies (npm install)... first run only"
    npm install
fi

echo ""
echo "==> Vite at http://localhost:5173"
echo ""
exec npm run dev
