<?php

declare(strict_types=1);

namespace Erpify\Shared\Mailer\Infrastructure;

use Erpify\Shared\Mailer\Application\MailAddressRedaction;
use Override;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * The application's own account of a failed send, raised in place of the transport's.
 *
 * **Why the original never travels.** `SmtpTransport::assertResponseCode()` embeds the server's reply verbatim,
 * and the command whose reply fails on a rejected recipient is `RCPT TO:<address>` — so a refusal such as
 * `550 5.1.1 <alice@example.test>: Recipient address rejected: User unknown` carries a person's address inside
 * an exception message. Every component that swallows that throwable and logs it, every serializer that stores
 * it, and every error reporter that ships it then holds an identifier the erasure path cannot reach.
 *
 * The message here is COMPOSED rather than filtered, and that is the whole design: an SMTP status code and an
 * enhanced status code have an alphabet of digits and dots, so neither can carry an address however the server
 * phrased its reply. A filter over the server's text would instead have to be right about every form a mail
 * server anywhere might emit, and would fail silently and toward the leak whenever it was not.
 *
 * The class name is the one part with no bounded alphabet — an anonymous class stringifies to a name holding
 * `@` and the defining file's path — which is why {@see MailAddressRedaction} still runs over the composed
 * result rather than being dropped as redundant. For such a class the second pass swallows the name, which
 * costs the diagnosis and leaks nothing; a named class, which is every throwable this application raises,
 * passes through untouched.
 *
 * **Declared cost.** A failure that carries no reply — a refused connection, a TLS handshake, a read timeout —
 * has no code and no status, so four distinct operational faults all read `code 0, status none` and differ
 * only by class. That is the price of composing rather than copying: the text that would tell them apart is
 * the same text that carries the recipient. Recorded in `PRODUCTION_SECURITY_CHECKLIST.md`.
 *
 * **Nothing is chained as `previous`.** A normalising formatter walks that chain and would print the original
 * message again, which would undo the whole exercise; and `TransportException::getDebug()` accumulates the
 * entire SMTP conversation, `RCPT TO:` included, so keeping a reference to the original keeps that transcript
 * reachable too. What survives is what an operator acts on: which failure it was, what the server said in
 * numbers, and where it happened.
 */
final class MailDeliveryFailed extends RuntimeException implements TransportExceptionInterface
{
    /**
     * The enhanced status code of RFC 3463, as a mail server writes it in a reply: a `4` or `5` class, then a
     * subject and a detail of up to three digits each. It is the part of the reply that distinguishes "mailbox
     * full" from "user unknown" from "relay denied" — the diagnosis an operator needs — and its alphabet is
     * digits and dots, so it can carry no address.
     *
     * Bounded at both ends rather than by `\b`, which matches inside a longer dotted run: an IPv4 address in
     * `Connection to 5.1.1.1 timed out` and a dotted local part in `<a.5.1.1.b@example.test>` both yield a
     * three-part token that is not a status code. Neither can leak — the capture is digits and dots either way
     * — but presenting a fabricated diagnosis in the shape of a real one costs an operator the time this field
     * exists to save.
     */
    private const string ENHANCED_STATUS = '/(?:^|["\s])([45]\.\d{1,3}\.\d{1,3})(?![.\d])/';

    private const string NO_STATUS = 'none';

    public static function from(Throwable $throwable): self
    {
        // The redaction pass is defence in depth over a string this class built out of a class name and two
        // numbers. It is expected to find nothing; it is here so that a future field added to this message
        // cannot turn a composed message back into a copied one without something catching it.
        return new self(MailAddressRedaction::apply(\sprintf(
            'SMTP delivery failed (%s, code %d, status %s).',
            $throwable::class,
            (int) $throwable->getCode(),
            self::enhancedStatusOf($throwable->getMessage()),
        )));
    }

    /**
     * Always empty, and that is the point of implementing the interface here. The transport's own exception
     * accumulates the entire SMTP conversation behind this method — `RCPT TO:<address>` included — so a
     * translation that carried a transcript across would hand back exactly what it removed from the message.
     */
    #[Override]
    public function getDebug(): string
    {
        return '';
    }

    /**
     * Accepted and discarded. Nothing in this application appends to a translated failure, and a transcript
     * is the one string this class exists not to hold, so there is no accumulation to perform.
     */
    #[Override]
    public function appendDebug(string $debug): void
    {
        unset($debug);
    }

    private static function enhancedStatusOf(string $message): string
    {
        $matched = \preg_match(self::ENHANCED_STATUS, $message, $matches);

        return 1 === $matched ? $matches[1] : self::NO_STATUS;
    }
}
