<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Crypto;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Crypto\Application\WrappedDek;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\DekDestroyed;
use Erpify\Shared\Crypto\Domain\KeyState;
use Erpify\Shared\Crypto\Infrastructure\Persistence\DbalKeystore;
use Erpify\Shared\Crypto\Infrastructure\ScopeKeyring;
use Erpify\Shared\Crypto\Infrastructure\SodiumEnvelopeEncryptor;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The keystore's serialisation between two writers, against a real Postgres: the row lock `lockState()` takes
 * holds off a destroy, an uncommitted mint holds off a destroy until it commits, and a tombstone committed
 * first refuses the mint that arrives after it.
 *
 * Two transactions have to overlap, so connection A is the kernel's and connection B a second one, opened from
 * the same parameters. Sequencing them in one process needs no `pcntl`: B only has to be BLOCKED while A holds
 * the row, and `lock_timeout` turns "blocked" into an outcome (SQLSTATE 55P03) instead of a hung suite. A
 * blocked B is then retried after A ends, which is the other half of each case.
 *
 * The fixtures are COMMITTED where B has to see them, and the rows of every scope a case created are removed
 * in `tearDown()` however it ends, so the shared database is left as it was found.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(DbalKeystore::class)]
final class DbalKeystoreConcurrencyFunctionalTest extends KernelTestCase
{
    private const string KEK = '0123456789abcdef0123456789abcdef';

    private const string LOCK_NOT_AVAILABLE = '55P03';

    private Connection $first;

    private ?Connection $second = null;

    /** @var list<string> */
    private array $scopes = [];

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->first = $entityManager->getConnection();
    }

    protected function tearDown(): void
    {
        if (isset($this->first) && $this->first->isTransactionActive()) {
            $this->first->rollBack();
        }

        if (isset($this->first)) {
            foreach ($this->scopes as $scope) {
                $this->secondConnection()->executeStatement(
                    'DELETE FROM dek_keystore WHERE encryption_scope_id = :scope',
                    ['scope' => $scope],
                );
            }
        }

        $this->second?->close();
        parent::tearDown();
    }

    public function testAnOpenRowLockHoldsOffADestroyUntilItsTransactionEnds(): void
    {
        $scope = $this->newScope();
        $this->secondKeystore()->store($scope, new WrappedDek('live-key', 1));

        $this->first->beginTransaction();
        $this->assertSame(KeyState::Live, (new DbalKeystore($this->first))->lockState($scope));

        $this->assertDestroyIsBlocked($scope);
        $this->assertInstanceOf(
            WrappedDek::class,
            $this->secondKeystore()->wrappedDekFor($scope),
            'the blocked destroy changed nothing',
        );

        $this->first->commit();

        $this->assertTrue($this->secondKeystore()->destroy($scope, DestroyReason::Erasure));
        $this->assertSame('erasure', $this->reasonOf($scope));
    }

    public function testAnUncommittedMintHoldsOffADestroyAndIsDestroyedOnceItCommits(): void
    {
        $scope = $this->newScope();

        $this->first->beginTransaction();
        (new DbalKeystore($this->first))->store($scope, new WrappedDek('live-key', 1));

        $this->assertDestroyIsBlocked($scope);

        $this->first->commit();

        $this->assertTrue($this->secondKeystore()->destroy($scope, DestroyReason::Erasure), 'it destroys the mint');
        $this->assertNull($this->secondKeystore()->wrappedDekFor($scope));
        $this->assertSame(KeyState::Tombstoned, $this->lockStateOnSecond($scope));
        $this->assertNull($this->wrappedDekColumnOf($scope), 'the key material is gone, the row is the tombstone');
    }

    public function testATombstoneCommittedFirstRefusesTheMintThatArrivesAfterIt(): void
    {
        $scope = $this->newScope();

        $this->assertTrue($this->secondKeystore()->destroy($scope, DestroyReason::AbortedUpload), 'closes the absent');

        $this->first->beginTransaction();
        $encryptor = new SodiumEnvelopeEncryptor(new ScopeKeyring(new DbalKeystore($this->first), self::KEK));

        try {
            $encryptor->encrypt($scope, 'late', 'aad');
            $this->fail('A late writer must not mint under a tombstone.');
        } catch (DekDestroyed) {
            $this->assertSame(KeyState::Tombstoned, (new DbalKeystore($this->first))->lockState($scope));
        } finally {
            $this->first->rollBack();
        }

        $this->assertSame('aborted-upload', $this->reasonOf($scope), 'and the tombstone is untouched');
    }

    private function assertDestroyIsBlocked(EncryptionScopeId $scope): void
    {
        // Session-scoped: it also bounds the later statements on this connection, which only ever run once
        // the first connection has let go.
        $this->secondConnection()->executeStatement("SET lock_timeout = '200ms'");

        try {
            $this->secondKeystore()->destroy($scope, DestroyReason::Erasure);
            $this->fail('The destroy must wait for the first transaction.');
        } catch (DriverException $driverException) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $driverException->getSQLState());
        }
    }

    private function newScope(): EncryptionScopeId
    {
        $scope = EncryptionScopeId::forDocument(Uuid::generate());
        $this->scopes[] = $scope->toString();

        return $scope;
    }

    private function secondKeystore(): DbalKeystore
    {
        return new DbalKeystore($this->secondConnection());
    }

    private function lockStateOnSecond(EncryptionScopeId $scope): KeyState
    {
        $connection = $this->secondConnection();
        $connection->beginTransaction();

        try {
            return (new DbalKeystore($connection))->lockState($scope);
        } finally {
            $connection->rollBack();
        }
    }

    private function reasonOf(EncryptionScopeId $scope): ?string
    {
        $reason = $this->secondConnection()->fetchOne(
            'SELECT destroy_reason FROM dek_keystore WHERE encryption_scope_id = :scope',
            ['scope' => $scope->toString()],
        );

        return \is_string($reason) ? $reason : null;
    }

    private function wrappedDekColumnOf(EncryptionScopeId $scope): ?string
    {
        $value = $this->secondConnection()->fetchOne(
            'SELECT wrapped_dek FROM dek_keystore WHERE encryption_scope_id = :scope',
            ['scope' => $scope->toString()],
        );

        return \is_string($value) ? $value : null;
    }

    private function secondConnection(): Connection
    {
        if (!$this->second instanceof Connection) {
            $this->second = DriverManager::getConnection($this->first->getParams());
        }

        return $this->second;
    }
}
