<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * Build-time twin of the publish guard's sender rule: a guarded message — a `DomainEvent`, or any message
 * routed to `async` — may be sent only to the transports `RequireTransactionalPublishMiddleware` admits.
 * The guard refuses anything else at the first publication; this reads the same rule off the repository's
 * routing and `#[AsMessage]` attributes so a committed line reds the build instead of a request.
 *
 * Resolution is {@see PersistentTransportPolicy}'s, the one `SendersLocator` itself follows, so both rules read
 * the routing the same way. The rule is its own class because it answers a different question: that one is
 * about which aggregate may be queued at all, this one about where a guarded message may go.
 *
 * @internal test support
 */
final readonly class PublishSenderPolicy
{
    /**
     * The transports the guard admits. Written again here because the guard's own constant is private;
     * `PersistentTransportRoutingShapeGateTest` holds the two equal.
     */
    public const array ADMITTED_SENDERS = [
        MessengerRoutingConfig::PERSISTED_TRANSPORT,
        MessengerRoutingConfig::NON_PERSISTED_TRANSPORT,
    ];

    public function __construct(private PersistentTransportPolicy $transport)
    {
    }

    /**
     * The build-time half of the publish guard's sender invariant: every event, resolved as
     * `SendersLocator` resolves it, reaches only `async` or `sync`. `RequireTransactionalPublishMiddleware`
     * refuses anything else at the first publication; this reads the same rule off the repository's routing so
     * a `routing:` line or an `#[AsMessage]` sending an event to `failed` (or beside `async`) reds the build
     * instead of a request. An event with no sender at all is in-process by design and is not a subject.
     *
     * @param array<string, list<string>> $routes routing key => transport names
     * @param list<string>                $events event FQCNs
     *
     * @return list<string>
     */
    public function eventsRoutedOutsideTheAdmittedSenders(array $routes, array $events): array
    {
        $violations = [];

        foreach ($events as $fqcn) {
            $violation = $this->sendersOutsideTheAdmittedSet($fqcn, $this->transport->sendersFor($fqcn, $routes));

            if (null !== $violation) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * The other half of what the guard watches: a message that is not a `DomainEvent` is guarded once it is
     * routed to `async`, and then every one of its senders must be admitted too. The subjects are the concrete
     * classes the repository routes — a routing key naming a class, and every class carrying an `#[AsMessage]`
     * — since a message nobody routes to `async` is not guarded at all.
     *
     * @param array<string, list<string>> $routes     routing key => transport names
     * @param list<string>                $attributed classes carrying an `#[AsMessage]`, inherited or not
     *
     * @return list<string>
     */
    public function asyncRoutedMessagesOutsideTheAdmittedSenders(array $routes, array $attributed): array
    {
        $subjects = \array_values(\array_unique([
            ...\array_filter(\array_keys($routes), class_exists(...)),
            ...$attributed,
        ]));
        $violations = [];

        foreach ($subjects as $fqcn) {
            $senders = $this->transport->sendersFor($fqcn, $routes);

            if (!\in_array(MessengerRoutingConfig::PERSISTED_TRANSPORT, $senders, true)) {
                continue;
            }

            $violation = $this->sendersOutsideTheAdmittedSet($fqcn, $senders);

            if (null !== $violation) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * Every class under `src` that an `#[AsMessage]` routes, on itself, a parent or an interface.
     *
     * @return list<class-string>
     */
    public function attributedMessagesInSource(): array
    {
        $attributed = [];

        foreach (ApiSourceFiles::phpFiles($this->transport->apiRoot() . '/src') as $file) {
            $relative = \substr($file->getPathname(), \strlen($this->transport->apiRoot() . '/src') + 1);
            $fqcn = 'Erpify\\' . \str_replace('/', '\\', \substr($relative, 0, -4));

            if (\class_exists($fqcn) && [] !== $this->transport->attributeTransportsFor($fqcn)) {
                $attributed[] = $fqcn;
            }
        }

        return $attributed;
    }

    /**
     * @param list<string> $senders
     */
    private function sendersOutsideTheAdmittedSet(string $fqcn, array $senders): ?string
    {
        $outside = \array_values(\array_diff($senders, self::ADMITTED_SENDERS));

        if ([] === $outside) {
            return null;
        }

        return \sprintf(
            '%s is routed to %s; the publish guard admits only %s and refuses it at runtime',
            $fqcn,
            \implode(', ', $outside),
            \implode(' and ', self::ADMITTED_SENDERS),
        );
    }
}
