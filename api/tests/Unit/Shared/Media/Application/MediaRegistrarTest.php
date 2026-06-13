<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Media\Application;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Erpify\Shared\Application\Validation\Validator;
use Erpify\Shared\Media\Application\Dto\NormalizedImage;
use Erpify\Shared\Media\Application\MediaRegistrar;
use Erpify\Shared\Media\Application\Port\ImageNormalizer;
use Erpify\Shared\Media\Domain\Entity\Media;
use Erpify\Shared\Media\Domain\Exception\ConcurrentMediaWinnerMissingException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Validation;

/**
 * @internal
 */
#[CoversClass(MediaRegistrar::class)]
#[CoversClass(ConcurrentMediaWinnerMissingException::class)]
final class MediaRegistrarTest extends TestCase
{
    private const string MEDIA_ID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    private const string CONTENT_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function testReturnsExistingMediaWhenContentHashIsAlreadyRegistered(): void
    {
        $existing = Media::create(self::MEDIA_ID, self::CONTENT_HASH, 'image/png', 4, 'PNG.');
        $mediaRepository = new FakeMediaRepository(found: $existing);
        $registrar = $this->makeRegistrar($mediaRepository);

        $result = $registrar->registerFromUploadedFile($this->createStub(UploadedFile::class));

        $this->assertSame($existing, $result);
        $this->assertSame(0, $mediaRepository->saveCalls);
    }

    public function testThrowsConcurrentMediaWinnerMissingExceptionWhenWinnerCannotBeRefetched(): void
    {
        // Dedup misses, the unique index rejects our insert (another request won the race),
        // then the winning row is absent from the re-query — an unrecoverable inconsistency.
        $mediaRepository = new FakeMediaRepository(found: null, saveFailure: $this->makeUniqueViolation());

        $this->expectException(ConcurrentMediaWinnerMissingException::class);
        $this->expectExceptionMessageMatches('/' . self::CONTENT_HASH . '/');

        $this->makeRegistrar($mediaRepository)->registerFromUploadedFile($this->createStub(UploadedFile::class));
    }

    public function testReQueriesForTheWinnerAfterLosingTheConcurrentInsertRace(): void
    {
        $mediaRepository = new FakeMediaRepository(found: null, saveFailure: $this->makeUniqueViolation());

        try {
            $this->makeRegistrar($mediaRepository)->registerFromUploadedFile($this->createStub(UploadedFile::class));
        } catch (ConcurrentMediaWinnerMissingException) {
            // Expected — the registrar gives up only after the post-reset re-query also misses.
        }

        // Dedup lookup + post-reset re-fetch: the registrar must re-query before giving up.
        $this->assertSame(2, $mediaRepository->findCalls);
    }

    private function makeRegistrar(FakeMediaRepository $mediaRepository): MediaRegistrar
    {
        $imageNormalizer = $this->createStub(ImageNormalizer::class);
        $imageNormalizer->method('normalize')->willReturn(
            new NormalizedImage('PNG.', 'image/png', self::CONTENT_HASH),
        );

        return new MediaRegistrar(
            $imageNormalizer,
            $this->createStub(ManagerRegistry::class),
            $mediaRepository,
            new Validator(Validation::createValidator()),
        );
    }

    private function makeUniqueViolation(): UniqueConstraintViolationException
    {
        // SQLSTATE 23505 = Postgres unique_violation, as raised by media_content_hash_uniq.
        return new UniqueConstraintViolationException($this->createStub(DriverException::class), null);
    }
}
