# capihouse-api — Backend Guidelines for AI Agents

## Overview
This is the modern Backend API for CapiHouse, built with Laravel.

## Tech Stack
- **Framework**: Laravel 13
- **PHP Version**: ^8.3 (running on PHP 8.4 recommended)
- **Authentication**: Laravel Sanctum (API token authentication)
- **WebSockets / Broadcasting**: Laravel Reverb
- **Database**: SQLite (local dev/testing) / PostgreSQL (production)
- **Testing**: Pest / PHPUnit
- **Code Style**: Laravel Pint

## Directory Layout
- `routes/api.php` — All API routes. All endpoints must be versioned or grouped cleanly.
- `app/Http/Controllers/Api/` — API controllers handling HTTP requests.
- `app/Http/Requests/` — FormRequest classes for validation.
- `app/Http/Resources/` — JsonResource classes for serializing models for the frontend.
- `app/Models/` — Eloquent models and relationship definitions.
- `database/migrations/` — Database schema migrations.
- `database/factories/` and `database/seeders/` — Test factories and seeders.
- `tests/Feature/` and `tests/Unit/` — Automated test suite.

## Development Rules
1. **API First**: Responses should return standard JSON responses, preferably using Laravel API Resources (`JsonResource`).
2. **Validation**: Use FormRequest classes (`app/Http/Requests/`) instead of in-controller `$request->validate()` for complex requests.
3. **Database**: Always use migrations for schema modifications.
4. **Verification**: Always verify changes by running `php artisan test`. Run Pint with `./vendor/bin/pint` to maintain clean formatting.

## Commands (Must run inside `capihouse-api/`)
```bash
php artisan test                       # Run full test suite
php artisan migrate                    # Run database migrations
php artisan route:list --path=api      # List registered API routes
./vendor/bin/pint                      # Run code styling formatter
```
