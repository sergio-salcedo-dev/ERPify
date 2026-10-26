<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Domain;

use Erpify\Backoffice\Bank\Domain\Event\BankDeletedDomainEvent;
use Erpify\Iam\Identity\Domain\Event\PasswordChanged;
use Erpify\Iam\Identity\Domain\Event\PasswordResetCompleted;
use Erpify\Iam\Identity\Domain\Event\PasswordResetRequested;
use Erpify\Iam\Identity\Domain\Event\RecoverySecretMinted;
use Erpify\Iam\Identity\Domain\Event\RecoverySecretRedeemed;
use Erpify\Iam\Identity\Domain\Event\RecoverySecretRevoked;
use Erpify\Iam\Identity\Domain\Event\UserDeactivated;
use Erpify\Iam\Identity\Domain\Event\UserInvitationRevoked;
use Erpify\Iam\Identity\Domain\Event\UserSuspended;
use Erpify\Iam\Invitation\Domain\Event\InvitationAccepted;
use Erpify\Iam\Invitation\Domain\Event\InvitationCreated;
use Erpify\Iam\Invitation\Domain\Event\InvitationExpired;
use Erpify\Iam\Invitation\Domain\Event\InvitationResent;
use Erpify\Iam\Invitation\Domain\Event\InvitationRevoked;
use Erpify\Iam\Invitation\Domain\Event\InvitationSent;
use Erpify\Iam\Session\Domain\Event\AllSessionsRevoked;
use Erpify\Shared\Event\Domain\DomainEvent;
use Erpify\Shared\Event\Domain\Exception\CorruptEventStoreRow;
use Erpify\Shared\Images\Domain\Event\ImageDeletionRequested;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every event whose schema declares no payload member rebuilds from an empty body and refuses any other: a
 * stored member means the row was written under a schema this class does not speak.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(DomainEvent::class)]
#[CoversClass(CorruptEventStoreRow::class)]
final class EmptyPayloadEventTest extends TestCase
{
    private const string AGGREGATE_ID = '01900000-0000-7000-8000-000000000001';

    private const string EVENT_ID = '01900000-0000-7000-8000-000000000002';

    private const string OCCURRED_ON = '2017-03-08T14:22:37+00:00';

    /**
     * @param class-string<DomainEvent> $class
     */
    #[DataProvider('provideEmptyPayloadEventCases')]
    public function testRebuildsFromAnEmptyBody(string $class): void
    {
        $event = $class::fromPrimitives(self::AGGREGATE_ID, [], self::EVENT_ID, self::OCCURRED_ON);

        $this->assertInstanceOf($class, $event);
        $this->assertSame(self::AGGREGATE_ID, $event->aggregateId());
        $this->assertSame(self::EVENT_ID, $event->eventId());
        $this->assertSame([], $event->toPrimitives());
    }

    /**
     * @param class-string<DomainEvent> $class
     */
    #[DataProvider('provideEmptyPayloadEventCases')]
    public function testRefusesABodyCarryingAnyMember(string $class): void
    {
        try {
            $class::fromPrimitives(self::AGGREGATE_ID, ['invitedUserId' => 'x'], self::EVENT_ID, self::OCCURRED_ON);
            $this->fail('a payload member this schema version does not declare must stop the replay');
        } catch (CorruptEventStoreRow $corruptEventStoreRow) {
            $this->assertSame(
                ['event_name' => $class::eventName(), 'member' => 'invitedUserId'],
                $corruptEventStoreRow->context(),
            );
        }
    }

    /**
     * @return iterable<string, array{class-string<DomainEvent>}>
     */
    public static function provideEmptyPayloadEventCases(): iterable
    {
        $classes = [
            BankDeletedDomainEvent::class,
            ImageDeletionRequested::class,
            InvitationAccepted::class,
            InvitationCreated::class,
            InvitationExpired::class,
            InvitationResent::class,
            InvitationRevoked::class,
            InvitationSent::class,
            PasswordChanged::class,
            PasswordResetCompleted::class,
            PasswordResetRequested::class,
            RecoverySecretMinted::class,
            RecoverySecretRedeemed::class,
            RecoverySecretRevoked::class,
            UserDeactivated::class,
            UserInvitationRevoked::class,
            UserSuspended::class,
            AllSessionsRevoked::class,
        ];

        foreach ($classes as $class) {
            yield $class::eventName() => [$class];
        }
    }
}
