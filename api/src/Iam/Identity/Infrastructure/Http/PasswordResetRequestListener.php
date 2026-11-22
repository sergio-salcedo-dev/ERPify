<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Http;

use Erpify\Iam\Identity\Application\RequestPasswordReset;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

/**
 * Runs the "forgot my password" work on `kernel.terminate` — after the uniform 202 is on the wire — so that no
 * branch of the endpoint pays for it before answering.
 *
 * THE LATENCY IS THE POINT. An `ACTIVE` identity costs a `FOR UPDATE` transaction (supersede, insert, event
 * publish) and a synchronous SMTP exchange; every other address costs one indexed read. A floor added to both
 * sides does not hide a difference between them, and an SMTP round trip is not sub-millisecond. Deferring the
 * whole use case removes the differential from the requester's view instead of paying it down:
 * {@see RequestPasswordResetController} does identical in-band work for every address — the budget claim, the
 * shared timing floor and one request attribute — and this listener, like {@see RecoveryThrottleAuditListener}
 * for a refused one, does whatever differs after the response. `docs/adr/identity-invitation-lifecycle.md` D10.
 *
 * THE PLAINTEXT TOKEN STILL NEVER TOUCHES A PERSISTED TRANSPORT. It is minted inside
 * {@see RequestPasswordReset} and handed to the mailer in the same process, in the same call: nothing is
 * dispatched to Messenger, so `SendEmailMessage` stays unrouted and the `async` table never holds a link. That
 * is why the deferral is `kernel.terminate` and not a queued message. The attribute carries only the address
 * as typed, which the request itself already held.
 *
 * NOTHING HERE ESCAPES. The response is already sent, so an exception would answer nobody and could only take
 * a worker down; it is reported on the always-on `observability` channel instead, with the exception CLASS
 * only: a Doctrine, DBAL or mailer message can embed the address or a bound parameter, and that channel has no
 * erasure path. A request that dies before the terminate phase (a worker kill, a platform timeout) loses the
 * reset: the requester sees a 202 and no mail, and asks again — the same outcome as a mail the relay refused.
 * The ASSUMPTION is a SAPI that flushes the response before running terminate listeners (FrankenPHP and
 * PHP-FPM are expected to, through `Symfony\Component\Runtime`; no test proves it); on one that does not, the
 * work is merely in-band again and the oracle returns.
 *
 * `kernel.terminate` runs after `finishRequest()` has popped the request, so it is pushed back for the
 * duration: the events the use case publishes are stamped from the current request, and without it they would
 * seal a `system` actor and a fresh correlation id — see {@see RecoveryThrottleAuditListener}.
 */
final readonly class PasswordResetRequestListener
{
    /**
     * Set by {@see RequestPasswordResetController} on every served (not throttled) request, carrying the
     * address as typed. It never leaves the request.
     */
    public const string TARGET_ATTRIBUTE = '_password_reset_request_target';

    public function __construct(
        private RequestPasswordReset $requestPasswordReset,
        private RequestStack $requestStack,
        #[Autowire(service: 'monolog.logger.observability')]
        private LoggerInterface $logger,
    ) {
    }

    /**
     * No main-request guard: {@see TerminateEvent} passes `MAIN_REQUEST` to its parent unconditionally.
     */
    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $target = $request->attributes->get(self::TARGET_ATTRIBUTE);

        if (!\is_string($target)) {
            return;
        }

        // Consumed before running: a second terminate dispatch for the same request must not mint a second token.
        $request->attributes->remove(self::TARGET_ATTRIBUTE);
        $this->requestStack->push($request);

        try {
            $this->requestPasswordReset->request($target);
        } catch (Throwable $throwable) {
            $this->reportSafely($throwable);
        } finally {
            $this->requestStack->pop();
        }
    }

    /**
     * A catch whose entire purpose is that nothing escapes may not throw, and the report is real I/O.
     */
    private function reportSafely(Throwable $failure): void
    {
        try {
            $this->logger->error(
                'Password reset requested; the deferred issue-and-send failed.',
                ['exception_class' => $failure::class],
            );
        } catch (Throwable) {
            // Nothing left to report to.
        }
    }
}
