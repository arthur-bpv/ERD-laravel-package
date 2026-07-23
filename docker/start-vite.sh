#!/bin/sh
# Sobe o Vite (CSS/JS com hot reload). Container separado so pra nao misturar
# Node dentro da imagem do PHP.
set -e
cd /app

# O resources/js/app.js importa arquivos de dentro de vendor/ (Livewire e
# WireFlow). Se o Vite subir antes do composer install terminar, ele quebra.
while [ ! -d vendor/getartisanflow/wireflow ]; do
    echo "==> Esperando o composer install terminar (container app)..."
    sleep 3
done

if [ ! -d node_modules ]; then
    echo "==> Instalando dependencias JS (npm install)... isso demora so na primeira vez"
    npm install
fi

echo ""
echo "==> Vite em http://localhost:5173"
echo ""
exec npm run dev
