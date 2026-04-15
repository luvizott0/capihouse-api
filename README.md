# CapiNet API

Backend API RESTful para o projeto CapiNet, construido com Laravel 13.

## Requisitos

- PHP 8.2+
- Composer 2.x
- MySQL 8.0+
- Laravel Valet ou Herd (recomendado para desenvolvimento)

## Instalacao

### 1. Clonar o repositorio

```bash
git clone <repository-url>
cd capinet-api
```

### 2. Instalar dependencias

```bash
composer install
```

### 3. Configurar ambiente

```bash
cp .env.example .env
php artisan key:generate
```

### 4. Configurar banco de dados

Edite o arquivo `.env` com suas credenciais MySQL:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=capinet
DB_USERNAME=root
DB_PASSWORD=sua_senha
```

### 5. Executar migrations

```bash
php artisan migrate
```

### 6. Configurar Valet/Herd (opcional)

```bash
valet link capinet-api
# ou
herd link capinet-api
```

A API estara disponivel em: `http://capinet-api.test`

## Estrutura do Projeto

```
app/
├── Http/
│   ├── Controllers/
│   │   └── Api/
│   │       ├── ApiController.php    # Controller base com helpers
│   │       └── V1/                  # Controllers versao 1
│   │           └── HealthController.php
│   ├── Middleware/
│   └── Requests/
├── Models/
├── Services/                        # Logica de negocio
└── Transformers/                    # Fractal Transformers
```

## Endpoints

### Health Check

```
GET /api/v1/health
```

Retorna o status da API.

### Autenticacao (Sanctum)

```
POST /api/v1/auth/login
POST /api/v1/auth/register
POST /api/v1/auth/logout
```

## Rate Limiting

- **Rotas publicas:** 10 requisicoes/minuto
- **Rotas autenticadas:** 60 requisicoes/minuto

## Formato de Resposta

Todas as respostas seguem o padrao:

```json
{
  "status": "success|error",
  "message": "Descricao da operacao",
  "data": {}
}
```

## Pacotes Instalados

- **laravel/sanctum** - Autenticacao via tokens
- **spatie/laravel-permission** - Controle de permissoes e roles
- **spatie/laravel-query-builder** - Filtros e ordenacao em queries
- **spatie/laravel-fractal** - Transformers para respostas JSON

## Comandos Uteis

```bash
# Rodar testes
php artisan test

# Limpar cache
php artisan optimize:clear

# Listar rotas
php artisan route:list

# Criar migration
php artisan make:migration create_nome_table

# Criar model com migration
php artisan make:model Nome -m

# Criar controller
php artisan make:controller Api/V1/NomeController
```

## CORS

A API esta configurada para aceitar requisicoes de:

- `http://localhost:3000` (desenvolvimento)

Para adicionar mais origens, edite a variavel `CORS_ALLOWED_ORIGINS` no `.env`:

```env
CORS_ALLOWED_ORIGINS=http://localhost:3000,https://meudominio.com
```

## Testes

```bash
# Rodar todos os testes
php artisan test

# Rodar testes com cobertura
php artisan test --coverage
```

## License

MIT
