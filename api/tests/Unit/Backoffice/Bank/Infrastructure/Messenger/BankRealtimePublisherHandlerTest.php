<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Infrastructure\Messenger;

use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Event\BankDeletedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Event\BankUpdatedDomainEvent;
use Erpify\Backoffice\Bank\Domain\MercureBankTopic;
use Erpify\Backoffice\Bank\Infrastructure\Messenger\BankRealtimePublisherHandler;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Asserts on the published payload as a JSON string (like {@see \Erpify\Tests\Unit\Frontoffice\Mercure
 * \Infrastructure\Controller\MercurePublishDemoControllerTest}) to keep the test free of
 * offset-access gymnastics over decoded mixed arrays.
 *
 * @internal
 */
#[CoversNothing]
final class BankRealtimePublisherHandlerTest extends TestCase
{
    private const string BANK_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    private const string CREATED_AT = '2026-01-01T00:00:00+00:00';

    private const string UPDATED_AT = '2026-01-02T00:00:00+00:00';

    private ?Update $captured = null;

    public function testPublishesCreatedToCollectionTopicAsPrivate(): void
    {
        $this->handler()->onBankCreated(new BankCreatedDomainEvent(
            self::BANK_ID,
            'event-id',
            'Acme Savings',
            'ACME',
            self::CREATED_AT,
            self::UPDATED_AT,
        ));

        $update = $this->capturedUpdate();
        $this->assertSame([MercureBankTopic::COLLECTION], $update->getTopics());
        $this->assertTrue($update->isPrivate());

        $data = $update->getData();
        $this->assertStringContainsString('"type":"bank.created"', $data);
        $this->assertStringContainsString('"id":"' . self::BANK_ID . '"', $data);
        $this->assertStringContainsString('"name":"Acme Savings"', $data);
        $this->assertStringContainsString('"shortName":"ACME"', $data);
        $this->assertStringNotContainsString('logoMediaId', $data);
        $this->assertStringNotContainsString('storedObjectContentHash', $data);
    }

    public function testPublishesUpdatedToCollectionAndPerBankTopics(): void
    {
        $this->handler()->onBankUpdated(new BankUpdatedDomainEvent(
            self::BANK_ID,
            'event-id',
            'Acme Renamed',
            'ACME',
            self::CREATED_AT,
            self::UPDATED_AT,
        ));

        $update = $this->capturedUpdate();
        $this->assertSame([MercureBankTopic::COLLECTION, MercureBankTopic::forBank(self::BANK_ID)], $update->getTopics());
        $this->assertTrue($update->isPrivate());
        $this->assertStringContainsString('"type":"bank.updated"', $update->getData());
        $this->assertStringContainsString('"name":"Acme Renamed"', $update->getData());
    }

    public function testPublishesDeletedWithIdOnlyToCollectionAndPerBankTopics(): void
    {
        $this->handler()->onBankDeleted(new BankDeletedDomainEvent(self::BANK_ID, 'event-id'));

        $update = $this->capturedUpdate();
        $this->assertSame([MercureBankTopic::COLLECTION, MercureBankTopic::forBank(self::BANK_ID)], $update->getTopics());
        $this->assertTrue($update->isPrivate());

        $data = $update->getData();
        $this->assertStringContainsString('"type":"bank.deleted"', $data);
        $this->assertStringContainsString('"id":"' . self::BANK_ID . '"', $data);
        $this->assertStringNotContainsString('"bank"', $data);
    }

    private function handler(): BankRealtimePublisherHandler
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects($this->once())
            ->method('publish')
            ->willReturnCallback(function (Update $update): string {
                $this->captured = $update;

                return 'id';
            })
        ;

        return new BankRealtimePublisherHandler($hub);
    }

    private function capturedUpdate(): Update
    {
        $this->assertInstanceOf(Update::class, $this->captured);

        return $this->captured;
    }
}
