<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Infrastructure;

use Erpify\Shared\Images\Application\FailureSignalWindow;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Storage\ImageBytesNotFound;
use Erpify\Shared\Images\Domain\Storage\ImageStorage;
use Erpify\Shared\Images\Domain\Storage\ImageStorageException;
use Erpify\Shared\Images\Domain\Storage\ImageStorageFailed;
use Erpify\Shared\Images\Domain\Storage\ImageStorageUnavailable;
use Erpify\Shared\Images\Domain\Storage\StorageFailureCategory;
use Erpify\Shared\Images\Domain\Storage\StorageOperation;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use Override;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
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
 * **The root is never created here, and the guard is what makes that true.** `lazyRootCreation` alone does
 * not deliver it: read in the library's own source, it only defers the `mkdir` to the first write, so
 * without `guardRootIsUsable()` running ahead of `write()` the first upload would recreate an unmounted
 * root inside the container's writable layer — and from then on every `delete()` answers success, a
 * confirmed erasure of bytes living somewhere else entirely. The deployment provisions the root; this
 * class refuses to invent it, on the write path as much as on the read and delete paths.
 *
 * **Translation is ordered by decreasing specificity.** The library's concrete types come first and the
 * `FilesystemException` interface last, as a net: catching the interface first would make every concrete
 * branch unreachable. The set of concrete types is not closed — the library ships more of them than the
 * obvious three — so the net is mandatory rather than decorative.
 *
 * **The net answers transient, the same direction {@see verdictFor()} defaults to.** Only the concrete
 * types expose `reason()`, and the library declares no interface promising one, so a failure arriving
 * through the net carries no condition to classify. Answering permanent there made the default direction
 * depend on which type transported the condition: `ensureDirectoryExists()` raises `UnableToCreateDirectory`
 * before the write, so a `mkdir` refused by something a retry resolves was permanent while the identical
 * condition arriving as `UnableToWriteFile` was transient. That type is now caught by name and its
 * condition read; everything still reaching the net degrades toward the retry, at the cost this class
 * already declares — a consumer retrying something no retry fixes.
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

    /**
     * `ENOENT`, the one `access(2)` failure that means "nothing is stored under this key" rather than "I
     * could not find out". POSIX fixes it at 2 on every platform this runs on, and the value is asserted
     * against the running kernel by the adapter's own test rather than trusted as a literal.
     */
    private const int ABSENT_PATH = 2;

    /**
     * The substrate conditions no retry resolves, as PHP words them in the message the storage library
     * carries back. Deliberately short: each entry is a condition an operator has to fix, and a longer
     * list guessing at transient failures would discard work a second attempt would have completed.
     */
    private const array PERMANENT_CONDITIONS = [
        'No space left on device',
        'Permission denied',
        'Read-only file system',
        'Disk quota exceeded',
    ];

    public function __construct(
        #[Autowire(service: 'images.storage')]
        private FilesystemOperator $filesystem,
        #[Autowire(param: 'erpify.images.storage_root')]
        private string $root,
        #[Autowire(service: 'monolog.logger.observability')]
        private LoggerInterface $logger,
        private FailureSignalWindow $window,
    ) {
    }

    #[Override]
    public function store(ImageId $id, #[SensitiveParameter] string $bytes): void
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
        } catch (UnableToCreateDirectory $refusal) {
            // Two arms rather than one union: rector and php-cs-fixer disagree on the spacing of a
            // multi-catch, so the union made this file's verdict depend on which fixer ran last.
            throw $this->discard($key, $this->verdictFor($refusal->reason(), StorageOperation::Store));
        } catch (UnableToWriteFile $refusal) {
            throw $this->discard($key, $this->verdictFor($refusal->reason(), StorageOperation::Store));
        } catch (FilesystemException) {
            throw $this->discard($key, new ImageStorageUnavailable(StorageOperation::Store));
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
        } catch (UnableToReadFile $refusal) {
            // Reached when the object stopped existing BETWEEN the check above and this read, and that
            // window is designed into the module rather than incidental to it: `DeleteImage` unlinks the
            // bytes before deleting the row, precisely so a crash can never leave a row promising bytes that
            // are gone. The library reports it with PHP's own `No such file or directory`, which matches no
            // `PERMANENT_CONDITIONS` entry, so `verdictFor()` would call it retryable — telling a caller to
            // retry a deletion, and pointing an operator at a substrate fault that does not exist. Asking
            // again is what distinguishes the two, and it costs a `posix_access` only on a path that has
            // already failed.
            if (!$this->objectExists($key, StorageOperation::Read)) {
                throw $this->report(new ImageBytesNotFound());
            }

            throw $this->report($this->verdictFor($refusal->reason(), StorageOperation::Read));
        } catch (FilesystemException) {
            throw $this->report(new ImageStorageUnavailable(StorageOperation::Read));
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
            // Reported, not silent. This is the one outcome that looks identical to a completed deletion
            // from the caller's side, and it is the one an operator most needs to be able to count: a
            // deployment answering "already absent" for every request is a deployment pointed at the wrong
            // bytes, and without this line that state produces no record at all.
            $this->emit(StorageOperation::Delete, StorageFailureCategory::ConfirmedAbsence);

            return;
        }

        try {
            $this->filesystem->delete($key);
        } catch (UnableToDeleteFile $refusal) {
            throw $this->report($this->verdictFor($refusal->reason(), StorageOperation::Delete));
        } catch (FilesystemException) {
            throw $this->report(new ImageStorageUnavailable(StorageOperation::Delete));
        }
    }

    /**
     * Deterministic, relative, derived from the identifier and nothing else — no filename, media type,
     * dimensions or any other value a caller supplied.
     *
     * **The shards are read off the TAIL of the identifier, and that is the whole point.** An `ImageId` is
     * a UUID v7, whose leading hex is a millisecond timestamp: the first pair advances once every ~35
     * years and the second once every ~50 days, so sharding on the head puts every image of a ~50-day
     * window into one directory — the opposite of the property a shard is for. Measured: 4000 identifiers
     * minted in one run gave **one** distinct leading pair and **3869** distinct trailing pairs. The tail
     * is the random half of the layout, so the two shards spread uniformly over 65 536 buckets while
     * staying a pure function of the identifier.
     */
    private function keyFor(ImageId $id): string
    {
        $value = $id->toString();

        return \sprintf(
            '%s/%s/%s',
            \substr($value, -2 * self::SHARD_LENGTH, self::SHARD_LENGTH),
            \substr($value, -self::SHARD_LENGTH),
            $value,
        );
    }

    /**
     * The root must be present and traversable. Its absence is the unmounted-volume case and is permanent:
     * no retry mounts a volume, and treating it as transient would have a consumer retry for ever.
     *
     * **Decided at the syscall, for the reason {@see objectExists()} spells out at length.** `is_dir()` and
     * `is_executable()` are `stat()` wrappers, so with an ancestor of the root at mode 0000 they answer
     * `false` and the operator is told the root "is not present" — sending them to look at the mount when
     * the fault is a permission. The verdict is the same either way, which is what keeps this a diagnosis
     * bug rather than a correctness one, but the diagnosis is the only thing this message is for.
     */
    private function guardRootIsUsable(StorageOperation $operation): void
    {
        \clearstatcache(true, $this->root);

        if (!\posix_access($this->root, POSIX_F_OK)) {
            throw $this->report(new ImageStorageFailed(
                $operation,
                self::ABSENT_PATH === \posix_get_last_error()
                    ? 'the storage root is not present'
                    : 'the presence of the storage root could not be established',
            ));
        }

        if (!\posix_access($this->root, POSIX_X_OK)) {
            throw $this->report(new ImageStorageFailed($operation, 'the storage root cannot be traversed'));
        }
    }

    /**
     * Answers `false` only for a demonstrable absence, and raises whenever existence cannot be established.
     *
     * **The verdict comes from the kernel's own errno, not from a predicate over the path.** `access(2)`
     * distinguishes the two outcomes `file_exists()` conflates: `ENOENT` means no such path — the object,
     * or a directory above it, is not there, and either way nothing is stored under this key — while
     * anything else (`EACCES` on any ancestor, a symlink loop, a component that is not a directory) means
     * the question could not be answered. `file_exists()` answers the same `false` to both and fails toward
     * the first, which on the delete path is a confirmed erasure of bytes still present.
     *
     * A chain of `is_dir()`/`is_executable()` checks cannot replace it, and the reason is measured: those
     * predicates are `stat()` wrappers too, so a directory sitting under an unreadable ancestor reports
     * `false` for "is a directory" and the check skips itself. Guarding only the immediate parent left
     * exactly that hole — with `<root>/ab` at mode 0000, `delete()` returned success while the object was
     * still on disk.
     *
     * The price is that this adapter now reads the local filesystem directly rather than asking the
     * library. That coupling is not new — the root is already a constructor argument and already stat-ed —
     * and it is the point: the library's answer is the one that cannot be trusted here.
     */
    private function objectExists(string $key, StorageOperation $operation): bool
    {
        $path = $this->root . '/' . $key;
        \clearstatcache(true, $path);

        if (\posix_access($path, POSIX_F_OK)) {
            return true;
        }

        if (self::ABSENT_PATH === \posix_get_last_error()) {
            return false;
        }

        throw $this->report(
            new ImageStorageFailed($operation, 'the existence of the object could not be established'),
        );
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
            throw $this->discard(
                $key,
                new ImageStorageFailed(StorageOperation::VerifyIntegrity, 'the stored object could not be read back'),
            );
        }

        // Compared as bytes, not as digests. Two locally computed hashes buy no collision resistance a
        // direct comparison lacks and cost two extra passes over the payload; there is no remote party and
        // no timing oracle here, so `hash_equals` would be ceremony.
        if ($bytes !== $storedBytes) {
            throw $this->discard(
                $key,
                new ImageStorageFailed(
                    StorageOperation::VerifyIntegrity,
                    'the stored object does not match what was written',
                ),
            );
        }
    }

    /**
     * Removes an object a failed write or a refused verification left behind, then hands the failure back.
     *
     * **A failed write leaves one too, and that is not obvious.** `LocalFilesystemAdapter::write()` calls
     * `file_put_contents()`, whose `open(2)` carries `O_CREAT|O_TRUNC`: the file exists under the final key
     * before a single byte is written, so a write that fails part-way — `ENOSPC`, a quota, an I/O error —
     * still leaves an object there. Under `ENOSPC` that residue is self-amplifying: every attempt consumes
     * more of the space that was already exhausted.
     *
     * Leaving either would poison the identifier for ever: `store()` refuses a key that already carries an
     * object, so a retry under the same id would meet the corrupt one and be rejected as a reuse, and
     * `read()` performs no digest check, so those bytes would be served as valid. Removal also makes the
     * port's promise true as written — a partial object is observable only while the write is in flight.
     *
     * The removal is best-effort by design: a substrate that just failed a write or a read-back may well
     * fail this too, and the caller is owed the original failure rather than whatever the cleanup hit.
     */
    private function discard(string $key, ImageStorageException $failure): ImageStorageException
    {
        try {
            $this->filesystem->delete($key);
        } catch (FilesystemException) {
            // Swallowed: the object becomes an orphan of the kind the upload path already accepts, and
            // saying so here would replace the verdict the caller has to act on.
        }

        return $this->report($failure);
    }

    /**
     * Which class of failure a refusal from the library is.
     *
     * The library reports every `file_put_contents` failure as one type, so the type alone cannot say
     * whether a retry has anything to work with. What it does carry is the condition PHP reported, and two
     * of those are conditions only an operator resolves. Recognising them is an enumeration, and its
     * default direction is the harmful one — an unrecognised permanent condition degrades to transient, so
     * a consumer retries something no retry fixes, bounded only by the worker's retry count. That residual
     * is the price of not guessing: everything else really can be transient, and treating it as permanent
     * would discard work a second attempt would have completed.
     *
     * **The reason is read and never carried.** For the local adapter it is PHP's own message, which
     * quotes the path — and the path contains the image identifier — so it is matched against and then
     * dropped. What travels is the matched condition, which is one of the literals below.
     */
    private function verdictFor(string $reason, StorageOperation $operation): ImageStorageException
    {
        foreach (self::PERMANENT_CONDITIONS as $condition) {
            if (\str_contains($reason, $condition)) {
                return new ImageStorageFailed(
                    $operation,
                    \sprintf('the substrate refused in a way no retry resolves (%s)', $condition),
                );
            }
        }

        return new ImageStorageUnavailable($operation);
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
        $this->emit($failure->operation(), $failure->storageFailure());

        return $failure;
    }

    /**
     * Emits the signal, and never lets it become load-bearing for whatever it is reporting.
     *
     * Gated by {@see FailureSignalWindow}, which this class needs for the same reason its read-path sibling
     * does and did not need before: until a route served bytes, nothing here was reachable from a client at
     * all, so the rate was bounded by what the application itself did.
     *
     * Spelled as two per-level calls rather than one `log($level, …)`: the carrier gate that reads which
     * channels can reach the container log classifies by the level in the method NAME, and refuses the
     * PSR-3 form precisely because no matcher can classify it.
     */
    private function emit(StorageOperation $operation, StorageFailureCategory $verdict): void
    {
        if (!$this->window->admits($operation->value . '|' . $verdict->value)) {
            return;
        }

        $context = [
            'operation' => $operation->value,
            'failure_category' => $verdict->value,
        ];

        try {
            if ($verdict->isOutcome()) {
                $this->logger->info('image_storage_failure', $context);

                return;
            }

            $this->logger->warning('image_storage_failure', $context);
        } catch (Throwable) {
            // Swallowed by design — observability is never load-bearing for the outcome itself.
        }
    }
}
