<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\RehashPasswordBestEffort;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(RehashPasswordBestEffort::class)]
final class RehashPasswordBestEffortTest extends TestCase
{
    private const string REHASHED = 'the-same-secret-at-the-configured-cost';

    public function testSwapsTheVerifiedCredentialForItsReEncodingInsideTheTransaction(): void
    {
        $user = UserMother::create();
        $users = new InMemoryUserRepository($user);
        $transactions = new InlineTransactionManager();
        $users->onReplacePasswordHash = function () use ($transactions): void {
            $this->assertFalse($transactions->committed, 'the swap is decided inside the unit of work');
        };

        $this->rehasher($users, $transactions)
            ->rehash(UserMother::DEFAULT_ID, UserMother::DEFAULT_HASH, self::REHASHED)
        ;

        $this->assertSame([UserMother::DEFAULT_ID], $users->replacePasswordHashCalls);
        $this->assertTrue($transactions->committed);
        $this->assertSame(self::REHASHED, $user->passwordHash()?->toString());
        $this->assertSame([], $user->pullDomainEvents(), 'a re-encoding records no fact');
    }

    public function testLeavesACredentialReplacedSinceTheLoginVerifiedItAlone(): void
    {
        $user = UserMother::create(password: HashedPassword::fromHash('set-by-a-reset-that-committed-first'));
        $users = new InMemoryUserRepository($user);

        $this->rehasher($users)->rehash(UserMother::DEFAULT_ID, UserMother::DEFAULT_HASH, self::REHASHED);

        $this->assertSame('set-by-a-reset-that-committed-first', $user->passwordHash()?->toString());
    }

    /**
     * The login has already succeeded, so a store fault is swallowed. The report names the failure's class and
     * nothing it carried, since the statement that failed held both hashes.
     */
    public function testAStoreFaultIsSwallowedAndReportedWithoutEitherHash(): void
    {
        $user = UserMother::create();
        $users = new InMemoryUserRepository($user);
        $users->onReplacePasswordHash = static function (): never {
            throw new RuntimeException(
                'statement failed with parameters ' . self::REHASHED . ' / ' . UserMother::DEFAULT_HASH,
            );
        };
        $logger = new RecordingLogger();

        (new RehashPasswordBestEffort($users, new InlineTransactionManager(), $logger))
            ->rehash(UserMother::DEFAULT_ID, UserMother::DEFAULT_HASH, self::REHASHED)
        ;

        $this->assertSame(UserMother::DEFAULT_HASH, $user->passwordHash()?->toString());
        $this->assertCount(1, $logger->records);
        $this->assertSame(['exception_class' => RuntimeException::class], $logger->records[0]['context']);

        $serialised = \serialize($logger->records);
        $this->assertStringNotContainsString(self::REHASHED, $serialised);
        $this->assertStringNotContainsString(UserMother::DEFAULT_HASH, $serialised);
    }

    public function testAHashTheValueObjectRefusesIsSwallowedBeforeTheStoreIsAsked(): void
    {
        $users = new InMemoryUserRepository(UserMother::create());
        $logger = new RecordingLogger();

        (new RehashPasswordBestEffort($users, new InlineTransactionManager(), $logger))
            ->rehash(UserMother::DEFAULT_ID, UserMother::DEFAULT_HASH, '')
        ;

        $this->assertSame([], $users->replacePasswordHashCalls);
        $this->assertCount(1, $logger->records);
    }

    private function rehasher(
        InMemoryUserRepository $users,
        InlineTransactionManager $transactions = new InlineTransactionManager(),
    ): RehashPasswordBestEffort {
        return new RehashPasswordBestEffort($users, $transactions, new RecordingLogger());
    }
}
