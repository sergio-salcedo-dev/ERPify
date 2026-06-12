<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Single source for the recursive `api/src` PHP-file walk. The error-contract gates each
 * need to sweep every production source file — one greps catch-blocks as text, the other
 * autoloads and reflects throwable classes — but the directory traversal itself is identical
 * boilerplate. Centralising it here keeps the two gates from drifting apart on what "every
 * source file" means (extension filter, dot-skipping, non-file entries).
 *
 * Walk only: each caller layers its own derivation (text read or FQCN + reflection) on top.
 *
 * @internal test support
 */
final class ApiSourceFiles
{
    /**
     * Absolute path to `api/src`, derived from this file's location (`api/tests/Support`) so it
     * holds regardless of the working directory a test runner is invoked from.
     */
    public static function root(): string
    {
        return \dirname(__DIR__, 2) . '/src';
    }

    /**
     * Yields a {@see SplFileInfo} for every `.php` file under `$root` (defaulting to
     * {@see self::root()}). A missing root yields nothing rather than throwing, so a caller
     * can assert on an empty sweep instead of a fatal.
     *
     * @return iterable<SplFileInfo>
     */
    public static function phpFiles(?string $root = null): iterable
    {
        $root ??= self::root();

        if (!\is_dir($root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            if (!$file->isFile()) {
                continue;
            }

            if ('php' !== $file->getExtension()) {
                continue;
            }

            yield $file;
        }
    }
}
