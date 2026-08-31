<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Infrastructure;

use Erpify\Shared\Clock\Domain\NativeClock;
use Erpify\Shared\Images\Application\FailureSignalWindow;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Storage\ImageBytesNotFound;
use Erpify\Shared\Images\Domain\Storage\ImageStorage;
use Erpify\Shared\Images\Domain\Storage\ImageStorageFailed;
use Erpify\Shared\Images\Domain\Storage\StorageFailureCategory;
use Erpify\Shared\Images\Domain\Storage\StorageOperation;
use Erpify\Shared\Images\Infrastructure\FlysystemImageStorage;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * The adapter against a real temporary filesystem rather than a double. A double cannot fail a write the
 * way a filesystem does, which is the property the integrity check exists to catch.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the port's whole verdict vocabulary is under test here
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") one method per property of the contract; merging any
 *                                                two would hide which one went red
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

    /**
     * The other half of the promise above, and the half a healthy temporary directory can never exercise:
     * `store()` returning success has to MEAN the bytes are there, not merely that the substrate reported
     * a completed write. Driven through a filesystem that accepts the write and keeps half of it — the
     * shape of a truncated write — so the guarantee is a property of the adapter rather than of the
     * machine the suite happens to run on.
     */
    public function testAWriteTheFilesystemAcceptedButCorruptedIsRefusedRatherThanReportedAsStored(): void
    {
        $storage = new FlysystemImageStorage(
            new PartiallyWritingFilesystem($this->root),
            $this->root,
            new RecordingLogger(),
            new FailureSignalWindow(new NativeClock()),
        );
        $imageId = ImageId::generate();

        try {
            $storage->store($imageId, \random_bytes(2048));
            $this->fail('a write the substrate corrupted must not be reported as stored');
        } catch (ImageStorageFailed $imageStorageFailed) {
            $this->assertSame(StorageOperation::VerifyIntegrity, $imageStorageFailed->operation());
        }

        // And it is not left behind. Keeping it would poison the identifier: `store()` refuses a key that
        // already carries an object, so the retry would be rejected as a reuse, and `read()` runs no
        // digest check, so the truncated bytes would be served as valid.
        $healthy = $this->storage();

        try {
            $healthy->read($imageId);
            $this->fail('the refused object must not survive its own refusal');
        } catch (ImageBytesNotFound) {
            $this->addToAssertionCount(1);
        }

        $healthy->store($imageId, 'the identifier is still usable');
        $this->assertSame('the identifier is still usable', $healthy->read($imageId));
    }

    /**
     * The adapter decides "demonstrably absent" from one errno, and a raw errno in source is a literal
     * nobody can check by reading. This asks the kernel the suite is running on for the value it actually
     * reports for a path that is not there, and compares it with the constant the adapter trusts — so a
     * platform that disagreed would red here instead of silently reclassifying every absence as a failure
     * (or, far worse, every undecidable existence as an absence).
     */
    public function testTheAbsenceErrnoTheAdapterTrustsIsWhatThisKernelReports(): void
    {
        $trusted = (new ReflectionClass(FlysystemImageStorage::class))->getConstant('ABSENT_PATH');

        \posix_access($this->root . '/nothing-is-here', POSIX_F_OK);

        $this->assertSame(\posix_get_last_error(), $trusted, 'ENOENT is what a path that is not there reports');
    }

    public function testReadingAnAbsentObjectIsAConfirmedAbsenceRatherThanAFailure(): void
    {
        $storage = $this->storage();

        try {
            $storage->read(ImageId::generate());
            $this->fail('an absent object must be reported');
        } catch (ImageBytesNotFound $imageBytesNotFound) {
            $this->assertSame(StorageFailureCategory::ConfirmedAbsence, $imageBytesNotFound->storageFailure());
            $this->assertSame(StorageOperation::Read, $imageBytesNotFound->operation());
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
            $this->fail('a reused identifier must be refused rather than overwriting');
        } catch (ImageStorageFailed $imageStorageFailed) {
            $this->assertSame(StorageFailureCategory::Permanent, $imageStorageFailed->storageFailure());
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
     * A shard that does not spread is not a shard. This is asserted over minted identifiers rather than
     * reasoned about, because the property depends on where the entropy sits in the identifier's layout:
     * `ImageId` is a UUID v7, so its leading hex is a millisecond clock and sharding there yields one
     * directory per ~50 days. Measured on the head-based derivation this replaced, 4000 identifiers gave
     * a single bucket; the assertion below reds for it and passes for the tail.
     */
    public function testTheShardsSpreadAcrossDirectoriesRatherThanTrackingTheClock(): void
    {
        $buckets = [];

        for ($minted = 0; $minted < 512; ++$minted) {
            $key = $this->keyOf(ImageId::generate());
            $buckets[\dirname($key)] = true;
        }

        $this->assertGreaterThan(
            256,
            \count($buckets),
            'the shards must be read off the random half of the identifier, not off its timestamp',
        );
    }

    /**
     * The derivation as the adapter performs it, obtained by storing and looking the object up — never by
     * recomputing it here, which would make every assertion about the key agree with the implementation
     * by construction.
     */
    private function keyOf(ImageId $imageId): string
    {
        $storage = $this->storage();
        $storage->store($imageId, 'bytes');

        return \substr($this->pathFor($imageId), \strlen($this->root) + 1);
    }

    /**
     * The port must not become a delivery surface. Type alone cannot police this — a `publicUrl(): string`
     * and a `read(): string` are indistinguishable by signature — so the check is on the names.
     */
    public function testThePortExposesNoUrlReturningMethod(): void
    {
        $methods = \array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(ImageStorage::class))->getMethods(),
        );

        $this->assertSame(['store', 'read', 'delete'], $methods, 'the surface is exactly these three');

        foreach (['publicUrl', 'temporaryUrl', 'url', 'path', 'storageKey'] as $forbidden) {
            $this->assertNotContains($forbidden, $methods);
        }
    }

    public function testEveryOperationOnTheContractSpeaksIdentitiesRatherThanPaths(): void
    {
        foreach ((new ReflectionClass(ImageStorage::class))->getMethods() as $reflectionMethod) {
            foreach ($reflectionMethod->getParameters() as $parameter) {
                $type = $parameter->getType();

                if (!$type instanceof ReflectionNamedType || 'string' !== $type->getName()) {
                    $this->assertSame(ImageId::class, $type instanceof ReflectionNamedType ? $type->getName() : null);

                    continue;
                }

                $this->assertSame('bytes', $parameter->getName(), 'the only string a caller supplies is the payload');
            }
        }
    }

    /**
     * A write that fails leaves an object behind, and `store()` owes its removal.
     *
     * The library's `write()` opens with `O_CREAT|O_TRUNC`, so the key is occupied before any byte lands
     * and a failure part-way through leaves a short object under it. Left there it poisons the identifier
     * for ever — a retry meets it and is refused as a reused id, and `read()` runs no digest check, so
     * those bytes would be served as valid — and under `ENOSPC` every attempt adds another one, consuming
     * the space that was already exhausted.
     */
    public function testAFailedWriteLeavesNoObjectBehind(): void
    {
        $imageId = ImageId::generate();
        $storage = new FlysystemImageStorage(
            new FailingAfterPartialWriteFilesystem($this->root),
            $this->root,
            new RecordingLogger(),
            new FailureSignalWindow(new NativeClock()),
        );

        try {
            $storage->store($imageId, 'canonical bytes the substrate will only half accept');
            $this->fail('a refused write must surface');
        } catch (ImageStorageFailed) {
            // The condition PHP reports is one no retry resolves, so the verdict is permanent.
        }

        $residue = [];

        foreach (self::walk($this->root) as $file) {
            $residue[] = $file->getFilename();
        }

        $this->assertSame([], $residue, 'the half-written object must not survive the failure');
    }

    /**
     * The key agrees with the row, whatever case the identifier arrived in.
     *
     * `Uuid::isValid()` matches case-insensitively and Postgres compares its `uuid` column by value, while
     * this adapter builds its key by slicing the characters — so the normalisation `ImageId` performs is
     * what keeps those two readers pointing at the same thing. Without it a deletion for an upper-cased id
     * finds no object, reports a CONFIRMED absence, and leaves the bytes behind with the row gone.
     */
    public function testAnIdentifierInAnotherCaseAddressesTheSameObject(): void
    {
        $storage = $this->storage();
        $imageId = ImageId::generate();
        $storage->store($imageId, 'bytes addressed once');

        $shouted = ImageId::fromString(\strtoupper($imageId->toString()));

        $this->assertSame('bytes addressed once', $storage->read($shouted));

        $storage->delete($shouted);

        try {
            $storage->read($imageId);
            $this->fail('the object must be gone for the canonical spelling too');
        } catch (ImageBytesNotFound) {
            // The pair closed: one value, one key, one object.
        }
    }
}
