#!/bin/sh
# Sobe o backend. Roda toda vez que o container `app` inicia, mas cada passo
# só acontece se ainda for necessário — subir de novo é rápido.
set -e
cd /app

if [ ! -d vendor ]; then
    echo "==> Instalando dependencias PHP (composer install)... isso demora so na primeira vez"
    composer install --no-interaction --prefer-dist
fi

if [ ! -f .env ]; then
    echo "==> Criando .env a partir do .env.example"
    cp .env.example .env
fi

# APP_KEY vazio = erro de "encryption key" logo na primeira pagina.
if ! grep -q '^APP_KEY=base64:' .env; then
    echo "==> Gerando APP_KEY"
    php artisan key:generate --force
fi

# O banco e um arquivo SQLite: nao existe container de banco de dados.
if [ ! -f database/database.sqlite ]; then
    echo "==> Criando o banco SQLite (database/database.sqlite)"
    touch database/database.sqlite
fi

echo "==> Rodando migrations"
php artisan migrate --force

echo ""
echo "==> Aplicacao em http://localhost:8000"
echo ""
exec php artisan serve --host=0.0.0.0 --port=8000
