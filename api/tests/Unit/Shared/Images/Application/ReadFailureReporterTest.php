<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application;

use Erpify\Shared\Images\Application\ReadFailureReporter;
use Erpify\Shared\Images\Domain\Entity\Image;
use Erpify\Shared\Images\Domain\Exception\FailureCategory;
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
     *
     * **What actually holds this property is the sibling assertion above, not this one, and saying so is the
     * point.** `objectTooLarge()` and `digestMismatch()` take no arguments, so no identifier, digest, key or
     * byte can reach the context BY CONSTRUCTION — which makes this test unable to fail while the signature
     * stays that way, and a test that cannot fail is not a guard. The `assertSame` over the whole context
     * beside it is what would red on a value appearing. This one is kept as a REGRESSION case for the day
     * somebody widens the signature, which is the only way the property becomes losable, and it now names
     * the storage key as well: the key is derived from the identifier but does not SPELL it, so an
     * assertion about the identifier alone never covered it.
     */
    public function testTheSignalCarriesNeitherTheIdentifierNorTheDigestNorTheBytesNorTheStorageKey(): void
    {
        $image = ImageFinderHarness::image();
        $logger = $this->emitADigestMismatch($image);

        $serialized = \json_encode($logger->records, JSON_THROW_ON_ERROR);
        $identifier = $image->id()->toString();
        $storageKey = \sprintf(
            '%s/%s/%s',
            \substr($identifier, -4, 2),
            \substr($identifier, -2),
            $identifier,
        );

        $this->assertStringNotContainsString($identifier, $serialized);
        $this->assertStringNotContainsString($image->digest(), $serialized);
        $this->assertStringNotContainsString(ImageFinderHarness::BYTES, $serialized);
        $this->assertStringNotContainsString($storageKey, $serialized);
    }

    /**
     * The read verdicts stay disjoint from BOTH sibling vocabularies, which is what keeps `failure_category`
     * one closed universe by union across the enums that feed it — so a single query over that dimension
     * sees every verdict without knowing how many enums produce them.
     *
     * **Three enums make three pairs, and disjointness is not transitive across two of them.** Asserting
     * this pair and letting `StorageFailureVocabularyTest` assert storage-against-pipeline leaves
     * read-against-pipeline unasserted, where the collision is not hypothetical: rename
     * `ObjectTooLarge` to the natural `input_too_large` and it collides with `FailureCategory::InputTooLarge`
     * — both existing assertions stay green, and a query over `failure_category` starts attributing a read
     * refusal to the upload pipeline.
     */
    public function testTheReadVerdictsShareNoValueWithEitherSiblingVocabulary(): void
    {
        $this->assertSame([], \array_intersect(
            \array_column(ReadFailureCategory::cases(), 'value'),
            [
                ...\array_column(StorageFailureCategory::cases(), 'value'),
                ...\array_column(FailureCategory::cases(), 'value'),
            ],
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
