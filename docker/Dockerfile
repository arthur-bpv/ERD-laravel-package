FROM php:8.3-alpine

RUN apk add --no-cache unzip git

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

CMD ["sh", "/app/docker/start-app.sh"]
