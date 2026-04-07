<?php

namespace ErDhruvMishra\SqliteFFI\CLI;

use PDO;
use PDOException;

/**
 * Third-tier SQLite driver: uses the sqlite3 CLI binary via proc_open.
 *
 * Fallback when both pdo_sqlite AND FFI are unavailable.
 * Extends \PDO to satisfy Laravel's type hints.
 *
 * Limitations vs native PDO:
 * - Parameters are escaped into the SQL string (no true server-side prepared statements)
 * - All result rows are buffered in memory after execute()
 * - Slightly slower due to process IPC overhead
 */
class SqliteCliPDO extends PDO
{
    private SqliteProcess $proc;
    private bool $inTx = false;
    private int $lastInsertId = 0;

    private string $lastErrorCode = '';
    private array $lastErrorInfo = ['', null, null];

    private array $attributes = [];

    public function __construct(
        string $dsn,
        ?string $username = null,
        ?string $password = null,
        ?array $options = null,
    ) {
        // Do NOT call parent::__construct()

        $this->attributes = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_BOTH,
            PDO::ATTR_CASE               => PDO::CASE_NATURAL,
            PDO::ATTR_ORACLE_NULLS       => PDO::NULL_NATURAL,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        if ($options) {
            foreach ($options as $k => $v) {
                $this->attributes[$k] = $v;
            }
        }

        $path = $this->parseDsn($dsn);
        $binary = BinaryLocator::find();
        $this->proc = new SqliteProcess($binary, $path);
    }

    public function __destruct()
    {
        $this->proc->close();
    }

    /* ------------------------------------------------------------------ */
    /*  PDO overrides                                                     */
    /* ------------------------------------------------------------------ */

    #[\Override]
    public function prepare(string $query, array $options = []): SqliteCliPDOStatement|false
    {
        return new SqliteCliPDOStatement($this, $query);
    }

    #[\Override]
    public function exec(string $statement): int|false
    {
        try {
            $affected = $this->proc->exec($statement);
            $this->lastInsertId = $this->proc->lastInsertRowId();
            return $affected;
        } catch (\Throwable $e) {
            return $this->handleError($e->getMessage());
        }
    }

    #[\Override]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): SqliteCliPDOStatement|false
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
        $this->proc->exec('BEGIN');
        $this->inTx = true;
        return true;
    }

    #[\Override]
    public function commit(): bool
    {
        if (!$this->inTx) {
            throw new PDOException('No active transaction');
        }
        $this->proc->exec('COMMIT');
        $this->inTx = false;
        return true;
    }

    #[\Override]
    public function rollBack(): bool
    {
        if (!$this->inTx) {
            throw new PDOException('No active transaction');
        }
        $this->proc->exec('ROLLBACK');
        $this->inTx = false;
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
        return true;
    }

    #[\Override]
    public function getAttribute(int $attribute): mixed
    {
        return match ($attribute) {
            PDO::ATTR_DRIVER_NAME      => 'sqlite',
            PDO::ATTR_SERVER_VERSION,
            PDO::ATTR_CLIENT_VERSION   => $this->getSqliteVersion(),
            PDO::ATTR_AUTOCOMMIT       => !$this->inTx,
            default                    => $this->attributes[$attribute] ?? null,
        };
    }

    #[\Override]
    public function lastInsertId(?string $name = null): string|false
    {
        return (string) $this->lastInsertId;
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
    /*  Internal helpers (used by SqliteCliPDOStatement)                   */
    /* ------------------------------------------------------------------ */

    public function getProcess(): SqliteProcess
    {
        return $this->proc;
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

    public function updateLastInsertId(): void
    {
        $this->lastInsertId = $this->proc->lastInsertRowId();
    }

    /**
     * Escape a value for safe inclusion in a SQL string.
     * Used by SqliteCliPDOStatement for parameter substitution.
     */
    public static function escapeValue(mixed $value, int $type = PDO::PARAM_STR): string
    {
        if ($value === null || $type === PDO::PARAM_NULL) {
            return 'NULL';
        }

        switch ($type) {
            case PDO::PARAM_INT:
            case PDO::PARAM_BOOL:
                return (string) (int) $value;

            case PDO::PARAM_LOB:
                $content = is_resource($value) ? stream_get_contents($value) : (string) $value;
                return "X'" . bin2hex($content) . "'";

            default: // PARAM_STR
                return "'" . str_replace("'", "''", (string) $value) . "'";
        }
    }

    /* ------------------------------------------------------------------ */

    private function parseDsn(string $dsn): string
    {
        if (str_starts_with($dsn, 'sqlite:')) {
            return substr($dsn, 7);
        }
        return $dsn;
    }

    private function getSqliteVersion(): string
    {
        try {
            $result = $this->proc->query('SELECT sqlite_version() as v');
            return $result['rows'][0]['v'] ?? 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    private function handleError(string $msg): false
    {
        $sqlstate = str_contains($msg, 'UNIQUE constraint') ? '23000' : 'HY000';
        $this->lastErrorCode = $sqlstate;
        $this->lastErrorInfo = [$sqlstate, 0, $msg];

        if ($this->getErrMode() === PDO::ERRMODE_EXCEPTION) {
            $e = new PDOException("SQLSTATE[$sqlstate]: $msg");
            $e->errorInfo = $this->lastErrorInfo;
            $ref = new \ReflectionProperty($e, 'code');
            $ref->setValue($e, $sqlstate);
            throw $e;
        }

        if ($this->getErrMode() === PDO::ERRMODE_WARNING) {
            trigger_error("PDO SQLite CLI: $msg", E_USER_WARNING);
        }

        return false;
    }
}
