<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity;

use DateTimeInterface;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Event\BankUpdatedDomainEvent;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Clock\Infrastructure\SymfonyClock;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity\Mother\BankMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Clock\MockClock;

/**
 * Idempotence of the rename. Equality is decided against the three persisted columns — `name`,
 * `nameNormalized`, `shortName` — never against the two arguments, so trimming and the accent-folded
 * upper-casing of the short name are applied before anything is compared.
 *
 * Every test moves the clock between storing and renaming, which is what makes an `updatedAt` that
 * should not have moved observable.
 *
 * @internal
 */
#[CoversClass(Bank::class)]
final class BankRenameNoOpTest extends TestCase
{
    private const string STORED_AT = '2026-06-14T09:30:00+00:00';

    private const string RENAMED_AT = '2026-06-15T11:00:00+00:00';

    private const string NAME = 'Acme Savings';

    private const string SHORT_NAME = 'ACME';

    protected function tearDown(): void
    {
        SystemClock::reset();

        parent::tearDown();
    }

    public function testRenameWithTheStoredValuesRecordsNothingAndLeavesUpdatedAtUntouched(): void
    {
        $bank = $this->storedBank();

        $bank->rename(self::NAME, self::SHORT_NAME);

        $this->assertNoOp($bank);
    }

    public function testRenameComparesTheTrimmedDisplayNameSoSurroundingWhitespaceIsANoOp(): void
    {
        $bank = $this->storedBank();

        $bank->rename('   Acme Savings   ', self::SHORT_NAME);

        $this->assertNoOp($bank);
    }

    public function testRenameComparesTheCanonicalShortNameSoCaseAndAccentsAreANoOp(): void
    {
        $bank = $this->storedBank();

        $bank->rename(self::NAME, 'acmé');

        $this->assertSame(self::SHORT_NAME, $bank->getShortName());
        $this->assertNoOp($bank);
    }

    public function testRenameOfTheDisplayNameAloneMutatesBumpsUpdatedAtAndRecordsTheUpdatedEvent(): void
    {
        $bank = $this->storedBank();

        $bank->rename('Acme Renamed', self::SHORT_NAME);

        $this->assertSame('Acme Renamed', $bank->getName());
        $this->assertSame('acme renamed', $bank->getNameNormalized());
        $this->assertMutated($bank);
    }

    public function testRenameOfTheDisplayCasingAloneMutatesEvenThoughTheNormalizedTwinIsUnchanged(): void
    {
        // Uniqueness ignores case, so the normalized twin does not move — but the display column is
        // what the UI renders, and the stored casing is exactly what the user asked to change.
        $bank = $this->storedBank();

        $bank->rename('ACME SAVINGS', self::SHORT_NAME);

        $this->assertSame('ACME SAVINGS', $bank->getName());
        $this->assertSame('acme savings', $bank->getNameNormalized());
        $this->assertMutated($bank);
    }

    public function testRenameOfTheShortNameAloneMutatesBumpsUpdatedAtAndRecordsTheUpdatedEvent(): void
    {
        $bank = $this->storedBank();

        $bank->rename(self::NAME, 'ACME2');

        $this->assertSame('ACME2', $bank->getShortName());
        $this->assertMutated($bank);
    }

    /**
     * The canonicalization on the write path is only reachable when the value ALSO changes: an input
     * that canonicalizes to what is stored takes the early return, so every non-canonical input in the
     * cases above stops before the assignment. Without this case both write lines could hand over the
     * raw arguments with the whole suite green.
     */
    public function testRenameWritesTheCanonicalFormsOfAChangedName(): void
    {
        $bank = $this->storedBank();

        $bank->rename('   Banco Uno   ', 'bú');

        $this->assertSame('Banco Uno', $bank->getName());
        $this->assertSame('banco uno', $bank->getNameNormalized());
        $this->assertSame('BU', $bank->getShortName());
        $this->assertMutated($bank);
    }

    public function testRenameRederivesAStaleNormalizedNameEvenWhenTheDisplayNameIsUnchanged(): void
    {
        // Doctrine hydrates the mapped columns directly, so the stored twin is only as current as the
        // normalization rule that wrote it. Comparing the persisted columns — rather than re-deriving
        // from the display name alone — is what lets a rename repair a twin an older rule left behind.
        $bank = $this->storedBank();
        (new ReflectionProperty(Bank::class, 'nameNormalized'))->setValue($bank, 'acme-savings');

        $bank->rename(self::NAME, self::SHORT_NAME);

        $this->assertSame('acme savings', $bank->getNameNormalized());
        $this->assertMutated($bank);
    }

    private function storedBank(): Bank
    {
        SystemClock::set(new SymfonyClock(new MockClock(self::STORED_AT)));

        $bank = BankMother::drained(name: self::NAME, shortName: self::SHORT_NAME);

        SystemClock::set(new SymfonyClock(new MockClock(self::RENAMED_AT)));

        return $bank;
    }

    private function assertNoOp(Bank $bank): void
    {
        $this->assertSame(self::NAME, $bank->getName());
        $this->assertSame('acme savings', $bank->getNameNormalized());
        $this->assertSame(self::STORED_AT, $bank->getUpdatedAt()->format(DateTimeInterface::ATOM));
        $this->assertSame([], $bank->pullDomainEvents());
    }

    private function assertMutated(Bank $bank): void
    {
        $this->assertSame(self::RENAMED_AT, $bank->getUpdatedAt()->format(DateTimeInterface::ATOM));

        $events = $bank->pullDomainEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(BankUpdatedDomainEvent::class, $events[0]);
        $this->assertSame(BankMother::DEFAULT_ID, $events[0]->aggregateId());
    }
}
