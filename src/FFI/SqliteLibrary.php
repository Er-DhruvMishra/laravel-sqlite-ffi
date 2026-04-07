<?php

namespace ErDhruvMishra\SqliteFFI\FFI;

use FFI;
use RuntimeException;

class SqliteLibrary
{
    public const SQLITE_OK = 0;
    public const SQLITE_ERROR = 1;
    public const SQLITE_BUSY = 5;
    public const SQLITE_LOCKED = 6;
    public const SQLITE_MISUSE = 21;
    public const SQLITE_ROW = 100;
    public const SQLITE_DONE = 101;

    public const SQLITE_INTEGER = 1;
    public const SQLITE_FLOAT = 2;
    public const SQLITE_TEXT = 3;
    public const SQLITE_BLOB = 4;
    public const SQLITE_NULL = 5;

    /** SQLITE_TRANSIENT = ((void(*)(void*))-1) — tells SQLite to copy bound data immediately */
    public const SQLITE_TRANSIENT = -1;

    /** SQLITE_STATIC = ((void(*)(void*))0) — tells SQLite the pointer is stable */
    public const SQLITE_STATIC = 0;

    public const SQLITE_OPEN_READWRITE = 0x00000002;
    public const SQLITE_OPEN_CREATE = 0x00000004;
    public const SQLITE_OPEN_FULLMUTEX = 0x00010000;

    private static ?FFI $ffi = null;

    /**
     * Get the shared FFI instance for libsqlite3.
     */
    public static function get(): FFI
    {
        if (self::$ffi === null) {
            self::$ffi = FFI::cdef(self::DECLARATIONS, self::findLibrary());
        }

        return self::$ffi;
    }

    /**
     * Locate the libsqlite3 shared library on the system.
     */
    private static function findLibrary(): string
    {
        // Allow override via environment variable
        $envPath = getenv('SQLITE_FFI_LIBRARY_PATH');
        if ($envPath !== false && file_exists($envPath)) {
            return $envPath;
        }

        // Common library names — dlopen resolves via ldconfig
        $candidates = [
            'libsqlite3.so.0',
            'libsqlite3.so',
            '/lib/x86_64-linux-gnu/libsqlite3.so.0',
            '/usr/lib/x86_64-linux-gnu/libsqlite3.so.0',
            '/usr/lib/libsqlite3.so.0',
            '/usr/lib64/libsqlite3.so.0',
            // macOS
            '/usr/lib/libsqlite3.dylib',
            '/opt/homebrew/opt/sqlite/lib/libsqlite3.dylib',
        ];

        foreach ($candidates as $lib) {
            // For short names, rely on dlopen's search path
            if (!str_contains($lib, '/')) {
                return $lib;
            }
            if (file_exists($lib)) {
                return $lib;
            }
        }

        throw new RuntimeException(
            'libsqlite3 shared library not found. Install libsqlite3 or set SQLITE_FFI_LIBRARY_PATH.'
        );
    }

    /**
     * C declarations for the SQLite3 API subset we need.
     *
     * Notes:
     * - Destructor params (sqlite3_bind_text/blob) are declared as int64_t
     *   instead of function pointers for ABI-compatible constant passing
     *   (-1 for SQLITE_TRANSIENT, 0 for SQLITE_STATIC).
     */
    private const DECLARATIONS = <<<'C'
        typedef struct sqlite3 sqlite3;
        typedef struct sqlite3_stmt sqlite3_stmt;

        /* Connection lifecycle */
        int sqlite3_open(const char *filename, sqlite3 **ppDb);
        int sqlite3_open_v2(const char *filename, sqlite3 **ppDb, int flags, const char *zVfs);
        int sqlite3_close(sqlite3 *db);
        int sqlite3_close_v2(sqlite3 *db);

        /* Error reporting */
        const char *sqlite3_errmsg(sqlite3 *db);
        int sqlite3_errcode(sqlite3 *db);
        int sqlite3_extended_errcode(sqlite3 *db);

        /* Simple execution (multiple statements, no result set) */
        int sqlite3_exec(sqlite3 *db, const char *sql, void *callback, void *arg, char **errmsg);
        void sqlite3_free(void *ptr);

        /* Prepared statements */
        int sqlite3_prepare_v2(sqlite3 *db, const char *zSql, int nByte, sqlite3_stmt **ppStmt, const char **pzTail);
        int sqlite3_step(sqlite3_stmt *pStmt);
        int sqlite3_finalize(sqlite3_stmt *pStmt);
        int sqlite3_reset(sqlite3_stmt *pStmt);
        int sqlite3_clear_bindings(sqlite3_stmt *pStmt);
        const char *sqlite3_sql(sqlite3_stmt *pStmt);

        /* Parameter binding */
        int sqlite3_bind_int(sqlite3_stmt *pStmt, int idx, int value);
        int sqlite3_bind_int64(sqlite3_stmt *pStmt, int idx, int64_t value);
        int sqlite3_bind_double(sqlite3_stmt *pStmt, int idx, double value);
        int sqlite3_bind_text(sqlite3_stmt *pStmt, int idx, const char *value, int n, int64_t destructor);
        int sqlite3_bind_blob(sqlite3_stmt *pStmt, int idx, const char *value, int n, int64_t destructor);
        int sqlite3_bind_null(sqlite3_stmt *pStmt, int idx);
        int sqlite3_bind_parameter_count(sqlite3_stmt *pStmt);
        const char *sqlite3_bind_parameter_name(sqlite3_stmt *pStmt, int idx);
        int sqlite3_bind_parameter_index(sqlite3_stmt *pStmt, const char *zName);

        /* Result columns */
        int sqlite3_column_count(sqlite3_stmt *pStmt);
        const char *sqlite3_column_name(sqlite3_stmt *pStmt, int N);
        int sqlite3_column_type(sqlite3_stmt *pStmt, int iCol);
        const char *sqlite3_column_text(sqlite3_stmt *pStmt, int iCol);
        int sqlite3_column_int(sqlite3_stmt *pStmt, int iCol);
        int64_t sqlite3_column_int64(sqlite3_stmt *pStmt, int iCol);
        double sqlite3_column_double(sqlite3_stmt *pStmt, int iCol);
        const void *sqlite3_column_blob(sqlite3_stmt *pStmt, int iCol);
        int sqlite3_column_bytes(sqlite3_stmt *pStmt, int iCol);
        const char *sqlite3_column_decltype(sqlite3_stmt *pStmt, int iCol);

        /* Metadata & state */
        int sqlite3_changes(sqlite3 *db);
        int sqlite3_total_changes(sqlite3 *db);
        int64_t sqlite3_last_insert_rowid(sqlite3 *db);
        int sqlite3_busy_timeout(sqlite3 *db, int ms);
        const char *sqlite3_libversion(void);
        int sqlite3_libversion_number(void);
    C;
}
