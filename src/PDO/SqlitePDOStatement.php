<?php

namespace TransIndus\SqliteFFI\PDO;

use FFI;
use PDO;
use PDOException;
use PDOStatement;
use TransIndus\SqliteFFI\FFI\SqliteLibrary;

/**
 * FFI-backed replacement for PDOStatement (SQLite).
 *
 * Extends \PDOStatement so it satisfies type-hints in Laravel's Connection
 * (e.g. bindValues(PDOStatement $stmt) and prepared(PDOStatement $stmt)).
 *
 * The parent constructor is never called — the internal C struct is safely
 * zeroed by PHP's create_object handler and cleaned up by free_obj.
 */
class SqlitePDOStatement extends PDOStatement
{
    private SqlitePDO $pdo;
    private FFI $lib;
    private ?FFI\CData $stmt;

    private int $fetchMode;
    private array $fetchModeArgs = [];
    private int $fetchColumnIndex = 0;

    /** @var array<int|string, array{value: mixed, type: int}> */
    private array $boundValues = [];

    /** @var array<int|string, array{var: mixed, type: int}> bound by reference */
    private array $boundParams = [];

    private bool $preFetched = false;
    private bool $done = false;
    private int $affectedRows = 0;

    private string $errorCode = '';
    private array $errorInfo = ['', null, null];

    /* ------------------------------------------------------------------ */
    /*  Constructor / destructor                                          */
    /* ------------------------------------------------------------------ */

    public function __construct(SqlitePDO $pdo, FFI\CData $stmt, FFI $lib)
    {
        // Intentionally not calling parent — PDOStatement's constructor is
        // internal-only and the zeroed C struct is safe.
        $this->pdo  = $pdo;
        $this->lib  = $lib;
        $this->stmt = $stmt;
        $this->fetchMode = $pdo->getDefaultFetchMode();
    }

    public function __destruct()
    {
        $this->finalize();
    }

    /* ------------------------------------------------------------------ */
    /*  Core execution                                                    */
    /* ------------------------------------------------------------------ */

    #[\Override]
    public function execute(?array $params = null): bool
    {
        $this->preFetched = false;
        $this->done       = false;

        // Reset from any previous execution
        $this->lib->sqlite3_reset($this->stmt);
        $this->lib->sqlite3_clear_bindings($this->stmt);

        // Bind inline params (positional, 1-indexed)
        if ($params !== null) {
            $i = 1;
            foreach ($params as $key => $value) {
                $index = is_int($key) ? $key + 1 : $this->resolveParamIndex($key);
                $this->bindSqliteValue($index, $value, PDO::PARAM_STR);
                $i++;
            }
        }

        // Bind values registered via bindValue / bindParam
        $this->applyBoundValues();

        // Step once (PDO SQLite pre-fetches first row during execute)
        $rc = $this->lib->sqlite3_step($this->stmt);

        if ($rc === SqliteLibrary::SQLITE_ROW) {
            $this->preFetched = true;
        } elseif ($rc === SqliteLibrary::SQLITE_DONE) {
            $this->done = true;
        } else {
            $this->captureError();
            if ($this->pdo->getErrMode() === PDO::ERRMODE_EXCEPTION) {
                throw new PDOException($this->errorInfo[2] ?? 'SQLite execute error');
            }
            return false;
        }

        $this->affectedRows = $this->lib->sqlite3_changes($this->pdo->getDb());

        return true;
    }

    /* ------------------------------------------------------------------ */
    /*  Fetching                                                          */
    /* ------------------------------------------------------------------ */

    #[\Override]
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if ($this->done) {
            return false;
        }

        $mode = $this->resolveMode($mode);

        if ($this->preFetched) {
            $this->preFetched = false;
            return $this->formatRow($this->readRow(), $mode);
        }

        $rc = $this->lib->sqlite3_step($this->stmt);

        if ($rc === SqliteLibrary::SQLITE_ROW) {
            return $this->formatRow($this->readRow(), $mode);
        }

        $this->done = true;
        return false;
    }

    #[\Override]
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $mode = $this->resolveMode($mode);
        $rows = [];

        // Handle special fetchAll modes
        $colIndex = 0;
        $className = 'stdClass';
        $ctorArgs  = [];

        if ($mode === PDO::FETCH_COLUMN) {
            $colIndex = $args[0] ?? 0;
        } elseif ($mode === PDO::FETCH_CLASS) {
            $className = $args[0] ?? 'stdClass';
            $ctorArgs  = $args[1] ?? [];
        }

        while (($row = $this->fetch(PDO::FETCH_ASSOC)) !== false) {
            if ($mode === PDO::FETCH_COLUMN) {
                $values = array_values($row);
                $rows[] = $values[$colIndex] ?? null;
            } elseif ($mode === PDO::FETCH_CLASS) {
                $obj = new $className(...$ctorArgs);
                foreach ($row as $k => $v) {
                    $obj->$k = $v;
                }
                $rows[] = $obj;
            } else {
                $rows[] = $this->formatRow($row, $mode);
            }
        }

        return $rows;
    }

    #[\Override]
    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->fetch(PDO::FETCH_NUM);
        if ($row === false) {
            return false;
        }
        return $row[$column] ?? false;
    }

    #[\Override]
    public function fetchObject(?string $class = 'stdClass', array $constructorArgs = []): object|false
    {
        $row = $this->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return false;
        }

        $obj = new $class(...$constructorArgs);
        foreach ($row as $k => $v) {
            $obj->$k = $v;
        }
        return $obj;
    }

    /* ------------------------------------------------------------------ */
    /*  Parameter binding                                                 */
    /* ------------------------------------------------------------------ */

    #[\Override]
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->boundValues[$param] = ['value' => $value, 'type' => $type];
        return true;
    }

    #[\Override]
    public function bindParam(
        string|int $param,
        mixed &$var,
        int $type = PDO::PARAM_STR,
        int $maxLength = 0,
        mixed $driverOptions = null,
    ): bool {
        $this->boundParams[$param] = ['var' => &$var, 'type' => $type];
        return true;
    }

    /* ------------------------------------------------------------------ */
    /*  Metadata                                                          */
    /* ------------------------------------------------------------------ */

    #[\Override]
    public function rowCount(): int
    {
        return $this->affectedRows;
    }

    #[\Override]
    public function columnCount(): int
    {
        return $this->lib->sqlite3_column_count($this->stmt);
    }

    #[\Override]
    public function setFetchMode(int $mode, mixed ...$args): bool
    {
        $this->fetchMode = $mode;
        $this->fetchModeArgs = $args;

        if ($mode === PDO::FETCH_COLUMN) {
            $this->fetchColumnIndex = (int) ($args[0] ?? 0);
        }

        return true;
    }

    #[\Override]
    public function closeCursor(): bool
    {
        if ($this->stmt !== null) {
            $this->lib->sqlite3_reset($this->stmt);
        }
        $this->preFetched = false;
        $this->done = true;
        return true;
    }

    #[\Override]
    public function getColumnMeta(int $column): array|false
    {
        $count = $this->columnCount();
        if ($column < 0 || $column >= $count) {
            return false;
        }

        $namePtr = $this->lib->sqlite3_column_name($this->stmt, $column);
        $name = $namePtr !== null ? SqlitePDO::cString($namePtr) : '';

        $declTypePtr = $this->lib->sqlite3_column_decltype($this->stmt, $column);
        $declType = ($declTypePtr !== null && !FFI::isNull($declTypePtr))
            ? SqlitePDO::cString($declTypePtr)
            : 'TEXT';

        return [
            'name'           => $name,
            'native_type'    => $declType,
            'driver:decl_type' => $declType,
            'pdo_type'       => $this->mapDeclTypeToPdo($declType),
        ];
    }

    #[\Override]
    public function errorCode(): ?string
    {
        return $this->errorCode ?: null;
    }

    #[\Override]
    public function errorInfo(): array
    {
        return $this->errorInfo;
    }

    #[\Override]
    public function debugDumpParams(): ?bool
    {
        $sql = SqlitePDO::cString($this->lib->sqlite3_sql($this->stmt));
        $paramCount = $this->lib->sqlite3_bind_parameter_count($this->stmt);

        echo "SQL: [$sql]\n";
        echo "Params: $paramCount\n";

        foreach ($this->boundValues as $key => $data) {
            echo "Key: $key => Value: " . var_export($data['value'], true) . "\n";
        }

        return true;
    }

    #[\Override]
    public function getIterator(): \Iterator
    {
        while (($row = $this->fetch()) !== false) {
            yield $row;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Private helpers                                                    */
    /* ------------------------------------------------------------------ */

    private function finalize(): void
    {
        if ($this->stmt !== null) {
            $this->lib->sqlite3_finalize($this->stmt);
            $this->stmt = null;
        }
    }

    /**
     * Read all column values from the current row (after a successful step).
     *
     * @return array<string, mixed> column_name => value
     */
    private function readRow(): array
    {
        $count = $this->lib->sqlite3_column_count($this->stmt);
        $row   = [];

        for ($i = 0; $i < $count; $i++) {
            $namePtr = $this->lib->sqlite3_column_name($this->stmt, $i);
            $name    = SqlitePDO::cString($namePtr);
            $row[$name] = $this->getColumnValue($i);
        }

        return $row;
    }

    /**
     * Read a single column value, respecting SQLite's dynamic typing.
     */
    private function getColumnValue(int $i): mixed
    {
        $type = $this->lib->sqlite3_column_type($this->stmt, $i);
        $stringify = $this->pdo->shouldStringifyFetches();

        switch ($type) {
            case SqliteLibrary::SQLITE_INTEGER:
                $v = $this->lib->sqlite3_column_int64($this->stmt, $i);
                return $stringify ? (string) $v : (int) $v;

            case SqliteLibrary::SQLITE_FLOAT:
                $v = $this->lib->sqlite3_column_double($this->stmt, $i);
                return $stringify ? (string) $v : $v;

            case SqliteLibrary::SQLITE_TEXT:
                $ptr = $this->lib->sqlite3_column_text($this->stmt, $i);
                if ($ptr === null) {
                    return null;
                }
                return SqlitePDO::cString($ptr);

            case SqliteLibrary::SQLITE_BLOB:
                $bytes = $this->lib->sqlite3_column_bytes($this->stmt, $i);
                if ($bytes === 0) {
                    return '';
                }
                $ptr = $this->lib->sqlite3_column_blob($this->stmt, $i);
                if (is_string($ptr)) {
                    return $ptr;
                }
                return FFI::string(FFI::cast('char*', $ptr), $bytes);

            case SqliteLibrary::SQLITE_NULL:
            default:
                return null;
        }
    }

    /**
     * Format an associative row array into the requested fetch-mode shape.
     */
    private function formatRow(array $assoc, int $mode): mixed
    {
        return match ($mode) {
            PDO::FETCH_ASSOC => $assoc,

            PDO::FETCH_NUM => array_values($assoc),

            PDO::FETCH_BOTH => $this->buildFetchBoth($assoc),

            PDO::FETCH_OBJ => (object) $assoc,

            PDO::FETCH_COLUMN => array_values($assoc)[$this->fetchColumnIndex] ?? null,

            PDO::FETCH_NAMED => $assoc, // simplified — identical to ASSOC for simple queries

            default => $assoc,
        };
    }

    /**
     * Build an array with both integer and string keys (PDO::FETCH_BOTH).
     */
    private function buildFetchBoth(array $assoc): array
    {
        $result = [];
        $i = 0;
        foreach ($assoc as $key => $value) {
            $result[$i++] = $value;
            $result[$key] = $value;
        }
        return $result;
    }

    /**
     * Resolve FETCH_DEFAULT (0) to the actual configured mode.
     */
    private function resolveMode(int $mode): int
    {
        return ($mode === PDO::FETCH_DEFAULT || $mode === 0)
            ? $this->fetchMode
            : $mode;
    }

    /**
     * Apply all values registered via bindValue() and bindParam() to the
     * underlying sqlite3_stmt.
     */
    private function applyBoundValues(): void
    {
        foreach ($this->boundValues as $param => $data) {
            $index = is_int($param)
                ? $param
                : $this->resolveParamIndex($param);
            $this->bindSqliteValue($index, $data['value'], $data['type']);
        }

        foreach ($this->boundParams as $param => $data) {
            $index = is_int($param)
                ? $param
                : $this->resolveParamIndex($param);
            $this->bindSqliteValue($index, $data['var'], $data['type']);
        }
    }

    /**
     * Resolve a named parameter (":foo" or "foo") to a 1-based index.
     */
    private function resolveParamIndex(string $name): int
    {
        if (!str_starts_with($name, ':')) {
            $name = ':' . $name;
        }

        $idx = $this->lib->sqlite3_bind_parameter_index($this->stmt, $name);
        if ($idx === 0) {
            throw new PDOException("Invalid parameter name: $name");
        }

        return $idx;
    }

    /**
     * Bind a single value to the sqlite3_stmt at the given 1-based index.
     */
    private function bindSqliteValue(int $index, mixed $value, int $type): void
    {
        if ($value === null || $type === PDO::PARAM_NULL) {
            $this->lib->sqlite3_bind_null($this->stmt, $index);
            return;
        }

        switch ($type) {
            case PDO::PARAM_INT:
            case PDO::PARAM_BOOL:
                $this->lib->sqlite3_bind_int64(
                    $this->stmt,
                    $index,
                    (int) $value,
                );
                break;

            case PDO::PARAM_LOB:
                $content = is_resource($value)
                    ? stream_get_contents($value)
                    : (string) $value;
                $len = strlen($content);
                $this->lib->sqlite3_bind_blob(
                    $this->stmt,
                    $index,
                    $content,
                    $len,
                    SqliteLibrary::SQLITE_TRANSIENT,
                );
                break;

            default: // PDO::PARAM_STR and everything else
                $str = (string) $value;
                $this->lib->sqlite3_bind_text(
                    $this->stmt,
                    $index,
                    $str,
                    strlen($str),
                    SqliteLibrary::SQLITE_TRANSIENT,
                );
                break;
        }
    }

    /**
     * Capture the current SQLite error into this statement's error state.
     */
    private function captureError(): void
    {
        $db   = $this->pdo->getDb();
        $code = $this->lib->sqlite3_errcode($db);
        $msg  = SqlitePDO::cString($this->lib->sqlite3_errmsg($db));

        $this->errorCode = 'HY000';
        $this->errorInfo = ['HY000', $code, $msg];
    }

    /**
     * Map a SQLite declared column type to PDO::PARAM_* constant.
     */
    private function mapDeclTypeToPdo(string $declType): int
    {
        $upper = strtoupper($declType);

        if (str_contains($upper, 'INT') || str_contains($upper, 'BOOL')) {
            return PDO::PARAM_INT;
        }
        if (str_contains($upper, 'BLOB') || str_contains($upper, 'BINARY')) {
            return PDO::PARAM_LOB;
        }
        if ($upper === 'NULL') {
            return PDO::PARAM_NULL;
        }

        return PDO::PARAM_STR;
    }
}
