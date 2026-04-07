<?php

namespace ErDhruvMishra\SqliteFFI\CLI;

use PDO;
use PDOException;
use PDOStatement;

/**
 * PDOStatement replacement backed by the sqlite3 CLI process.
 *
 * Parameters are substituted directly into the SQL string using safe escaping.
 * All result rows are buffered in memory after execute().
 */
class SqliteCliPDOStatement extends PDOStatement
{
    private SqliteCliPDO $pdo;
    private string $queryTemplate;

    private int $fetchMode;
    private array $fetchModeArgs = [];
    private int $fetchColumnIndex = 0;

    /** @var array<int|string, array{value: mixed, type: int}> */
    private array $boundValues = [];

    /** @var array<int|string, array{var: mixed, type: int}> */
    private array $boundParams = [];

    /** Buffered result rows (associative) */
    private array $rows = [];

    /** Column names from last query */
    private array $columns = [];

    /** Current cursor position in $rows */
    private int $cursor = 0;

    private int $affectedRows = 0;

    private string $errorCode = '';
    private array $errorInfo = ['', null, null];

    public function __construct(SqliteCliPDO $pdo, string $query)
    {
        $this->pdo = $pdo;
        $this->queryTemplate = $query;
        $this->fetchMode = $pdo->getDefaultFetchMode();
    }

    /* ------------------------------------------------------------------ */
    /*  Execution                                                         */
    /* ------------------------------------------------------------------ */

    #[\Override]
    public function execute(?array $params = null): bool
    {
        $this->rows = [];
        $this->columns = [];
        $this->cursor = 0;
        $this->affectedRows = 0;
        $this->errorCode = '';
        $this->errorInfo = ['', null, null];

        $sql = $this->buildSql($params);

        try {
            $result = $this->pdo->getProcess()->query($sql);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            // Also check process stderr
            $procErr = $this->pdo->getProcess()->getLastError();
            if ($procErr) {
                $msg = $procErr;
            }

            $sqlstate = str_contains($msg, 'UNIQUE constraint') ? '23000'
                      : (str_contains($msg, 'constraint') ? '23000' : 'HY000');

            $this->errorCode = $sqlstate;
            $this->errorInfo = [$sqlstate, 0, $msg];

            if ($this->pdo->getErrMode() === PDO::ERRMODE_EXCEPTION) {
                $ex = new PDOException("SQLSTATE[$sqlstate]: $msg");
                $ex->errorInfo = $this->errorInfo;
                $ref = new \ReflectionProperty($ex, 'code');
                $ref->setValue($ex, $sqlstate);
                throw $ex;
            }
            return false;
        }

        // Check for errors reported via stderr
        $procErr = $this->pdo->getProcess()->getLastError();
        if ($procErr) {
            $sqlstate = str_contains($procErr, 'UNIQUE constraint') ? '23000'
                      : (str_contains($procErr, 'constraint') ? '23000' : 'HY000');

            $this->errorCode = $sqlstate;
            $this->errorInfo = [$sqlstate, 0, $procErr];

            if ($this->pdo->getErrMode() === PDO::ERRMODE_EXCEPTION) {
                $ex = new PDOException("SQLSTATE[$sqlstate]: $procErr");
                $ex->errorInfo = $this->errorInfo;
                $ref = new \ReflectionProperty($ex, 'code');
                $ref->setValue($ex, $sqlstate);
                throw $ex;
            }
            return false;
        }

        $this->columns = $result['columns'];
        $this->rows = $result['rows'];

        // Get affected rows for DML statements
        $changesResult = $this->pdo->getProcess()->query('SELECT changes() as c');
        $this->affectedRows = (int) ($changesResult['rows'][0]['c'] ?? 0);

        $this->pdo->updateLastInsertId();

        return true;
    }

    /* ------------------------------------------------------------------ */
    /*  Fetching                                                          */
    /* ------------------------------------------------------------------ */

    #[\Override]
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if ($this->cursor >= count($this->rows)) {
            return false;
        }

        $row = $this->rows[$this->cursor++];
        return $this->formatRow($row, $this->resolveMode($mode));
    }

    #[\Override]
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $mode = $this->resolveMode($mode);
        $result = [];

        $colIndex = 0;
        $className = 'stdClass';
        $ctorArgs = [];

        if ($mode === PDO::FETCH_COLUMN) {
            $colIndex = $args[0] ?? 0;
        } elseif ($mode === PDO::FETCH_CLASS) {
            $className = $args[0] ?? 'stdClass';
            $ctorArgs = $args[1] ?? [];
        }

        while (($row = $this->fetch(PDO::FETCH_ASSOC)) !== false) {
            if ($mode === PDO::FETCH_COLUMN) {
                $values = array_values($row);
                $result[] = $values[$colIndex] ?? null;
            } elseif ($mode === PDO::FETCH_CLASS) {
                $obj = new $className(...$ctorArgs);
                foreach ($row as $k => $v) {
                    $obj->$k = $v;
                }
                $result[] = $obj;
            } else {
                $result[] = $this->formatRow($row, $mode);
            }
        }

        return $result;
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
        return count($this->columns);
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
        $this->cursor = count($this->rows); // exhaust cursor
        return true;
    }

    #[\Override]
    public function getColumnMeta(int $column): array|false
    {
        if ($column < 0 || $column >= count($this->columns)) {
            return false;
        }
        return [
            'name'        => $this->columns[$column],
            'native_type' => 'TEXT',
            'pdo_type'    => PDO::PARAM_STR,
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
        echo "SQL: [{$this->queryTemplate}]\n";
        echo "Bound values: " . count($this->boundValues) . "\n";
        foreach ($this->boundValues as $k => $v) {
            echo "  $k => " . var_export($v['value'], true) . "\n";
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

    /**
     * Build the final SQL by substituting bound parameters.
     */
    private function buildSql(?array $inlineParams): string
    {
        $sql = $this->queryTemplate;

        // Merge inline execute() params with bindValue/bindParam
        $allParams = [];

        // bindValue params
        foreach ($this->boundValues as $key => $data) {
            $allParams[$key] = SqliteCliPDO::escapeValue($data['value'], $data['type']);
        }

        // bindParam params (by reference — read current value)
        foreach ($this->boundParams as $key => $data) {
            $allParams[$key] = SqliteCliPDO::escapeValue($data['var'], $data['type']);
        }

        // Inline params from execute($params)
        if ($inlineParams !== null) {
            foreach ($inlineParams as $key => $value) {
                $escapedValue = SqliteCliPDO::escapeValue($value, PDO::PARAM_STR);
                if (is_int($key)) {
                    $allParams[$key + 1] = $escapedValue; // 1-indexed
                } else {
                    $allParams[$key] = $escapedValue;
                }
            }
        }

        if (empty($allParams)) {
            return $sql;
        }

        // Replace named parameters (:name)
        $hasNamed = false;
        foreach ($allParams as $key => $escaped) {
            if (is_string($key)) {
                $hasNamed = true;
                $paramName = str_starts_with($key, ':') ? $key : ':' . $key;
                $sql = str_replace($paramName, $escaped, $sql);
            }
        }

        // Replace positional parameters (?)
        if (!$hasNamed) {
            $positional = [];
            ksort($allParams); // ensure order
            foreach ($allParams as $escaped) {
                $positional[] = $escaped;
            }

            $idx = 0;
            $sql = preg_replace_callback('/\?/', function () use ($positional, &$idx) {
                return $positional[$idx++] ?? '?';
            }, $sql);
        }

        return $sql;
    }

    private function resolveMode(int $mode): int
    {
        return ($mode === PDO::FETCH_DEFAULT || $mode === 0)
            ? $this->fetchMode
            : $mode;
    }

    private function formatRow(array $assoc, int $mode): mixed
    {
        return match ($mode) {
            PDO::FETCH_ASSOC => $assoc,
            PDO::FETCH_NUM   => array_values($assoc),
            PDO::FETCH_BOTH  => $this->buildFetchBoth($assoc),
            PDO::FETCH_OBJ   => (object) $assoc,
            PDO::FETCH_COLUMN => array_values($assoc)[$this->fetchColumnIndex] ?? null,
            default           => $assoc,
        };
    }

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
}
