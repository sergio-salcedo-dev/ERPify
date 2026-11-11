<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Event;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Event\Infrastructure\Messenger\AsyncTransportValidator;
use Erpify\Shared\Event\Infrastructure\Messenger\PublishedOutsideTransaction;
use Erpify\Shared\Event\Infrastructure\Messenger\RequireTransactionalPublishMiddleware;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Tests\Functional\Shared\Event\Fixtures\AsyncProbeMessage;
use Erpify\Tests\Functional\Shared\Event\Fixtures\AsyncProbeRig;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use UnexpectedValueException;

/**
 * The after-commit guarantee `async` is trusted for, observed on the real Doctrine transport rather than
 * inferred from its class: a message published inside a use case's transaction is a row only once that
 * transaction commits, and a rollback takes it along.
 *
 * `async` is the in-memory double under `when@test`, so no other test ever sees the transport's `INSERT`. The
 * rig builds the real one, on the ORM's own connection and on a queue of its own, behind the production guard
 * and Messenger's real sender middleware. Visibility is read from a SECOND connection, because the
 * application's own connection sees its uncommitted rows and would answer "queued" in every case.
 *
 * @internal
 */
#[CoversClass(RequireTransactionalPublishMiddleware::class)]
#[CoversClass(AsyncTransportValidator::class)]
final class AsyncDoctrineTransportFunctionalTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private Connection $outside;

    private TransactionManager $transactions;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $transactions = self::getContainer()->get(TransactionManager::class);
        $this->assertInstanceOf(TransactionManager::class, $transactions);

        $this->entityManager = $entityManager;
        $this->transactions = $transactions;
        // A second connection: only COMMITTED rows are visible from it.
        $this->outside = DriverManager::getConnection($entityManager->getConnection()->getParams());
        // A run killed between a commit and its teardown leaves a committed row that would read as 2 for ever.
        AsyncProbeRig::purge($this->outside);
        $this->assertFalse($entityManager->getConnection()->isTransactionActive(), 'the case starts in no transaction');
    }

    protected function tearDown(): void
    {
        // A setUp that failed part-way leaves these uninitialised, and an Error here would mask its failure.
        if (isset($this->entityManager)) {
            $connection = $this->entityManager->getConnection();

            for ($level = $connection->getTransactionNestingLevel(); $level > 0; --$level) {
                $connection->rollBack();
            }
        }

        if (isset($this->outside)) {
            AsyncProbeRig::purge($this->outside);
            $this->outside->close();
        }

        parent::tearDown();
    }

    #[Test]
    public function aMessagePublishedInATransactionIsQueuedOnlyOnceItCommits(): void
    {
        $bus = $this->rigOn($this->entityManager->getConnection())->bus();

        $this->transactions->transactional(function () use ($bus): void {
            $bus->dispatch(new AsyncProbeMessage());

            $this->assertSame(1, AsyncProbeRig::queued($this->entityManager->getConnection()), 'the insert never ran');
            $this->assertSame(0, AsyncProbeRig::queued($this->outside), 'visible before its transaction commits');
        });

        $this->assertSame(1, AsyncProbeRig::queued($this->outside), 'the commit did not publish the message');
    }

    #[Test]
    public function aRollbackTakesTheQueuedMessageAlong(): void
    {
        $bus = $this->rigOn($this->entityManager->getConnection())->bus();
        $published = false;

        try {
            $this->transactions->transactional(function () use ($bus, &$published): never {
                $bus->dispatch(new AsyncProbeMessage());

                $this->assertSame(1, AsyncProbeRig::queued($this->entityManager->getConnection()), 'no insert ran');
                $published = true;

                throw new UnexpectedValueException('the business write failed after the publication');
            });
        } catch (UnexpectedValueException) {
            // expected
        }

        // Narrow on purpose: the guard's refusals are RuntimeExceptions and so is PHPUnit's own assertion
        // failure, so a broader catch would let a dispatch that threw satisfy the zero below.
        $this->assertTrue($published, 'the case never reached the failure it is about');
        $this->assertSame(0, AsyncProbeRig::queued($this->outside), 'a rolled-back publication left a message');
        $this->assertSame(0, AsyncProbeRig::queued($this->entityManager->getConnection()));
    }

    #[Test]
    public function aPublicationOutsideATransactionIsRefusedAndQueuesNothing(): void
    {
        $bus = $this->rigOn($this->entityManager->getConnection())->bus();

        $this->expectException(PublishedOutsideTransaction::class);

        try {
            $bus->dispatch(new AsyncProbeMessage());
        } finally {
            $this->assertSame(0, AsyncProbeRig::queued($this->outside));
        }
    }

    #[Test]
    public function theValidatorAcceptsADoctrineTransportOnTheApplicationConnection(): void
    {
        $rig = $this->rigOn($this->entityManager->getConnection());

        $this->assertNull($rig->validator()->problem());
    }

    #[Test]
    public function theValidatorRefusesADoctrineTransportOnAnotherConnection(): void
    {
        $rig = $this->rigOn($this->outside);

        $this->assertStringContainsString(
            'different connection',
            (string) $rig->validator()->problem(),
            'a transport on its own connection commits on its own, so a rollback would not take its rows along',
        );
    }

    private function rigOn(Connection $transportConnection): AsyncProbeRig
    {
        return new AsyncProbeRig($this->entityManager, $transportConnection);
    }
}
