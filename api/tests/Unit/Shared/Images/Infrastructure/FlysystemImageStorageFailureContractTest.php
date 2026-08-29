<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Infrastructure;

use Closure;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Storage\ImageBytesNotFound;
use Erpify\Shared\Images\Domain\Storage\ImageStorageException;
use Erpify\Shared\Images\Domain\Storage\ImageStorageFailed;
use Erpify\Shared\Images\Domain\Storage\ImageStorageUnavailable;
use Erpify\Shared\Images\Domain\Storage\StorageFailureCategory;
use Erpify\Shared\Images\Domain\Storage\StorageOperation;
use Erpify\Shared\Images\Infrastructure\FlysystemImageStorage;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Throwable;

/**
 * What a storage failure IS, and what it CARRIES — the two halves of the port's failure contract, over
 * one table of scenarios so neither can drift away from the other.
 *
 * **What it is.** The library's exceptions never cross into the application untranslated, and the
 * translation is by the library's own concrete hierarchy rather than by `Throwable`: a failure that is
 * NOT the library's must keep its own identity, because disguising a programming error as a retryable
 * substrate failure has a consumer retry it for ever.
 *
 * **What it carries.** Every scenario feeds the adapter a library exception whose message quotes the
 * key — which is exactly what the library does, `UnableToWriteFile::atLocation($path)` — and the key is
 * derived from the identifier. Four surfaces are then searched for that identifier as a substring: the
 * translated message, the whole `previous` chain, the `ErrorDetailsStamp` a failed message carries into
 * `messenger_messages`, and the observability record. The stamp matters as much as the log, and is far
 * easier to forget: nothing prunes that table by subject, so an identifier landing there outlives every
 * erasure path this application has.
 *
 * @internal
 *
 * @phpstan-type Scenario array{
 *     class: class-string<ImageStorageException>,
 *     category: StorageFailureCategory,
 *     operation: StorageOperation,
 *     run: Closure(ImageId, LoggerInterface): void,
 * }
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the subject IS a translation matrix, so every library
 *                                                   exception and every verdict it maps to has to be named
 */
#[CoversClass(FlysystemImageStorage::class)]
#[CoversClass(ImageBytesNotFound::class)]
#[CoversClass(ImageStorageFailed::class)]
#[CoversClass(ImageStorageUnavailable::class)]
final class FlysystemImageStorageFailureContractTest extends TestCase
{
    use TemporaryImageStorage;

    private const string PROBE_BYTES = 'canonical bytes that must not reach any failure surface';

    public function testEveryLibraryFailureBecomesAVerdictOfThisModulesOwnVocabulary(): void
    {
        foreach ($this->scenarios() as $label => $scenario) {
            $failure = $this->raise($scenario['run'], ImageId::generate(), new RecordingLogger(), $label);

            $this->assertInstanceOf($scenario['class'], $failure, $label);
            $this->assertSame($scenario['category'], $failure->storageFailure(), $label);
            $this->assertSame($scenario['operation'], $failure->operation(), $label);
        }
    }

    /**
     * The behavioural form of "no `catch (\Throwable)`". A structural check on the source would be
     * satisfied by a `catch (FilesystemException)` that is nonetheless too wide; this observes the
     * property itself.
     */
    public function testAFailureThatIsNotTheLibrarysIsNeverDisguisedAsAStorageVerdict(): void
    {
        $logger = new RecordingLogger();
        $storage = $this->storage($logger, FailingFilesystem::raisingFrom(
            'write',
            new RuntimeException('a defect in this application, not in the substrate'),
            $this->root,
        ));

        try {
            $storage->store(ImageId::generate(), self::PROBE_BYTES);
            $this->fail('the scenario completed instead of raising');
        } catch (ImageStorageException $translated) {
            $this->fail(\sprintf('a non-library failure was translated into %s', $translated::class));
        } catch (RuntimeException $untouched) {
            $this->assertSame('a defect in this application, not in the substrate', $untouched->getMessage());
        }

        $this->assertSame([], $logger->records, 'and it is not reported as a storage verdict either');
    }

    public function testNoFailureSurfaceCarriesTheIdentifierTheDigestOrTheKey(): void
    {
        $digest = \hash('sha256', self::PROBE_BYTES);

        foreach ($this->scenarios() as $label => $scenario) {
            $logger = new RecordingLogger();
            $identifier = ImageId::generate();
            $failure = $this->raise($scenario['run'], $identifier, $logger, $label);

            foreach ($this->surfacesOf($failure, $logger) as $surface => [$haystack, $knownToBeThere]) {
                $where = \sprintf('%s / %s', $label, $surface);

                // A token this surface really carries, rather than `!== ''`: three of the four are never
                // empty (a class name, a serialised object, `'[]'`), so an emptiness guard would only ever
                // have been live on the message — and a search that cannot find anything proves nothing
                // when it finds no identifier either.
                $this->assertStringContainsStringIgnoringCase(
                    $knownToBeThere,
                    $haystack,
                    \sprintf('%s: the search must find what IS there, or its silence proves nothing', $where),
                );

                $value = $identifier->toString();

                foreach ([$value, \substr($value, 0, 8), $digest, self::PROBE_BYTES] as $secret) {
                    $this->assertStringNotContainsString($secret, $haystack, $where);
                }
            }
        }
    }

    /**
     * The four places a failure is retained. The `previous` chain is walked rather than checked for
     * `null`, because a chain that is empty today is one `previous: $exception` away from carrying the
     * library's own message, key and all — measured, that single edit reds this test on the chain surface.
     *
     * **The stamp is dominated by the two before it and is asserted anyway.** `ErrorDetailsStamp::create()`
     * reads nothing but the throwable, so today it can only carry what the message and the chain already
     * carry; it adds no independent detection power and must not be read as a second control. It is here
     * because it is the SINK — a reader who sees only a log assertion concludes the log is where an
     * exception's text goes, and `messenger_messages` is the copy no erasure path reaches.
     *
     * Each surface is paired with a token it demonstrably carries, so a search that finds no identifier is
     * known to be a search capable of finding anything at all.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function surfacesOf(ImageStorageException $failure, RecordingLogger $logger): array
    {
        $chain = [];

        for ($link = $failure->getPrevious(); $link instanceof Throwable; $link = $link->getPrevious()) {
            $chain[] = $link::class . ': ' . $link->getMessage();
        }

        return [
            // Every verdict's text names the subject; the operation is in two of the three, so the token
            // that is live for all of them is the subject itself.
            'the translated message' => [$failure->getMessage(), 'image'],
            'the previous chain' => [$failure::class . ' -> ' . \implode(' -> ', $chain), $failure::class],
            'the ErrorDetailsStamp' => [\serialize(ErrorDetailsStamp::create($failure)), $failure::class],
            'the observability record' => [
                \json_encode($logger->records, JSON_THROW_ON_ERROR),
                'image_storage_failure',
            ],
        ];
    }

    /**
     * Every branch of the adapter that raises **and can be driven from this process**, with the verdict it
     * owes. Split by operation only because one table long enough to hold them all stops being readable.
     *
     * The two it cannot reach are the permission branches — a root that cannot be traversed, and an
     * existence that cannot be established — because this container runs the suite as uid 0 and the kernel
     * lets root traverse a directory at mode 0000 (measured: `is_executable()` and `access(F_OK)` both
     * answer true there for root, and false for uid 1000). They are exercised, with the same leak
     * assertions, by the unprivileged subprocess in
     * {@see FlysystemImageStorageUndecidableExistenceTest}.
     *
     * @return array<string, Scenario>
     */
    private function scenarios(): array
    {
        return [
            ...$this->writeScenarios(),
            ...$this->integrityScenarios(),
            ...$this->readScenarios(),
            ...$this->deleteScenarios(),
        ];
    }

    /**
     * @return array<string, Scenario>
     */
    private function writeScenarios(): array
    {
        return [
            'the library refuses the write for a condition a retry may clear' => [
                'class' => ImageStorageUnavailable::class,
                'category' => StorageFailureCategory::Transient,
                'operation' => StorageOperation::Store,
                'run' => function (ImageId $identifier, LoggerInterface $logger): void {
                    $failure = UnableToWriteFile::atLocation($this->keyLike($identifier), 'Device or resource busy');

                    $this->storage($logger, $this->raisingFrom('write', $failure))
                        ->store($identifier, self::PROBE_BYTES)
                    ;
                },
            ],
            'the library refuses the write for a condition only an operator clears' => [
                'class' => ImageStorageFailed::class,
                'category' => StorageFailureCategory::Permanent,
                'operation' => StorageOperation::Store,
                'run' => function (ImageId $identifier, LoggerInterface $logger): void {
                    // Worded as PHP words it, path included, because that is exactly what the library
                    // hands back — and the assertions below prove the path does not survive the reading.
                    $failure = UnableToWriteFile::atLocation($this->keyLike($identifier), \sprintf(
                        'file_put_contents(%s): Failed to open stream: No space left on device',
                        $this->keyLike($identifier),
                    ));

                    $this->storage($logger, $this->raisingFrom('write', $failure))
                        ->store($identifier, self::PROBE_BYTES)
                    ;
                },
            ],
            'the library fails the write in a way it does not name' => [
                'class' => ImageStorageFailed::class,
                'category' => StorageFailureCategory::Permanent,
                'operation' => StorageOperation::Store,
                'run' => function (ImageId $identifier, LoggerInterface $logger): void {
                    $failure = UnableToCreateDirectory::atLocation($this->keyLike($identifier), 'permission denied');

                    $this->storage($logger, $this->raisingFrom('write', $failure))
                        ->store($identifier, self::PROBE_BYTES)
                    ;
                },
            ],
        ];
    }

    /**
     * @return array<string, Scenario>
     */
    private function integrityScenarios(): array
    {
        return [
            'the stored object cannot be read back' => [
                'class' => ImageStorageFailed::class,
                'category' => StorageFailureCategory::Permanent,
                'operation' => StorageOperation::VerifyIntegrity,
                'run' => function (ImageId $identifier, LoggerInterface $logger): void {
                    $failure = UnableToReadFile::fromLocation($this->keyLike($identifier), 'i/o error');

                    $this->storage($logger, $this->raisingFrom('read', $failure))
                        ->store($identifier, self::PROBE_BYTES)
                    ;
                },
            ],
            'the substrate accepted the write and kept something else' => [
                'class' => ImageStorageFailed::class,
                'category' => StorageFailureCategory::Permanent,
                'operation' => StorageOperation::VerifyIntegrity,
                'run' => function (ImageId $identifier, LoggerInterface $logger): void {
                    $this->storage($logger, new PartiallyWritingFilesystem($this->root))
                        ->store($identifier, self::PROBE_BYTES)
                    ;
                },
            ],
            'the identifier already carries an object' => [
                'class' => ImageStorageFailed::class,
                'category' => StorageFailureCategory::Permanent,
                'operation' => StorageOperation::Store,
                'run' => function (ImageId $identifier, LoggerInterface $logger): void {
                    $storage = $this->storage($logger);
                    $storage->store($identifier, self::PROBE_BYTES);
                    $storage->store($identifier, self::PROBE_BYTES);
                },
            ],
        ];
    }

    /**
     * @return array<string, Scenario>
     */
    private function readScenarios(): array
    {
        return [
            'the object is demonstrably absent on read' => [
                'class' => ImageBytesNotFound::class,
                'category' => StorageFailureCategory::ConfirmedAbsence,
                'operation' => StorageOperation::Read,
                'run' => function (ImageId $identifier, LoggerInterface $logger): void {
                    $this->storage($logger)->read($identifier);
                },
            ],
            'the library refuses the read' => [
                'class' => ImageStorageUnavailable::class,
                'category' => StorageFailureCategory::Transient,
                'operation' => StorageOperation::Read,
                'run' => function (ImageId $identifier, LoggerInterface $logger): void {
                    $this->storage($logger)->store($identifier, self::PROBE_BYTES);
                    $failure = UnableToReadFile::fromLocation($this->keyLike($identifier), 'i/o error');

                    $this->storage($logger, $this->raisingFrom('read', $failure))
                        ->read($identifier)
                    ;
                },
            ],
            'the library fails the read in a way it does not name' => [
                'class' => ImageStorageFailed::class,
                'category' => StorageFailureCategory::Permanent,
                'operation' => StorageOperation::Read,
                'run' => function (ImageId $identifier, LoggerInterface $logger): void {
                    $this->storage($logger)->store($identifier, self::PROBE_BYTES);
                    $failure = UnableToCreateDirectory::atLocation($this->keyLike($identifier), 'permission denied');

                    $this->storage($logger, $this->raisingFrom('read', $failure))
                        ->read($identifier)
                    ;
                },
            ],
        ];
    }

    /**
     * @return array<string, Scenario>
     */
    private function deleteScenarios(): array
    {
        return [
            'the library refuses the deletion' => [
                'class' => ImageStorageUnavailable::class,
                'category' => StorageFailureCategory::Transient,
                'operation' => StorageOperation::Delete,
                'run' => function (ImageId $identifier, LoggerInterface $logger): void {
                    $this->storage($logger)->store($identifier, self::PROBE_BYTES);
                    $failure = UnableToDeleteFile::atLocation($this->keyLike($identifier), 'device busy');

                    $this->storage($logger, $this->raisingFrom('delete', $failure))
                        ->delete($identifier)
                    ;
                },
            ],
            'the library fails the deletion in a way it does not name' => [
                'class' => ImageStorageFailed::class,
                'category' => StorageFailureCategory::Permanent,
                'operation' => StorageOperation::Delete,
                'run' => function (ImageId $identifier, LoggerInterface $logger): void {
                    $this->storage($logger)->store($identifier, self::PROBE_BYTES);
                    $failure = UnableToCreateDirectory::atLocation($this->keyLike($identifier), 'permission denied');

                    $this->storage($logger, $this->raisingFrom('delete', $failure))
                        ->delete($identifier)
                    ;
                },
            ],
            'the root was never mounted' => [
                'class' => ImageStorageFailed::class,
                'category' => StorageFailureCategory::Permanent,
                'operation' => StorageOperation::Delete,
                'run' => function (ImageId $identifier, LoggerInterface $logger): void {
                    $missingRoot = $this->root . '/never-provisioned';

                    (new FlysystemImageStorage(
                        new Filesystem(new LocalFilesystemAdapter($missingRoot, lazyRootCreation: true)),
                        $missingRoot,
                        $logger,
                    ))->delete($identifier);
                },
            ],
        ];
    }

    /**
     * The shape the library quotes in its own messages: two shards of the identifier and the identifier
     * itself. Using it as the location is what makes the leak assertions non-vacuous.
     */
    private function keyLike(ImageId $identifier): string
    {
        $value = $identifier->toString();

        return \sprintf('%s/%s/%s', \substr($value, 0, 2), \substr($value, 2, 2), $value);
    }

    private function raisingFrom(string $operation, Throwable $failure): FilesystemOperator
    {
        return FailingFilesystem::raisingFrom($operation, $failure, $this->root);
    }

    /**
     * Runs a scenario that must raise, and fails the test when it does not — a branch that quietly stopped
     * failing would otherwise be read as a passing assertion about its verdict.
     *
     * @param Closure(ImageId, LoggerInterface): void $run
     */
    private function raise(
        Closure $run,
        ImageId $identifier,
        LoggerInterface $logger,
        string $label,
    ): ImageStorageException {
        try {
            $run($identifier, $logger);
        } catch (ImageStorageException $imageStorageException) {
            return $imageStorageException;
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
