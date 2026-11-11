<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Infrastructure\Storage;

use Erpify\Documents\Document\Domain\Storage\DocumentStorageFailed;
use Erpify\Documents\Document\Domain\Storage\ObjectAlreadyExists;
use Erpify\Documents\Document\Domain\Storage\ObjectNotFound;
use Erpify\Documents\Document\Domain\Storage\StorageArea;
use Erpify\Documents\Document\Infrastructure\Storage\FilesystemDocumentStorage;
use FilesystemIterator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Uid\Uuid;

/**
 * The port's contract against a real temporary root, including the claim the port makes only for one
 * filesystem: that staging and final share a device and a promotion is a single move there. The adapter's own
 * test is what probes it, because `st_dev` equality is not proof and nothing else in the suite reads the disk.
 *
 * Paths are SEARCHED for rather than recomputed from the adapter's rule, so no assertion about the key agrees
 * with the implementation by construction.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") one case per clause of the port's contract and per refusal
 */
#[CoversClass(FilesystemDocumentStorage::class)]
#[CoversClass(ObjectAlreadyExists::class)]
#[CoversClass(ObjectNotFound::class)]
#[CoversClass(DocumentStorageFailed::class)]
final class FilesystemDocumentStorageTest extends TestCase
{
    private string $root;

    private FilesystemDocumentStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = \sys_get_temp_dir() . '/erpify-document-storage-' . \bin2hex(\random_bytes(6));
        \mkdir($this->root . '/staging', 0o750, true);
        \mkdir($this->root . '/final', 0o750, true);
        $this->storage = new FilesystemDocumentStorage($this->root);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);

        parent::tearDown();
    }

    #[Test]
    public function enoentIsTheValueTheAbsenceCheckAssumes(): void
    {
        // The adapter decides "nothing is stored" by errno 2. POSIX fixes it there; this asserts it against
        // the running kernel instead of trusting the literal.
        \posix_access($this->root . '/staging/definitely/absent', POSIX_F_OK);

        $this->assertSame(2, \posix_get_last_error());
    }

    #[Test]
    public function stagedBytesPromoteIntoTheFinalAreaAndReadBackIdentical(): void
    {
        $scope = $this->scope();
        $bytes = \random_bytes(200_000);

        $this->storage->stage($scope, $this->streamOf($bytes));
        $this->assertNotNull($this->find(StorageArea::Staging, $scope));
        $this->assertNull($this->find(StorageArea::Final, $scope));

        $this->storage->promote($scope);

        $this->assertNull($this->find(StorageArea::Staging, $scope), 'the staged name is gone once promoted');
        $this->assertNotNull($this->find(StorageArea::Final, $scope));

        $stream = $this->storage->open($scope);
        $this->assertSame($bytes, \stream_get_contents($stream));
        \fclose($stream);
    }

    #[Test]
    public function stagingAndFinalShareOneDeviceAndThePromotionIsAMoveWithinIt(): void
    {
        $scope = $this->scope();
        $this->storage->stage($scope, $this->streamOf('evidence'));
        $stagedInode = \fileinode((string) $this->find(StorageArea::Staging, $scope));
        $this->assertSame(
            \stat($this->root . '/staging')['dev'] ?? null,
            \stat($this->root . '/final')['dev'] ?? null,
            'the claim of atomicity is made only for one filesystem',
        );

        $this->storage->promote($scope);

        $this->assertSame(
            $stagedInode,
            \fileinode((string) $this->find(StorageArea::Final, $scope)),
            'the object was linked, not copied: no second copy of the bytes ever exists',
        );
    }

    #[Test]
    public function stagingRefusesAnExistingKeyAndLeavesTheObjectUntouched(): void
    {
        $scope = $this->scope();
        $this->storage->stage($scope, $this->streamOf('first'));

        try {
            $this->storage->stage($scope, $this->streamOf('second'));
            $this->fail('Expected ObjectAlreadyExists.');
        } catch (ObjectAlreadyExists $objectAlreadyExists) {
            $this->assertSame(StorageArea::Staging, $objectAlreadyExists->area());
        }

        $this->assertSame('first', \file_get_contents((string) $this->find(StorageArea::Staging, $scope)));
    }

    #[Test]
    public function promotingOntoAnExistingDestinationFailsWithATypedErrorAndOverwritesNothing(): void
    {
        $scope = $this->scope();
        $this->storage->stage($scope, $this->streamOf('original'));
        $this->storage->promote($scope);
        $this->storage->stage($scope, $this->streamOf('impostor'));

        try {
            $this->storage->promote($scope);
            $this->fail('Expected ObjectAlreadyExists.');
        } catch (ObjectAlreadyExists $objectAlreadyExists) {
            $this->assertSame(StorageArea::Final, $objectAlreadyExists->area());
        }

        $this->assertSame('original', \file_get_contents((string) $this->find(StorageArea::Final, $scope)));
        $this->assertSame(
            'impostor',
            \file_get_contents((string) $this->find(StorageArea::Staging, $scope)),
            'a failed promotion leaves the staged object where it was',
        );
    }

    #[Test]
    public function promotingWithNothingStagedIsATypedAbsenceAndANewMeaningNotIdempotent(): void
    {
        $scope = $this->scope();

        $this->expectException(ObjectNotFound::class);

        $this->storage->promote($scope);
    }

    #[Test]
    public function aSecondPromotionFindsNothingStaged(): void
    {
        $scope = $this->scope();
        $this->storage->stage($scope, $this->streamOf('x'));
        $this->storage->promote($scope);

        $this->expectException(ObjectNotFound::class);

        $this->storage->promote($scope);
    }

    #[Test]
    public function openingAnAbsentObjectIsATypedAbsenceNeverAnEmptyStream(): void
    {
        $this->expectException(ObjectNotFound::class);

        $this->storage->open($this->scope());
    }

    #[Test]
    public function aStagedObjectIsNotReadableAsFinal(): void
    {
        $scope = $this->scope();
        $this->storage->stage($scope, $this->streamOf('not yet'));

        $this->expectException(ObjectNotFound::class);

        $this->storage->open($scope);
    }

    #[Test]
    public function deletingIsIdempotentAndNamesTheAreaItTouches(): void
    {
        $scope = $this->scope();
        $this->storage->stage($scope, $this->streamOf('a'));
        $this->storage->promote($scope);
        $this->storage->stage($scope, $this->streamOf('b'));

        $this->storage->delete(StorageArea::Staging, $scope);
        $this->storage->delete(StorageArea::Staging, $scope);

        $this->assertNull($this->find(StorageArea::Staging, $scope));
        $this->assertNotNull($this->find(StorageArea::Final, $scope), 'deleting the staged one spares the final one');

        $this->storage->delete(StorageArea::Final, $scope);
        $this->storage->delete(StorageArea::Final, $scope);

        $this->assertNull($this->find(StorageArea::Final, $scope));
    }

    #[Test]
    public function aRootThatIsNotProvisionedIsAFailureNotAnInventedDirectory(): void
    {
        $storage = new FilesystemDocumentStorage($this->root . '/not-mounted');

        try {
            $storage->stage($this->scope(), $this->streamOf('x'));
            $this->fail('Expected DocumentStorageFailed.');
        } catch (DocumentStorageFailed) {
            $this->assertDirectoryDoesNotExist($this->root . '/not-mounted');
        }

        try {
            $storage->delete(StorageArea::Final, $this->scope());
            $this->fail('Expected DocumentStorageFailed: an unmounted root must not read as a confirmed deletion.');
        } catch (DocumentStorageFailed) {
            $this->addToAssertionCount(1);
        }
    }

    #[Test]
    public function aScopeIdInAnyOtherSpellingIsRefusedSoOneScopeHasOneKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->storage->stage(\strtoupper($this->scope()), $this->streamOf('x'));
    }

    #[Test]
    #[DataProvider('provideAScopeIdThatIsNotAUuidCannotBuildAPathCases')]
    public function aScopeIdThatIsNotAUuidCannotBuildAPath(string $scope): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->storage->delete(StorageArea::Final, $scope);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAScopeIdThatIsNotAUuidCannotBuildAPathCases(): iterable
    {
        yield 'a traversal' => ['../../etc/passwd'];
        yield 'an empty id' => [''];
        yield 'a path separator' => ['a/b'];
        yield 'a valid UUID with a trailing newline' => ["00000000-0000-0000-0000-000000000000\n"];
    }

    #[Test]
    public function aFailureCarriesNeitherTheKeyNorThePath(): void
    {
        $scope = $this->scope();
        $this->storage->stage($scope, $this->streamOf('x'));

        try {
            $this->storage->stage($scope, $this->streamOf('y'));
            $this->fail('Expected ObjectAlreadyExists.');
        } catch (ObjectAlreadyExists $objectAlreadyExists) {
            $this->assertStringNotContainsString($scope, $objectAlreadyExists->getMessage());
            $this->assertStringNotContainsString($this->root, $objectAlreadyExists->getMessage());
        }
    }

    #[Test]
    public function stageWithHandsTheWriterTheOpenedObjectAndKeepsWhatItWrote(): void
    {
        $scope = $this->scope();

        $this->storage->stageWith($scope, static function ($sink): void {
            \fwrite($sink, 'sealed bytes');
        });

        $path = $this->find(StorageArea::Staging, $scope);
        $this->assertNotNull($path);
        $this->assertSame('sealed bytes', \file_get_contents($path));
    }

    #[Test]
    public function stageWithRefusesAnObjectThatAlreadyExists(): void
    {
        $scope = $this->scope();
        $this->storage->stage($scope, $this->streamOf('first'));

        $this->expectException(ObjectAlreadyExists::class);

        $this->storage->stageWith($scope, static function ($sink): void {
            \fwrite($sink, 'second');
        });
    }

    #[Test]
    public function stageWithRemovesTheObjectWhenTheWriterFails(): void
    {
        $scope = $this->scope();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('the writer failed');

        try {
            $this->storage->stageWith($scope, static function ($sink): never {
                \fwrite($sink, 'half');

                throw new InvalidArgumentException('the writer failed');
            });
        } finally {
            $this->assertNull($this->find(StorageArea::Staging, $scope), 'no half-written object is left behind');
            $this->assertFalse($this->storage->has(StorageArea::Staging, $scope));
        }
    }

    private function scope(): string
    {
        return Uuid::v4()->toRfc4122();
    }

    /**
     * @return resource
     */
    private function streamOf(string $bytes)
    {
        $stream = \fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);
        \fwrite($stream, $bytes);
        \rewind($stream);

        return $stream;
    }

    private function find(StorageArea $area, string $scope): ?string
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root . '/' . $area->value, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getFilename() === $scope) {
                return $file->getPathname();
            }
        }

        return null;
    }

    private static function removeTree(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }

        \chmod($path, 0o750);

        $flags = FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::SKIP_DOTS;

        foreach (new FilesystemIterator($path, $flags) as $entry) {
            \PHPUnit\Framework\Assert::assertInstanceOf(SplFileInfo::class, $entry);

            if ($entry->isDir() && !$entry->isLink()) {
                self::removeTree($entry->getPathname());

                continue;
            }

            \unlink($entry->getPathname());
        }

        \rmdir($path);
    }
}
