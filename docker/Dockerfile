# Imagem do backend: PHP 8.3 + Composer.
# Usamos o servidor embutido do Laravel (`php artisan serve`), então NÃO há
# nginx, php-fpm nem nada além do PHP aqui. Menos peça, menos coisa pra quebrar.
#
# A base é Alpine (148 MB, contra 749 MB da versão Debian): baixa mais rápido,
# ocupa menos disco e o build não precisa compilar nada.
FROM php:8.3-alpine

# A imagem oficial do PHP já traz mbstring, pdo_sqlite, tokenizer, dom, curl...
# Só falta o `unzip`, que o Composer usa pra descompactar os pacotes, e o git.
RUN apk add --no-cache unzip git

# Composer vem pronto da imagem oficial dele — não precisa instalar na mão.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

CMD ["sh", "/app/docker/start-app.sh"]
