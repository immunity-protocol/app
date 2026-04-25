<?php

declare(strict_types=1);

namespace App\Models\Core;

use RuntimeException;

/**
 * Resolves psql `\ir` (include relative) directives in pure PHP.
 *
 * Lets `sql/0-init-database.sql` orchestrate per-domain SQL files without
 * requiring the psql client to be installed in the calling PHP container.
 */
final class SqlLoader
{
    /**
     * Read a SQL file and return its contents with all `\ir <path>` directives
     * recursively inlined. Other psql meta-commands (lines starting with `\`)
     * are stripped.
     */
    public static function load(string $path): string
    {
        $real = realpath($path);
        if ($real === false || !is_readable($real)) {
            throw new RuntimeException("SQL file not found: $path");
        }
        $dir = dirname($real);
        $contents = file_get_contents($real);
        if ($contents === false) {
            throw new RuntimeException("Cannot read SQL file: $real");
        }

        $out = '';
        foreach (preg_split('/\r?\n/', $contents) as $line) {
            $trim = ltrim($line);
            if ($trim === '' || str_starts_with($trim, '--')) {
                $out .= $line . "\n";
                continue;
            }
            if (preg_match('/^\\\\ir\s+(.+?);?\s*$/', $trim, $m)) {
                $included = $dir . DIRECTORY_SEPARATOR . trim($m[1]);
                $out .= self::load($included) . "\n";
                continue;
            }
            // Strip any other psql meta-command (line starts with backslash).
            if (str_starts_with($trim, '\\')) {
                continue;
            }
            $out .= $line . "\n";
        }
        return $out;
    }
}
