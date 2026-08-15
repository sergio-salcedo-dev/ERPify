<?php

declare(strict_types=1);

namespace Erpify\Shared\Mailer\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Bounds how long an SMTP server may hold a caller.
 *
 * **Nothing else in the stack bounds it.** `EsmtpTransportFactory` reads `auto_tls`, `require_tls`,
 * `source_ip`, `verify_peer`, `peer_fingerprint`, `local_domain`, `max_per_second`, `restart_threshold` and
 * `ping_threshold` — and no timeout of any kind, so `SocketStream::getTimeout()` falls through to
 * `default_socket_timeout`: sixty seconds, per socket, from a php.ini value nobody set for the mailer.
 *
 * One value covers both ways a server hangs, which is why a single knob is enough:
 * `SocketStream::initialize()` passes it to `stream_socket_client()` (a server that never accepts) *and* to
 * `stream_set_timeout()` (a server that accepts and then says nothing, which `AbstractStream::readLine()`
 * turns into a `TransportException`). The second is the shape a real outage takes, and it is the one an
 * unbounded connect timeout would miss entirely.
 *
 * **The seam is the transport, not the sender.** Every path that mails — the shared security link, the
 * invitation, the password-changed and account-locked notices — resolves through this one factory, so a
 * bound placed here holds for senders that do not exist yet and needs no sender to know it exists. Bounding
 * each caller instead means a count that is wrong the moment someone adds the next one, and it was already
 * wrong: the lockout notice sends from a five-minute scheduler tick on a single-replica worker, where a
 * blocked socket does not cost one slow request — it costs the clock.
 *
 * The `timeout` DSN option is honoured above the configured default because it is the spelling an operator
 * reaches for first (`smtp://host?timeout=5`) and upstream discards it in silence; leaving it unread would
 * park a second no-op next to the knob that fixes the first. A non-numeric or non-positive option falls back
 * rather than disabling the bound, since `0` means *wait for ever* to every socket function underneath.
 *
 * Decoration rather than a replacement factory: the upstream `create()` owns TLS negotiation, peer
 * verification and authentication, none of which this is entitled to reimplement to add one setter.
 */
#[AsDecorator('mailer.transport_factory.smtp')]
final readonly class TimeBoundedSmtpTransportFactory implements TransportFactoryInterface
{
    private const string TIMEOUT_OPTION = 'timeout';

    public function __construct(
        #[AutowireDecorated]
        private TransportFactoryInterface $decorated,
        #[Autowire('%env(float:MAILER_SMTP_TIMEOUT)%')]
        private float $defaultTimeout,
    ) {
    }

    public function create(Dsn $dsn): TransportInterface
    {
        $transport = $this->decorated->create($dsn);

        if (!$transport instanceof SmtpTransport) {
            return $transport;
        }

        $stream = $transport->getStream();

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

        return $timeout > 0.0 ? $timeout : $this->defaultTimeout;
    }
}
