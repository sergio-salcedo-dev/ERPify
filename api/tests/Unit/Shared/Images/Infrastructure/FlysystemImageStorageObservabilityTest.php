<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Infrastructure;

use Closure;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Storage\ImageStorageException;
use Erpify\Shared\Images\Domain\Storage\StorageFailureCategory;
use Erpify\Shared\Images\Domain\Storage\StorageOperation;
use Erpify\Shared\Images\Infrastructure\FlysystemImageStorage;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The observability signal the storage adapter emits, asserted as a metric rather than as a log line.
 *
 * Two properties carry the weight here and neither is visible at a call site. The dimensions come from
 * closed enums, because a free-form value on a metric is a cardinality explosion rather than a typo; and
 * the record must not carry the image identifier, the digest or the derived key — **as a VALUE, not as a
 * key name**. Asserting the absence of an `imageId` KEY is the failure mode this repository has already
 * shipped twice: a context spelled `['path' => 'images/01H9…']` satisfies every name-based check while
 * leaking the identifier whole. The assertions below therefore serialise the record and search it as a
 * string, and each one first proves the instrument can find something that IS there.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") one failure per operation means naming the substrates
 *                                                   that produce them
 */
#[CoversClass(FlysystemImageStorage::class)]
final class FlysystemImageStorageObservabilityTest extends TestCase
{
    use TemporaryImageStorage;

    private const string PROBE_BYTES = 'canonical bytes whose digest must never reach the signal';

    private const string ABSENT_ON_READ = 'an absent object is a confirmed absence on read';

    private const string UNMOUNTED_ROOT = 'an unmounted root refuses a deletion';

    private const string CORRUPTED_WRITE = 'a write the filesystem accepted but corrupted fails verification';

    /**
     * The enum is only closed if every one of its cases is REACHED: an operation nothing reports under is
     * a dimension value no dashboard will ever see, and one nobody would notice going missing.
     */
    public function testEveryOperationInTheClosedSetIsReachedAndNoDimensionEscapesItsEnum(): void
    {
        $reached = [];

        foreach ($this->failingOperations() as $label => $produceFailure) {
            $logger = new RecordingLogger();
            $this->capture($produceFailure, $logger, $label);

            $this->assertCount(1, $logger->records, \sprintf('%s: exactly one record per failure', $label));

            $context = $logger->records[0]['context'];
            $this->assertSame(
                ['operation', 'failure_category'],
                \array_keys($context),
                \sprintf('%s: the context is these two dimensions and nothing else', $label),
            );

            $operation = $context['operation'] ?? null;
            $category = $context['failure_category'] ?? null;
            $this->assertIsString($operation);
            $this->assertIsString($category);

            $this->assertInstanceOf(
                StorageOperation::class,
                StorageOperation::tryFrom($operation),
                \sprintf('%s: "%s" is outside the closed operation vocabulary', $label, $operation),
            );
            $this->assertInstanceOf(
                StorageFailureCategory::class,
                StorageFailureCategory::tryFrom($category),
                \sprintf('%s: "%s" is outside the closed category vocabulary', $label, $category),
            );

            $reached[] = $operation;
        }

        $declared = \array_column(StorageOperation::cases(), 'value');
        \sort($declared);
        $reached = \array_values(\array_unique($reached));
        \sort($reached);

        $this->assertSame($declared, $reached, 'every declared operation must have a failure that reports under it');
    }

    /**
     * A confirmed absence is an outcome; anything else is a fault. Read from the sink they are the same
     * record, so the level is the only thing telling an operator whether to look at the deployment.
     */
    public function testAnAbsenceIsReportedOneLevelBelowASubstrateFailure(): void
    {
        $absence = new RecordingLogger();
        $this->capture($this->failingOperation(self::ABSENT_ON_READ), $absence, self::ABSENT_ON_READ);

        $fault = new RecordingLogger();
        $this->capture($this->failingOperation(self::UNMOUNTED_ROOT), $fault, self::UNMOUNTED_ROOT);

        $this->assertArrayHasKey(0, $absence->records);
        $this->assertArrayHasKey(0, $fault->records);
        $this->assertSame('info', $absence->records[0]['level']);
        $this->assertSame('warning', $fault->records[0]['level']);
    }

    /**
     * The whole point of the criterion, and the one assertion a name-based check cannot make.
     */
    public function testTheRecordCarriesNeitherTheIdentifierNorTheDigestNorTheKeyAsAValue(): void
    {
        $digest = \hash('sha256', self::PROBE_BYTES);

        foreach ($this->failingOperations() as $label => $produceFailure) {
            $logger = new RecordingLogger();
            $identifier = $this->capture($produceFailure, $logger, $label);

            $this->assertNotEmpty(
                $logger->records,
                \sprintf('%s: assert the line was emitted before asserting what it omits', $label),
            );

            $serialised = \json_encode($logger->records[0], JSON_THROW_ON_ERROR);

            $this->assertStringContainsString(
                'image_storage_failure',
                $serialised,
                \sprintf('%s: the search must find what IS there, or its silence proves nothing', $label),
            );

            foreach ([$identifier->toString(), $digest, self::PROBE_BYTES] as $secret) {
                $this->assertStringNotContainsString(
                    $secret,
                    $serialised,
                    \sprintf('%s: leaked into the signal', $label),
                );
            }

            // The derived key is the identifier under two shards OF the identifier, so a partial leak
            // spells the opening characters rather than the whole value.
            $this->assertStringNotContainsString(\substr($identifier->toString(), 0, 4), $serialised, $label);
        }
    }

    /**
     * A deletion that finds nothing is a SUCCESS, and it is the one outcome the caller cannot distinguish
     * from a completed deletion — so if it emitted nothing, a deployment answering "already absent" for
     * every request would produce no record at all. It is reported at the outcome level, not the fault
     * level, so counting it never competes with the alert that means something is broken.
     */
    public function testAnIdempotentAbsenceOnTheDeletePathIsReportedRatherThanSilent(): void
    {
        $logger = new RecordingLogger();

        $this->storage($logger)->delete(ImageId::generate());

        $this->assertCount(1, $logger->records, 'the one successful outcome that must still be countable');
        $this->assertSame('info', $logger->records[0]['level']);
        $this->assertSame([
            'operation' => StorageOperation::Delete->value,
            'failure_category' => StorageFailureCategory::ConfirmedAbsence->value,
        ], $logger->records[0]['context']);
    }

    public function testTheSignalIsNeverLoadBearingForTheFailureItself(): void
    {
        $storage = $this->storage(new ThrowingLogger());

        $this->expectException(ImageStorageException::class);

        $storage->read(ImageId::generate());
    }

    public function testAnOperationThatSucceedsIsSilent(): void
    {
        // The presence comes first on purpose: an assertion that a path logged NOTHING is satisfied just
        // as well by a logger nothing was ever wired to.
        $wired = new RecordingLogger();
        $this->capture($this->failingOperation(self::ABSENT_ON_READ), $wired, self::ABSENT_ON_READ);
        $this->assertCount(1, $wired->records, 'this logger does receive records');

        $logger = new RecordingLogger();
        $storage = $this->storage($logger);
        $identifier = ImageId::generate();

        $storage->store($identifier, self::PROBE_BYTES);
        $storage->read($identifier);
        $storage->delete($identifier);

        $this->assertSame([], $logger->records, 'a healthy path emits nothing');
    }

    /**
     * @return Closure(LoggerInterface, ImageId): void
     */
    private function failingOperation(string $label): Closure
    {
        $operations = $this->failingOperations();
        $this->assertArrayHasKey($label, $operations, 'the scenario table no longer carries this label');

        return $operations[$label];
    }

    /**
     * One failure per member of the operation vocabulary, each raised for an identifier the caller
     * supplies so the leak assertions know what to search for.
     *
     * @return array<string, Closure(LoggerInterface, ImageId): void>
     */
    private function failingOperations(): array
    {
        return [
            'a reused identifier refuses the write' => function (LoggerInterface $logger, ImageId $identifier): void {
                $storage = $this->storage($logger);
                $storage->store($identifier, self::PROBE_BYTES);
                $storage->store($identifier, self::PROBE_BYTES);
            },
            self::ABSENT_ON_READ => function (LoggerInterface $logger, ImageId $identifier): void {
                $this->storage($logger)->read($identifier);
            },
            self::UNMOUNTED_ROOT => function (LoggerInterface $logger, ImageId $identifier): void {
                $missingRoot = $this->root . '/never-provisioned';

                (new FlysystemImageStorage(
                    new Filesystem(new LocalFilesystemAdapter($missingRoot, lazyRootCreation: true)),
                    $missingRoot,
                    $logger,
                ))->delete($identifier);
            },
            self::CORRUPTED_WRITE => function (LoggerInterface $logger, ImageId $identifier): void {
                $this->storage($logger, new PartiallyWritingFilesystem($this->root))
                    ->store($identifier, self::PROBE_BYTES)
                ;
            },
        ];
    }

    /**
     * Runs a scenario that must raise, and fails the test when it does not — so a scenario that quietly
     * stopped producing a failure can never be read as a passing assertion about its record.
     *
     * @param Closure(LoggerInterface, ImageId): void $produceFailure
     */
    private function capture(Closure $produceFailure, LoggerInterface $logger, string $label): ImageId
    {
        $identifier = ImageId::generate();

        try {
            $produceFailure($logger, $identifier);
        } catch (ImageStorageException) {
            return $identifier;
        }

        $this->fail(\sprintf('%s: the scenario completed instead of raising', $label));
    }

    private function storage(LoggerInterface $logger, ?FilesystemOperator $filesystem = null): FlysystemImageStorage
    {
        return new FlysystemImageStorage(
            $filesystem ?? new Filesystem(new LocalFilesystemAdapter($this->root, lazyRootCreation: true)),
            $this->root,
            $logger,
        );
    }
}
