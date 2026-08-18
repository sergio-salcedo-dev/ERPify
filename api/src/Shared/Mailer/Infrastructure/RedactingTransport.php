<?php

declare(strict_types=1);

namespace Erpify\Shared\Mailer\Infrastructure;

use Override;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/**
 * The boundary at which a mail failure stops being the transport's and becomes the application's.
 *
 * A rejected recipient makes the transport raise an exception whose message quotes the server's reply
 * verbatim, and that reply names the recipient. Translating it here — rather than redacting it downstream —
 * is what makes the guarantee statable: past this decorator no component holds an object that carries the
 * address, so no log, no serializer and no error reporter can emit one.
 *
 * **The transport, and not the mailer, is the chokepoint.** Three components in the compiled production
 * container take the transport collection directly, and only one of them is the mailer: `MailerTestCommand`
 * calls `send()` on it with no `try`, and Messenger's `MessageHandler` would do the same from the worker if
 * `SendEmailMessage` were ever routed. A boundary drawn at `MailerInterface` covers neither, and covers them
 * silently — the command's failure reaches the console error listener, which logs it at `critical` with the
 * recipient in a field no translation can reach.
 *
 * The count is a description of today, not a property: the framework wires a fourth consumer, the notifier's
 * email channel, which is absent here only because no notifier is configured. Decorating the id rather than
 * its consumers is what makes that harmless — anything wired to the collection inherits this by construction,
 * so a new consumer needs no edit here and no line in a list that would otherwise have to be maintained.
 *
 * `Mailer::send()` unwraps a `HandlerFailedException` looking for a `TransportExceptionInterface` and rethrows
 * it bare, which is why {@see MailDeliveryFailed} implements that interface: without it the mailer would
 * surface a Messenger wrapper instead, and the failure an operator reads would name the bus rather than the
 * send.
 */
#[AsDecorator(decorates: 'mailer.transports')]
final readonly class RedactingTransport implements TransportInterface
{
    public function __construct(
        #[AutowireDecorated]
        private TransportInterface $inner,
    ) {
    }

    #[Override]
    public function __toString(): string
    {
        return (string) $this->inner;
    }

    /**
     * `Throwable` and not `TransportExceptionInterface`, because a send fails in more ways than the transport
     * contract names: serialising the message can raise a MIME `InvalidArgumentException` quoting the header
     * it refused, and that implements no transport interface at all. A boundary admitting only the failures it
     * could name would let the other classes through untouched.
     *
     * The cost of that width, stated because the docblock above argues only its benefit: a serialisation bug is
     * a permanent programming error and a refused connection is a transient fault, and past this line both
     * present as `TransportExceptionInterface`. The composed message carries the origin file and line, which is
     * what keeps the two tellable apart in a log.
     *
     * The same width erases a decision Messenger reads off the TYPE. Its retry listener asks whether the
     * throwable implements the recoverable or the unrecoverable marker, and a translation answers neither — so
     * a failure raised as unrecoverable inside a send would be retried to the dead letter instead of stopping.
     * Latent, and measured so: neither this application nor the mail library raises those markers today. The
     * shape to watch for is a transport or a listener that starts to.
     *
     * **What this cannot reach is anything raised before the send.** The senders build their `Email` first, and
     * `Mime\Address` quotes the argument it rejects, so a malformed recipient throws in the sender rather than
     * here — where the `*BestEffort` wrapper logs it raw. That residual is recorded, with its measured bound,
     * in `PRODUCTION_SECURITY_CHECKLIST.md`.
     */
    #[Override]
    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        try {
            return $this->inner->send($message, $envelope);
        } catch (Throwable $throwable) {
            throw MailDeliveryFailed::from($throwable);
        }
    }
}
