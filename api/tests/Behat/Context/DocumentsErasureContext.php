<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Behat\Context\Context;
use Behat\Hook\AfterScenario;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Erpify\Tests\Double\Erasure\FakeErasureLedger;

/**
 * Steps for the operator erasure: scripting the ledger into the endings a real one has, setting the ledger override,
 * and
 * reading what the erasure left in the ledger and the keystore. The double is static for the reason the scanner's is —
 * the kernel reboots between requests — so it is cleared around every scenario, and so is the override table.
 */
final class DocumentsErasureContext extends AbstractContext implements Context
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[BeforeScenario]
    public function startWithAHealthyLedger(): void
    {
        FakeErasureLedger::reset();
        $this->entityManager->getConnection()->executeStatement('TRUNCATE erasure_ledger_override');
    }

    #[AfterScenario]
    public function leaveNoLedgerState(): void
    {
        FakeErasureLedger::reset();
        $this->entityManager->getConnection()->executeStatement('TRUNCATE erasure_ledger_override');
    }

    #[Given('the erasure ledger cannot make an entry durable')]
    public function theErasureLedgerCannotMakeAnEntryDurable(): void
    {
        FakeErasureLedger::failWrites();
    }

    #[Given('the erasure ledger write times out after the entry landed')]
    public function theErasureLedgerWriteTimesOutAfterTheEntryLanded(): void
    {
        FakeErasureLedger::timeOutAfterLanding();
    }

    #[Given('the erasure ledger override is set')]
    public function theErasureLedgerOverrideIsSet(): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO erasure_ledger_override (id, reason, record_ref, set_at) VALUES (:id, :reason, :ref, NOW())',
            ['id' => Uuid::generate(), 'reason' => 'ledger_lost', 'ref' => Uuid::generate()],
        );
    }

    #[Then('/^the erasure ledger holds the scope of document "([^"]*)"$/')]
    public function theErasureLedgerHoldsTheScopeOfDocument(string $id): void
    {
        self::assertTrue(FakeErasureLedger::has($this->scopeOf($id)), 'The ledger holds no entry for that document.');
    }

    #[Then('the erasure ledger holds no entry')]
    public function theErasureLedgerHoldsNoEntry(): void
    {
        self::assertSame(
            [],
            \array_values(\array_filter(
                FakeErasureLedger::attempts(),
                FakeErasureLedger::has(...),
            )),
            'The ledger holds an entry.',
        );
    }

    #[Then('/^the key of document "([^"]*)" is tombstoned as an erasure$/')]
    public function theKeyOfDocumentIsTombstonedAsAnErasure(string $id): void
    {
        self::assertSame('erasure', $this->tombstoneOf($id), 'The key is not tombstoned as an erasure.');
    }

    #[Then('/^the key of document "([^"]*)" is not tombstoned$/')]
    public function theKeyOfDocumentIsNotTombstoned(string $id): void
    {
        self::assertNull($this->tombstoneOf($id), 'The key was destroyed.');
    }

    private function tombstoneOf(string $id): ?string
    {
        $reason = $this->entityManager->getConnection()->fetchOne(
            'SELECT destroy_reason FROM dek_keystore WHERE encryption_scope_id = :scope AND destroyed_at IS NOT NULL',
            ['scope' => EncryptionScopeId::forDocument($this->scopeOf($id))->toString()],
        );

        return \is_string($reason) ? $reason : null;
    }

    private function scopeOf(string $id): string
    {
        $scope = $this->entityManager->getConnection()->fetchOne(
            'SELECT scope_id FROM document WHERE id = CAST(:id AS UUID)',
            ['id' => $id],
        );
        self::assertIsString($scope, 'There is no such document.');

        return $scope;
    }
}
