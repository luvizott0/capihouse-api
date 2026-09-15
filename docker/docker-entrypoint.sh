#!/bin/sh
set -e

echo "==> Iniciando inicialização do CapiHouse API..."

# Garantir existência do diretório e arquivo do SQLite se configurado
DB_CONN="${DB_CONNECTION:-sqlite}"
if [ "$DB_CONN" = "sqlite" ]; then
    echo "==> Configurando banco SQLite..."
    mkdir -p /var/www/html/database
    if [ ! -f /var/www/html/database/database.sqlite ]; then
        echo "==> Criando arquivo database/database.sqlite..."
        touch /var/www/html/database/database.sqlite
    fi
fi

# Sincronizar migrações e seeders da versão atual da imagem para o volume database
if [ -d "/var/www/html/database_src" ]; then
    echo "==> Sincronizando migrações e seeders da versão atual..."
    mkdir -p /var/www/html/database/migrations /var/www/html/database/seeders
    cp -a /var/www/html/database_src/migrations/. /var/www/html/database/migrations/ 2>/dev/null || true
    cp -a /var/www/html/database_src/seeders/. /var/www/html/database/seeders/ 2>/dev/null || true
fi

# Garantir estrutura de pastas do storage e bootstrap/cache
mkdir -p /var/www/html/storage/framework/cache/data \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/logs \
         /var/www/html/storage/app/public \
         /var/www/html/storage/app/private \
         /var/www/html/bootstrap/cache

# Ajustar permissões para o usuário web (www-data)
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/database || true
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/database || true

# Criar link simbólico do storage público
if [ ! -L /var/www/html/public/storage ]; then
    echo "==> Criando link simbólico do storage..."
    php artisan storage:link || true
fi

# Garantir chave de aplicação se não estiver configurada
if [ -z "$APP_KEY" ]; then
    echo "==> APP_KEY não informada! Gerando chave de aplicação..."
    php artisan key:generate --force
fi

# Executar migrações e seeders essenciais automaticamente se habilitado
if [ "${AUTORUN_LARAVEL_MIGRATION:-true}" = "true" ]; then
    echo "==> Executando migrações do banco de dados..."
    php artisan migrate --force || echo "==> Aviso: Falha ao executar migrações ou banco indisponível no momento."
    echo "==> Garantindo existência do usuário inicial (UsersSeeder)..."
    php artisan db:seed --class=UsersSeeder --force || echo "==> Aviso: Falha ao executar UsersSeeder."
fi

# Otimizar caches se em ambiente de produção
if [ "$APP_ENV" = "production" ]; then
    echo "==> Otimizando caches para produção..."
    php artisan config:cache || true
    php artisan route:cache || true
    php artisan view:cache || true
fi

echo "==> CapiHouse API pronto! Iniciando servidor..."
exec "$@"
