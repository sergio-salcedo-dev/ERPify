<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Closure;
use Erpify\Iam\Identity\Application\ProveCurrentPassword;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Exception\InvalidCurrentPassword;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The credential re-proof shared by the three writes under `/me`, and the reason it is one class: all three
 * of its refusals answer with ONE type.
 *
 * A wrong password, an identity carrying no credential at all, and a stored credential the domain cannot read
 * are three different situations for the operator and exactly one for the caller — telling them apart on the
 * wire would say whether an account has a password set, which is a fact about the account nobody who failed
 * to prove it may learn.
 *
 * @internal
 */
#[CoversClass(ProveCurrentPassword::class)]
final class ProveCurrentPasswordTest extends TestCase
{
    /**
     * @param Closure(): User               $identity
     * @param Closure(HashedPassword): bool $verify
     */
    #[Test]
    #[DataProvider('provideEveryRefusalAnswersWithTheSameTypeCases')]
    public function everyRefusalAnswersWithTheSameType(Closure $identity, Closure $verify): void
    {
        try {
            (new ProveCurrentPassword())->ensure($identity(), $verify);
            $this->fail('a refusal path did not refuse');
        } catch (InvalidCurrentPassword $invalidCurrentPassword) {
            $this->assertSame('invalid-current-password', $invalidCurrentPassword->type());
        }
    }

    /**
     * The three ways it refuses. Declared together because the claim is that they are INDISTINGUISHABLE, and
     * a claim about a set is not made by three cases that never compare.
     *
     * @return iterable<string, array{Closure(): User, Closure(HashedPassword): bool}>
     */
    public static function provideEveryRefusalAnswersWithTheSameTypeCases(): iterable
    {
        yield 'the submitted password is not the stored one' => [
            static fn (): User => UserMother::create(password: HashedPassword::fromHash('stored-hash')),
            static fn (HashedPassword $stored): bool => 'never-this' === $stored->toString(),
        ];
        yield 'the identity carries no credential' => [
            // An invited identity has never set one, so `passwordHash()` answers null.
            static fn (): User => UserMother::invited(),
            static fn (HashedPassword $stored): bool => '' !== $stored->toString(),
        ];
        yield 'the stored credential is unreadable' => [
            self::identityWithACorruptStoredHash(...),
            static fn (HashedPassword $stored): bool => '' !== $stored->toString(),
        ];
    }

    #[Test]
    public function theCorrectPasswordIsProvedAgainstTheSTOREDCredential(): void
    {
        // The seam is handed the stored credential rather than being called blind: one that took no argument
        // would satisfy this class while proving nothing about a caller that stopped passing it along.
        $seen = [];
        $user = UserMother::create(password: HashedPassword::fromHash('stored-hash'));

        (new ProveCurrentPassword())->ensure($user, static function (HashedPassword $stored) use (&$seen): bool {
            $seen[] = $stored->toString();

            return true;
        });

        $this->assertSame(['stored-hash'], $seen);
    }

    #[Test]
    public function anUnreadableCredentialIsARefusalRatherThanA500(): void
    {
        // `passwordHash()` raises on a corrupt row, and the `??` below it never fires. Without the catch, a
        // marker-less exception lands as a 500 where this plainly intends the same refusal as a wrong
        // password — and a 500 is an oracle, because only some accounts produce it.
        $this->expectException(InvalidCurrentPassword::class);

        (new ProveCurrentPassword())->ensure(
            self::identityWithACorruptStoredHash(),
            static fn (HashedPassword $stored): bool => '' !== $stored->toString(),
        );
    }

    /**
     * A row Doctrine hydrated with a value the domain refuses. Written through the property rather than
     * through the factory because the factory is exactly what stops this state existing — the branch is for
     * a row that reached the database before it did, or beside it.
     */
    private static function identityWithACorruptStoredHash(): User
    {
        $user = UserMother::create(password: HashedPassword::fromHash('stored-hash'));
        (new ReflectionProperty(User::class, 'passwordHash'))->setValue($user, '');

        return $user;
    }
}
