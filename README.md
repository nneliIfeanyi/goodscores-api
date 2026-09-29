# GoodScores API

PHP 8 REST API for the GoodScores question bank and exam paper generator.

## Local setup

Requirements:

- PHP 8.0 or newer
- MySQL with PDO MySQL enabled
- Composer
- Apache with URL rewriting enabled

Install dependencies and create the environment file:

```bash
cd backend
composer install
copy .env.example .env
```

Set the database connection, application URL, JWT secret, and any optional AI or payment credentials in `.env`. Never commit `.env`.

Create the database using `sql/schema.sql`, then serve the API through `public/`. With XAMPP, the local health endpoint is:

```text
http://localhost/goodscores/backend/public/health
```

It should return a successful status and database connection.

## Production deployment

Deploy this backend repository to DirectAdmin or another PHP host. Point the API domain's document root at `backend/public`; do not expose `src/`, `tests/`, `.env`, or `storage/` directly.

Set these values in the production environment:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.example.com
APP_BASE_PATH=
FRONTEND_URL=https://app.example.com
CORS_ALLOWED_ORIGINS=https://app.example.com
JWT_SECRET=use-a-long-random-secret
```

Also configure the live database credentials and optional `OPENAI_API_KEY`, Paystack, or other service keys in the server environment only. `CORS_ALLOWED_ORIGINS` must contain the exact frontend origin, without a trailing path.

The frontend is deployed separately as a static site. Set its `window.GOODSCORES_API_BASE` value to the public API URL. Do not put API secrets or database credentials in frontend files.

## Database

For a new database:

```bash
mysql -u USER -p DATABASE_NAME < sql/schema.sql
```

Back up the production database before applying migrations. Apply migration files in order and verify the application after each migration.

## PHP and permissions

Enable `pdo_mysql`, `curl`, and `mbstring`; enable `gd` when image processing is required. Ensure Apache rewrite and header modules are enabled. The web server must be able to write to runtime storage directories:

```bash
chmod -R 775 storage
```

Do not deploy local uploads, logs, database files, `.env`, or development caches. Composer dependencies are required for local PHPUnit tests; production PDF export currently supports browser printing without deploying `vendor/`.

## Production checklist

- [ ] HTTPS enabled
- [ ] Strong random `JWT_SECRET` configured
- [ ] `APP_DEBUG=false`
- [ ] Production database credentials configured
- [ ] CORS restricted to the real frontend origin
- [ ] `.env` inaccessible from the web
- [ ] Rate limiting enabled
- [ ] Storage permissions verified
- [ ] `GET /health` returns a healthy database status
- [ ] Authentication, question creation, paper creation, PDF printing, and OCR tested
- [ ] Database and uploads backed up regularly

## Tests

Run the backend test suite with:

```bash
vendor/bin/phpunit
```