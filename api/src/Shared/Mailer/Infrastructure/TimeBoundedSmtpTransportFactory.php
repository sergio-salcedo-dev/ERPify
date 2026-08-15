<?php

declare(strict_types=1);

namespace Erpify\Shared\Mailer\Infrastructure;

use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Bounds how long an SMTP server may hold a caller on any single socket operation.
 *
 * **Nothing else in the stack bounds it.** `EsmtpTransportFactory` reads `auto_tls`, `require_tls`,
 * `source_ip`, `verify_peer`, `peer_fingerprint`, `local_domain`, `max_per_second`, `restart_threshold` and
 * `ping_threshold` — and no timeout of any kind, so `SocketStream::getTimeout()` falls through to
 * `default_socket_timeout`: sixty seconds, per socket, from a php.ini value nobody set for the mailer.
 *
 * **One value covers every way a single operation hangs.** `SocketStream::initialize()` passes it to
 * `stream_socket_client()` (a server that never accepts) *and* to `stream_set_timeout()` (a server that
 * accepts and then says nothing, which `AbstractStream::readLine()` turns into a `TransportException`); it
 * also bounds the STARTTLS handshake. The silent-server shape is what a real outage looks like, and the one
 * a connect-only timeout would miss entirely.
 *
 * **What it does not cover, because a socket option cannot.** The bound is per *operation*, and SMTP is a
 * conversation — so one send costs round-trips × this value. Measured against a relay that answers every
 * command correctly but after nine seconds, a ten-second bound held a send for fifty-four seconds across six
 * round trips, before STARTTLS and AUTH add roughly four more. A peer that trickles bytes without ever
 * completing a line resets the window on every read and is not bounded here at all. `max_execution_time` is
 * `0` in both PHP containers, so nothing above this caps the total either. The default is therefore chosen
 * low enough that the round-trip product stays under the sixty seconds it replaces; a true ceiling on total
 * time has to live somewhere one can exist — a worker time limit, or a deadline held by the caller.
 *
 * **The seam is the transport, not the sender.** Every path that mails — the shared security link, the
 * invitation, the password-changed and account-locked notices — resolves through this one factory, so a
 * bound placed here holds for senders that do not exist yet and needs no sender to know it exists. Bounding
 * each caller instead means a count that is wrong the moment someone adds the next one, and it was already
 * wrong: the lockout notice sends from a five-minute scheduler tick on a single-replica worker, where a
 * blocked socket does not cost one slow request — it costs the clock.
 *
 * **The bound is only as wide as the scheme, and that limit is real.** This decorates the `smtp` factory, so
 * it covers `smtp://` and `smtps://` and nothing else: point `MAILER_DSN` at an API bridge or at
 * `sendmail://` and a different factory builds a transport with no socket of ours to bound. Those carry
 * their own timeouts (an HTTP client, a local binary) rather than `default_socket_timeout`, which is why
 * this is a declared edge and not a silent hole — but a deployment that changes scheme changes what holds
 * it, and nothing here will say so.
 *
 * **Both inputs are held to one range; only one of them falls back.** The `timeout` DSN option is honoured
 * above the configured default because it is the spelling an operator reaches for first
 * (`smtp://host?timeout=5`) and upstream discards it in silence; leaving it unread would park a second no-op
 * beside the knob that fixes the first. An option outside the range falls back to the configured default,
 * but a *configured default* outside it is refused, because falling back there would reinstate the silent
 * no-op this class exists to remove.
 *
 * The range, not the type check, is what does the work — every one of these is a number: measured,
 * `MAILER_SMTP_TIMEOUT=0` makes `stream_socket_client()` give up before it connects and every send fails in
 * hundredths of a second; a negative value leaves `stream_set_timeout()` with no bound and the read never
 * returns; `1e308` casts to `0` inside `stream_set_timeout()`, which is the instant-failure mode again;
 * `1e400` becomes `INF` and reaches `stream_socket_client()` as a `ValueError` — not a
 * `TransportExceptionInterface`, so it escapes the mailer's own error contract; and `0.001` passes every
 * type check yet cannot complete a round trip against a healthy relay, so it discards mail while looking
 * configured. The ceiling refuses the last three and the floor the rest.
 *
 * **The refusal is loud but late, and the PR that added it may not claim otherwise.** Env placeholders
 * resolve at runtime and container compilation does not instantiate services, so `lint:container` and
 * `make php.lint.prod-container` both exit `0` on a rejected value; the throw fires when `mailer.transports`
 * is first built — the first request or tick that mails. The deploy-time half of this guard lives in
 * `make prod.env.check`, which validates the key before the stack starts.
 *
 * Decoration rather than a replacement factory: the upstream `create()` owns TLS negotiation, peer
 * verification and authentication, none of which this is entitled to reimplement to add one setter.
 */
#[AsDecorator('mailer.transport_factory.smtp')]
final readonly class TimeBoundedSmtpTransportFactory implements TransportFactoryInterface
{
    private const string TIMEOUT_OPTION = 'timeout';

    /**
     * Below this no round trip completes against a real relay, so the bound would discard mail rather than
     * bound it.
     */
    private const float MINIMUM_SECONDS = 1.0;

    /**
     * Above this the value stops bounding anything operationally — it already exceeds the five-minute tick
     * it is meant to protect — and far above it the `(int)` cast inside `stream_set_timeout()` silently
     * yields `0`.
     */
    private const float MAXIMUM_SECONDS = 300.0;

    public function __construct(
        #[AutowireDecorated]
        private TransportFactoryInterface $decorated,
        #[Autowire('%env(float:MAILER_SMTP_TIMEOUT)%')]
        private float $defaultTimeout,
    ) {
        if (!$this->isAcceptable($defaultTimeout)) {
            throw new InvalidArgumentException(\sprintf(
                'MAILER_SMTP_TIMEOUT must be between %s and %s seconds, got %s.',
                self::MINIMUM_SECONDS,
                self::MAXIMUM_SECONDS,
                $defaultTimeout,
            ));
        }
    }

    public function create(Dsn $dsn): TransportInterface
    {
        $transport = $this->decorated->create($dsn);

        if (!$transport instanceof SmtpTransport) {
            return $transport;
        }

        $stream = $transport->getStream();

        // `setTimeout()` belongs to `SocketStream`, not to the declared `AbstractStream`, so this narrowing
        // is what PHPStan reads — and PHPStan is its only gate: the decorated factory is `final` and always
        // builds a `SocketStream`, so no test can reach the false branch. Removing it reds `make php.stan`
        // and nothing in the suite.
        if ($stream instanceof SocketStream) {
            $stream->setTimeout($this->timeoutFor($dsn));
        }

        return $transport;
    }

    public function supports(Dsn $dsn): bool
    {
        return $this->decorated->supports($dsn);
    }

    private function timeoutFor(Dsn $dsn): float
    {
        $option = $dsn->getOption(self::TIMEOUT_OPTION);

        if (!\is_numeric($option)) {
            return $this->defaultTimeout;
        }

        $timeout = (float) $option;

        return $this->isAcceptable($timeout) ? $timeout : $this->defaultTimeout;
    }

    private function isAcceptable(float $timeout): bool
    {
        return $timeout >= self::MINIMUM_SECONDS && $timeout <= self::MAXIMUM_SECONDS;
    }
}
