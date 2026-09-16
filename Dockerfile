# Base FrankenPHP com PHP 8.4 no Alpine Linux
FROM dunglas/frankenphp:1-php8.4-alpine AS base

# Instalar extensões PHP essenciais (SQLite, MySQL, Postgres, Redis, etc.)
RUN install-php-extensions \
    pdo_mysql \
    pdo_pgsql \
    pdo_sqlite \
    bcmath \
    opcache \
    pcntl \
    intl \
    zip \
    redis

# Copiar Composer oficial
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

ENV COMPOSER_ALLOW_SUPERUSER=1

# Copiar dependências primeiro para cache eficiente de camadas Docker
COPY composer.json composer.lock ./

# Instalar dependências sem scripts antes de copiar o código fonte
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

# Copiar restante do código da aplicação
COPY . .

# Preservar migrações e seeders para sincronização com volumes montados
RUN cp -r database /var/www/html/database_src

# Finalizar autoload e descoberta de pacotes do Laravel
RUN composer dump-autoload --optimize --no-dev && \
    php artisan package:discover --ansi

# Copiar configuração do servidor FrankenPHP / Caddy e PHP ini
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/php.ini /usr/local/etc/php/conf.d/uploads.ini

# Copiar e configurar permissões do script de entrypoint
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh && \
    chmod +x /usr/local/bin/docker-entrypoint.sh

# Variáveis padrão
ENV SERVER_NAME=":8000"
ENV CADDY_GLOBAL_OPTIONS="auto_https off"
ENV APP_ENV="production"
ENV AUTORUN_LARAVEL_MIGRATION="true"

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost:8000/up || exit 1

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
