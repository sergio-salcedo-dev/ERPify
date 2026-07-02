<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Infrastructure\Messenger;

use Erpify\Backoffice\BankAccount\Domain\MercureBankAccountTopic;
use Erpify\Backoffice\BankAccount\Infrastructure\Messenger\RefreshRealtimeOnBankAccountChanged;
use Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity\Mother\BankAccountMother;
use Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Event\Mother\BankAccountCreatedDomainEventMother;
use Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Event\Mother\BankAccountDeletedDomainEventMother;
use Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Event\Mother\BankAccountSnapshotMother;
use Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Event\Mother\BankAccountUpdatedDomainEventMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Locks the minimal, PII-free realtime payload the PWA refetch consumer depends on. The published JSON
 * is decoded and asserted structurally so any drift — a renamed key, or a leaked IBAN / holder name /
 * BIC / alias — fails CI here. The detail is fetched by the client, never carried in the broadcast.
 *
 * @internal
 */
#[CoversClass(RefreshRealtimeOnBankAccountChanged::class)]
final class RefreshRealtimeOnBankAccountChangedTest extends TestCase
{
    private const string ACCOUNT_ID = BankAccountMother::DEFAULT_ID;

    private const string BANK_ID = BankAccountMother::DEFAULT_BANK_ID;

    private ?Update $captured = null;

    public function testPublishesCreatedToCollectionBankAndAccountTopicsAsPrivateWithMinimalPayload(): void
    {
        $this->handler()->onBankAccountCreated(BankAccountCreatedDomainEventMother::create(
            self::ACCOUNT_ID,
            BankAccountSnapshotMother::create(self::BANK_ID, 'ACTIVE'),
        ));

        $update = $this->capturedUpdate();
        $this->assertSame(
            [
                MercureBankAccountTopic::COLLECTION,
                MercureBankAccountTopic::forBank(self::BANK_ID),
                MercureBankAccountTopic::forAccount(self::ACCOUNT_ID),
            ],
            $update->getTopics(),
        );
        $this->assertTrue($update->isPrivate());
        $this->assertSame(
            ['type' => 'bank_account.created', 'id' => self::ACCOUNT_ID, 'bankId' => self::BANK_ID],
            $this->decoded($update),
        );
    }

    public function testPublishesUpdatedToCollectionBankAndAccountTopics(): void
    {
        $this->handler()->onBankAccountUpdated(BankAccountUpdatedDomainEventMother::create(
            self::ACCOUNT_ID,
            BankAccountSnapshotMother::create(self::BANK_ID, 'INACTIVE'),
        ));

        $update = $this->capturedUpdate();
        $this->assertSame(
            [
                MercureBankAccountTopic::COLLECTION,
                MercureBankAccountTopic::forBank(self::BANK_ID),
                MercureBankAccountTopic::forAccount(self::ACCOUNT_ID),
            ],
            $update->getTopics(),
        );
        $this->assertSame(
            ['type' => 'bank_account.updated', 'id' => self::ACCOUNT_ID, 'bankId' => self::BANK_ID],
            $this->decoded($update),
        );
    }

    public function testPublishesDeletedToCollectionBankAndAccountTopics(): void
    {
        $this->handler()->onBankAccountDeleted(BankAccountDeletedDomainEventMother::create(
            self::ACCOUNT_ID,
            BankAccountSnapshotMother::create(self::BANK_ID, 'CLOSED'),
        ));

        $update = $this->capturedUpdate();
        $this->assertSame(
            [
                MercureBankAccountTopic::COLLECTION,
                MercureBankAccountTopic::forBank(self::BANK_ID),
                MercureBankAccountTopic::forAccount(self::ACCOUNT_ID),
            ],
            $update->getTopics(),
        );
        $this->assertSame(
            ['type' => 'bank_account.deleted', 'id' => self::ACCOUNT_ID, 'bankId' => self::BANK_ID],
            $this->decoded($update),
        );
    }

    /**
     * At-least-once delivery may replay the same event. Re-handling must publish a byte-identical
     * Update (clients reconcile by refetching — re-applying is a no-op once in sync).
     */
    public function testRehandlingTheSameEventPublishesAnIdenticalUpdate(): void
    {
        $payloads = [];
        $hub = $this->createMock(HubInterface::class);
        $hub->expects($this->exactly(2))
            ->method('publish')
            ->willReturnCallback(static function (Update $update) use (&$payloads): string {
                $payloads[] = $update->getData();

                return 'id';
            })
        ;

        $handler = new RefreshRealtimeOnBankAccountChanged($hub);
        $event = BankAccountUpdatedDomainEventMother::create(
            self::ACCOUNT_ID,
            BankAccountSnapshotMother::create(self::BANK_ID),
        );

        $handler->onBankAccountUpdated($event);
        $handler->onBankAccountUpdated($event);

        $this->assertCount(2, $payloads);
        $this->assertCount(1, \array_unique($payloads), 're-handling must publish a byte-identical payload');
    }

    private function handler(): RefreshRealtimeOnBankAccountChanged
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects($this->once())
            ->method('publish')
            ->willReturnCallback(function (Update $update): string {
                $this->captured = $update;

                return 'id';
            })
        ;

        return new RefreshRealtimeOnBankAccountChanged($hub);
    }

    private function capturedUpdate(): Update
    {
        $this->assertInstanceOf(Update::class, $this->captured);

        return $this->captured;
    }

    /**
     * @return array<string, mixed>
     */
    private function decoded(Update $update): array
    {
        $data = \json_decode($update->getData(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }
}
