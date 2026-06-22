<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Media\Application;

use Erpify\Shared\Media\Application\Dto\NormalizedImage;
use Erpify\Shared\Media\Application\Dto\UploadedImage;
use Erpify\Shared\Media\Application\MediaRegistrar;
use Erpify\Shared\Media\Application\Port\ImageNormalizer;
use Erpify\Shared\Media\Domain\Entity\Media;
use Erpify\Shared\Validation\Application\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Exception\ValidationFailedException;
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

        $result = $this->makeRegistrar($mediaRepository)->register(
            new UploadedImage('image/png', 'PNG.'),
        );

        $this->assertSame($existing, $result);
        $this->assertSame(0, $mediaRepository->saveOrGetCalls, 'an existing hash must not be re-written');
    }

    public function testRegistersAndReturnsNewMediaWhenContentHashIsUnseen(): void
    {
        $mediaRepository = new RecordingMediaRepository();

        $result = $this->makeRegistrar($mediaRepository)->register(
            new UploadedImage('image/png', 'PNG.'),
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

        $result = $this->makeRegistrar($mediaRepository)->register(
            new UploadedImage('image/png', 'PNG.'),
        );

        $this->assertSame($winner, $result);
        $this->assertSame(1, $mediaRepository->saveOrGetCalls);
    }

    #[DataProvider('provideRejectsMediaWhoseContentHashIsNotALowercaseSha256DigestCases')]
    public function testRejectsMediaWhoseContentHashIsNotALowercaseSha256Digest(string $contentHash): void
    {
        // The content hash is machine-computed (sha256 hex); an invalid one signals a corrupt pipeline,
        // so the registrar must reject it before persistence rather than let a malformed public URL be
        // synthesized from it downstream.
        $mediaRepository = new RecordingMediaRepository();

        try {
            $this->makeRegistrar($mediaRepository, $contentHash)->register(
                new UploadedImage('image/png', 'PNG.'),
            );
            $this->fail('expected the registrar to reject an invalid content hash');
        } catch (ValidationFailedException) {
            // Expected — the assertion below pins that nothing was persisted.
        }

        $this->assertSame(0, $mediaRepository->saveOrGetCalls, 'an invalid content hash must not be persisted');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRejectsMediaWhoseContentHashIsNotALowercaseSha256DigestCases(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => [' '];
        yield 'too short' => ['abc'];
        yield 'uppercase hex' => [\strtoupper(self::CONTENT_HASH)];
        yield 'non-hex characters' => [\str_repeat('z', 64)];
    }

    private function makeRegistrar(
        RecordingMediaRepository $mediaRepository,
        string $contentHash = self::CONTENT_HASH,
    ): MediaRegistrar {
        $imageNormalizer = $this->createStub(ImageNormalizer::class);
        $imageNormalizer->method('normalize')->willReturn(
            new NormalizedImage('PNG.', 'image/png', $contentHash),
        );

        return new MediaRegistrar(
            $imageNormalizer,
            $mediaRepository,
            new Validator(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()),
        );
    }
}
