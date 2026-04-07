<?php

namespace ErDhruvMishra\SqliteFFI;

use Illuminate\Database\Connection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\ServiceProvider;

/**
 * Auto-discovered service provider that replaces the built-in 'sqlite' driver
 * with the best available backend.
 *
 * Fallback chain (first available wins):
 *   1. Native pdo_sqlite  — fastest, requires PHP extension
 *   2. FFI (libsqlite3)   — near-native, requires ext-ffi + ffi.enable=true
 *   3. sqlite3 CLI binary — universal, requires sqlite3 on system
 *
 * After `composer require er-dhruvmishra/laravel-sqlite-ffi`, no code changes
 * are needed — existing `'driver' => 'sqlite'` configs work transparently.
 */
class SqliteFFIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Connection::resolverFor('sqlite', function ($connection, $database, $prefix, $config) {
            $pdoResolver = function () use ($config) {
                return PdoFactory::create($config['database'] ?? ':memory:', $config);
            };

            return new SQLiteConnection($pdoResolver, $database, $prefix, $config);
        });
    }

    public function boot(): void
    {
        // Tier detection is lazy — errors surface at connection time, not boot time.
    }
}
