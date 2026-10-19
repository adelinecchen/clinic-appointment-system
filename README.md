# Clinic Appointment System

Privacy-conscious appointment scheduling for a community health clinic. Patients will book and manage appointments on a public website. Clinic staff will use an administrative dashboard. This repository currently contains only the project scaffold.

The browser never talks to MySQL directly. Pages and API scripts in `public/` call PHP in `src/`, and that PHP code will be the only layer that opens a database connection.

## Layout

```
public/                 Web root (the only directory Apache or the PHP server should expose)
  index.php             Patient site entry point
  admin/index.php       Administrative dashboard placeholder
  api/health.php        JSON health check
src/                    PHP that is not meant to be requested by a browser
  bootstrap.php         Loads .env and shared helpers
  .htaccess             Denies direct web access to this folder
.env.example            Documented configuration. Copy to .env locally.
.gitignore              Keeps .env and other local files out of Git
```

No database schema, booking flow, or login is included yet.

## Requirements

- PHP 8.0 or newer (included with a current XAMPP install)
- MySQL or MariaDB later, through XAMPP. It is not required to run the health check.
- Git

No Composer packages or frontend framework are used.

## Run it with XAMPP

1. Install [XAMPP](https://www.apachefriends.org/) and start **Apache** from the XAMPP control panel. MySQL can stay stopped until a database is added.
2. Put this project where Apache can see it. On macOS that is often a symlink:

   ```bash
   ln -s /Users/adelinechen/capstone/Capstone /Applications/XAMPP/htdocs/capstone
   ```

   On Windows, copy or link the project into `C:\xampp\htdocs\capstone`.
3. Open:

   - Patient site: [http://localhost/capstone/public/](http://localhost/capstone/public/)
   - Admin placeholder: [http://localhost/capstone/public/admin/](http://localhost/capstone/public/admin/)
   - Health check: [http://localhost/capstone/public/api/health.php](http://localhost/capstone/public/api/health.php)

Apache is serving the whole `public/` folder, so the URL includes `/public`. A later Apache virtual host can set the document root to `public/` and drop that segment from the URL. Do not point the document root at the repository root: that would expose `src/` and `.env`. `src/.htaccess` blocks direct access if Apache still reaches that folder.

## Run it without changing XAMPP

From the repository root, PHP's built-in server can serve `public/` for a quick check:

```bash
php -S localhost:8000 -t public
```

If `php` is not on your PATH, XAMPP's binary works the same way:

```bash
/Applications/XAMPP/xamppfiles/bin/php -S localhost:8000 -t public
```

Leave that terminal open, then visit:

- [http://localhost:8000/](http://localhost:8000/)
- [http://localhost:8000/admin/](http://localhost:8000/admin/)
- [http://localhost:8000/api/health.php](http://localhost:8000/api/health.php)

Stop the server with Ctrl+C. Use one server at a time if both try to use the same port.

## Test the health check

With either server running:

```bash
curl -i http://localhost:8000/api/health.php
```

For the XAMPP URL, use `http://localhost/capstone/public/api/health.php` instead.

Expected body:

```json
{"status":"ok","service":"clinic-appointments","database":"not_configured"}
```

`database` stays `not_configured` until `.env` has `DB_HOST`, `DB_NAME`, and `DB_USER`. The endpoint does not open a database connection.

You can also check the pages in a browser. The patient page should show the application name and a link to the health check. The admin page should state that management features are not implemented yet.

## Local configuration

```bash
cp .env.example .env
```

Edit `.env` when you create the database. The variables are:

| Variable | Purpose |
| --- | --- |
| `APP_ENV` | `local` while developing |
| `APP_NAME` | Name shown on the placeholder pages |
| `DB_HOST` | Database host, usually `127.0.0.1` for XAMPP |
| `DB_PORT` | Database port, usually `3306` |
| `DB_NAME` | Database name, chosen when the schema is designed |
| `DB_USER` | Database user |
| `DB_PASSWORD` | Database password |

`.env` is listed in `.gitignore`. Commit `.env.example` only.
