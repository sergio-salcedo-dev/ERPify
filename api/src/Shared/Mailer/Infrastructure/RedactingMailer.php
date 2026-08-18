<?php

declare(strict_types=1);

namespace Erpify\Shared\Mailer\Infrastructure;

use Override;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/**
 * The boundary at which a mail failure stops being the transport's and becomes the application's.
 *
 * A rejected recipient makes the mail library raise an exception whose message quotes the server's reply
 * verbatim, and that reply names the recipient. Translating it here — rather than redacting it downstream —
 * is what makes the guarantee statable: past this decorator no component holds an object that carries the
 * address, so no log, no serializer and no error reporter can emit one. Downstream redaction could only ever
 * cover the sinks somebody remembered, and this application's mail failures reach several: a swallow that
 * logs, a rethrow that Messenger stores in `messenger_messages`, and an error reporter that reads the
 * throwable outside the logging stack entirely.
 *
 * **Decorating the mailer, rather than editing the senders, is what makes it exhaustive.** `MailerInterface`
 * is the single seam every send in this application passes through, and a sender added later inherits the
 * translation by using the interface at all. The one shape that would escape is a component reaching past it
 * to a `TransportInterface`; that is a boundary a reader can state and a rule can check, unlike "every future
 * sender remembers to catch".
 */
#[AsDecorator(decorates: 'mailer.mailer')]
final readonly class RedactingMailer implements MailerInterface
{
    public function __construct(
        #[AutowireDecorated]
        private MailerInterface $inner,
    ) {
    }

    /**
     * `Throwable` and not `TransportExceptionInterface`: the address reaches a message from more than one
     * place. The transport quotes the server, and the MIME layer quotes the argument it refused when an
     * address cannot be parsed — that one is an `InvalidArgumentException` and implements no transport
     * interface at all. A translation boundary that admitted only the failures it could name would let the
     * other class through untouched.
     */
    #[Override]
    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        try {
            $this->inner->send($message, $envelope);
        } catch (Throwable $throwable) {
            throw MailDeliveryFailed::from($throwable);
        }
    }
}
