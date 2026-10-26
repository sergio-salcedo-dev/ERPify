<?php

declare(strict_types=1);

namespace Erpify\Shared\Monitoring\Infrastructure\Sentry;

use Erpify\Shared\ErrorContract\Domain\Exception\ClientError;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Sentry\Event;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\AuthenticationServiceException;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

/**
 * Recognises the expected 4xx client errors of an HTTP request, which {@see SentryEventFilter} drops:
 * every throwable the RFC 9457 pipeline answers with a 4xx, in both families it maps. The domain
 * family is any exception implementing {@see ClientError} — a business outcome such as deleting a bank
 * that still has accounts (409). The framework family carries no marker: the bridges
 * `ProblemDetailsFactory::fromThrowable()` turns into a client status — `ValidationFailedException`
 * (422), Security's `AuthenticationException` (401: bad credentials, no session on `/me`) and
 * `AccessDeniedException` (403), an `HttpExceptionInterface` whose own status is 4xx (the
 * cross-origin refusal of the recovery routes) — and a serializer `ExtraAttributesException`, which
 * Sentry captures at `kernel.exception` priority 128 before `UnknownPayloadMemberListener` rewrites it
 * into the 422 the client receives.
 *
 * 5xx paths keep flowing: a marker-less DomainException maps to `unhandled-exception` (500), an
 * `HttpExceptionInterface` carrying a 5xx keeps its status, and a login refused because the
 * authentication SYSTEM failed (`AuthenticationServiceException` anywhere in the chain) is kept
 * although the client sees a 401. Agreement with the error contract is pinned by
 * `SentryEventFilterClientErrorTest`, which runs one corpus through both and requires "dropped here"
 * ⇔ "answered 4xx there".
 *
 * **Only on an HTTP request**, read as an event carrying request data. The same throwable means
 * something else elsewhere: Sentry's Messenger listener unwraps a `HandlerFailedException` and
 * captures each nested throwable on its own, so a worker's validation failure arrives here top-level,
 * indistinguishable by type from a client's — and in a handler it is a malformed message, a publisher
 * bug or cross-context drift, never a client mistake. A console command or a scheduler tick has no
 * client either.
 */
final readonly class ExpectedClientError
{
    public function isExpected(Event $event, Throwable $throwable): bool
    {
        return [] !== $event->getRequest() && $this->answersAClientStatus($throwable);
    }

    /**
     * Mirrors the precedence of `ProblemDetailsFactory::fromThrowable()`: a `DomainException` is
     * decided by its marker alone (the factory honours a marker on nothing else, so a marker on a
     * non-domain throwable is a 500 and is kept), then the framework bridges. The one divergence is a
     * `ValidationFailedException` the factory finds deeper in the chain, behind a sync-bus
     * `HandlerFailedException`: kept here, which errs toward reporting.
     */
    private function answersAClientStatus(Throwable $throwable): bool
    {
        if ($throwable instanceof DomainException) {
            return $throwable instanceof ClientError;
        }

        return !$this->chainHas($throwable, AuthenticationServiceException::class)
            && ($this->isFrameworkClientBridge($throwable) || $this->isClientHttpException($throwable));
    }

    private function isFrameworkClientBridge(Throwable $throwable): bool
    {
        return $throwable instanceof ValidationFailedException
            || $throwable instanceof AccessDeniedException
            || $throwable instanceof AuthenticationException
            || $this->chainHas($throwable, ExtraAttributesException::class);
    }

    private function isClientHttpException(Throwable $throwable): bool
    {
        return $throwable instanceof HttpExceptionInterface
            && $throwable->getStatusCode() >= 400
            && $throwable->getStatusCode() < 500;
    }

    /**
     * @param class-string $class
     */
    private function chainHas(Throwable $throwable, string $class): bool
    {
        $seen = [];

        for ($link = $throwable; $link instanceof Throwable; $link = $link->getPrevious()) {
            if ($link instanceof $class) {
                return true;
            }

            if (\in_array($link, $seen, true)) {
                return false;
            }

            $seen[] = $link;
        }

        return false;
    }
}
