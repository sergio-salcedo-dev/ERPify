<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Media\Application;

use Erpify\Shared\Application\Validation\Validator;
use Erpify\Shared\Media\Application\Dto\NormalizedImage;
use Erpify\Shared\Media\Application\MediaRegistrar;
use Erpify\Shared\Media\Application\Port\ImageNormalizer;
use Erpify\Shared\Media\Domain\Entity\Media;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Validation;

/**
 * @internal
 */
#[CoversClass(MediaRegistrar::class)]
final class MediaRegistrarTest extends TestCase
{
    private const string MEDIA_ID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    private const string CONTENT_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function testReturnsExistingMediaWithoutWritingWhenContentHashIsAlreadyRegistered(): void
    {
        $existing = Media::create(self::MEDIA_ID, self::CONTENT_HASH, 'image/png', 4, 'PNG.');
        $mediaRepository = new RecordingMediaRepository(found: $existing);

        $result = $this->makeRegistrar($mediaRepository)->registerFromUploadedFile(
            $this->createStub(UploadedFile::class),
        );

        $this->assertSame($existing, $result);
        $this->assertSame(0, $mediaRepository->saveOrGetCalls, 'an existing hash must not be re-written');
    }

    public function testRegistersAndReturnsNewMediaWhenContentHashIsUnseen(): void
    {
        $mediaRepository = new RecordingMediaRepository();

        $result = $this->makeRegistrar($mediaRepository)->registerFromUploadedFile(
            $this->createStub(UploadedFile::class),
        );

        $this->assertSame(self::CONTENT_HASH, $result->getContentHash());
        $this->assertSame(1, $mediaRepository->findCalls, 'happy path must not re-query');
        $this->assertSame(1, $mediaRepository->saveOrGetCalls);
    }

    public function testPropagatesTheCanonicalMediaTheRepositoryResolvesForTheContentHash(): void
    {
        // The repository owns the concurrent-insert race; the registrar must surface whatever
        // canonical row it returns (here, the winner of a lost race), not the row it built.
        $winner = Media::create(self::MEDIA_ID, self::CONTENT_HASH, 'image/png', 4, 'PNG.');
        $mediaRepository = new RecordingMediaRepository(winner: $winner);

        $result = $this->makeRegistrar($mediaRepository)->registerFromUploadedFile(
            $this->createStub(UploadedFile::class),
        );

        $this->assertSame($winner, $result);
        $this->assertSame(1, $mediaRepository->saveOrGetCalls);
    }

    private function makeRegistrar(RecordingMediaRepository $mediaRepository): MediaRegistrar
    {
        $imageNormalizer = $this->createStub(ImageNormalizer::class);
        $imageNormalizer->method('normalize')->willReturn(
            new NormalizedImage('PNG.', 'image/png', self::CONTENT_HASH),
        );

        return new MediaRegistrar(
            $imageNormalizer,
            $mediaRepository,
            new Validator(Validation::createValidator()),
        );
    }
}
