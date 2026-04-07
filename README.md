# Laravel SQLite FFI

A **drop-in replacement** for Laravel's SQLite database driver that uses PHP FFI to call `libsqlite3` directly. No `pdo_sqlite` or `sqlite3` PHP extensions required.

**Zero code changes needed** — install via Composer and your existing `'driver' => 'sqlite'` configuration works immediately.

## Why?

Some hosting environments or custom PHP builds don't include the `pdo_sqlite` extension. This package provides the same SQLite functionality by calling the system's `libsqlite3` shared library through PHP's FFI (Foreign Function Interface).

- Works with Laravel 10, 11, and 12
- Supports migrations, Eloquent, Query Builder, Schema Builder, transactions, cursors
- Same behavior as the native driver — your application code doesn't change

## Requirements

| Requirement | Details |
|-------------|---------|
| PHP | >= 8.1 |
| FFI extension | `extension=ffi.so` with `ffi.enable=true` |
| libsqlite3 | System library (`libsqlite3-0` or equivalent) |
| Laravel | 10.x / 11.x / 12.x |

## Installation

### 1. Install the package

```bash
composer require transindus/laravel-sqlite-ffi
```

Laravel auto-discovers the service provider. No manual registration needed.

### 2. Enable PHP FFI

Make sure the FFI extension is enabled and allowed to run:

```ini
; /etc/php/8.x/cli/conf.d/20-ffi.ini  (and fpm equivalent)
extension=ffi.so
ffi.enable=true
```

### 3. Disable native SQLite extensions (optional)

If you want to fully replace the native driver, comment out these extensions:

```ini
; /etc/php/8.x/cli/conf.d/20-pdo_sqlite.ini
; extension=pdo_sqlite.so

; /etc/php/8.x/cli/conf.d/20-sqlite3.ini
; extension=sqlite3.so
```

Then restart PHP-FPM:

```bash
sudo systemctl restart php8.x-fpm
```

### 4. Use it

No changes to your code or config. The standard Laravel SQLite configuration works as-is:

```php
// config/database.php
'sqlite' => [
    'driver' => 'sqlite',
    'database' => database_path('database.sqlite'),
    'prefix' => '',
    'foreign_key_constraints' => true,
],
```

## How It Works

```
Your Laravel App
       |
  'driver' => 'sqlite'
       |
  [SqliteFFIServiceProvider]  ← auto-discovered, registers via Connection::resolverFor()
       |
  [SqlitePDO extends \PDO]   ← replaces native PDO SQLite driver
       |
  [PHP FFI]                   ← calls libsqlite3.so directly
       |
  [libsqlite3]                ← system SQLite library
```

The package:

1. Registers a connection resolver for the `sqlite` driver via `Connection::resolverFor()`
2. When Laravel creates a SQLite connection, our resolver provides an FFI-backed PDO instance
3. `SqlitePDO` extends `\PDO` and `SqlitePDOStatement` extends `\PDOStatement`, satisfying all type hints
4. All SQLite operations go through FFI to the system's `libsqlite3` shared library

## Supported Features

- **CRUD** — SELECT, INSERT, UPDATE, DELETE with parameter binding
- **Transactions** — BEGIN, COMMIT, ROLLBACK, savepoints
- **Migrations** — `php artisan migrate` works normally
- **Schema Builder** — create/alter/drop tables, indexes, foreign keys
- **Eloquent ORM** — models, relationships, eager loading
- **Query Builder** — where, join, aggregate, pagination
- **Cursors** — memory-efficient iteration via generators
- **NULL handling** — proper NULL value support
- **BLOB support** — binary data storage
- **Foreign key constraints** — via `foreign_key_constraints` config option
- **WAL mode** — via `journal_mode` config option
- **Busy timeout** — via `busy_timeout` config option

## Configuration Options

All standard Laravel SQLite config options are supported, plus:

```php
'sqlite' => [
    'driver' => 'sqlite',
    'database' => database_path('database.sqlite'),
    'prefix' => '',
    'foreign_key_constraints' => true,   // PRAGMA foreign_keys = ON
    'journal_mode' => 'wal',             // PRAGMA journal_mode = wal
    'busy_timeout' => 5000,              // milliseconds
],
```

### Custom libsqlite3 path

If `libsqlite3` is in a non-standard location, set the environment variable:

```bash
SQLITE_FFI_LIBRARY_PATH=/custom/path/libsqlite3.so
```

## Compatibility

| Feature | Status |
|---------|--------|
| `DB::connection('sqlite')` | Supported |
| `Schema::create()` / `drop()` | Supported |
| `DB::table()->get()` / `insert()` / `update()` / `delete()` | Supported |
| Eloquent models with `$connection = 'sqlite'` | Supported |
| `DB::transaction(function() { ... })` | Supported |
| `DB::select()` / `DB::statement()` | Supported |
| `php artisan migrate --database=sqlite` | Supported |
| `php artisan migrate:fresh` | Supported |
| Multiple SQLite connections | Supported |
| In-memory databases (`:memory:`) | Supported |
| `PDO::lastInsertId()` | Supported |
| `PDO::quote()` | Supported |

## Troubleshooting

### "FFI extension is required but not loaded"

Enable the FFI extension in your PHP configuration:

```bash
# Check if FFI is loaded
php -m | grep FFI

# If not, enable it
sudo phpenmod ffi
```

### "libsqlite3 shared library not found"

Install the SQLite3 library:

```bash
# Debian/Ubuntu
sudo apt-get install libsqlite3-0

# RHEL/CentOS
sudo yum install sqlite-libs

# macOS (via Homebrew)
brew install sqlite
```

### "ffi.enable must be set to true"

Edit your PHP configuration:

```ini
; Find the FFI config file
php --ini | grep ffi

; Set ffi.enable=true
ffi.enable=true
```

## License

MIT License. See [LICENSE](LICENSE) for details.
