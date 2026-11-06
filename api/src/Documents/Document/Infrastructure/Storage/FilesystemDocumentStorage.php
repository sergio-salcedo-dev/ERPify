<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Storage;

use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\DocumentStorageFailed;
use Erpify\Documents\Document\Domain\Storage\ObjectAlreadyExists;
use Erpify\Documents\Document\Domain\Storage\ObjectNotFound;
use Erpify\Documents\Document\Domain\Storage\StorageArea;
use Erpify\Shared\Uuid\Domain\Uuid;
use InvalidArgumentException;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Local-filesystem adapter for {@see DocumentStorage}: two sibling directories under one root, `staging/` and
 * `final/`, each sharded by the tail of the scope id. It mirrors the images adapter's discipline and does not
 * reuse it, because the two ports promise different things.
 *
 * **Native filesystem calls, not Flysystem, and the reason is exclusivity.** The port refuses to overwrite,
 * on `stage` and on `promote`, and the library's local adapter overwrites on write and has no create-if-absent
 * or rename-without-replace. Checking first and writing after is a race that destroys one document's bytes
 * under another's name; here the kernel decides, through `fopen(…, 'xb')` (`O_EXCL`) and `link(2)`.
 *
 * **`promote` is `link(2)` then `unlink(2)`, which is a rename that refuses to replace.** `rename(2)` is the
 * atomic same-filesystem move, but it silently replaces an existing destination, and PHP offers no
 * `RENAME_NOREPLACE`. `link(2)` creates the destination exclusively (`EEXIST`) and, like `rename(2)`, fails
 * with `EXDEV` across mount points, so the claim "staging and final are one filesystem" is exactly as strong
 * as before and is probed by this adapter's own test rather than assumed from `st_dev`. A crash between the
 * two calls leaves both names on one inode: the object is complete under `final/`, and the stale staged name
 * is what the upload flow's sweep removes. If the `unlink` fails the new name is removed again, so a failed
 * promotion leaves the staged object exactly where it was.
 *
 * **The roots are never created here.** The deployment provisions them (`docker-entrypoint.sh`, only when a
 * volume is mounted at the parent); an adapter that invented a missing root would write into the container's
 * writable layer and every later `delete` would answer success for bytes living somewhere else entirely.
 *
 * **Absence is decided at the syscall**, by `access(2)`'s errno: `ENOENT` means nothing is stored, anything
 * else means the question could not be answered. `file_exists()` answers the same `false` to both and fails
 * toward "deleted", which on `delete` is a confirmed erasure of bytes still present.
 *
 * **Durability is claimed where the upload flow needs it.** {@see stageWith()} syncs the staged file and the
 * staging shard directory before it returns, and {@see promote()} syncs the final shard directory after the
 * link and the staging one after the unlink, so a name the caller was told exists survives a crash of the host.
 * The boot probe and the sweeps are the upload flow's ({@see StorageBootProbe}). Failures carry fixed
 * sentences, never the path or the library's message, both of which quote the scope id.
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity") one adapter, every branch an errno the port must translate
 * @SuppressWarnings("PHPMD.ExcessiveClassLength") the same: the length is the failure handling the port promises
 */
#[AsAlias(DocumentStorage::class)]
final readonly class FilesystemDocumentStorage implements DocumentStorage
{
    private const int SHARD_LENGTH = 2;

    /**
     * `ENOENT`, the one `access(2)` failure that means "nothing is stored under this path" rather than "I
     * could not find out". POSIX fixes it at 2 on every platform this runs on, and the adapter's test asserts
     * the value against the running kernel.
     */
    private const int ABSENT_PATH = 2;

    private const int CHUNK_BYTES = 65_536;

    private const int DIRECTORY_MODE = 0o750;

    public function __construct(
        #[Autowire(param: 'erpify.documents.storage_root')]
        private string $root,
    ) {
        // An empty or relative root resolves against whatever the working directory happens to be, which is a
        // different place under FrankenPHP, the console and a worker — and every object would land there.
        if ('' === $root || !\str_starts_with($root, '/')) {
            throw new InvalidArgumentException('The document storage root must be an absolute path.');
        }
    }

    #[Override]
    public function stage(string $scopeId, $stream): void
    {
        $path = $this->pathFor(StorageArea::Staging, $scopeId);
        $this->guardAreaIsUsable(StorageArea::Staging);

        if (!\is_resource($stream) || 'stream' !== \get_resource_type($stream)) {
            throw new InvalidArgumentException('Staging needs an open stream.');
        }

        if ($this->objectExists($path, StorageArea::Staging)) {
            throw ObjectAlreadyExists::in(StorageArea::Staging);
        }

        $this->ensureDirectory(\dirname($path), StorageArea::Staging);

        $handle = $this->quietly(static fn () => \fopen($path, 'x'));

        if (false === $handle) {
            // Created by someone else between the check and the open: the kernel's refusal is the verdict.
            throw $this->objectExists($path, StorageArea::Staging)
                ? ObjectAlreadyExists::in(StorageArea::Staging)
                : DocumentStorageFailed::because(StorageArea::Staging, 'the staging object could not be created');
        }

        try {
            $this->streamInto($stream, $handle);
        } catch (Throwable $throwable) {
            // Whatever the source stream raised — not only this adapter's own failure — the handle is closed and
            // the partial object removed, or the half-written file would block the key with ObjectAlreadyExists.
            \fclose($handle);
            $this->quietly(static fn (): bool => \unlink($path));

            throw $throwable;
        }

        // A close that fails is a flush that failed: the data may not be on disk, so the object is not stored.
        if (!\fclose($handle)) {
            $this->quietly(static fn (): bool => \unlink($path));

            throw DocumentStorageFailed::because(StorageArea::Staging, 'the staging object could not be closed');
        }
    }

    #[Override]
    public function stageWith(string $scopeId, callable $writer): void
    {
        $path = $this->pathFor(StorageArea::Staging, $scopeId);
        $this->guardAreaIsUsable(StorageArea::Staging);

        if ($this->objectExists($path, StorageArea::Staging)) {
            throw ObjectAlreadyExists::in(StorageArea::Staging);
        }

        $this->ensureDirectory(\dirname($path), StorageArea::Staging);

        $handle = $this->quietly(static fn () => \fopen($path, 'x'));

        if (false === $handle) {
            throw $this->objectExists($path, StorageArea::Staging)
                ? ObjectAlreadyExists::in(StorageArea::Staging)
                : DocumentStorageFailed::because(StorageArea::Staging, 'the staging object could not be created');
        }

        try {
            $writer($handle);

            if (!\fflush($handle) || !\fsync($handle)) {
                throw DocumentStorageFailed::because(StorageArea::Staging, 'the staging object could not be synced');
            }
        } catch (Throwable $throwable) {
            \fclose($handle);
            $this->quietly(static fn (): bool => \unlink($path));

            throw $throwable;
        }

        if (!\fclose($handle)) {
            $this->quietly(static fn (): bool => \unlink($path));

            throw DocumentStorageFailed::because(StorageArea::Staging, 'the staging object could not be closed');
        }

        $this->syncDirectory(\dirname($path), StorageArea::Staging);
    }

    #[Override]
    public function has(StorageArea $area, string $scopeId): bool
    {
        $path = $this->pathFor($area, $scopeId);
        $this->guardAreaIsReadable($area);

        return $this->objectExists($path, $area);
    }

    /**
     * @return lowercase-string[]
     */
    #[Override]
    public function scopesOlderThan(StorageArea $area, int $olderThanSeconds, int $limit): array
    {
        $areaDirectory = $this->guardAreaIsReadable($area);
        $cutoff = \time() - $olderThanSeconds;
        $scopes = [];

        foreach ($this->shardsOf($areaDirectory, $area) as $first) {
            foreach ($this->shardsOf($areaDirectory . '/' . $first, $area) as $second) {
                $shard = $areaDirectory . '/' . $first . '/' . $second;

                foreach ($this->entriesOf($shard, $area) as $name) {
                    if (\count($scopes) >= $limit) {
                        return $scopes;
                    }

                    if (!Uuid::isValid($name) || \strtolower($name) !== $name) {
                        continue;
                    }

                    \clearstatcache(true, $shard . '/' . $name);
                    $modified = $this->quietly(static fn (): false|int => \filemtime($shard . '/' . $name));

                    if (false !== $modified && $modified <= $cutoff) {
                        $scopes[] = $name;
                    }
                }
            }
        }

        return $scopes;
    }

    #[Override]
    public function promote(string $scopeId): void
    {
        $staged = $this->pathFor(StorageArea::Staging, $scopeId);
        $final = $this->pathFor(StorageArea::Final, $scopeId);
        $this->guardAreaIsUsable(StorageArea::Staging);
        $this->guardAreaIsUsable(StorageArea::Final);

        if (!$this->objectExists($staged, StorageArea::Staging)) {
            throw ObjectNotFound::in(StorageArea::Staging);
        }

        $this->ensureDirectory(\dirname($final), StorageArea::Final);

        if (!$this->quietly(static fn (): bool => \link($staged, $final))) {
            throw $this->objectExists($final, StorageArea::Final)
                ? ObjectAlreadyExists::in(StorageArea::Final)
                : DocumentStorageFailed::because(
                    StorageArea::Final,
                    'the staged object could not be linked into the final area',
                );
        }

        if (!$this->quietly(static fn (): bool => \unlink($staged))) {
            // Roll the new name back so a failure leaves the staged object exactly where it was.
            $this->quietly(static fn (): bool => \unlink($final));

            throw DocumentStorageFailed::because(StorageArea::Staging, 'the staged name could not be removed');
        }

        $this->syncDirectory(\dirname($final), StorageArea::Final);
        $this->syncDirectory(\dirname($staged), StorageArea::Staging);
    }

    #[Override]
    public function open(string $scopeId)
    {
        $path = $this->pathFor(StorageArea::Final, $scopeId);
        $this->guardAreaIsReadable(StorageArea::Final);

        if (!$this->objectExists($path, StorageArea::Final)) {
            throw ObjectNotFound::in(StorageArea::Final);
        }

        $handle = $this->quietly(static fn () => \fopen($path, 'r'));

        if (false === $handle) {
            // Gone between the check and the open (an erasure racing a read) is an absence, not a fault.
            throw $this->objectExists($path, StorageArea::Final)
                ? DocumentStorageFailed::because(StorageArea::Final, 'the object could not be opened')
                : ObjectNotFound::in(StorageArea::Final);
        }

        return $handle;
    }

    #[Override]
    public function delete(StorageArea $area, string $scopeId): void
    {
        $path = $this->pathFor($area, $scopeId);
        $this->guardAreaIsUsable($area);

        // Establishing absence BEFORE unlinking: `objectExists()` raises when the answer cannot be had, so
        // reaching `false` means the object is demonstrably gone and the call is already satisfied.
        if (!$this->objectExists($path, $area)) {
            return;
        }

        if (!$this->quietly(static fn (): bool => \unlink($path)) && $this->objectExists($path, $area)) {
            throw DocumentStorageFailed::because($area, 'the object could not be deleted');
        }
    }

    /**
     * The key is the scope id and nothing else, sharded from its TAIL: scope ids are random, but nothing
     * here may depend on that, and the tail is the half of any UUID that is never a timestamp.
     *
     * The spelling is validated rather than normalised. A key derived from the spelling of an identifier whose
     * row is selected by value is the shape of the defect where an upper-cased id stored an object under one
     * name and erased another, reporting a confirmed erasure of bytes still on disk. Refusing every spelling
     * but the canonical one leaves a single key per scope.
     */
    private function pathFor(StorageArea $area, string $scopeId): string
    {
        if (!Uuid::isValid($scopeId) || \strtolower($scopeId) !== $scopeId) {
            throw new InvalidArgumentException('The scope id must be a lower-case UUID.');
        }

        return \sprintf(
            '%s/%s/%s/%s/%s',
            $this->root,
            $area->value,
            \substr($scopeId, -2 * self::SHARD_LENGTH, self::SHARD_LENGTH),
            \substr($scopeId, -self::SHARD_LENGTH),
            $scopeId,
        );
    }

    /**
     * The area's directory must be present, traversable and writable for an operation that writes. Its absence is
     * the unmounted-volume case and is permanent: no retry mounts a volume.
     */
    private function guardAreaIsUsable(StorageArea $area): void
    {
        $directory = $this->guardAreaIsReadable($area);

        if (!\posix_access($directory, POSIX_W_OK)) {
            throw DocumentStorageFailed::because($area, 'the storage area cannot be written to');
        }
    }

    /**
     * Reading an object needs only to find the area and traverse it, so a read-only mount can still serve
     * documents.
     *
     * @return string the area's directory
     */
    private function guardAreaIsReadable(StorageArea $area): string
    {
        $directory = $this->root . '/' . $area->value;
        \clearstatcache(true, $directory);

        if (!\posix_access($directory, POSIX_F_OK)) {
            throw DocumentStorageFailed::because(
                $area,
                self::ABSENT_PATH === \posix_get_last_error()
                    ? 'the storage area is not present'
                    : 'the presence of the storage area could not be established',
            );
        }

        if (!\posix_access($directory, POSIX_X_OK)) {
            throw DocumentStorageFailed::because($area, 'the storage area cannot be traversed');
        }

        return $directory;
    }

    private function objectExists(string $path, StorageArea $area): bool
    {
        \clearstatcache(true, $path);

        if (\posix_access($path, POSIX_F_OK)) {
            return true;
        }

        if (self::ABSENT_PATH === \posix_get_last_error()) {
            return false;
        }

        throw DocumentStorageFailed::because($area, 'the existence of the object could not be established');
    }

    /**
     * A directory entry (a created, linked or unlinked name) is durable only once its directory is synced:
     * syncing the file makes its bytes durable, not the name that reaches them.
     */
    private function syncDirectory(string $directory, StorageArea $area): void
    {
        $handle = $this->quietly(static fn () => \fopen($directory, 'r'));

        if (false === $handle) {
            throw DocumentStorageFailed::because($area, 'the shard directory could not be opened for syncing');
        }

        try {
            if (!$this->quietly(static fn (): bool => \fsync($handle))) {
                throw DocumentStorageFailed::because($area, 'the shard directory could not be synced');
            }
        } finally {
            \fclose($handle);
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

    private function ensureDirectory(string $directory, StorageArea $area): void
    {
        // Tolerating a concurrent creator: `mkdir` fails when the directory appeared meanwhile, which is the
        // outcome wanted.
        if (\is_dir($directory)) {
            return;
        }

        $created = $this->quietly(static fn (): bool => \mkdir($directory, self::DIRECTORY_MODE, true));

        if (!$created && !\is_dir($directory)) {
            throw DocumentStorageFailed::because($area, 'the shard directory could not be created');
        }
    }

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
     * @param resource $source
     * @param resource $destination
     */
    private function streamInto($source, $destination): void
    {
        while (!\feof($source)) {
            $chunk = \fread($source, self::CHUNK_BYTES);

            if (false === $chunk || ('' === $chunk && !\feof($source))) {
                throw DocumentStorageFailed::because(StorageArea::Staging, 'the source stream could not be read');
            }

            for ($offset = 0, $length = \strlen($chunk); $offset < $length; $offset += $written) {
                $written = \fwrite($destination, \substr($chunk, $offset));

                if (false === $written || 0 === $written) {
                    throw DocumentStorageFailed::because(
                        StorageArea::Staging,
                        'the staging object could not be written',
                    );
                }
            }
        }
    }
}
