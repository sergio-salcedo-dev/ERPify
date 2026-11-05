<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Infrastructure\Storage;

use Erpify\Documents\Document\Domain\Storage\DocumentStorageFailed;
use Erpify\Documents\Document\Infrastructure\Storage\FilesystemDocumentStorage;
use FilesystemIterator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Uid\Uuid;

/**
 * What the adapter does when the world misbehaves: a source that fails halfway, a root that is not one, an area
 * the process can read but not write, and a directory it cannot traverse.
 *
 * The last two are only observable to an UNPRIVILEGED user — root reads and traverses everything — so those
 * cases drive the adapter from a separate process running as `nonroot`, the way the images adapter's
 * undecidable-existence test does, and read its verdict back as JSON. A probe that reports nothing fails the
 * decode instead of reading as a pass.
 *
 * @internal
 */
#[CoversClass(FilesystemDocumentStorage::class)]
final class FilesystemDocumentStorageResilienceTest extends TestCase
{
    private const int ENTRIES = FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::SKIP_DOTS;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = \sys_get_temp_dir() . '/erpify-document-resilience-' . \bin2hex(\random_bytes(6));
        \mkdir($this->root . '/storage/staging', 0o755, true);
        \mkdir($this->root . '/storage/final', 0o755, true);
        \chmod($this->root, 0o755);
    }

    protected function tearDown(): void
    {
        ScriptedStream::release();
        $this->removeTree($this->root);

        parent::tearDown();
    }

    #[Test]
    #[DataProvider('provideARootThatIsNotAnAbsolutePathIsRefusedCases')]
    public function aRootThatIsNotAnAbsolutePathIsRefused(string $root): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FilesystemDocumentStorage($root);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideARootThatIsNotAnAbsolutePathIsRefusedCases(): iterable
    {
        yield 'an empty root' => [''];
        yield 'a relative root' => ['var/documents-storage'];
        yield 'a dot-relative root' => ['./documents'];
    }

    #[Test]
    #[DataProvider('provideASourceThatFailsMidReadLeavesNothingStagedAndTheKeyIsReusableCases')]
    public function aSourceThatFailsMidReadLeavesNothingStagedAndTheKeyIsReusable(string $then): void
    {
        $storage = new FilesystemDocumentStorage($this->root . '/storage');
        $scope = Uuid::v4()->toRfc4122();

        try {
            $storage->stage($scope, ScriptedStream::open(\str_repeat('x', 200_000), $then));
            $this->fail('Expected the failing source to abort the staging.');
        } catch (DocumentStorageFailed|RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame([], $this->filesUnder($this->root . '/storage/staging'), 'no partial object is left behind');

        $good = \fopen('php://memory', 'r+');
        $this->assertNotFalse($good);
        \fwrite($good, 'whole');
        \rewind($good);
        $storage->stage($scope, $good);

        $this->assertCount(1, $this->filesUnder($this->root . '/storage/staging'), 'the same scope stages afterwards');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideASourceThatFailsMidReadLeavesNothingStagedAndTheKeyIsReusableCases(): iterable
    {
        yield 'a source that throws after some bytes' => [ScriptedStream::THEN_THROW];
        yield 'a source that reports a read error after some bytes' => [ScriptedStream::THEN_FAIL];
    }

    #[Test]
    public function aWriteThatTheFilesystemRefusesLeavesNothingStagedAndTheKeyIsReusable(): void
    {
        // A file-size limit makes the kernel refuse the write, which is how a full disk looks from here, and
        // needs no privilege to set. The probe's shell ignores SIGXFSZ, which the child inherits, so the refusal
        // arrives as an error and not as a kill.
        $scope = Uuid::v4()->toRfc4122();
        $outcome = $this->probe(
            <<<'PHP'
                $source = fopen('php://memory', 'r+b');
                fwrite($source, str_repeat('x', 2_000_000));
                rewind($source);
                try {
                    $storage->stage('__SCOPE__', $source);
                    $report = ['outcome' => 'SILENT_SUCCESS'];
                } catch (\Erpify\Documents\Document\Domain\Storage\DocumentStorageFailed $failure) {
                    $report = ['outcome' => 'FAILED'];
                }
                PHP,
            ['__SCOPE__' => $scope],
            "ulimit -f 1; trap '' XFSZ; ",
        );

        $this->assertSame('FAILED', $outcome['outcome'] ?? null, 'the refused write must surface as a typed failure');
        $this->assertSame([], $this->filesUnder($this->root . '/storage/staging'), 'and no partial object remains');
    }

    #[Test]
    public function anAreaThatCanBeReadButNotWrittenStillServesTheObject(): void
    {
        $storage = new FilesystemDocumentStorage($this->root . '/storage');
        $scope = Uuid::v4()->toRfc4122();
        $source = \fopen('php://memory', 'r+');
        $this->assertNotFalse($source);
        \fwrite($source, 'sealed bytes');
        \rewind($source);
        $storage->stage($scope, $source);
        $storage->promote($scope);
        $this->openTree($this->root);
        \chmod($this->root . '/storage/final', 0o555);

        try {
            $read = $this->probe(
                <<<'PHP'
                    $stream = $storage->open('__SCOPE__');
                    $report = ['outcome' => 'OPENED', 'bytes' => stream_get_contents($stream)];
                    PHP,
                ['__SCOPE__' => $scope],
            );
            $write = $this->probe(
                <<<'PHP'
                    try {
                        $storage->delete(\Erpify\Documents\Document\Domain\Storage\StorageArea::Final, '__SCOPE__');
                        $report = ['outcome' => 'SILENT_SUCCESS'];
                    } catch (\Erpify\Documents\Document\Domain\Storage\DocumentStorageFailed $failure) {
                        $report = ['outcome' => 'FAILED'];
                    }
                    PHP,
                ['__SCOPE__' => $scope],
            );
        } finally {
            \chmod($this->root . '/storage/final', 0o755);
        }

        $this->assertSame(['outcome' => 'OPENED', 'bytes' => 'sealed bytes'], $read, 'a read-only mount can serve');
        $this->assertSame('FAILED', $write['outcome'] ?? null, 'but a write is refused where it cannot happen');
    }

    #[Test]
    public function anObjectUnderADirectoryThatCannotBeTraversedIsNotReportedAsDeleted(): void
    {
        $storage = new FilesystemDocumentStorage($this->root . '/storage');
        $scope = Uuid::v4()->toRfc4122();
        $source = \fopen('php://memory', 'r+');
        $this->assertNotFalse($source);
        \fwrite($source, 'still here');
        \rewind($source);
        $storage->stage($scope, $source);
        $this->openTree($this->root);
        $objects = $this->filesUnder($this->root . '/storage/staging');
        $this->assertCount(1, $objects);
        $shard = \dirname($objects[0]);
        // The area itself is writable to the probe, so the refusal below can only come from the blocked shard.
        \chmod($this->root . '/storage/staging', 0o777);
        \chmod($shard, 0o000);

        try {
            $outcome = $this->probe(
                <<<'PHP'
                    try {
                        $storage->delete(\Erpify\Documents\Document\Domain\Storage\StorageArea::Staging, '__SCOPE__');
                        $report = ['outcome' => 'SILENT_SUCCESS'];
                    } catch (\Erpify\Documents\Document\Domain\Storage\DocumentStorageFailed $failure) {
                        $report = ['outcome' => 'FAILED', 'message' => $failure->getMessage()];
                    }
                    PHP,
                ['__SCOPE__' => $scope],
            );
        } finally {
            \chmod($shard, 0o755);
        }

        $this->assertSame('FAILED', $outcome['outcome'] ?? null, 'an undecidable existence must fail');
        $this->assertSame('the existence of the object could not be established', $outcome['message'] ?? null);
        $this->assertFileExists($objects[0], 'and the bytes must still be there');
    }

    /**
     * Runs `$body` against the adapter in a separate process as `nonroot` and returns the `$report` it built.
     *
     * @param array<string, string> $replacements placeholders substituted into the body
     *
     * @return array<string, mixed>
     */
    private function probe(string $body, array $replacements, string $shellPrefix = ''): array
    {
        $script = $this->root . '/probe.php';
        \file_put_contents($script, \strtr(<<<'PROBE'
            <?php
            require '/app/api/vendor/autoload.php';

            $storage = new \Erpify\Documents\Document\Infrastructure\Storage\FilesystemDocumentStorage('__ROOT__');
            __BODY__

            echo json_encode($report);
            PROBE, ['__ROOT__' => $this->root . '/storage', '__BODY__' => \strtr($body, $replacements)]));
        \chmod($script, 0o644);

        $output = [];
        \exec(\sprintf('su nonroot -c %s 2>&1', \escapeshellarg($shellPrefix . 'php ' . $script)), $output);

        $decoded = \json_decode(\trim(\implode("\n", $output)), true);
        $this->assertIsArray($decoded, \sprintf('the probe did not report a verdict: %s', \implode("\n", $output)));

        return \array_combine(\array_map(strval(...), \array_keys($decoded)), $decoded);
    }

    /**
     * Opens every directory and file of the tree to the unprivileged probe: the adapter creates shards at 0750.
     */
    private function openTree(string $path): void
    {
        \chmod($path, 0o755);

        foreach (new FilesystemIterator($path, self::ENTRIES) as $entry) {
            $this->assertInstanceOf(SplFileInfo::class, $entry);

            if ($entry->isDir()) {
                $this->openTree($entry->getPathname());

                continue;
            }

            \chmod($entry->getPathname(), 0o644);
        }
    }

    /**
     * @return list<string>
     */
    private function filesUnder(string $directory): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function removeTree(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }

        \chmod($path, 0o755);

        foreach (new FilesystemIterator($path, self::ENTRIES) as $entry) {
            $this->assertInstanceOf(SplFileInfo::class, $entry);

            if ($entry->isDir() && !$entry->isLink()) {
                $this->removeTree($entry->getPathname());

                continue;
            }

            \unlink($entry->getPathname());
        }

        \rmdir($path);
    }
}
