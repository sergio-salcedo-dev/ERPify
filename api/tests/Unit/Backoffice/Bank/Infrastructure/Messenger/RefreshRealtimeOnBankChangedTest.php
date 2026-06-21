<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Infrastructure\Messenger;

use Erpify\Backoffice\Bank\Application\BankAccountCountEnricher;
use Erpify\Backoffice\Bank\Domain\MercureBankTopic;
use Erpify\Backoffice\Bank\Infrastructure\Messenger\RefreshRealtimeOnBankChanged;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountCounter;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity\Mother\BankMother;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Event\Mother\BankCreatedDomainEventMother;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Event\Mother\BankDeletedDomainEventMother;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Event\Mother\BankSnapshotMother;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Event\Mother\BankUpdatedDomainEventMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Locks the exact realtime payload contract the PWA `isBankPrimitives` consumer
 * depends on. The published JSON is decoded and asserted structurally (not via
 * substrings) so any drift in `BankCreatedDomainEvent::toPrimitives()` — a
 * renamed key, a dropped field, or a leaked `logo*` / `storedObject*` field —
 * fails CI here instead of silently producing a `null` the consumer drops.
 *
 * @internal
 */
#[CoversClass(RefreshRealtimeOnBankChanged::class)]
final class RefreshRealtimeOnBankChangedTest extends TestCase
{
    private const string BANK_ID = BankMother::DEFAULT_ID;

    private const string CREATED_AT = '2026-01-01T00:00:00+00:00';

    private const string UPDATED_AT = '2026-01-02T00:00:00+00:00';

    private ?Update $captured = null;

    public function testPublishesCreatedToCollectionTopicAsPrivate(): void
    {
        // Pass the optional logo / stored-object metadata to prove it never
        // leaves the handler — only the six public bank fields ship.
        $this->handler()->onBankCreated(BankCreatedDomainEventMother::create(
            self::BANK_ID,
            BankSnapshotMother::create(
                'Acme Savings',
                'ACME',
                self::CREATED_AT,
                self::UPDATED_AT,
                'logo-media-id',
                'logo-content-hash',
                'stored-object-content-hash',
                'image/png',
            ),
        ));

        $update = $this->capturedUpdate();
        $this->assertSame([MercureBankTopic::COLLECTION], $update->getTopics());
        $this->assertTrue($update->isPrivate());
        $this->assertSame([
            'type' => 'bank.created',
            'bank' => [
                'id' => self::BANK_ID,
                'name' => 'Acme Savings',
                'shortName' => 'ACME',
                'createdAt' => self::CREATED_AT,
                'updatedAt' => self::UPDATED_AT,
                'accountCount' => 0,
            ],
        ], $this->decoded($update));
    }

    public function testPublishesUpdatedToCollectionAndPerBankTopics(): void
    {
        $this->handler()->onBankUpdated(BankUpdatedDomainEventMother::create(
            self::BANK_ID,
            BankSnapshotMother::create(
                'Acme Renamed',
                'ACME',
                self::CREATED_AT,
                self::UPDATED_AT,
                'logo-media-id',
                'logo-content-hash',
                'stored-object-content-hash',
                'image/png',
            ),
        ));

        $update = $this->capturedUpdate();
        $this->assertSame(
            [MercureBankTopic::COLLECTION, MercureBankTopic::forBank(self::BANK_ID)],
            $update->getTopics(),
        );
        $this->assertTrue($update->isPrivate());
        $this->assertSame([
            'type' => 'bank.updated',
            'bank' => [
                'id' => self::BANK_ID,
                'name' => 'Acme Renamed',
                'shortName' => 'ACME',
                'createdAt' => self::CREATED_AT,
                'updatedAt' => self::UPDATED_AT,
                'accountCount' => 0,
            ],
        ], $this->decoded($update));
    }

    public function testPublishesDeletedWithIdOnlyToCollectionAndPerBankTopics(): void
    {
        $this->handler()->onBankDeleted(
            BankDeletedDomainEventMother::create(self::BANK_ID),
        );

        $update = $this->capturedUpdate();
        $this->assertSame(
            [MercureBankTopic::COLLECTION, MercureBankTopic::forBank(self::BANK_ID)],
            $update->getTopics(),
        );
        $this->assertTrue($update->isPrivate());
        $this->assertSame(
            ['type' => 'bank.deleted', 'id' => self::BANK_ID],
            $this->decoded($update),
        );
    }

    /**
     * At-least-once delivery may replay the same event. Re-handling with the same
     * DB state must publish a byte-identical Update (the client reconciles by id —
     * re-applying is a no-op once in sync).
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

        $accountCounts = $this->createStub(BankAccountCounter::class);
        $accountCounts->method('countsByBankIds')->willReturn([self::BANK_ID => 3]);

        $handler = new RefreshRealtimeOnBankChanged($hub, new BankAccountCountEnricher($accountCounts));
        $event = BankUpdatedDomainEventMother::create(
            self::BANK_ID,
            BankSnapshotMother::create(createdAt: self::CREATED_AT, updatedAt: self::UPDATED_AT),
        );

        $handler->onBankUpdated($event);
        $handler->onBankUpdated($event);

        $this->assertCount(2, $payloads);
        $this->assertCount(1, \array_unique($payloads), 're-handling must publish a byte-identical payload');
    }

    private function handler(int $accountCount = 0): RefreshRealtimeOnBankChanged
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects($this->once())
            ->method('publish')
            ->willReturnCallback(function (Update $update): string {
                $this->captured = $update;

                return 'id';
            })
        ;

        $accountCounts = $this->createStub(BankAccountCounter::class);
        $accountCounts->method('countsByBankIds')->willReturn(
            $accountCount > 0 ? [self::BANK_ID => $accountCount] : [],
        );

        return new RefreshRealtimeOnBankChanged($hub, new BankAccountCountEnricher($accountCounts));
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
