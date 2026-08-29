<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Infrastructure;

use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Storage\ImageStorageFailed;
use Erpify\Shared\Images\Domain\Storage\StorageFailureCategory;
use Erpify\Shared\Images\Infrastructure\FlysystemImageStorage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The branch the contract calls "existence could not be established", which must fail rather than take the
 * idempotent-success path absence takes.
 *
 * It is the branch most worth pinning and the easiest to lose: `file_exists()` is a `stat()` wrapper that
 * answers the same `false` for "not there" and for "I was not allowed to look", and it fails toward the
 * first. On the delete path that difference is a confirmed erasure of bytes that are still present.
 *
 * **Every case here runs as a genuinely unprivileged user**, because the assertion is about a permission
 * the root user does not have to obey. Measured in this container with a directory at mode 0000:
 * `is_executable()`, `is_dir()` on its children and `access(F_OK)` on a file beneath it all answer TRUE for
 * uid 0 and FALSE (or `EACCES`) for uid 1000. A case running as root would exercise nothing and pass green
 * — which is also why the sibling contract test cannot reach these branches and says so.
 *
 * They double as the leak assertions for those branches: the verdict's text and its `previous` chain are
 * read back out of the subprocess and searched for the identifier.
 *
 * @internal
 */
#[CoversClass(FlysystemImageStorage::class)]
final class FlysystemImageStorageUndecidableExistenceTest extends TestCase
{
    use TemporaryImageStorage;

    /**
     * The unmounted-volume case, and the reason the storage is configured not to create its own root: with
     * the root absent the library would fabricate an empty directory on the first write and then answer
     * success for every deletion, reporting erasures of bytes that are somewhere else entirely.
     */
    public function testAnAbsentStorageRootIsAPermanentFailureAndNeverAnAbsence(): void
    {
        $missingRoot = $this->root . '/never-provisioned';
        $storage = new FlysystemImageStorage(
            new Filesystem(new LocalFilesystemAdapter($missingRoot, lazyRootCreation: true)),
            $missingRoot,
            new RecordingLogger(),
        );

        try {
            $storage->delete(ImageId::generate());
            $this->fail('a missing root must not be reported as the object being absent');
        } catch (ImageStorageFailed $imageStorageFailed) {
            $this->assertSame(StorageFailureCategory::Permanent, $imageStorageFailed->storageFailure());
            // The reason is asserted, not only the category: an absent root and an untraversable one are
            // both permanent, and `is_executable()` answers false for both, so a category-only assertion
            // leaves the absence branch unfalsifiable — measured by deleting it and watching this stay
            // green. An operator reading "cannot be traversed" for a volume that was never mounted looks
            // at permissions instead of at the mount.
            $this->assertStringContainsString('not present', $imageStorageFailed->getMessage());
        }

        $this->assertDirectoryDoesNotExist($missingRoot, 'the adapter must not create the root it could not find');
    }

    /**
     * A `store()` against an absent root must refuse too, rather than letting the library provision it.
     * The configuration flag is NOT what delivers that: measured in the library's own source, it only
     * defers creation to the first write (`writeToFile()` calls `ensureRootDirectoryExists()`), so without
     * the guard the very first upload would recreate the root inside the container's writable layer and
     * every later deletion would answer success over bytes living somewhere else.
     */
    public function testAStoreAgainstAnAbsentRootRefusesRatherThanProvisioningIt(): void
    {
        $missingRoot = $this->root . '/never-provisioned';
        $storage = new FlysystemImageStorage(
            new Filesystem(new LocalFilesystemAdapter($missingRoot, lazyRootCreation: true)),
            $missingRoot,
            new RecordingLogger(),
        );

        try {
            $storage->store(ImageId::generate(), 'bytes that must not be written into the image layer');
            $this->fail('a missing root must not be provisioned by a write');
        } catch (ImageStorageFailed $imageStorageFailed) {
            $this->assertSame(StorageFailureCategory::Permanent, $imageStorageFailed->storageFailure());
            $this->assertStringContainsString('not present', $imageStorageFailed->getMessage());
        }

        $this->assertDirectoryDoesNotExist($missingRoot);
    }

    public function testExistenceThatCannotBeEstablishedFailsRatherThanReportingAnErasure(): void
    {
        $this->assertUndecidableWhenBlocked(static fn (string $object): string => \dirname($object));
    }

    /**
     * The same property one level up, and the level the first version of this guard did not cover: the key
     * is `<shard>/<shard>/<id>`, so blocking the OUTER shard leaves the inner one unreadable too. Every
     * `is_dir()`/`is_executable()` predicate over that inner directory then answers false — it is a
     * `stat()` wrapper meeting the same `EACCES` — so a guard that inspects only the containing directory
     * skips itself and hands the library's `false` straight to the idempotent-absence path.
     *
     * Measured before the fix: `delete()` returned success while the object was still on disk.
     */
    public function testAnUntraversableShardAboveTheObjectIsAlsoUndecidable(): void
    {
        $this->assertUndecidableWhenBlocked(static fn (string $object): string => \dirname($object, 2));
    }

    /**
     * The root guard's own version of the same distinction, and the branch that did not exist until the
     * guard stopped deciding with `is_dir()`.
     *
     * With the root's PARENT untraversable, `is_dir($root)` answers `false` for exactly the `EACCES` the
     * guard is there to catch — so the operator was told the root "is not present" and went to look at the
     * mount, when the fault was a permission. The verdict is permanent either way, which is what keeps
     * this a diagnosis defect rather than a correctness one; the message is the only thing it produces.
     */
    public function testARootThatCannotBeExaminedIsNotReportedAsAnAbsentRoot(): void
    {
        $vault = $this->root . '/vault';
        $storageRoot = $vault . '/images';
        \mkdir($storageRoot, 0o755, true);

        \chmod($vault, 0o000);

        try {
            $outcome = $this->deleteAsUnprivilegedUser(ImageId::generate(), $storageRoot);
        } finally {
            \chmod($vault, 0o755);
        }

        $this->assertSame('FAILED', $outcome['outcome']);
        $this->assertStringContainsString('could not be established', $outcome['message']);
        $this->assertStringNotContainsString(
            'not present',
            $outcome['message'],
            'a root that exists and cannot be examined must not be reported as one that is absent',
        );
    }

    /**
     * Blocks one directory on the path to a stored object, runs the deletion as an unprivileged user, and
     * asserts the three things that matter: it failed rather than reporting an erasure, the bytes survived,
     * and the verdict it raised carries neither the identifier nor a `previous` that could.
     *
     * @param callable(string): string $blockedDirectoryOf receives the object's absolute path
     */
    private function assertUndecidableWhenBlocked(callable $blockedDirectoryOf): void
    {
        $imageId = ImageId::generate();
        $storage = new FlysystemImageStorage(
            new Filesystem(new LocalFilesystemAdapter($this->root, lazyRootCreation: true)),
            $this->root,
            new RecordingLogger(),
        );
        $storage->store($imageId, 'bytes that must survive');

        $objectPath = $this->pathFor($imageId);
        $blocked = $blockedDirectoryOf($objectPath);

        // Open every directory on the path FIRST. The library creates its shards at 0700, so without this
        // an unprivileged probe is stopped at the outermost shard whichever level the case meant to block
        // — and both cases would then measure the same thing while reading as two.
        $directory = \dirname($objectPath);

        while (\strlen($directory) >= \strlen($this->root)) {
            \chmod($directory, 0o755);
            $directory = \dirname($directory);
        }

        \chmod($blocked, 0o000);

        try {
            $outcome = $this->deleteAsUnprivilegedUser($imageId);
        } finally {
            \chmod($blocked, 0o755);
        }

        $this->assertSame('FAILED', $outcome['outcome'], 'an undecidable existence must fail, never report success');
        $this->assertFileExists($objectPath, 'and the bytes must still be there');

        $this->assertStringNotContainsString(
            $imageId->toString(),
            $outcome['message'],
            'the verdict must not name the identifier',
        );
        $this->assertStringNotContainsString(\substr($imageId->toString(), 0, 8), $outcome['message']);
        $this->assertSame(0, $outcome['previous'], 'nor carry a previous that would name it');
    }

    /**
     * Drives the adapter from a separate process running as `nonroot`, and reports which branch it took.
     * Any unexpected output fails the decode, so a broken probe is visible instead of being read as a
     * passing assertion.
     *
     * @return array{outcome: string, message: string, previous: int}
     */
    private function deleteAsUnprivilegedUser(ImageId $imageId, ?string $storageRoot = null): array
    {
        // The script always lives under the case's own root, never under the adapter's: the root-guard
        // case blocks the adapter's root precisely so the probe cannot stat it, and a script living there
        // would be unreadable too — the probe would report nothing and the assertion would be about the
        // harness rather than about the guard.
        $storageRoot ??= $this->root;
        $script = $this->root . '/probe.php';
        \file_put_contents($script, \strtr(<<<'PROBE'
            <?php
            require '/app/api/vendor/autoload.php';

            use Erpify\Shared\Images\Domain\ImageId;
            use Erpify\Shared\Images\Domain\Storage\ImageStorageException;
            use Erpify\Shared\Images\Infrastructure\FlysystemImageStorage;
            use League\Flysystem\Filesystem;
            use League\Flysystem\Local\LocalFilesystemAdapter;

            $root = '__ROOT__';
            $storage = new FlysystemImageStorage(
                new Filesystem(new LocalFilesystemAdapter($root, lazyRootCreation: true)),
                $root,
                new class extends \Psr\Log\AbstractLogger {
                    public function log($level, string|\Stringable $message, array $context = []): void {}
                },
            );

            try {
                $storage->delete(ImageId::fromString('__ID__'));
                $report = ['outcome' => 'SILENT_SUCCESS', 'message' => '', 'previous' => 0];
            } catch (ImageStorageException $failure) {
                $depth = 0;

                for ($link = $failure->getPrevious(); null !== $link; $link = $link->getPrevious()) {
                    ++$depth;
                }

                $report = ['outcome' => 'FAILED', 'message' => $failure->getMessage(), 'previous' => $depth];
            }

            echo json_encode($report);
            PROBE, ['__ROOT__' => $storageRoot, '__ID__' => $imageId->toString()]));
        \chmod($script, 0o644);

        $output = [];
        \exec(\sprintf('su nonroot -c %s 2>&1', \escapeshellarg('php ' . $script)), $output);

        $decoded = \json_decode(\trim(\implode("\n", $output)), true);
        $this->assertIsArray($decoded, \sprintf('the probe did not report a verdict: %s', \implode("\n", $output)));
        $this->assertIsString($decoded['outcome'] ?? null);
        $this->assertIsString($decoded['message'] ?? null);
        $this->assertIsInt($decoded['previous'] ?? null);

        return ['outcome' => $decoded['outcome'], 'message' => $decoded['message'], 'previous' => $decoded['previous']];
    }
}
