<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application;

use Erpify\Shared\Images\Application\ReadFailureReporter;
use Erpify\Shared\Images\Domain\Entity\Image;
use Erpify\Shared\Images\Domain\Read\ReadFailureCategory;
use Erpify\Shared\Images\Domain\Read\UnservableImage;
use Erpify\Shared\Images\Domain\Storage\StorageFailureCategory;
use Erpify\Shared\Images\Domain\Storage\StorageOperation;
use Erpify\Tests\Unit\Shared\Images\Infrastructure\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What the read path's failure signal carries, and — the half that matters — what it must never carry.
 *
 * Filed apart from the outcome tests because the subject is different: those ask what the caller is told,
 * this asks what the OPERATOR is told and what a sink with no TTL and no owner of erasure ends up holding.
 *
 * @internal
 */
#[CoversClass(ReadFailureReporter::class)]
final class ReadFailureReporterTest extends TestCase
{
    public function testTheSignalNamesTheOperationAndTheVerdictAndNothingElse(): void
    {
        $logger = $this->emitADigestMismatch();

        $this->assertCount(1, $logger->records);
        $this->assertSame('warning', $logger->records[0]['level']);
        $this->assertSame('image_read_failure', $logger->records[0]['message']);
        $this->assertSame([
            'operation' => StorageOperation::VerifyIntegrity->value,
            'failure_category' => ReadFailureCategory::DigestMismatch->value,
        ], $logger->records[0]['context']);
    }

    /**
     * Asserted over the SERIALIZED VALUES rather than the key names, because the shape that leaks is a value
     * under an innocent key — `['path' => 'images/ab/cd/01H9…']` passes any assertion about key names while
     * putting the identifier into a sink no erasure path reaches.
     */
    public function testTheSignalCarriesNeitherTheIdentifierNorTheDigestNorTheBytes(): void
    {
        $image = ImageFinderHarness::image();
        $logger = $this->emitADigestMismatch($image);

        $serialized = \json_encode($logger->records, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($image->id()->toString(), $serialized);
        $this->assertStringNotContainsString($image->digest(), $serialized);
        $this->assertStringNotContainsString(ImageFinderHarness::BYTES, $serialized);
    }

    /**
     * The read verdicts stay disjoint from the storage vocabulary, which is what keeps `failure_category`
     * one closed universe by union across the enums that feed it — so a single query over that dimension
     * sees every verdict without knowing how many enums produce them.
     */
    public function testTheReadVerdictsShareNoValueWithTheStorageVerdicts(): void
    {
        $this->assertSame([], \array_intersect(
            \array_column(ReadFailureCategory::cases(), 'value'),
            \array_column(StorageFailureCategory::cases(), 'value'),
        ));
    }

    private function emitADigestMismatch(?Image $image = null): RecordingLogger
    {
        $image ??= ImageFinderHarness::image();
        $repository = new InMemoryImageRepository();
        $repository->save($image);

        $logger = new RecordingLogger();

        $finder = ImageFinderHarness::finder(
            $repository,
            new CorruptingImageStorage('tampered bytes'),
            1_048_576,
            $logger,
        );

        try {
            $finder->find($image->id());
        } catch (UnservableImage) {
            // The record is the subject; the failure is only how it is produced.
        }

        return $logger;
    }
}
