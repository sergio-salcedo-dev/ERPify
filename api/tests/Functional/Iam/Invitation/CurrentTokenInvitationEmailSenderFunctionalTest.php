<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Invitation;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Invitation\Application\InvitationEmailSender;
use Erpify\Iam\Invitation\Application\SendInvitationEmailBestEffort;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Iam\Invitation\Domain\Repository\InvitationRepository;
use Erpify\Iam\Invitation\Infrastructure\Mail\CurrentTokenInvitationEmailSender;
use Erpify\Iam\Invitation\Infrastructure\Mail\InvitationLinkSuperseded;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Unit\Iam\Invitation\Application\SpyInvitationEmailSender;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The currency check against a real database, where the in-memory double cannot reach the case that matters: a
 * resend committed by ANOTHER process. That process's write never passes through this process's unit of work, so
 * an entity this request already manages still holds the old digest; only a read that goes back to the table sees
 * the replacement. The other process is played by a raw `UPDATE`, which bypasses the identity map exactly as a
 * concurrent writer does, inside a transaction the test rolls back.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the price of driving the real repository, clock and decorator
 * together, for the reason the sibling functional tests state
 */
#[CoversClass(CurrentTokenInvitationEmailSender::class)]
final class CurrentTokenInvitationEmailSenderFunctionalTest extends KernelTestCase
{
    private const string RECIPIENT = 'invitee@erpify.test';

    public function testALinkReplacedByAnotherProcessIsNotMailedEvenThoughThisProcessStillHoldsTheOldDigest(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection, EntityManagerInterface $entityManager): void {
            $invitations = $this->service(InvitationRepository::class, InvitationRepository::class);
            $clock = $this->service(Clock::class, Clock::class);
            $first = SingleUseToken::mint($clock->now()->modify('+7 days'));
            $id = Uuid::generate();
            $invitation = Invitation::create($id, Uuid::generate(), Uuid::generate(), $first->token);
            $invitation->markSent();
            $invitation->pullDomainEvents();

            $invitations->save($invitation);

            $oldLink = $id . '.' . $first->plaintext();

            // Another process resends: the table now holds a different digest, and this process knows nothing of it.
            $second = SingleUseToken::mint($clock->now()->modify('+7 days'));
            $connection->executeStatement(
                'UPDATE iam_invitation SET token_hash = :hash WHERE id = :id',
                ['hash' => $second->token->toHash(), 'id' => $id],
            );

            $stale = $invitations->findById($id);
            $this->assertInstanceOf(Invitation::class, $stale);
            $this->assertTrue(
                $stale->verify($first->plaintext(), $clock->now()),
                'the premise: a plain read still answers from the managed entity, so it would let the old link through',
            );

            $spy = new SpyInvitationEmailSender();
            $sender = new CurrentTokenInvitationEmailSender($spy, $invitations, $clock);

            try {
                $sender->send(self::RECIPIENT, $oldLink);
                $this->fail('A link another process replaced must not be mailed.');
            } catch (InvitationLinkSuperseded) {
                $this->assertSame([], $spy->sent);
            }

            $this->assertFalse(
                (new SendInvitationEmailBestEffort($sender, new NullLogger()))->send(self::RECIPIENT, $oldLink),
                'the wrapper reports the refused send as not accepted instead of claiming delivery',
            );

            $replacement = new SpyInvitationEmailSender();
            (new CurrentTokenInvitationEmailSender($replacement, $invitations, $clock))
                ->send(self::RECIPIENT, $id . '.' . $second->plaintext())
            ;
            $this->assertSame(1, $replacement->sentCount(), 'the replacement link is the live one and is mailed');

            $entityManager->clear();
        });
    }

    public function testTheContainerResolvesTheSenderPortToTheCurrencyCheck(): void
    {
        self::bootKernel();

        $this->assertInstanceOf(
            CurrentTokenInvitationEmailSender::class,
            $this->service(InvitationEmailSender::class, InvitationEmailSender::class),
            'the check is only worth having if every caller of the port goes through it',
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     * @param class-string<T> $expected
     *
     * @return T
     */
    private function service(string $id, string $expected): object
    {
        $service = self::getContainer()->get($id);
        $this->assertInstanceOf($expected, $service);

        return $service;
    }

    /**
     * @param callable(Connection, EntityManagerInterface): void $body
     */
    private function inRolledBackTransaction(callable $body): void
    {
        self::bootKernel();

        $entityManager = $this->service(EntityManagerInterface::class, EntityManagerInterface::class);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $body($connection, $entityManager);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }
}
