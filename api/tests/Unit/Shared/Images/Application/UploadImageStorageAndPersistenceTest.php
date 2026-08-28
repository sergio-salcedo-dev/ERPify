<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application;

use Erpify\Shared\Images\Application\UploadImage;
use Erpify\Shared\Images\Domain\CanonicalImage;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Repository\ImageRepository;
use Erpify\Shared\Persistence\Application\TransactionManager;
use InvalidArgumentException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The steps this use case gained beyond assembling the aggregate: what reaches storage, in what order, and
 * what is deliberately left behind when something fails.
 *
 * @internal
 */
#[CoversClass(UploadImage::class)]
final class UploadImageStorageAndPersistenceTest extends TestCase
{
    public function testOnlyTheCanonicalBytesEverReachStorage(): void
    {
        $storage = new InMemoryImageStorage();
        $canonical = new CanonicalImage('canonical-bytes', 'image/webp', 10, 20);

        $image = $this->uploadImage($storage, processor: new StubImageProcessor($canonical))
            ->upload('ORIGINAL-UPLOADED-BYTES');

        $this->assertSame(['canonical-bytes'], \array_values($storage->objects));
        $this->assertSame('canonical-bytes', $storage->read($image->id()));
    }

    /**
     * The aggregate validates in its constructor, so building it after the write would let a degenerate
     * canonical representation strand an object through a VALIDATION path — a failure that has nothing to
     * do with infrastructure, and one this use case does not compensate for either.
     */
    public function testAValidationFailureLeavesNothingBehindInStorage(): void
    {
        $storage = new InMemoryImageStorage();
        // Zero-length canonical bytes: `CanonicalImage` derives `byteSize` from them and does not validate,
        // while the aggregate refuses a non-positive size.
        $degenerate = new CanonicalImage('', 'image/png', 10, 20);

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->uploadImage($storage, processor: new StubImageProcessor($degenerate))->upload('raw');
        } finally {
            $this->assertSame([], $storage->objects, 'nothing may be written before the aggregate is valid');
        }
    }

    /**
     * The accepted residue, asserted rather than assumed: a write that succeeded is not coupled to the row,
     * so a failure afterwards leaves the object with no row referencing it — and nothing here reverses or
     * collects it. That is a decision about scope, and this test is what makes it visible if it changes.
     */
    public function testAPersistenceFailureLeavesTheStoredObjectOrphanedAndUncompensated(): void
    {
        $storage = new InMemoryImageStorage();
        $canonical = new CanonicalImage('canonical-bytes', 'image/png', 4, 4);

        try {
            $this->uploadImage($storage, new StubImageProcessor($canonical), new FailingImageRepository())->upload('raw');
            self::fail('the persistence failure must surface to the caller');
        } catch (RuntimeException) {
            $this->assertCount(1, $storage->objects, 'the orphan is accepted, not silently cleaned up');
        }
    }

    /**
     * Two properties in one sequence: the bytes are written BEFORE the transaction opens — so no rollback
     * is ever expected to undo a filesystem write — and the caller receives the aggregate only after the
     * row has committed.
     */
    public function testTheObjectIsStoredBeforeTheTransactionAndReturnedOnlyAfterItCommits(): void
    {
        $storage = new InMemoryImageStorage();
        $transactions = new ImmediateTransactionManager();
        $canonical = new CanonicalImage('canonical-bytes', 'image/png', 4, 4);
        $repository = new InMemoryImageRepository();

        $probe = new class($storage, $transactions) implements TransactionManager {
            public bool $objectWasStoredBeforeTheTransaction = false;

            public function __construct(
                private readonly InMemoryImageStorage $storage,
                private readonly ImmediateTransactionManager $inner,
            ) {
            }

            #[Override]
            public function transactional(callable $operation): mixed
            {
                $this->objectWasStoredBeforeTheTransaction = [] !== $this->storage->objects;

                return $this->inner->transactional($operation);
            }
        };

        $image = (new UploadImage(new StubImageProcessor($canonical), $storage, $repository, $probe))->upload('raw');

        $this->assertTrue($probe->objectWasStoredBeforeTheTransaction);
        $this->assertSame(1, $transactions->committed, 'the row committed');
        $this->assertNotNull($repository->findById($image->id()), 'and the identity handed back resolves to it');
    }

    private function uploadImage(
        InMemoryImageStorage $storage,
        StubImageProcessor $processor,
        ?ImageRepository $repository = null,
    ): UploadImage {
        return new UploadImage(
            $processor,
            $storage,
            $repository ?? new InMemoryImageRepository(),
            new ImmediateTransactionManager(),
        );
    }
}

