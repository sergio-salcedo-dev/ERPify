<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Application\TotpSecretVault;
use Erpify\Iam\Identity\Domain\Entity\TotpFactor;
use Erpify\Iam\Identity\Domain\Exception\SecondFactorIntegrityFailure;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Crypto\Application\Keystore;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\Exception\DecryptionFailed;
use Erpify\Shared\Crypto\Domain\Exception\DekDestroyed;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The second-factor vault against the container's real envelope and the real `dek_keystore`: the registered
 * reader of the crypto read boundary for `api/src/Iam/Identity/Application/TotpSecretVault.php`.
 *
 * Both crypto outcomes are provoked and both must come back as {@see SecondFactorIntegrityFailure} — the 500 the
 * error tracker sees — with the crypto cause riding along as `previous`: `dek-destroyed` is corruption on this path
 * (no erasure leaves a factor row behind its destroyed key) and `decryption-failed` is a ciphertext that does not
 * authenticate under its scope and its identity.
 *
 * Everything runs inside a rolled-back transaction, so neither the minted key nor the tombstone survives the test:
 * a tombstone with no erasure evidence left in the database would read as a divergence to the subject-erasure
 * reconciler.
 *
 * @internal
 */
#[CoversClass(TotpSecretVault::class)]
final class TotpSecretVaultFunctionalTest extends KernelTestCase
{
    use ResolvesContainerServices;

    private const string SECRET = 'twenty-bytes-secret!';

    private Connection $connection;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = $this->service(EntityManagerInterface::class)->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    #[Test]
    public function aSealedSecretOpensForItsOwnIdentity(): void
    {
        $factor = $this->sealedFactor(Uuid::generate());

        $this->assertSame(self::SECRET, $this->service(TotpSecretVault::class)->open($factor));
    }

    #[Test]
    #[TestDox('A factor whose key was destroyed (dek-destroyed) is an integrity failure, never a readable secret')]
    public function aFactorWhoseKeyWasDestroyedIsAnIntegrityFailure(): void
    {
        $factor = $this->sealedFactor(Uuid::generate());
        $this->service(Keystore::class)->destroy(
            TotpSecretVault::scopeOf($factor->scopeId()),
            DestroyReason::Erasure,
        );

        try {
            $this->service(TotpSecretVault::class)->open($factor);
            $this->fail('A factor whose key is destroyed must not open.');
        } catch (SecondFactorIntegrityFailure $secondFactorIntegrityFailure) {
            $this->assertSame(SecondFactorIntegrityFailure::TYPE, $secondFactorIntegrityFailure->type());
            $this->assertInstanceOf(DekDestroyed::class, $secondFactorIntegrityFailure->getPrevious());
        }
    }

    #[Test]
    #[TestDox('A ciphertext moved to another identity (decryption-failed) is an integrity failure, never opened')]
    public function aCiphertextMovedToAnotherIdentityIsAnIntegrityFailure(): void
    {
        $original = $this->sealedFactor(Uuid::generate());
        $moved = TotpFactor::enrol(
            Uuid::generate(),
            Uuid::generate(),
            $original->scopeId(),
            $original->sealedSecret(),
            SystemClock::now(),
        );

        try {
            $this->service(TotpSecretVault::class)->open($moved);
            $this->fail('A ciphertext sealed for one identity must not open for another.');
        } catch (SecondFactorIntegrityFailure $secondFactorIntegrityFailure) {
            $this->assertSame(SecondFactorIntegrityFailure::TYPE, $secondFactorIntegrityFailure->type());
            $this->assertInstanceOf(DecryptionFailed::class, $secondFactorIntegrityFailure->getPrevious());
        }
    }

    private function sealedFactor(string $userId): TotpFactor
    {
        $scopeId = Uuid::generate();

        return TotpFactor::enrol(
            Uuid::generate(),
            $userId,
            $scopeId,
            $this->service(TotpSecretVault::class)->seal($scopeId, $userId, self::SECRET),
            SystemClock::now(),
        );
    }
}
