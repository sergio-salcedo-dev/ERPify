<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\ProveActorPassword;
use Erpify\Iam\Identity\Application\ProveCurrentPassword;
use Erpify\Iam\Identity\Domain\Exception\InvalidCurrentPassword;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What it takes to count as the acting identity re-proving its password: the stored credential matches what
 * the adapter's closure was handed, and in every other state — a wrong value, no identity row, no credential
 * — the answer is the same refusal, so a caller learns nothing about which of them it met.
 *
 * @internal
 */
#[CoversClass(ProveActorPassword::class)]
final class ProveActorPasswordTest extends TestCase
{
    private const string PASSWORD = 'the-current-password';

    #[Test]
    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function theStoredCredentialMatchingPassesTheProof(): void
    {
        $proof = $this->proofFor(new InMemoryUserRepository($this->actor()));

        $proof->ensure(
            UserMother::DEFAULT_ID,
            static fn (HashedPassword $stored): bool => self::PASSWORD === $stored->toString(),
        );
    }

    #[Test]
    public function aWrongPasswordIsRefused(): void
    {
        $proof = $this->proofFor(new InMemoryUserRepository($this->actor()));

        $this->expectException(InvalidCurrentPassword::class);

        $proof->ensure(
            UserMother::DEFAULT_ID,
            static fn (HashedPassword $stored): bool => 'not-it' === $stored->toString(),
        );
    }

    #[Test]
    public function anActorWithNoIdentityRowIsAnsweredAsAWrongPasswordAndNothingIsVerified(): void
    {
        $verified = false;
        $proof = $this->proofFor(new InMemoryUserRepository());

        try {
            $proof->ensure(UserMother::DEFAULT_ID, static function () use (&$verified): bool {
                $verified = true;

                return true;
            });
            $this->fail('A missing actor must be refused.');
        } catch (InvalidCurrentPassword) {
            $this->assertFalse($verified, 'the verifier ran for an identity that does not exist');
        }
    }

    private function proofFor(InMemoryUserRepository $users): ProveActorPassword
    {
        return new ProveActorPassword($users, new ProveCurrentPassword());
    }

    private function actor(): \Erpify\Iam\Identity\Domain\Entity\User
    {
        return UserMother::create(password: HashedPassword::fromHash(self::PASSWORD));
    }
}
