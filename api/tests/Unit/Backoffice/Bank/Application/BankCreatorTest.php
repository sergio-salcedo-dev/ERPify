<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Application\BankCreator;
use Erpify\Backoffice\Bank\Application\Command\CreateBankCommand;
use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use Erpify\Shared\Media\Application\Dto\UploadedImage;
use Erpify\Shared\Media\Application\MediaRegistrar;
use Erpify\Shared\Media\Domain\Entity\Media;
use Erpify\Shared\Storage\Domain\StoredObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankCreator::class)]
final class BankCreatorTest extends TestCase
{
    use BankCollaboratorStubs;
    use InlineTransactionStubs;

    private const string MEDIA_ID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    public function testCreatesBankWithoutImagesAndPublishesTheCreatedEventInOneTransaction(): void
    {
        $bankRepository = new InMemoryBankRepository();
        $eventBus = new RecordingEventBus();

        $bank = $this->makeCreator($bankRepository, $eventBus)->create(
            new CreateBankCommand('Acme Savings', 'ACME'),
        );

        $this->assertSame('Acme Savings', $bank->getName());
        $this->assertSame('ACME', $bank->getShortName());
        $this->assertNotInstanceOf(Media::class, $bank->getLogo());
        $this->assertNotInstanceOf(StoredObject::class, $bank->getStoredObject());

        $this->assertSame([$bank], $bankRepository->saved);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(BankCreatedDomainEvent::class, $eventBus->publishedEvents[0]);
    }

    public function testStoresTheUploadedObjectAndAttachesItsHandleToTheBank(): void
    {
        $bank = $this->makeCreator(new InMemoryBankRepository(), new RecordingEventBus())->create(
            new CreateBankCommand('Acme Savings', 'ACME'),
            storedObject: new UploadedImage('image/webp', 'webp-bytes'),
        );

        $storedObject = $bank->getStoredObject();
        $this->assertInstanceOf(StoredObject::class, $storedObject);
        $this->assertSame(self::CONTENT_HASH, $storedObject->contentHash);
    }

    public function testRegistersTheUploadedLogoAndAttachesTheResolvedMediaToTheBank(): void
    {
        $media = Media::create(self::MEDIA_ID, self::CONTENT_HASH, 'image/png', 4, 'PNG.');

        $bank = $this->makeCreator(
            new InMemoryBankRepository(),
            new RecordingEventBus(),
            mediaRegistrar: $this->mediaRegistrar($media),
        )->create(
            new CreateBankCommand('Acme Savings', 'ACME'),
            logo: new UploadedImage('image/png', 'PNG.'),
        );

        $this->assertSame($media, $bank->getLogo());
    }

    private function makeCreator(
        InMemoryBankRepository $bankRepository,
        RecordingEventBus $eventBus,
        ?MediaRegistrar $mediaRegistrar = null,
    ): BankCreator {
        return new BankCreator(
            $bankRepository,
            $eventBus,
            $mediaRegistrar ?? $this->mediaRegistrar(),
            $this->storedImageObjectWriter(),
            $this->passThroughValidator(),
            $this->inlineTransactionEntityManager(),
        );
    }
}
