<?php

namespace ErDhruvMishra\SqliteFFI\PDO;

use FFI;
use PDO;
use PDOException;
use ErDhruvMishra\SqliteFFI\FFI\SqliteLibrary;

/**
 * Drop-in replacement for PDO with the sqlite driver, powered by FFI.
 *
 * Extends \PDO so it satisfies return-type declarations in Laravel's
 * Connection::getPdo(): PDO.
 *
 * The parent constructor is intentionally NOT called — the internal C
 * structure stays zeroed and is safely cleaned up by PHP's free_obj handler.
 */
class SqlitePDO extends PDO
{
    private FFI $lib;
    private FFI\CData $db;
    private bool $dbOpen = false;
    private bool $inTx = false;

    private string $lastErrorCode = '';
    private array $lastErrorInfo = ['', null, null];

    private array $attributes = [];

    /* ------------------------------------------------------------------ */
    /*  Constructor / destructor                                          */
    /* ------------------------------------------------------------------ */

    /**
     * @param string      $dsn     "sqlite:/path/to/db" or "sqlite::memory:"
     * @param string|null $username Ignored (SQLite has no auth)
     * @param string|null $password Ignored
     * @param array|null  $options  PDO attribute overrides
     */
    public function __construct(
        string $dsn,
        ?string $username = null,
        ?string $password = null,
        ?array $options = null,
    ) {
        // Do NOT call parent::__construct() — we bypass the native PDO driver.

        $this->lib = SqliteLibrary::get();

        // Sensible defaults (same as Laravel's Connector base class)
        $this->attributes = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_BOTH,
            PDO::ATTR_CASE               => PDO::CASE_NATURAL,
            PDO::ATTR_ORACLE_NULLS       => PDO::NULL_NATURAL,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        // Apply caller-supplied options
        if ($options) {
            foreach ($options as $attr => $val) {
                $this->attributes[$attr] = $val;
            }
        }

        $path = $this->parseDsn($dsn);
        $this->open($path);
    }

    public function __destruct()
    {
        $this->closeDb();
    }

    /* ------------------------------------------------------------------ */
    /*  PDO public API overrides                                          */
    /* ------------------------------------------------------------------ */

    #[\Override]
    public function prepare(string $query, array $options = []): SqlitePDOStatement|false
    {
        $stmtPtr = $this->lib->new('sqlite3_stmt*');
        $rc = $this->lib->sqlite3_prepare_v2($this->db, $query, -1, FFI::addr($stmtPtr), null);

        if ($rc !== SqliteLibrary::SQLITE_OK) {
            return $this->handleError('prepare');
        }

        return new SqlitePDOStatement($this, $stmtPtr, $this->lib);
    }

    #[\Override]
    public function exec(string $statement): int|false
    {
        $rc = $this->lib->sqlite3_exec($this->db, $statement, null, null, null);

        if ($rc !== SqliteLibrary::SQLITE_OK) {
            return $this->handleError('exec');
        }

        return $this->lib->sqlite3_changes($this->db);
    }

    #[\Override]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): SqlitePDOStatement|false
    {
        $stmt = $this->prepare($query);
        if ($stmt === false) {
            return false;
        }

        if ($fetchMode !== null) {
            $stmt->setFetchMode($fetchMode, ...$fetchModeArgs);
        }

        $stmt->execute();
        return $stmt;
    }

    #[\Override]
    public function beginTransaction(): bool
    {
        if ($this->inTx) {
            throw new PDOException('Already in a transaction');
        }

        $rc = $this->lib->sqlite3_exec($this->db, 'BEGIN', null, null, null);
        if ($rc !== SqliteLibrary::SQLITE_OK) {
            return $this->handleError('beginTransaction');
        }

        $this->inTx = true;
        return true;
    }

    #[\Override]
    public function commit(): bool
    {
        if (!$this->inTx) {
            throw new PDOException('No active transaction');
        }

        $rc = $this->lib->sqlite3_exec($this->db, 'COMMIT', null, null, null);
        $this->inTx = false;

        if ($rc !== SqliteLibrary::SQLITE_OK) {
            return $this->handleError('commit');
        }

        return true;
    }

    #[\Override]
    public function rollBack(): bool
    {
        if (!$this->inTx) {
            throw new PDOException('No active transaction');
        }

        $rc = $this->lib->sqlite3_exec($this->db, 'ROLLBACK', null, null, null);
        $this->inTx = false;

        if ($rc !== SqliteLibrary::SQLITE_OK) {
            return $this->handleError('rollBack');
        }

        return true;
    }

    #[\Override]
    public function inTransaction(): bool
    {
        return $this->inTx;
    }

    #[\Override]
    public function setAttribute(int $attribute, mixed $value): bool
    {
        $this->attributes[$attribute] = $value;

        // Side-effects for specific attributes
        if ($attribute === PDO::ATTR_TIMEOUT && $this->dbOpen) {
            $this->lib->sqlite3_busy_timeout($this->db, (int) $value * 1000);
        }

        return true;
    }

    #[\Override]
    public function getAttribute(int $attribute): mixed
    {
        return match ($attribute) {
            PDO::ATTR_DRIVER_NAME      => 'sqlite',
            PDO::ATTR_SERVER_VERSION,
            PDO::ATTR_CLIENT_VERSION   => self::cString($this->lib->sqlite3_libversion()),
            PDO::ATTR_AUTOCOMMIT       => !$this->inTx,
            PDO::ATTR_CONNECTION_STATUS => $this->dbOpen ? 'open' : 'closed',
            default                    => $this->attributes[$attribute] ?? null,
        };
    }

    #[\Override]
    public function lastInsertId(?string $name = null): string|false
    {
        if (!$this->dbOpen) {
            return false;
        }

        return (string) $this->lib->sqlite3_last_insert_rowid($this->db);
    }

    #[\Override]
    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        if ($type === PDO::PARAM_INT) {
            return (string) (int) $string;
        }

        return "'" . str_replace("'", "''", $string) . "'";
    }

    #[\Override]
    public function errorCode(): ?string
    {
        return $this->lastErrorCode ?: null;
    }

    #[\Override]
    public function errorInfo(): array
    {
        return $this->lastErrorInfo;
    }

    /* ------------------------------------------------------------------ */
    /*  Internal helpers (used by SqlitePDOStatement too)                  */
    /* ------------------------------------------------------------------ */

    public function getDb(): FFI\CData
    {
        return $this->db;
    }

    public function getLib(): FFI
    {
        return $this->lib;
    }

    public function getDefaultFetchMode(): int
    {
        return $this->attributes[PDO::ATTR_DEFAULT_FETCH_MODE] ?? PDO::FETCH_BOTH;
    }

    public function shouldStringifyFetches(): bool
    {
        return (bool) ($this->attributes[PDO::ATTR_STRINGIFY_FETCHES] ?? false);
    }

    public function getErrMode(): int
    {
        return $this->attributes[PDO::ATTR_ERRMODE] ?? PDO::ERRMODE_EXCEPTION;
    }

    /* ------------------------------------------------------------------ */
    /*  Private helpers                                                    */
    /* ------------------------------------------------------------------ */

    private function parseDsn(string $dsn): string
    {
        // Accept "sqlite:/path" or just "/path" or ":memory:"
        if (str_starts_with($dsn, 'sqlite:')) {
            $path = substr($dsn, 7);
        } else {
            $path = $dsn;
        }

        return $path;
    }

    private function open(string $path): void
    {
        $dbPtr = $this->lib->new('sqlite3*');
        $flags = SqliteLibrary::SQLITE_OPEN_READWRITE
               | SqliteLibrary::SQLITE_OPEN_CREATE
               | SqliteLibrary::SQLITE_OPEN_FULLMUTEX;

        $rc = $this->lib->sqlite3_open_v2($path, FFI::addr($dbPtr), $flags, null);

        if ($rc !== SqliteLibrary::SQLITE_OK) {
            $msg = $rc === SqliteLibrary::SQLITE_OK
                ? 'Unknown error'
                : self::cString($this->lib->sqlite3_errmsg($dbPtr));
            throw new PDOException("Unable to open database [$path]: $msg", $rc);
        }

        $this->db = $dbPtr;
        $this->dbOpen = true;

        // Default busy timeout (2 s)
        $timeout = $this->attributes[PDO::ATTR_TIMEOUT] ?? 2;
        $this->lib->sqlite3_busy_timeout($this->db, (int) $timeout * 1000);
    }

    private function closeDb(): void
    {
        if ($this->dbOpen) {
            $this->lib->sqlite3_close_v2($this->db);
            $this->dbOpen = false;
        }
    }

    /**
     * Populate error state from the current sqlite3 error and optionally throw.
     *
     * @return false Always returns false so callers can `return $this->handleError(...)`.
     */
    private function handleError(string $context): false
    {
        $code = $this->lib->sqlite3_errcode($this->db);
        $msg  = self::cString($this->lib->sqlite3_errmsg($this->db));

        $this->lastErrorCode = 'HY000';
        $this->lastErrorInfo = ['HY000', $code, $msg];

        if ($this->getErrMode() === PDO::ERRMODE_EXCEPTION) {
            throw new PDOException("SQLSTATE[HY000]: General error: $code $msg");
        }

        if ($this->getErrMode() === PDO::ERRMODE_WARNING) {
            trigger_error("PDO SQLite FFI ($context): $msg", E_USER_WARNING);
        }

        return false;
    }

    /**
     * Safely convert an FFI CData pointer (const char*) or already-converted
     * PHP string to a PHP string.  PHP FFI may auto-convert const char*
     * returns to native strings depending on version/config.
     */
    public static function cString(mixed $ptr): string
    {
        if (is_string($ptr)) {
            return $ptr;
        }

        if ($ptr === null) {
            return '';
        }

        return FFI::string($ptr);
    }
}
