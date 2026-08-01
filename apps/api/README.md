# API application

The Laravel API is the sole business-data authority and PostgreSQL writer defined
by ADR-0001. The image-analysis service must communicate through versioned,
asynchronous messages rather than direct HTTP callbacks or database access.

## Commands

```bash
composer install
php artisan serve
composer format:check
composer typecheck
composer test
```

The operational health endpoints are available at `GET /up` and
`GET /api/health`.
