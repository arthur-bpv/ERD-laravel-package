# Imagem do backend: PHP 8.3 + Composer.
# Usamos o servidor embutido do Laravel (`php artisan serve`), então NÃO há
# nginx, php-fpm nem nada além do PHP aqui. Menos peça, menos coisa pra quebrar.
FROM php:8.3-cli

# A imagem oficial do PHP já traz mbstring, pdo_sqlite, tokenizer, xml, curl...
# Só falta a `zip` (Composer usa pra descompactar pacotes) e o git.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev \
    && docker-php-ext-install -j"$(nproc)" zip \
    && rm -rf /var/lib/apt/lists/*

# Composer vem pronto da imagem oficial dele — não precisa instalar na mão.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

CMD ["sh", "/app/docker/start-app.sh"]
