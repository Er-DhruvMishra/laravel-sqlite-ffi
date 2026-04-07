# Laravel SQLite FFI

A **drop-in replacement** for Laravel's SQLite database driver with a 3-tier fallback chain. Works even when `pdo_sqlite` and FFI are both unavailable.

**Zero code changes needed** — install via Composer and your existing `'driver' => 'sqlite'` configuration works immediately.

## Why?

Some hosting environments or custom PHP builds don't include the `pdo_sqlite` extension. This package provides the same SQLite functionality through multiple backends:

| Tier | Backend | Speed | Requires |
|------|---------|-------|----------|
| 1 | Native `pdo_sqlite` | Fastest | PHP extension |
| 2 | FFI (`libsqlite3`) | Near-native | ext-ffi + `ffi.enable=true` |
| 3 | `sqlite3` CLI binary | Slower (IPC) | Binary on system or auto-downloaded |

The package auto-detects the best available backend. The CLI tier **auto-downloads** `sqlite3` from sqlite.org on first use if not found on the system.

- Works with Laravel 10, 11, and 12
- Supports migrations, Eloquent, Query Builder, Schema Builder, transactions, cursors
- Cross-platform: Linux, macOS, Windows
- Same behavior as the native driver — your application code doesn't change

## Requirements

| Requirement | Details |
|-------------|---------|
| PHP | >= 8.1 |
| Laravel | 10.x / 11.x / 12.x |
| **At least one of:** | |
| pdo_sqlite | PHP extension (Tier 1, best performance) |
| ext-ffi + libsqlite3 | FFI extension with `ffi.enable=true` (Tier 2) |
| sqlite3 binary | System binary or auto-downloaded (Tier 3) |

## Installation

### 1. Install the package

```bash
composer require er-dhruvmishra/laravel-sqlite-ffi
```

Laravel auto-discovers the service provider. No manual registration needed.

### 2. Use it

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

The package automatically picks the best available backend.

### 3. Optional: Enable specific backends

**For FFI (Tier 2):**
```ini
; /etc/php/8.x/cli/conf.d/20-ffi.ini
extension=ffi.so
ffi.enable=true
```

**For CLI (Tier 3):**
```bash
# Install sqlite3 binary (or let the package auto-download it)
sudo apt install sqlite3        # Debian/Ubuntu
sudo yum install sqlite         # RHEL/CentOS
brew install sqlite             # macOS
```

## How It Works

```
Your Laravel App
       |
  'driver' => 'sqlite'
       |
  [SqliteFFIServiceProvider]       ← auto-discovered
       |
  [PdoFactory]                     ← picks best available backend
       |
  ┌────┴────────────┬──────────────────┐
  │                 │                  │
Tier 1           Tier 2             Tier 3
native PDO    SqlitePDO(FFI)    SqliteCliPDO
  │                 │                  │
pdo_sqlite     libsqlite3.so     sqlite3 binary
extension      via PHP FFI       via proc_open
```

## Backend Priority Configuration

By default, the fallback order is: **native → ffi → cli**

You can customize this in three ways:

### Force a specific backend

In `config/database.php`:
```php
'sqlite' => [
    'driver' => 'sqlite',
    'database' => database_path('database.sqlite'),
    'sqlite_backend' => 'ffi',   // 'native', 'ffi', or 'cli'
],
```

Or via environment variable:
```bash
SQLITE_BACKEND=ffi
```

### Custom fallback order

In `config/database.php`:
```php
'sqlite' => [
    'driver' => 'sqlite',
    'database' => database_path('database.sqlite'),
    'sqlite_priority' => ['cli', 'ffi', 'native'],  // try CLI first
],
```

Or via environment variable:
```bash
SQLITE_PRIORITY=cli,ffi,native
```

### Set default priority in code

```php
use ErDhruvMishra\SqliteFFI\PdoFactory;

// In a service provider's register() method:
PdoFactory::setDefaultPriority(['ffi', 'cli', 'native']);
```

### Check which backend is active

```php
use ErDhruvMishra\SqliteFFI\PdoFactory;

echo PdoFactory::activeTier();  // 'native', 'ffi', 'cli', or 'none'
```

## TNTSearch Compatibility

If you use [teamtnt/tntsearch](https://github.com/teamtnt/tntsearch), it calls `new PDO('sqlite:...')` directly which fails without `pdo_sqlite`. This package includes a drop-in engine replacement:

```php
$tnt->loadConfig([
    'driver'   => 'mysql',
    'host'     => config('database.connections.mysql.host'),
    'database' => config('database.connections.mysql.database'),
    'username' => config('database.connections.mysql.username'),
    'password' => config('database.connections.mysql.password'),
    'storage'  => storage_path('tnt_indices') . '/',
    'engine'   => \ErDhruvMishra\SqliteFFI\Compat\TntSearchEngine::class,
]);
```

The `TntSearchEngine` uses the same 3-tier fallback as the main driver.

## Configuration Options

All standard Laravel SQLite config options are supported, plus:

```php
'sqlite' => [
    'driver' => 'sqlite',
    'database' => database_path('database.sqlite'),
    'prefix' => '',
    'foreign_key_constraints' => true,        // PRAGMA foreign_keys = ON
    'journal_mode' => 'wal',                  // PRAGMA journal_mode = wal
    'busy_timeout' => 5000,                   // PRAGMA busy_timeout (ms)
    'sqlite_backend' => null,                 // Force: 'native', 'ffi', 'cli'
    'sqlite_priority' => null,                // Custom order: ['ffi', 'cli']
],
```

### Environment variables

| Variable | Description | Example |
|----------|-------------|---------|
| `SQLITE_BACKEND` | Force a specific backend | `ffi` |
| `SQLITE_PRIORITY` | Custom fallback order (comma-separated) | `cli,ffi,native` |
| `SQLITE_FFI_LIBRARY_PATH` | Custom path to `libsqlite3.so` | `/opt/lib/libsqlite3.so` |
| `SQLITE3_BINARY_PATH` | Custom path to `sqlite3` binary | `/opt/bin/sqlite3` |

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
- **Foreign key constraints** — via `foreign_key_constraints` config
- **WAL mode** — via `journal_mode` config
- **Busy timeout** — via `busy_timeout` config

## Compatibility

| Feature | Native | FFI | CLI |
|---------|--------|-----|-----|
| `DB::connection('sqlite')` | Yes | Yes | Yes |
| `Schema::create()` / `drop()` | Yes | Yes | Yes |
| Query Builder CRUD | Yes | Yes | Yes |
| Eloquent models | Yes | Yes | Yes |
| Transactions + rollback | Yes | Yes | Yes |
| `php artisan migrate` | Yes | Yes | Yes |
| Multiple connections | Yes | Yes | Yes |
| In-memory (`:memory:`) | Yes | Yes | Yes |
| `lastInsertId()` | Yes | Yes | Yes |
| Server-side prepared statements | Yes | Yes | No* |
| TNTSearch indexing | Yes | Yes | Yes |

\* CLI tier uses client-side parameter escaping (safe, but slightly different execution model).

## Troubleshooting

### "No SQLite backend available"

At least one backend must be available. Check:

```bash
# Check what's available
php -r "
echo 'pdo_sqlite: ' . (extension_loaded('pdo_sqlite') ? 'YES' : 'no') . PHP_EOL;
echo 'FFI: ' . (extension_loaded('FFI') ? 'YES' : 'no') . PHP_EOL;
echo 'ffi.enable: ' . ini_get('ffi.enable') . PHP_EOL;
echo 'sqlite3 CLI: '; exec('which sqlite3 2>/dev/null', \$o, \$c); echo \$c === 0 ? 'YES' : 'no'; echo PHP_EOL;
"
```

### "FFI API is restricted by ffi.enable"

FFI is loaded but `ffi.enable` is set to `preload` (default) instead of `true`:

```ini
; Change from preload to true
ffi.enable=true
```

Restart PHP-FPM after changing:
```bash
sudo systemctl restart php8.x-fpm
```

### "libsqlite3 shared library not found"

Install the SQLite3 library:

```bash
# Debian/Ubuntu
sudo apt install libsqlite3-0

# RHEL/CentOS
sudo yum install sqlite-libs

# macOS
brew install sqlite
```

### "sqlite3 binary not found"

For CLI tier, install sqlite3 or let the package auto-download it:

```bash
# Debian/Ubuntu
sudo apt install sqlite3

# Or set a custom path
export SQLITE3_BINARY_PATH=/path/to/sqlite3
```

The package will also auto-download from sqlite.org on first use if the `bin/` directory is writable.

## License

MIT License. See [LICENSE](LICENSE) for details.
