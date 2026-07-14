<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Messenger;

use Erpify\Iam\Identity\Application\PasswordChangedEmailSender;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Event\PasswordResetCompleted;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Event\Application\DomainEventHandlerDeduplicator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * The async consumer of {@see PasswordResetCompleted}: notifies the identity that its credential changed. It
 * runs OUTSIDE the reset request on purpose — the completing use case sits at its coupling ceiling and a
 * notification email is not part of the reset's transaction — and it may run async precisely because this
 * email carries no token (the event payload is the aggregate id alone, so nothing secret rides the transport).
 *
 * The recipient is resolved in-module from the aggregate id at handling time: a user hard-deleted before this
 * reactor runs simply skips the send — the event carries no email to fall back on, so a GDPR-erased identity
 * is never resurrected into a mail.
 */
final readonly class SendEmailOnPasswordResetCompleted
{
    public function __construct(
        private DomainEventHandlerDeduplicator $domainEventHandlerDeduplicator,
        private PasswordChangedEmailSender $emailSender,
        private UserRepository $users,
        private LoggerInterface $logger,
    ) {
    }

    #[AsMessageHandler]
    public function onPasswordResetCompleted(PasswordResetCompleted $passwordResetCompleted): void
    {
        // why: Messenger delivery is at-least-once, and an email is not idempotent — a redelivery after a
        // successful send would mail the recipient twice. Claim the (eventId, handler) pair first; a failed
        // send releases the claim so the transport's retry can attempt it again.
        if (!$this->domainEventHandlerDeduplicator->claim($passwordResetCompleted->eventId(), self::class)) {
            return;
        }

        try {
            $user = $this->users->findById($passwordResetCompleted->aggregateId());

            if (!$user instanceof User) {
                return;
            }

            $this->emailSender->send($user->email());
        } catch (Throwable $throwable) {
            $this->releaseClaim($passwordResetCompleted->eventId());

            throw $throwable;
        }
    }

    private function releaseClaim(string $eventId): void
    {
        try {
            $this->domainEventHandlerDeduplicator->release($eventId, self::class);
        } catch (Throwable $throwable) {
            // why: the send failure is the real error the transport must see; a release failure on top must
            // not mask it. Log it (the claim then lingers, suppressing the retry — the at-most-once sliver)
            // and let the original exception propagate.
            $this->logger->warning('Failed to release domain-event handler claim after a send failure.', [
                'eventId' => $eventId,
                'handler' => self::class,
                'exception' => $throwable,
            ]);
        }
    }
}
