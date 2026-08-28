<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Infrastructure;

use Erpify\Shared\Images\Domain\ImageId;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A real temporary root for the storage adapter, plus the two things a test needs that must NOT come
 * from the adapter itself.
 *
 * `pathFor()` SEARCHES the tree for a file named after the identifier instead of recomputing the key the
 * way the adapter does. Recomputing it would make every assertion about the key circular: the test would
 * agree with the implementation by construction and could not observe a wrong derivation.
 *
 * @internal
 */
trait TemporaryImageStorage
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = \sys_get_temp_dir() . '/erpify-image-storage-' . \bin2hex(\random_bytes(6));
        \mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);

        parent::tearDown();
    }

    /**
     * The absolute path of the stored object, found by walking the root. Fails the test when the object is
     * not there, so a missing file never reads as a passing path comparison.
     */
    private function pathFor(ImageId $id): string
    {
        foreach (self::walk($this->root) as $file) {
            if ($file->getFilename() === $id->toString()) {
                return $file->getPathname();
            }
        }

        self::fail('no stored object was found under the root for the given identity');
    }

    private function readFromDiskDirectly(ImageId $id): string
    {
        $contents = \file_get_contents($this->pathFor($id));
        self::assertIsString($contents);

        return $contents;
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private static function walk(string $root): iterable
    {
        if (!\is_dir($root)) {
            return [];
        }

        /** @var iterable<SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if ($entry->isFile()) {
                yield $entry;
            }
        }
    }

    /**
     * @SuppressWarnings("PHPMD.ErrorControlOperator") teardown must not fail the run over a mode it cannot
     *                                                  restore; the recursive delete below is what actually
     *                                                  has to succeed
     */
    private static function removeTree(string $root): void
    {
        if (!\is_dir($root)) {
            return;
        }

        // A directory a case made untraversable would otherwise strand the whole tree.
        foreach (self::walk($root) as $file) {
            @\chmod($file->getPath(), 0o755);
        }

        @\chmod($root, 0o755);
        \exec('rm -rf ' . \escapeshellarg($root));
    }
}
