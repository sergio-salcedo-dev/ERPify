<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Storage;

use Erpify\Documents\Document\Domain\Storage\DocumentStorageFailed;
use Erpify\Documents\Document\Domain\Storage\StorageArea;

/**
 * The filesystem calls of {@see FilesystemDocumentStorage} that carry no storage policy: a call whose warning is
 * turned into a return value, and the listing of a sharded directory. Expects the using class to declare
 * `SHARD_LENGTH`.
 */
trait QuietFilesystemCalls
{
    /**
     * Runs a filesystem call whose failure is the answer, not an event: a refused `fopen`, `link` or `mkdir`
     * raises a PHP warning that the caller turns into a typed outcome, and that warning would otherwise reach
     * the log with the path in it. A handler that swallows it for the duration of the one call is scoped
     * (restored in `finally`) where the `@` operator is not.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function quietly(callable $operation): mixed
    {
        \set_error_handler(static fn (): bool => true);

        try {
            return $operation();
        } finally {
            \restore_error_handler();
        }
    }

    /**
     * @return list<string> the names in `$directory`, `.` and `..` excluded
     */
    private function entriesOf(string $directory, StorageArea $area): array
    {
        $entries = $this->quietly(static fn (): array|false => \scandir($directory));

        if (false === $entries) {
            throw DocumentStorageFailed::because($area, 'a shard directory could not be listed');
        }

        return \array_values(\array_filter(
            $entries,
            static fn (string $entry): bool => '.' !== $entry && '..' !== $entry,
        ));
    }

    /**
     * @return list<string> the shard directories in `$directory`; anything else at that level is not an object
     */
    private function shardsOf(string $directory, StorageArea $area): array
    {
        return \array_values(\array_filter(
            $this->entriesOf($directory, $area),
            static fn (string $entry): bool => self::SHARD_LENGTH === \strlen($entry)
                && \is_dir($directory . '/' . $entry),
        ));
    }
}
