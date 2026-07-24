#!/bin/sh
# Sobe o Vite (CSS/JS com hot reload). Container separado so pra nao misturar
# Node dentro da imagem do PHP.
set -e
cd /app

# Esperamos o container `app` deixar a casa pronta:
#   - vendor/: o resources/js/app.js importa o Livewire e o WireFlow de la;
#     subir antes disso quebra o Vite.
#   - .env: e de onde o Vite le a APP_URL. Sem esperar, ele sobe primeiro,
#     loga "APP_URL: undefined" e so acerta no restart — o que parece erro
#     pra quem esta comecando, mas nao e.
while [ ! -d vendor/getartisanflow/wireflow ] || [ ! -f .env ]; do
    echo "==> Esperando o container app preparar vendor/ e .env..."
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
