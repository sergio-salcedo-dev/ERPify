<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Persistence;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Infrastructure\Persistence\Doctrine\DoctrineBankRepository;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine\DoctrineBankAccountRepository;
use Erpify\Shared\Persistence\Domain\Exception\ConcurrentUniqueWrite;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\AsciiUpperTextFieldNormalizer;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\DoctrineSearchEngine;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\NormalizedTextFieldNormalizer;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity\Mother\BankMother;
use Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity\Mother\BankAccountMother;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/**
 * Losing the uniqueness race must not be the database's message to deliver.
 *
 * `#[UniqueEntity]` is a SELECT and the write is an INSERT; nothing makes the pair atomic, so a
 * competing request can commit the same value in between. The application's check passes, the write
 * hits the unique index, and Postgres answers — with
 * `duplicate key value violates unique constraint … DETAIL: Key (iban)=(…) already exists.`
 *
 * Unhandled, that message becomes an unhandled-exception 500 and lands verbatim in `exception_message`
 * and in the Sentry event. The value it names can be personal data: `bank_account.iban` is
 * `#[PersonalData]`. So the port translates at the boundary, which is also where DBAL is allowed to be
 * known, and the translation carries no value.
 *
 * The coupling is the two ports under test plus the collaborators their constructors demand; driving a
 * persistence boundary end to end is what it costs.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(DoctrineBankAccountRepository::class)]
#[CoversClass(DoctrineBankRepository::class)]
#[CoversClass(ConcurrentUniqueWrite::class)]
final class RepositoryUniqueViolationTest extends TestCase
{
    private const string IBAN = 'DE89370400440532013000';

    private const string DRIVER_MESSAGE = 'An exception occurred while executing a query: SQLSTATE[23505]: '
        . 'Unique violation: 7 ERROR:  duplicate key value violates unique constraint "uniq_53a23e0afad56e62" '
        . 'DETAIL:  Key (iban)=(' . self::IBAN . ') already exists.';

    #[Test]
    #[DataProvider('provideARefusedWriteBecomesAConflictThatNamesNoValueCases')]
    public function aRefusedWriteBecomesAConflictThatNamesNoValue(string $resource): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willThrowException($this->driverViolation());

        try {
            $this->save($resource, $entityManager);

            $this->fail('The repository swallowed a write the database refused.');
        } catch (ConcurrentUniqueWrite $concurrentUniqueWrite) {
            $this->assertSame('concurrent-unique-write', $concurrentUniqueWrite->type());
            $this->assertSame($resource, $concurrentUniqueWrite->context()['resource'] ?? null);
            // The message is a literal on the class, so asserting the IBAN is absent from it proves
            // nothing on its own. `previous` is the reachable vector: both siblings in this namespace
            // keep the driver exception there on purpose, so harmonising the three would carry the
            // value into the dev/test debug chain — and only this assertion would notice.
            $this->assertNotInstanceOf(
                Throwable::class,
                $concurrentUniqueWrite->getPrevious(),
                'The driver exception was kept as `previous`, so its message — which names the '
                . 'offending value, and `bank_account.iban` is personal data — reaches the dev/test '
                . '`debug.previous_chain`.',
            );
            $this->assertStringNotContainsString(self::IBAN, $concurrentUniqueWrite->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideARefusedWriteBecomesAConflictThatNamesNoValueCases(): iterable
    {
        yield 'bank account' => ['bank-account'];
        yield 'bank' => ['bank'];
    }

    private function save(string $resource, EntityManagerInterface $entityManager): void
    {
        if ('bank-account' === $resource) {
            (new DoctrineBankAccountRepository($entityManager))->save($this->account());

            return;
        }

        (new DoctrineBankRepository(
            $entityManager,
            $this->unconstructed(DoctrineSearchEngine::class),
            $this->unconstructed(NormalizedTextFieldNormalizer::class),
            $this->unconstructed(AsciiUpperTextFieldNormalizer::class),
        ))->save(BankMother::create());
    }

    private function account(): BankAccount
    {
        return BankAccountMother::create();
    }

    /**
     * The bank port's search collaborators are `final`, so they cannot be doubled — and `save()` never
     * reaches them, so an unconstructed instance says exactly as much as a double would.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function unconstructed(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    /**
     * DBAL's exception as it reaches the port. Built without its constructor because the driver-level
     * chain it wants says nothing about the behaviour under test — only the message the application
     * must not adopt.
     */
    private function driverViolation(): UniqueConstraintViolationException
    {
        $violation = (new ReflectionClass(UniqueConstraintViolationException::class))
            ->newInstanceWithoutConstructor()
        ;

        (new ReflectionProperty(Exception::class, 'message'))->setValue($violation, self::DRIVER_MESSAGE);

        return $violation;
    }
}
