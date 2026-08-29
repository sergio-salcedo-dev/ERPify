<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Domain;

use Erpify\Shared\Images\Domain\Exception\FailureCategory;
use Erpify\Shared\Images\Domain\Storage\ImageBytesNotFound;
use Erpify\Shared\Images\Domain\Storage\ImageStorageFailed;
use Erpify\Shared\Images\Domain\Storage\ImageStorageUnavailable;
use Erpify\Shared\Images\Domain\Storage\StorageFailureCategory;
use Erpify\Shared\Images\Domain\Storage\StorageOperation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The vocabulary the port promises, asserted as a vocabulary rather than through whichever adapter
 * happens to produce it.
 *
 * Its whole reason to exist is that a consumer chooses its recovery by these three classes: absence is a
 * 404 and never a retry, transient is a retry, permanent is neither. Collapsing any two of them is
 * invisible at every call site — the code still compiles, the adapter still raises, and the consumer
 * simply recovers wrongly — so the separation is pinned here instead of being inferred from usage.
 *
 * @internal
 */
#[CoversClass(ImageBytesNotFound::class)]
#[CoversClass(ImageStorageFailed::class)]
#[CoversClass(ImageStorageUnavailable::class)]
#[CoversClass(StorageFailureCategory::class)]
#[CoversClass(StorageOperation::class)]
final class StorageFailureVocabularyTest extends TestCase
{
    public function testTheThreeVerdictsAreDistinctClassesCarryingDistinctCategories(): void
    {
        $verdicts = [
            new ImageBytesNotFound(),
            new ImageStorageFailed(StorageOperation::Store, 'a reason'),
            new ImageStorageUnavailable(StorageOperation::Store),
        ];

        $producers = [];

        foreach ($verdicts as $verdict) {
            $producers[$verdict->storageFailure()->value] = $verdict::class;
        }

        $this->assertCount(\count($verdicts), $producers, 'two verdicts sharing a category are one verdict');

        // Driven off the enum rather than off a count, so a category added without a verdict that can
        // produce it fails here instead of being a number nobody updates.
        foreach (StorageFailureCategory::cases() as $category) {
            $this->assertArrayHasKey($category->value, $producers, 'every category needs a verdict producing it');
        }
    }

    /**
     * Absence is a property of the READ path only. On the delete path it is success, so a verdict that
     * could be raised there would be a confirmed erasure that never happened.
     */
    public function testAConfirmedAbsenceCanOnlyEverBeReported(): void
    {
        $absence = new ImageBytesNotFound();

        $this->assertSame(StorageFailureCategory::ConfirmedAbsence, $absence->storageFailure());
        $this->assertSame(StorageOperation::Read, $absence->operation());
    }

    /**
     * Every failure names the operation it belongs to, because the semantics of absence differ between
     * them and a verdict with no operation cannot say which contract it is speaking under.
     */
    public function testAFailureAlwaysNamesTheOperationItBelongsTo(): void
    {
        foreach (StorageOperation::cases() as $operation) {
            $this->assertSame($operation, (new ImageStorageFailed($operation, 'a reason'))->operation());
            $this->assertSame($operation, (new ImageStorageUnavailable($operation))->operation());
        }
    }

    /**
     * The two enums feed ONE `failure_category` dimension, one from the pipeline and one from the
     * substrate. A shared value would give that dimension two meanings and let a consumer match a case
     * that can never arise for it.
     */
    public function testTheSubstrateAndPipelineVocabulariesShareNoValue(): void
    {
        // No emptiness guard: static analysis proves both enums non-empty on every run and refuses the
        // assertion as already narrowed, so writing one would be noise rather than a control.
        $this->assertSame(
            [],
            \array_intersect(
                \array_column(StorageFailureCategory::cases(), 'value'),
                \array_column(FailureCategory::cases(), 'value'),
            ),
        );
    }
}
