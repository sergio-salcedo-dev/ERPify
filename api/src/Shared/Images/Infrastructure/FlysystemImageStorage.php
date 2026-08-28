<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Infrastructure;

use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Storage\ImageBytesNotFound;
use Erpify\Shared\Images\Domain\Storage\ImageStorage;
use Erpify\Shared\Images\Domain\Storage\ImageStorageException;
use Erpify\Shared\Images\Domain\Storage\ImageStorageFailed;
use Erpify\Shared\Images\Domain\Storage\ImageStorageUnavailable;
use Erpify\Shared\Images\Domain\Storage\StorageOperation;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Local-filesystem adapter for {@see ImageStorage}.
 *
 * **Why this class decides absence itself instead of trusting the library.** The local adapter answers
 * `delete()` with a silent success whenever `file_exists()` is false, and `file_exists()` wraps `stat()`,
 * which fails with `EACCES` when a parent directory cannot be traversed — returning exactly the `false`
 * it returns for a file that is not there. Measured as an unprivileged user: a file that EXISTS under a
 * directory at mode 0000 reports `false`, identical to an absent one. On the delete path that is a
 * confirmed erasure of bytes still present, so the traversability of the containing directory is checked
 * FIRST and a failure to establish existence is raised rather than reported as absence.
 *
 * The root is never created here. `lazyRootCreation` is set in the storage configuration so an unmounted
 * volume surfaces as a permanent failure instead of being replaced by a fresh empty directory in the
 * container's writable layer — measured, that substitution makes every `delete()` answer success.
 *
 * **Translation is ordered by decreasing specificity.** The library's concrete types come first and the
 * `FilesystemException` interface last, as a net: catching the interface first would make every concrete
 * branch unreachable. The set of concrete types is not closed — the library ships more of them than the
 * obvious three — so the net is mandatory rather than decorative.
 *
 * Neither the key nor the library's own exception is carried into the translated failure, not even as
 * `previous`: the library quotes the path in its message, and the path is derived from the image
 * identifier.
 *
 * **The write is direct and then verified, rather than atomic.** The bytes go to the final key and are read
 * back and compared by digest before the call returns, so what the port promises holds from the moment
 * `store()` returns; a partial object under the final key is observable only during the write itself, to a
 * reader holding an identifier that has not been handed out. The argument for accepting that window, and
 * what a temporary-plus-rename would cost instead, is on the port beside the promise it qualifies.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") it names its own six-verdict vocabulary plus the
 *                                                   library's concrete exception hierarchy, and collapsing
 *                                                   either is the defect this class exists to prevent
 */
final readonly class FlysystemImageStorage implements ImageStorage
{
    private const int SHARD_LENGTH = 2;

    public function __construct(
        #[Autowire(service: 'images.storage')]
        private FilesystemOperator $filesystem,
        #[Autowire('%env(STORAGE_LOCAL_PATH)%/images')]
        private string $root,
        #[Autowire(service: 'monolog.logger.observability')]
        private LoggerInterface $logger,
    ) {
    }

    #[Override]
    public function store(ImageId $id, string $bytes): void
    {
        $key = $this->keyFor($id);
        $this->guardRootIsUsable(StorageOperation::Store);

        if ($this->objectExists($key, StorageOperation::Store)) {
            // The identifier is identity, never content, so a key that is already taken can only mean a
            // reused id. Overwriting would destroy one image's bytes under another's name silently.
            throw $this->report(
                new ImageStorageFailed(StorageOperation::Store, 'the identifier already carries an object'),
            );
        }

        try {
            $this->filesystem->write($key, $bytes);
        } catch (UnableToWriteFile) {
            throw $this->report(new ImageStorageUnavailable(StorageOperation::Store));
        } catch (FilesystemException) {
            throw $this->report(new ImageStorageFailed(StorageOperation::Store, 'the write could not be completed'));
        }

        $this->verifyStoredBytes($key, $bytes);
    }

    #[Override]
    public function read(ImageId $id): string
    {
        $key = $this->keyFor($id);
        $this->guardRootIsUsable(StorageOperation::Read);

        if (!$this->objectExists($key, StorageOperation::Read)) {
            throw $this->report(new ImageBytesNotFound());
        }

        try {
            return $this->filesystem->read($key);
        } catch (UnableToReadFile) {
            throw $this->report(new ImageStorageUnavailable(StorageOperation::Read));
        } catch (FilesystemException) {
            throw $this->report(new ImageStorageFailed(StorageOperation::Read, 'the read could not be completed'));
        }
    }

    #[Override]
    public function delete(ImageId $id): void
    {
        $key = $this->keyFor($id);
        $this->guardRootIsUsable(StorageOperation::Delete);

        // Establishing absence BEFORE delegating is the whole point: the library would answer success for
        // an object it merely failed to see. `objectExists()` raises rather than answering false when the
        // containing directory cannot be traversed, so reaching the `false` branch here means the object
        // is demonstrably gone and the operation is idempotently satisfied.
        if (!$this->objectExists($key, StorageOperation::Delete)) {
            return;
        }

        try {
            $this->filesystem->delete($key);
        } catch (UnableToDeleteFile) {
            throw $this->report(new ImageStorageUnavailable(StorageOperation::Delete));
        } catch (FilesystemException) {
            throw $this->report(
                new ImageStorageFailed(StorageOperation::Delete, 'the deletion could not be completed'),
            );
        }
    }

    /**
     * Deterministic, relative, derived from the identifier and nothing else — no filename, media type,
     * dimensions or any other value a caller supplied. The two leading shards keep a single directory
     * from growing without bound; they are read off the identifier, so they add no state.
     */
    private function keyFor(ImageId $id): string
    {
        $value = $id->toString();

        return \sprintf(
            '%s/%s/%s',
            \substr($value, 0, self::SHARD_LENGTH),
            \substr($value, self::SHARD_LENGTH, self::SHARD_LENGTH),
            $value,
        );
    }

    /**
     * The root must be present and traversable. Its absence is the unmounted-volume case and is permanent:
     * no retry mounts a volume, and treating it as transient would have a consumer retry for ever.
     */
    private function guardRootIsUsable(StorageOperation $operation): void
    {
        if (!\is_dir($this->root)) {
            throw $this->report(new ImageStorageFailed($operation, 'the storage root is not present'));
        }

        if (!\is_executable($this->root)) {
            throw $this->report(new ImageStorageFailed($operation, 'the storage root cannot be traversed'));
        }
    }

    /**
     * Answers `false` only for a demonstrable absence. When the containing directory exists but cannot be
     * traversed, existence is undecidable and this raises — because the alternative is to report the same
     * `false` a genuinely absent object produces.
     */
    private function objectExists(string $key, StorageOperation $operation): bool
    {
        $directory = $this->root . '/' . \dirname($key);

        if (\is_dir($directory) && !\is_executable($directory)) {
            throw $this->report(new ImageStorageFailed($operation, 'the containing directory cannot be traversed'));
        }

        try {
            return $this->filesystem->fileExists($key);
        } catch (FilesystemException) {
            throw $this->report(
                new ImageStorageFailed($operation, 'the existence of the object could not be established'),
            );
        }
    }

    /**
     * Round-trip verification: the bytes are read back and compared by digest. It is a property of the
     * system rather than of a test, and the cost is stated rather than hidden — one extra read per stored
     * image. A test cannot stand in for it, because what this catches is a write the filesystem itself
     * considered complete.
     */
    private function verifyStoredBytes(string $key, string $bytes): void
    {
        try {
            $storedBytes = $this->filesystem->read($key);
        } catch (FilesystemException) {
            throw $this->report(
                new ImageStorageFailed(StorageOperation::VerifyIntegrity, 'the stored object could not be read back'),
            );
        }

        if (!\hash_equals(\hash('sha256', $bytes), \hash('sha256', $storedBytes))) {
            throw $this->report(
                new ImageStorageFailed(
                    StorageOperation::VerifyIntegrity,
                    'the stored object does not match what was written',
                ),
            );
        }
    }

    /**
     * Emits the signal and hands the failure back so a call site reads `throw $this->report(...)`.
     *
     * The context carries the operation and the verdict, both from closed enums, and nothing else. No
     * identifier, digest, key, byte count or filename: a metric dimension with a free value is a
     * cardinality explosion, and an identifier here would be retained by a sink no erasure path reaches.
     *
     * A confirmed absence is reported one level below the rest, because it is an OUTCOME rather than a
     * fault: an image nobody stored is the ordinary answer to a caller holding an identifier this
     * deployment never wrote, and reporting it at the same level as an unmounted volume trains an
     * operator to ignore the level that means something is broken.
     */
    private function report(ImageStorageException $failure): ImageStorageException
    {
        try {
            $this->emit($failure);
        } catch (Throwable) {
            // Swallowed by design — observability is never load-bearing for the failure itself.
        }

        return $failure;
    }

    /**
     * Spelled as two per-level calls rather than one `log($level, …)`: the carrier gate that reads which
     * channels can reach the container log classifies by the level in the method NAME, and refuses the
     * PSR-3 form precisely because no matcher can classify it.
     */
    private function emit(ImageStorageException $failure): void
    {
        $verdict = $failure->storageFailure();
        $context = [
            'operation' => $failure->operation()->value,
            'failure_category' => $verdict->value,
        ];

        if ($verdict->isOutcome()) {
            $this->logger->info('image_storage_failure', $context);

            return;
        }

        $this->logger->warning('image_storage_failure', $context);
    }
}
