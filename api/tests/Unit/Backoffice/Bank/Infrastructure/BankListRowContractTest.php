<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Infrastructure;

use DateTimeInterface;
use Erpify\Backoffice\Bank\Application\BankAccountCountEnricher;
use Erpify\Backoffice\Bank\Application\BankWithAccountCount;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use Erpify\Backoffice\Bank\Infrastructure\Http\BankResourceMapper;
use Erpify\Backoffice\Bank\Infrastructure\Messenger\RefreshRealtimeOnBankChanged;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Support\Dig;
use Erpify\Tests\Support\PHPUnit\FreezeSystemClockExtension;
use Erpify\Tests\Unit\Backoffice\Bank\Application\InMemoryBankAccountCounter;
use Erpify\Tests\Unit\Backoffice\Bank\Application\InMemoryBankLogoReader;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity\Mother\BankMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Pins the realtime "list row" to the HTTP one so the two parallel wire shapes cannot drift apart.
 * The Mercure publisher ({@see RefreshRealtimeOnBankChanged}) hand-builds the `bank` row from
 * domain-event primitives, while `GET /banks` owns it through {@see BankResourceMapper::toListResource()};
 * both feed the same PWA `BankPrimitives` decoder and so must carry the same key set, order, types and
 * values.
 *
 * Both rows are derived from a single {@see Bank} aggregate — the HTTP one from the entity via the
 * mapper, the realtime one from the very event that same aggregate recorded — so full equality is a
 * real production invariant, not a fixture coincidence: a field added, renamed, retyped, or
 * reformatted on one side only fails here. The HTTP side is asserted at the {@see BankListResource}
 * level — its public-property shape IS its wire shape, since these resource DTOs carry no serializer
 * groups or name converter (the entity is never serialized; see the api-resource-dtos ADR) — and the
 * realtime side at the decoded Mercure bytes. `onBankUpdated` reuses the same `bankPayload()` builder,
 * so pinning the created row pins the shape for both lifecycle events.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") both wire paths, their collaborators and the clock the logo case moves
 */
#[CoversClass(RefreshRealtimeOnBankChanged::class)]
#[CoversClass(BankResourceMapper::class)]
final class BankListRowContractTest extends TestCase
{
    private const int ACCOUNT_COUNT = 7;

    public function testRealtimeBankRowMatchesHttpListRow(): void
    {
        $bank = BankMother::create();

        $events = $bank->pullDomainEvents();
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf(BankCreatedDomainEvent::class, $event);

        $this->assertSame($this->httpListRow($bank), $this->realtimeBankRow($event, new InMemoryBankLogoReader()));
    }

    /**
     * The logo is the one field the realtime side does not take from the event, so it is the one that could drift:
     * both sides carry it, with the same value, and `null` is pinned for a bank without one.
     *
     * The logo change lands at a later instant than the event it is published with, so the one field the two rows may
     * disagree on is `updatedAt`: the realtime row keeps the event's, the HTTP row reads the bank's. That divergence is
     * asserted rather than hidden behind a clock that never moves.
     */
    public function testBothRowsCarryTheLogoIdAndNullForABankWithoutOne(): void
    {
        $bank = BankMother::create();
        $events = $bank->pullDomainEvents();
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf(BankCreatedDomainEvent::class, $event);

        $this->assertNull(Dig::value($this->httpListRow($bank), 'logoImageId'));

        $logo = '0190b1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c';
        SystemClock::set(FixedClock::at(SystemClock::now()->modify('+1 second')->format(DateTimeInterface::ATOM)));

        try {
            $bank->changeLogo($logo);
        } finally {
            FreezeSystemClockExtension::pin();
        }

        $http = $this->httpListRow($bank);
        $realtime = $this->realtimeBankRow($event, new InMemoryBankLogoReader([$bank->getId() ?? '' => $logo]));

        $this->assertSame($logo, Dig::value($http, 'logoImageId'));
        $this->assertNotSame($http['updatedAt'] ?? null, $realtime['updatedAt'] ?? null);
        unset($http['updatedAt'], $realtime['updatedAt']);
        $this->assertSame($http, $realtime);
    }

    /**
     * @return array<string, mixed>
     */
    private function httpListRow(Bank $bank): array
    {
        $mapper = new BankResourceMapper();

        /** @var array<string, mixed> $row */
        $row = \get_object_vars($mapper->toListResource(new BankWithAccountCount($bank, self::ACCOUNT_COUNT)));

        return $row;
    }

    /**
     * Runs the publisher against the event and returns the decoded `bank` row from the published
     * Mercure Update — the actual wire bytes, not the handler's internal array.
     *
     * @return array<string, mixed>
     */
    private function realtimeBankRow(BankCreatedDomainEvent $event, InMemoryBankLogoReader $logos): array
    {
        $captured = null;
        $hub = $this->createMock(HubInterface::class);
        $hub->expects($this->once())
            ->method('publish')
            ->willReturnCallback(static function (Update $update) use (&$captured): string {
                $captured = $update;

                return 'id';
            })
        ;

        $enricher = new BankAccountCountEnricher(
            new InMemoryBankAccountCounter([$event->aggregateId() => self::ACCOUNT_COUNT]),
        );
        (new RefreshRealtimeOnBankChanged($hub, $enricher, $logos))->onBankCreated($event);

        $this->assertInstanceOf(Update::class, $captured);
        $decoded = \json_decode($captured->getData(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('bank', $decoded);
        $this->assertIsArray($decoded['bank']);

        /** @var array<string, mixed> $row */
        $row = $decoded['bank'];

        return $row;
    }
}
