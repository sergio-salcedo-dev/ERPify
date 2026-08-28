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
 * It is the branch most worth pinning and the easiest to lose: the storage library decides absence with
 * `file_exists()`, a `stat()` wrapper that answers the same `false` for "not there" and for "I was not
 * allowed to look", and it fails toward the first. On the delete path that difference is a confirmed
 * erasure of bytes that are still present.
 *
 * @internal
 */
#[CoversClass(FlysystemImageStorage::class)]
final class FlysystemImageStorageUndecidableExistenceTest extends TestCase
{
    use TemporaryImageStorage;

    /**
     * The unmounted-volume case, and the reason the storage is configured not to create its own root: with
     * the root absent the library would fabricate an empty directory and then answer success for every
     * deletion, reporting erasures of bytes that are somewhere else entirely.
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
     * Runs the deletion as a genuinely unprivileged user, because the assertion is about a permission the
     * root user does not have to obey: measured in this container, with the containing directory at mode
     * 0000, `file_exists()` on a file that IS there answers `false` for uid 1000 and `true` for uid 0. A
     * test running as root would exercise nothing and pass green.
     */
    public function testExistenceThatCannotBeEstablishedFailsRatherThanReportingAnErasure(): void
    {
        $imageId = ImageId::generate();
        $storage = new FlysystemImageStorage(
            new Filesystem(new LocalFilesystemAdapter($this->root, lazyRootCreation: true)),
            $this->root,
            new RecordingLogger(),
        );
        $storage->store($imageId, 'bytes that must survive');

        $objectPath = $this->pathFor($imageId);
        $containingDirectory = \dirname($objectPath);

        \chmod($this->root, 0o755);
        \chmod(\dirname($containingDirectory), 0o755);
        \chmod($containingDirectory, 0o000);

        $outcome = $this->deleteAsUnprivilegedUser($imageId);

        \chmod($containingDirectory, 0o755);

        $this->assertSame('FAILED', $outcome, 'an undecidable existence must fail, never report success');
        $this->assertFileExists($objectPath, 'and the bytes must still be there');
    }

    /**
     * Drives the adapter from a separate process running as `nonroot`, and reports which branch it took.
     * Any unexpected output is returned verbatim so a broken probe is visible instead of being read as a
     * passing assertion.
     */
    private function deleteAsUnprivilegedUser(ImageId $imageId): string
    {
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
                echo 'SILENT_SUCCESS';
            } catch (ImageStorageException) {
                echo 'FAILED';
            }
            PROBE, ['__ROOT__' => $this->root, '__ID__' => $imageId->toString()]));
        \chmod($script, 0o644);

        $output = [];
        \exec(\sprintf('su nonroot -c %s 2>&1', \escapeshellarg('php ' . $script)), $output);

        return \trim(\implode("\n", $output));
    }
}
