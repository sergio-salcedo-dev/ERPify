<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Event;

use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Event\UserRolesChanged;
use Erpify\Shared\Access\Domain\Role;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(UserRolesChanged::class)]
final class UserRolesChangedTest extends TestCase
{
    private const string USER_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a80';

    private const string EVENT_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a81';

    private const string OCCURRED_ON = '2026-07-19T09:45:00+00:00';

    #[Test]
    public function itIsAUserSubjectedFactCarryingTheResultingSet(): void
    {
        $event = new UserRolesChanged(
            self::USER_ID,
            [Role::EDITOR, Role::AUDIT_READER],
            self::EVENT_ID,
            new DateTimeImmutable(self::OCCURRED_ON),
        );

        $this->assertSame('erpify.iam.identity.roles-changed', $event::eventName());
        $this->assertSame('Iam.Identity', $event::aggregateType());
        $this->assertSame(self::USER_ID, $event->aggregateId());
        $this->assertSame(self::EVENT_ID, $event->eventId());
        $this->assertSame(['roles' => ['EDITOR', 'AUDIT_READER']], $event->toPrimitives());
    }

    #[Test]
    public function fromPrimitivesReconstructsAnIdenticalEvent(): void
    {
        $reconstructed = UserRolesChanged::fromPrimitives(
            self::USER_ID,
            ['roles' => ['MANAGER', 'ADMIN']],
            self::EVENT_ID,
            self::OCCURRED_ON,
        );

        $this->assertSame(self::USER_ID, $reconstructed->aggregateId());
        $this->assertSame(self::EVENT_ID, $reconstructed->eventId());
        $this->assertSame(['roles' => ['MANAGER', 'ADMIN']], $reconstructed->toPrimitives());
        $this->assertSame(self::OCCURRED_ON, $reconstructed->occurredOn()->format('c'));
    }
}
