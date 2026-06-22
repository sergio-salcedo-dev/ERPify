<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Erpify\Shared\Media\Application\Dto\NormalizedImage;
use Erpify\Shared\Media\Application\MediaRegistrar;
use Erpify\Shared\Media\Application\Port\ImageNormalizer;
use Erpify\Shared\Media\Domain\Entity\Media;
use Erpify\Shared\Storage\Application\Port\StoragePort;
use Erpify\Shared\Storage\Application\StoredImageObjectWriter;
use Erpify\Shared\Validation\Application\Validator;
use Erpify\Tests\Unit\Shared\Media\Application\RecordingMediaRepository;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Builds the collaborators a Bank write use case ({@see \Erpify\Backoffice\Bank\Application\BankCreator},
 * {@see \Erpify\Backoffice\Bank\Application\BankUpdater}) coordinates: a media registrar and stored-image
 * writer wired over stubbed image/storage ports, plus a pass-through validator. Shared so each use-case
 * test stays under the PHPMD object-coupling budget rather than re-importing every collaborator itself.
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait BankCollaboratorStubs
{
    /** A valid 64-char sha256 so {@see \Erpify\Shared\Storage\Domain\ContentAddressableObjectKey} accepts it. */
    private const string CONTENT_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private function mediaRegistrar(?Media $resolves = null): MediaRegistrar
    {
        $imageNormalizer = $this->createStub(ImageNormalizer::class);
        $imageNormalizer->method('normalize')->willReturn(
            new NormalizedImage('PNG.', 'image/png', self::CONTENT_HASH),
        );

        return new MediaRegistrar(
            $imageNormalizer,
            new RecordingMediaRepository(found: $resolves),
            $this->passThroughValidator(),
        );
    }

    private function storedImageObjectWriter(): StoredImageObjectWriter
    {
        $imageNormalizer = $this->createStub(ImageNormalizer::class);
        $imageNormalizer->method('normalize')->willReturn(
            new NormalizedImage('webp-bytes', 'image/webp', self::CONTENT_HASH),
        );

        $storagePort = $this->createStub(StoragePort::class);
        $storagePort->method('exists')->willReturn(true);

        return new StoredImageObjectWriter($imageNormalizer, $storagePort);
    }

    private function passThroughValidator(): Validator
    {
        $validator = $this->createStub(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new ConstraintViolationList());

        return new Validator($validator);
    }
}
