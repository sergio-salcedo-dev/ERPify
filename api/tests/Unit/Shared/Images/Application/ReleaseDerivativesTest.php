<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application;

use Erpify\Shared\Images\Application\ReleaseDerivatives;
use Erpify\Shared\Images\Domain\DerivativeOrigin;
use Erpify\Shared\Images\Domain\Entity\Image;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Tests\Unit\Shared\Images\Application\Double\RollbackingTransactionManager;
use Erpify\Tests\Unit\Shared\Images\Application\Double\TransactionalEventBus;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ReleaseDerivatives::class)]
final class ReleaseDerivativesTest extends TestCase
{
    private const string ORIGIN_ID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e01';

    private const string OTHER_ID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e02';

    private RollbackingTransactionManager $transactions;

    private TransactionalEventBus $bus;

    private InMemoryImageRepository $rows;

    #[Override]
    protected function setUp(): void
    {
        $this->transactions = new RollbackingTransactionManager();
        $this->bus = new TransactionalEventBus($this->transactions);
        $this->rows = new InMemoryImageRepository();
    }

    #[Test]
    public function itQueuesOneReleasePerDerivativeOfTheOriginAndNothingElse(): void
    {
        $first = $this->derivativeOf(new DerivativeOrigin('Documents.Document', self::ORIGIN_ID));
        $second = $this->derivativeOf(new DerivativeOrigin('Documents.Document', self::ORIGIN_ID));
        $this->derivativeOf(new DerivativeOrigin('Documents.Document', self::OTHER_ID));
        $this->derivativeOf(new DerivativeOrigin('Other.Thing', self::ORIGIN_ID));
        $this->derivativeOf(null);

        $queued = $this->release()->of(new DerivativeOrigin('Documents.Document', self::ORIGIN_ID));

        $this->assertSame(2, $queued);
        $expected = [$first, $second];
        \sort($expected);
        $this->assertSame($expected, $this->bus->queuedIds());
    }

    #[Test]
    public function anOriginNoImageDerivesFromAnymoreQueuesNothingAndAnswersZero(): void
    {
        $this->assertSame(0, $this->release()->of(new DerivativeOrigin('Documents.Document', self::ORIGIN_ID)));
        $this->assertSame([], $this->bus->queuedIds());
    }

    #[Test]
    public function aSecondCallQueuesTheSameImagesAgainUntilTheyAreGone(): void
    {
        $id = $this->derivativeOf(new DerivativeOrigin('Documents.Document', self::ORIGIN_ID));
        $origin = new DerivativeOrigin('Documents.Document', self::ORIGIN_ID);

        $this->release()->of($origin);
        $this->release()->of($origin);

        $this->assertSame([$id, $id], $this->bus->queuedIds(), 'what to release is re-read, never remembered');
    }

    #[Test]
    public function oneCallQueuesAtMostOneBatch(): void
    {
        $origin = new DerivativeOrigin('Documents.Document', self::ORIGIN_ID);

        for ($i = 0; $i <= ReleaseDerivatives::BATCH; ++$i) {
            $this->derivativeOf($origin);
        }

        $this->assertSame(ReleaseDerivatives::BATCH, $this->release()->of($origin));
    }

    private function release(): ReleaseDerivatives
    {
        return new ReleaseDerivatives($this->rows, $this->transactions, $this->bus);
    }

    private function derivativeOf(?DerivativeOrigin $origin): string
    {
        $image = new Image(ImageId::generate(), \str_repeat('a', 64), 'image/png', 4, 4, 10, $origin);
        $this->rows->save($image);

        return $image->id()->toString();
    }
}
