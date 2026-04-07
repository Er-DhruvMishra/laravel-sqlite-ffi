<?php

namespace ErDhruvMishra\SqliteFFI\CLI;

use RuntimeException;

/**
 * Locates (or downloads) the sqlite3 CLI binary across platforms.
 *
 * Search order:
 * 1. SQLITE3_BINARY_PATH env variable
 * 2. System PATH
 * 3. Bundled binary inside the package's bin/ directory
 * 4. Auto-download from sqlite.org to a local cache
 * 5. Common platform-specific locations
 */
class BinaryLocator
{
    private static ?string $cached = null;

    /** SQLite release version for auto-download */
    private const SQLITE_VERSION = '3460100';
    private const SQLITE_YEAR = '2024';

    public static function find(): string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        // 1. Explicit env override
        $envPath = getenv('SQLITE3_BINARY_PATH');
        if ($envPath !== false && $envPath !== '' && self::isExecutable($envPath)) {
            return self::$cached = $envPath;
        }

        // 2. System PATH
        $found = self::findInPath(self::binaryName());
        if ($found !== null) {
            return self::$cached = $found;
        }

        // 3. Bundled binary in package's bin/ directory
        $bundled = self::bundledPath();
        if ($bundled !== null && self::isExecutable($bundled)) {
            return self::$cached = $bundled;
        }

        // 4. Auto-download to package's bin/ directory
        $downloaded = self::autoDownload();
        if ($downloaded !== null) {
            return self::$cached = $downloaded;
        }

        // 5. Common platform locations
        foreach (self::commonPaths() as $path) {
            if (self::isExecutable($path)) {
                return self::$cached = $path;
            }
        }

        throw new RuntimeException(
            "[laravel-sqlite-ffi] sqlite3 binary not found.\n"
            . "Install it for your platform:\n"
            . "  Debian/Ubuntu: sudo apt install sqlite3\n"
            . "  RHEL/CentOS:   sudo yum install sqlite\n"
            . "  macOS:         (built-in at /usr/bin/sqlite3)\n"
            . "  Windows:       Download from https://www.sqlite.org/download.html\n"
            . "Or set SQLITE3_BINARY_PATH environment variable."
        );
    }

    public static function binaryName(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'sqlite3.exe' : 'sqlite3';
    }

    /**
     * Auto-download the sqlite3 binary from sqlite.org.
     */
    private static function autoDownload(): ?string
    {
        $binDir = self::binDir();
        $target = $binDir . DIRECTORY_SEPARATOR . self::binaryName();

        // Already downloaded?
        if (self::isExecutable($target)) {
            return $target;
        }

        $url = self::downloadUrl();
        if ($url === null) {
            return null; // unsupported platform
        }

        // Ensure bin/ directory exists
        if (!is_dir($binDir) && !@mkdir($binDir, 0755, true)) {
            return null;
        }

        // Download zip
        $zipPath = $binDir . DIRECTORY_SEPARATOR . 'sqlite-tools.zip';
        $context = stream_context_create(['http' => ['timeout' => 30]]);
        $data = @file_get_contents($url, false, $context);
        if ($data === false) {
            return null;
        }
        file_put_contents($zipPath, $data);

        // Extract sqlite3 binary from zip
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            @unlink($zipPath);
            return null;
        }

        $extracted = false;
        $binaryName = self::binaryName();
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (basename($name) === $binaryName) {
                $content = $zip->getFromIndex($i);
                if ($content !== false) {
                    file_put_contents($target, $content);
                    $extracted = true;
                }
                break;
            }
        }
        $zip->close();
        @unlink($zipPath);

        if (!$extracted) {
            return null;
        }

        // Make executable (Unix)
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($target, 0755);
        }

        return self::isExecutable($target) ? $target : null;
    }

    /**
     * Build the download URL for the current platform.
     */
    private static function downloadUrl(): ?string
    {
        $base = 'https://www.sqlite.org/' . self::SQLITE_YEAR;
        $ver = self::SQLITE_VERSION;

        if (PHP_OS_FAMILY === 'Windows') {
            return "{$base}/sqlite-tools-win-x64-{$ver}.zip";
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            return "{$base}/sqlite-tools-osx-x64-{$ver}.zip";
        }

        if (PHP_OS_FAMILY === 'Linux') {
            return "{$base}/sqlite-tools-linux-x64-{$ver}.zip";
        }

        return null;
    }

    /**
     * Path to the package's bin/ directory.
     */
    private static function binDir(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin';
    }

    private static function bundledPath(): ?string
    {
        $path = self::binDir() . DIRECTORY_SEPARATOR . self::binaryName();
        return file_exists($path) ? $path : null;
    }

    private static function findInPath(string $name): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            @exec("where $name 2>NUL", $output, $code);
        } else {
            @exec("which $name 2>/dev/null", $output, $code);
        }

        if ($code === 0 && !empty($output[0]) && self::isExecutable(trim($output[0]))) {
            return trim($output[0]);
        }

        return null;
    }

    private static function commonPaths(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return [
                'C:\\sqlite\\sqlite3.exe',
                'C:\\Program Files\\SQLite\\sqlite3.exe',
            ];
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            return [
                '/usr/bin/sqlite3',
                '/usr/local/bin/sqlite3',
                '/opt/homebrew/bin/sqlite3',
            ];
        }

        return [
            '/usr/bin/sqlite3',
            '/usr/local/bin/sqlite3',
            '/bin/sqlite3',
        ];
    }

    private static function isExecutable(string $path): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return file_exists($path);
        }
        return is_file($path) && is_executable($path);
    }
}
