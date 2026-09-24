<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Persistence\Infrastructure;

use Doctrine\DBAL\Connection;
use Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine\DbalPasswordResetTokenPersonReferences;
use Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine\DbalRecoverySecretPersonReferences;
use Erpify\Iam\Invitation\Infrastructure\Persistence\Doctrine\DbalInvitationPersonReferences;
use Erpify\Iam\Session\Infrastructure\Persistence\Doctrine\DbalSessionPersonReferences;
use Erpify\Organization\Membership\Infrastructure\Persistence\Doctrine\DbalMembershipPersonReferences;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalPersonResourceReferences;
use Erpify\Shared\Persistence\Infrastructure\KeysetDistinctIds;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins that every person-reference read is PAGED, which the sources' functional tests cannot see: their
 * containment and ordering assertions stay green over one unbounded `fetchFirstColumn`. Here each source is
 * built with a page size of one over a connection that answers `a`, `b` and then nothing, so a source that
 * stopped paging would issue one statement, bind no limit, and return whatever that single answer held.
 *
 * @internal
 */
#[CoversClass(DbalMembershipPersonReferences::class)]
#[CoversClass(DbalSessionPersonReferences::class)]
#[CoversClass(DbalInvitationPersonReferences::class)]
#[CoversClass(DbalPasswordResetTokenPersonReferences::class)]
#[CoversClass(DbalRecoverySecretPersonReferences::class)]
#[CoversClass(DbalPersonResourceReferences::class)]
#[CoversClass(KeysetDistinctIds::class)]
final class PersonReferenceSourcesPageTheirReadTest extends TestCase
{
    /**
     * @param callable(Connection): list<string> $read
     */
    #[DataProvider('provideTheReadIsIssuedOnePageAtATimeCases')]
    public function testTheReadIsIssuedOnePageAtATime(callable $read): void
    {
        $limits = [];

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(3))
            ->method('fetchFirstColumn')
            ->with($this->anything(), $this->callback(static function (mixed $parameters) use (&$limits): bool {
                \assert(\is_array($parameters));
                $limits[] = $parameters['keyset_limit'] ?? null;

                return true;
            }))
            ->willReturnOnConsecutiveCalls(['a'], ['b'], [])
        ;

        $this->assertSame(['a', 'b'], $read($connection));
        $this->assertSame([1, 1, 1], $limits, 'every statement binds the page size as its limit');
    }

    /**
     * @return iterable<string, array{callable(Connection): list<string>}>
     */
    public static function provideTheReadIsIssuedOnePageAtATimeCases(): iterable
    {
        yield 'membership' => [
            static fn (Connection $connection): array => (new DbalMembershipPersonReferences($connection, 1))
                ->retainedPersonIds(),
        ];

        yield 'session' => [
            static fn (Connection $connection): array => (new DbalSessionPersonReferences($connection, 1))
                ->retainedPersonIds(),
        ];

        yield 'invitation' => [
            static fn (Connection $connection): array => (new DbalInvitationPersonReferences($connection, 1))
                ->retainedPersonIds(),
        ];

        yield 'password reset token' => [
            static fn (Connection $connection): array => (new DbalPasswordResetTokenPersonReferences($connection, 1))
                ->retainedPersonIds(),
        ];

        yield 'recovery secret' => [
            static fn (Connection $connection): array => (new DbalRecoverySecretPersonReferences($connection, 1))
                ->retainedPersonIds(),
        ];

        yield 'audit resource' => [
            static fn (Connection $connection): array => (new DbalPersonResourceReferences($connection, 1))
                ->unerasedIdsOfType('User'),
        ];
    }
}
