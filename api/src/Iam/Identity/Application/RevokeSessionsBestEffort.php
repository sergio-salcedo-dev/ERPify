<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Session\Application\RevokeAllSessions;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Eager, defence-in-depth session teardown after a credential change: revokes every one of the user's sessions
 * but never lets that failure surface. The credential change already de-authenticates the old sessions through
 * the native `refreshUser` path, so this revoke is never the only barrier — swallowing its failure (logged for
 * ops) is what keeps a session-store outage from stranding an operation whose credential change already
 * committed. Owning the policy here keeps {@see CompletePasswordReset} free of it, and lets the future
 * "change password from my account" flow reuse the same teardown.
 *
 * The subject id is deliberately absent from the log context. This report goes to the always-on
 * `observability` channel, so it is emitted on every occurrence rather than discarded — and a log line is a
 * sink no erasure path reaches, with no rotation and no declared owner of its deletion. The request's
 * correlation id already ties the entry to the caller for as long as the request trail exists, which is the
 * same trade {@see \Erpify\Iam\Identity\Infrastructure\Security\ReauthenticateDeviceBestEffort} states.
 */
final readonly class RevokeSessionsBestEffort
{
    public function __construct(
        private RevokeAllSessions $revokeAllSessions,
        private LoggerInterface $logger,
    ) {
    }

    public function revoke(string $userId): void
    {
        try {
            $this->revokeAllSessions->revoke($userId);
        } catch (Throwable $throwable) {
            $this->logger->warning(
                'Credential change committed; eager session revoke skipped (revoke failed).',
                ['exception' => $throwable],
            );
        }
    }
}
