<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Infrastructure;

use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Storage\ImageBytesNotFound;
use Erpify\Shared\Images\Domain\Storage\ImageStorage;
use Erpify\Shared\Images\Domain\Storage\ImageStorageFailed;
use Erpify\Shared\Images\Domain\Storage\StorageFailureCategory;
use Erpify\Shared\Images\Domain\Storage\StorageOperation;
use Erpify\Shared\Images\Infrastructure\FlysystemImageStorage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The adapter against a real temporary filesystem rather than a double. A double cannot fail a write the
 * way a filesystem does, which is the property the integrity check exists to catch.
 *
 * @internal
 */
#[CoversClass(FlysystemImageStorage::class)]
final class FlysystemImageStorageTest extends TestCase
{
    use TemporaryImageStorage;

    public function testStoredBytesComeBackByteForByteAndByDigest(): void
    {
        $storage = $this->storage();
        $imageId = ImageId::generate();
        $bytes = \random_bytes(2048);

        $storage->store($imageId, $bytes);

        $this->assertSame($bytes, $storage->read($imageId));
        $this->assertSame(
            \hash('sha256', $bytes),
            \hash('sha256', $this->readFromDiskDirectly($imageId)),
            'compared against the filesystem itself, not only through the adapter that wrote it: a symmetric '
            . 'defect shared by store() and read() would pass a round trip through one object',
        );
    }

    public function testReadingAnAbsentObjectIsAConfirmedAbsenceRatherThanAFailure(): void
    {
        $storage = $this->storage();

        try {
            $storage->read(ImageId::generate());
            self::fail('an absent object must be reported');
        } catch (ImageBytesNotFound $absence) {
            $this->assertSame(StorageFailureCategory::ConfirmedAbsence, $absence->storageFailure());
            $this->assertSame(StorageOperation::Read, $absence->operation());
        }
    }

    public function testDeletingIsIdempotentTowardsAbsence(): void
    {
        $storage = $this->storage();
        $imageId = ImageId::generate();
        $storage->store($imageId, 'bytes');

        // Resolved BEFORE the deletion: the helper fails the test when nothing is found, so it can prove
        // presence but never absence — the path has to be captured while the object is still there.
        $path = $this->pathFor($imageId);
        $this->assertFileExists($path, 'assert the object exists before asserting it is gone');

        $storage->delete($imageId);
        $this->assertFileDoesNotExist($path);

        $storage->delete($imageId);
        $storage->delete(ImageId::generate());

        $this->addToAssertionCount(1);
    }

    public function testStoringUnderAnIdentifierThatAlreadyCarriesAnObjectIsRefused(): void
    {
        $storage = $this->storage();
        $imageId = ImageId::generate();
        $storage->store($imageId, 'first');

        try {
            $storage->store($imageId, 'second');
            self::fail('a reused identifier must be refused rather than overwriting');
        } catch (ImageStorageFailed $failure) {
            $this->assertSame(StorageFailureCategory::Permanent, $failure->storageFailure());
        }

        $this->assertSame('first', $storage->read($imageId), 'the original bytes survive the refused write');
    }

    public function testIdenticalBytesUnderTwoIdentitiesAreTwoIndependentObjects(): void
    {
        $storage = $this->storage();
        $first = ImageId::generate();
        $second = ImageId::generate();
        $bytes = 'identical canonical bytes';

        $storage->store($first, $bytes);
        $storage->store($second, $bytes);

        $firstPath = $this->pathFor($first);
        $secondPath = $this->pathFor($second);

        $this->assertNotSame($firstPath, $secondPath);
        $this->assertFileExists($firstPath);
        $this->assertFileExists($secondPath);

        $storage->delete($first);

        $this->assertFileDoesNotExist($firstPath);
        $this->assertFileExists($secondPath);
        $this->assertSame($bytes, $storage->read($second), 'deleting one must not touch the other');
    }

    public function testTheKeyIsDerivedFromTheIdentifierAloneAndStaysInsideTheRoot(): void
    {
        $storage = $this->storage();
        $imageId = ImageId::generate();
        $storage->store($imageId, 'bytes');

        $relative = \substr($this->pathFor($imageId), \strlen($this->root) + 1);

        $this->assertStringNotContainsString('..', $relative);
        $this->assertStringStartsNotWith('/', $relative);
        $this->assertSame($imageId->toString(), \basename($relative), 'the leaf IS the identity');
        $this->assertSame($this->pathFor($imageId), $this->pathFor($imageId), 'the derivation is deterministic');
    }

    /**
     * The port must not become a delivery surface. Type alone cannot police this — a `publicUrl(): string`
     * and a `read(): string` are indistinguishable by signature — so the check is on the names.
     */
    public function testThePortExposesNoUrlReturningMethod(): void
    {
        $methods = \array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(ImageStorage::class))->getMethods(),
        );

        $this->assertSame(['store', 'read', 'delete'], $methods, 'the surface is exactly these three');

        foreach (['publicUrl', 'temporaryUrl', 'url', 'path', 'storageKey'] as $forbidden) {
            $this->assertNotContains($forbidden, $methods);
        }
    }

    public function testEveryOperationOnTheContractSpeaksIdentitiesRatherThanPaths(): void
    {
        foreach ((new ReflectionClass(ImageStorage::class))->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();

                if (!$type instanceof ReflectionNamedType || 'string' !== $type->getName()) {
                    $this->assertSame(ImageId::class, $type instanceof ReflectionNamedType ? $type->getName() : null);

                    continue;
                }

                $this->assertSame('bytes', $parameter->getName(), 'the only string a caller supplies is the payload');
            }
        }
    }

    private function storage(): FlysystemImageStorage
    {
        return new FlysystemImageStorage(
            new Filesystem(new LocalFilesystemAdapter($this->root, lazyRootCreation: true)),
            $this->root,
            new RecordingLogger(),
        );
    }
}
