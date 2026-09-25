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
use Erpify\Shared\Privacy\Application\PersonReferenceSource;
use Erpify\Tests\Support\ApiSourceFiles;
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
    public function testTheReadIsIssuedOnePageAtATime(string $source, callable $read): void
    {
        $limits = [];
        $cursors = [];

        $recordPage = static function (mixed $parameters) use (&$limits, &$cursors): bool {
            \assert(\is_array($parameters));
            $limits[] = $parameters['keyset_limit'] ?? null;
            $cursors[] = $parameters['keyset_after'] ?? null;

            return true;
        };

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(3))
            ->method('fetchFirstColumn')
            ->with($this->anything(), $this->callback($recordPage))
            ->willReturnOnConsecutiveCalls(['a'], ['b'], [])
        ;

        $this->assertSame(['a', 'b'], $read($connection));
        $this->assertSame([1, 1, 1], $limits, $source . ': every statement binds the page size as its limit');
        $this->assertSame(
            [null, 'a', 'b'],
            $cursors,
            $source . ': each page after the first resumes from the last id the previous one returned',
        );
    }

    /**
     * @return iterable<string, array{class-string, callable(Connection): list<string>}>
     */
    public static function provideTheReadIsIssuedOnePageAtATimeCases(): iterable
    {
        yield 'membership' => [
            DbalMembershipPersonReferences::class,
            static fn (Connection $connection): array => (new DbalMembershipPersonReferences($connection, 1))
                ->retainedPersonIds(),
        ];

        yield 'session' => [
            DbalSessionPersonReferences::class,
            static fn (Connection $connection): array => (new DbalSessionPersonReferences($connection, 1))
                ->retainedPersonIds(),
        ];

        yield 'invitation' => [
            DbalInvitationPersonReferences::class,
            static fn (Connection $connection): array => (new DbalInvitationPersonReferences($connection, 1))
                ->retainedPersonIds(),
        ];

        yield 'password reset token' => [
            DbalPasswordResetTokenPersonReferences::class,
            static fn (Connection $connection): array => (new DbalPasswordResetTokenPersonReferences($connection, 1))
                ->retainedPersonIds(),
        ];

        yield 'recovery secret' => [
            DbalRecoverySecretPersonReferences::class,
            static fn (Connection $connection): array => (new DbalRecoverySecretPersonReferences($connection, 1))
                ->retainedPersonIds(),
        ];

        yield 'audit resource' => [
            DbalPersonResourceReferences::class,
            static fn (Connection $connection): array => (new DbalPersonResourceReferences($connection, 1))
                ->unerasedIdsOfType('User'),
        ];
    }

    /**
     * The cases above are written by hand, so a seventh source would be unpaged and every case still green.
     * Every class under `api/src` implementing {@see PersonReferenceSource} must therefore be one of them.
     */
    public function testEveryPersonReferenceSourceIsCovered(): void
    {
        $covered = \array_map(
            static fn (array $case): string => $case[0],
            \iterator_to_array(self::provideTheReadIsIssuedOnePageAtATimeCases()),
        );

        $implementers = [];
        $root = ApiSourceFiles::root();

        foreach (ApiSourceFiles::phpFiles($root) as $file) {
            $source = \file_get_contents($file->getPathname());

            if (false === $source || !\str_contains($source, 'PersonReferenceSource')) {
                continue;
            }

            $fqcn = 'Erpify\\' . \str_replace('/', '\\', \substr($file->getPathname(), \strlen($root) + 1, -4));

            if (\class_exists($fqcn) && \is_subclass_of($fqcn, PersonReferenceSource::class)) {
                $implementers[] = $fqcn;
            }
        }

        $this->assertNotSame([], $implementers, 'the sweep found no source at all, so it proves nothing');
        $this->assertSame([], \array_values(\array_diff($implementers, $covered)), 'sources with no paging case');
    }
}
