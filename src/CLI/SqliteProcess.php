<?php

namespace ErDhruvMishra\SqliteFFI\CLI;

use RuntimeException;

/**
 * Manages a persistent sqlite3 CLI process via proc_open.
 *
 * Communication protocol:
 * - Queries are sent via stdin
 * - A unique sentinel (.print BOUNDARY_xxx) follows each query
 * - stdout is read until the sentinel line appears
 * - stderr is checked (non-blocking) for error messages
 * - CSV mode with headers provides structured output
 */
class SqliteProcess
{
    /** @var resource|null */
    private $process = null;

    /** @var resource stdin pipe */
    private $stdin;

    /** @var resource stdout pipe */
    private $stdout;

    /** @var resource stderr pipe */
    private $stderr;

    private bool $alive = false;

    private string $lastError = '';

    private const NULL_MARKER = '<<<NULL_7f3a>>>';

    public function __construct(
        private readonly string $binary,
        private readonly string $dbPath,
    ) {
        $this->open();
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Execute a SQL statement and return parsed rows.
     *
     * @return array{columns: string[], rows: array<int, array<string, string|null>>}
     */
    public function query(string $sql): array
    {
        $this->ensureAlive();
        $this->lastError = '';

        $boundary = 'DONE_' . bin2hex(random_bytes(8));

        // Send the query + sentinel
        $this->writeLine($sql . ';');
        $this->writeLine(".print $boundary");

        // Read stdout until we hit the boundary
        $lines = [];
        while (true) {
            $line = fgets($this->stdout);
            if ($line === false) {
                // Process might have died
                $this->alive = false;
                throw new RuntimeException('sqlite3 process closed unexpectedly');
            }
            $trimmed = rtrim($line, "\r\n");
            if ($trimmed === $boundary) {
                break;
            }
            $lines[] = $trimmed;
        }

        // Check stderr for errors (non-blocking)
        $error = $this->drainStderr();
        if ($error !== '') {
            $this->lastError = $error;
        }

        return $this->parseCsvOutput($lines);
    }

    /**
     * Execute a non-SELECT statement. Returns affected row count.
     */
    public function exec(string $sql): int
    {
        $this->query($sql);

        // Get changes count
        $result = $this->query('SELECT changes() as c');

        return (int) ($result['rows'][0]['c'] ?? 0);
    }

    /**
     * Get the last insert rowid.
     */
    public function lastInsertRowId(): int
    {
        $result = $this->query('SELECT last_insert_rowid() as id');

        return (int) ($result['rows'][0]['id'] ?? 0);
    }

    /**
     * Send a raw command (no parsing).
     */
    public function command(string $cmd): void
    {
        $this->ensureAlive();
        $this->writeLine($cmd);
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function isAlive(): bool
    {
        if (!$this->alive || $this->process === null) {
            return false;
        }

        $status = proc_get_status($this->process);
        $this->alive = $status['running'];

        return $this->alive;
    }

    public function close(): void
    {
        if ($this->process !== null) {
            @fwrite($this->stdin, ".quit\n");
            @fclose($this->stdin);
            @fclose($this->stdout);
            @fclose($this->stderr);
            @proc_close($this->process);
            $this->process = null;
            $this->alive = false;
        }
    }

    /* ------------------------------------------------------------------ */

    private function open(): void
    {
        $descriptors = [
            0 => ['pipe', 'r'], // stdin:  child gets read-end, PHP gets write-end
            1 => ['pipe', 'w'], // stdout: child gets write-end, PHP gets read-end
            2 => ['pipe', 'w'], // stderr: child gets write-end, PHP gets read-end
        ];

        $cmd = [
            $this->binary,
            '-batch',       // suppress prompts
            '-noheader',    // we control headers ourselves
            $this->dbPath,
        ];

        $this->process = proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($this->process)) {
            throw new RuntimeException("Failed to start sqlite3 process: {$this->binary}");
        }

        $this->stdin  = $pipes[0];
        $this->stdout = $pipes[1];
        $this->stderr = $pipes[2];

        // Make stderr non-blocking so we can poll it without hanging
        stream_set_blocking($this->stderr, false);

        $this->alive = true;

        // Initialize session
        $this->writeLine('.bail off');
        $this->writeLine('.mode csv');
        $this->writeLine('.headers on');
        $this->writeLine('.nullvalue ' . self::NULL_MARKER);

        // Drain any startup output
        $this->drainStderr();
    }

    private function ensureAlive(): void
    {
        if (!$this->isAlive()) {
            $this->open();
        }
    }

    private function writeLine(string $line): void
    {
        fwrite($this->stdin, $line . "\n");
        fflush($this->stdin);
    }

    /**
     * Read all available data from stderr without blocking.
     */
    private function drainStderr(): string
    {
        $error = '';
        while (($line = fgets($this->stderr)) !== false) {
            $error .= $line;
        }
        return trim($error);
    }

    /**
     * Parse CSV-formatted output lines into columns + rows.
     *
     * @param  string[] $lines
     * @return array{columns: string[], rows: array<int, array<string, string|null>>}
     */
    private function parseCsvOutput(array $lines): array
    {
        if (empty($lines)) {
            return ['columns' => [], 'rows' => []];
        }

        // First line is the header
        $columns = str_getcsv($lines[0]);
        $rows = [];

        for ($i = 1, $count = count($lines); $i < $count; $i++) {
            if ($lines[$i] === '') {
                continue;
            }

            $values = str_getcsv($lines[$i]);
            $row = [];

            foreach ($columns as $j => $col) {
                $val = $values[$j] ?? null;
                $row[$col] = ($val === self::NULL_MARKER) ? null : $val;
            }

            $rows[] = $row;
        }

        return ['columns' => $columns, 'rows' => $rows];
    }
}
