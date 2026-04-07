<?php

namespace TransIndus\SqliteFFI;

use Illuminate\Database\Connection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\ServiceProvider;
use PDO;
use TransIndus\SqliteFFI\PDO\SqlitePDO;

/**
 * Auto-discovered service provider that replaces the built-in 'sqlite' driver
 * with an FFI-powered implementation.
 *
 * After installation (`composer require transindus/laravel-sqlite-ffi`),
 * no code changes are needed — existing `'driver' => 'sqlite'` configs
 * work transparently.
 */
class SqliteFFIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Use Connection::resolverFor to intercept at the ConnectionFactory level.
        // This is more reliable than DatabaseManager::extend() because it hooks
        // into the factory AFTER driver config is resolved, avoiding timing issues.
        Connection::resolverFor('sqlite', function ($connection, $database, $prefix, $config) {
            // $connection is a Closure from the default connector (which would fail
            // since pdo_sqlite is disabled). Replace it with our FFI-based PDO.
            $ffiPdoResolver = function () use ($config) {
                return $this->createFfiPdo($config);
            };

            return new SQLiteConnection($ffiPdoResolver, $database, $prefix, $config);
        });
    }

    public function boot(): void
    {
        if (!extension_loaded('FFI')) {
            throw new \RuntimeException(
                '[laravel-sqlite-ffi] The PHP FFI extension is required but not loaded. '
                . 'Enable it in php.ini (extension=ffi.so) and set ffi.enable=true.'
            );
        }
    }

    /**
     * Create the FFI-based PDO instance for a given database config.
     */
    private function createFfiPdo(array $config): SqlitePDO
    {
        $database = $config['database'] ?? ':memory:';

        // Resolve relative paths
        if ($database !== ':memory:' && !str_starts_with($database, '/')) {
            $database = database_path($database);
        }

        $dsn = 'sqlite:' . $database;

        $pdo = new SqlitePDO($dsn);

        // Apply standard connector options
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_NATURAL);
        $pdo->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_NATURAL);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        // Apply user-specified PDO options from config
        if (!empty($config['options']) && is_array($config['options'])) {
            foreach ($config['options'] as $key => $value) {
                $pdo->setAttribute($key, $value);
            }
        }

        // Foreign key constraints (common in Laravel SQLite configs)
        if (!empty($config['foreign_key_constraints'])) {
            $pdo->exec('PRAGMA foreign_keys = ON;');
        }

        // WAL mode for better concurrency
        if (!empty($config['wal_mode']) || !empty($config['journal_mode'])) {
            $mode = $config['journal_mode'] ?? 'wal';
            $pdo->exec("PRAGMA journal_mode = {$mode};");
        }

        // Busy timeout
        if (isset($config['busy_timeout'])) {
            $pdo->setAttribute(PDO::ATTR_TIMEOUT, $config['busy_timeout'] / 1000);
        }

        return $pdo;
    }
}
