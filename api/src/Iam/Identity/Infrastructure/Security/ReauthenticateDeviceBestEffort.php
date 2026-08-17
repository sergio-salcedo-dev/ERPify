<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * {@see ReauthenticateDevice} for the three callers that reach it AFTER their transaction has committed —
 * which is all of them.
 *
 * The re-login is the one step in those flows that cannot be rolled back into a truthful error. By the time
 * it runs the credential has been replaced, every session revoked and the security notice sent; a token flow
 * has also spent its single-use link. Letting the refusal decide the status code would answer a request that
 * fully succeeded with a 403, telling the caller their password was not changed and inviting a retry that can
 * no longer work — the old credential is gone, and the token with it.
 *
 * So the failure is contained rather than propagated: it goes to the log at `critical` and the caller keeps
 * the status its mutation earned. Nothing is lost — a walled identity meets the same wall on its next request,
 * where the response can be truthful because nothing is mid-flight.
 *
 * It is a collaborator rather than a `try`/`catch` in each controller for the reason this branch keeps
 * meeting: the same policy written three times is the divergence it exists to prevent, and here the three
 * copies would each be free to pick a different log level, a different message, or no containment at all —
 * which is exactly the state this replaces, where one flow contained it and two did not.
 *
 * The identity is deliberately absent from the log context. The id is the subject's own and a log line is a
 * sink no erasure path reaches; the request's correlation id already ties the entry to the caller for as long
 * as the request trail exists.
 *
 * On the always-on `observability` channel the level no longer buys arrival, and that trade is deliberate:
 * `critical` on the buffered channel DID flush up to fifty of the password-change request's records, real
 * context this line no longer gets. It is given up on purpose — by this repository's own measurement that
 * flush emits `ContextListener`'s `debug` line carrying the person's address, which is precisely what the
 * paragraph above keeps out of this context. `critical` is kept for what it says about the failure.
 */
final readonly class ReauthenticateDeviceBestEffort
{
    public function __construct(
        private ReauthenticateDevice $reauthenticateDevice,
        #[Autowire(service: 'monolog.logger.observability')]
        private LoggerInterface $logger,
    ) {
    }

    public function reauthenticate(string $emailIdentifier): void
    {
        try {
            $this->reauthenticateDevice->reauthenticate($emailIdentifier);
        } catch (Throwable $throwable) {
            $this->logger->critical(
                'The credential was replaced but the caller could not be re-authenticated.',
                ['exception' => $throwable],
            );
        }
    }
}
