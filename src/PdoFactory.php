<?php

namespace ErDhruvMishra\SqliteFFI;

use ErDhruvMishra\SqliteFFI\PDO\SqlitePDO;
use ErDhruvMishra\SqliteFFI\CLI\SqliteCliPDO;
use ErDhruvMishra\SqliteFFI\CLI\BinaryLocator;
use PDO;
use RuntimeException;

/**
 * Static factory that creates the best available SQLite PDO instance.
 *
 * Default fallback chain: native → ffi → cli
 *
 * Customizable via config/database.php:
 *   'sqlite' => [
 *       'driver'   => 'sqlite',
 *       'database' => database_path('database.sqlite'),
 *       'sqlite_backend' => 'ffi',                      // force a specific backend
 *       'sqlite_priority' => ['cli', 'ffi', 'native'],  // custom fallback order
 *   ],
 *
 * Or via environment:
 *   SQLITE_BACKEND=ffi              (force one backend)
 *   SQLITE_PRIORITY=cli,ffi,native  (custom order)
 */
class PdoFactory
{
    /** Default fallback order */
    private static array $defaultPriority = ['native', 'ffi', 'cli'];

    /** Tier name → availability checker */
    private const TIER_CHECKS = [
        'native' => 'hasNativeSupport',
        'ffi'    => 'hasFfiSupport',
        'cli'    => 'hasCliBinary',
    ];

    /** Tier name → PDO constructor */
    private const TIER_BUILDERS = [
        'native' => 'buildNative',
        'ffi'    => 'buildFfi',
        'cli'    => 'buildCli',
    ];

    /**
     * Create a configured PDO instance for the given database path.
     */
    public static function create(string $database, array $config = []): PDO
    {
        if ($database !== ':memory:' && !str_starts_with($database, '/') && function_exists('database_path')) {
            $database = database_path($database);
        }

        $dsn = 'sqlite:' . $database;

        // Check for forced backend
        $forced = self::forcedBackend($config);
        if ($forced !== null) {
            return self::buildTier($forced, $dsn, $config);
        }

        // Walk the priority chain
        foreach (self::priority($config) as $tier) {
            $checker = self::TIER_CHECKS[$tier] ?? null;
            if ($checker && self::$checker()) {
                return self::buildTier($tier, $dsn, $config);
            }
        }

        throw new RuntimeException(
            "[laravel-sqlite-ffi] No SQLite backend available.\n"
            . "Tried: " . implode(' → ', self::priority($config)) . "\n"
            . "Install one of:\n"
            . "  native:  PHP pdo_sqlite extension\n"
            . "  ffi:     PHP FFI extension + ffi.enable=true + libsqlite3\n"
            . "  cli:     sqlite3 binary (sudo apt install sqlite3)"
        );
    }

    /**
     * Detect which tier would be used with the current config.
     */
    public static function activeTier(array $config = []): string
    {
        $forced = self::forcedBackend($config);
        if ($forced !== null) {
            $checker = self::TIER_CHECKS[$forced] ?? null;
            return ($checker && self::$checker()) ? $forced : 'none';
        }

        foreach (self::priority($config) as $tier) {
            $checker = self::TIER_CHECKS[$tier] ?? null;
            if ($checker && self::$checker()) {
                return $tier;
            }
        }

        return 'none';
    }

    /**
     * Set the default priority globally (without config).
     *
     * @param string[] $priority e.g. ['ffi', 'cli', 'native']
     */
    public static function setDefaultPriority(array $priority): void
    {
        self::$defaultPriority = $priority;
    }

    /* ------------------------------------------------------------------ */
    /*  Tier availability checks                                          */
    /* ------------------------------------------------------------------ */

    public static function hasNativeSupport(): bool
    {
        return extension_loaded('pdo_sqlite');
    }

    public static function hasFfiSupport(): bool
    {
        if (!extension_loaded('FFI')) {
            return false;
        }
        $enable = ini_get('ffi.enable');
        return $enable === '1' || $enable === 'true';
    }

    public static function hasCliBinary(): bool
    {
        try {
            BinaryLocator::find();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Config resolution                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Get the forced backend (single tier, no fallback).
     * From config 'sqlite_backend' or env SQLITE_BACKEND.
     */
    private static function forcedBackend(array $config): ?string
    {
        $backend = $config['sqlite_backend']
            ?? (getenv('SQLITE_BACKEND') ?: null);

        if ($backend !== null && isset(self::TIER_CHECKS[$backend])) {
            return $backend;
        }

        return null;
    }

    /**
     * Get the fallback priority order.
     * From config 'sqlite_priority', env SQLITE_PRIORITY, or default.
     */
    private static function priority(array $config): array
    {
        // Config array takes precedence
        if (!empty($config['sqlite_priority']) && is_array($config['sqlite_priority'])) {
            return $config['sqlite_priority'];
        }

        // Env variable (comma-separated)
        $env = getenv('SQLITE_PRIORITY');
        if ($env !== false && $env !== '') {
            return array_map('trim', explode(',', $env));
        }

        return self::$defaultPriority;
    }

    /* ------------------------------------------------------------------ */
    /*  Tier builders                                                     */
    /* ------------------------------------------------------------------ */

    private static function buildTier(string $tier, string $dsn, array $config): PDO
    {
        $builder = self::TIER_BUILDERS[$tier] ?? null;
        if ($builder === null) {
            throw new RuntimeException("[laravel-sqlite-ffi] Unknown backend tier: $tier");
        }

        return self::configure(self::$builder($dsn), $config);
    }

    private static function buildNative(string $dsn): PDO
    {
        return new \PDO($dsn);
    }

    private static function buildFfi(string $dsn): SqlitePDO
    {
        return new SqlitePDO($dsn);
    }

    private static function buildCli(string $dsn): SqliteCliPDO
    {
        return new SqliteCliPDO($dsn);
    }

    /* ------------------------------------------------------------------ */
    /*  PDO configuration                                                 */
    /* ------------------------------------------------------------------ */

    private static function configure(PDO $pdo, array $config): PDO
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_NATURAL);
        $pdo->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_NATURAL);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);

        if ($pdo instanceof SqlitePDO || $pdo instanceof SqliteCliPDO) {
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        }

        if (!empty($config['options']) && is_array($config['options'])) {
            foreach ($config['options'] as $key => $value) {
                $pdo->setAttribute($key, $value);
            }
        }

        if (!empty($config['foreign_key_constraints'])) {
            $pdo->exec('PRAGMA foreign_keys = ON;');
        }

        if (!empty($config['journal_mode'])) {
            $pdo->exec("PRAGMA journal_mode = {$config['journal_mode']};");
        }

        if (isset($config['busy_timeout'])) {
            $pdo->exec("PRAGMA busy_timeout = {$config['busy_timeout']};");
        }

        return $pdo;
    }
}
