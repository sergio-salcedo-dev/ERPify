<?php

declare(strict_types=1);

namespace Erpify\Shared\Mailer\Infrastructure;

use Erpify\Shared\ErrorContract\Application\EmailAddressRedaction;
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
 * `@` and the defining file's path — which is why {@see EmailAddressRedaction} still runs over the composed
 * result rather than being dropped as redundant. For such a class the second pass swallows the name, which
 * costs the diagnosis and leaks nothing; a named class, which is every throwable this application raises,
 * passes through untouched.
 *
 * **The origin is the fourth field, and it separates most of what the other three cannot.** A failure carrying
 * no reply has neither a code nor a status, so those three fields alone would read identically for every fault
 * that never reached a server. The file and line the throwable was raised from tell a read timeout from an
 * unknown transport name from a rejected recipient, and their alphabet is a vendor source name and an integer,
 * so no caller can put an address there. The basename rather than the path, which identifies the site without
 * describing the deployment's layout.
 *
 * **Its measured limit.** The socket layer raises every connection failure from a SINGLE `throw` inside a
 * `set_error_handler` closure that receives only the message, discarding the `errno` — so a refused connection,
 * a DNS failure and a TLS handshake against a plaintext port produce the same file, the same line and code 0,
 * and are indistinguishable here. Telling them apart needs the text of the reply, which is the same text that
 * carries the recipient. That trade is the whole design, and this is where it is paid.
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
     * The `-` in the leading class is the RFC 5321 continuation marker, not decoration: a multi-line reply
     * spells every line but the last as `550-5.1.1 …`, which is what Google emits, and without it such a reply
     * reports no status at all.
     *
     * Bounded at both ends rather than by `\b`, which matches inside a longer dotted run: an IPv4 address in
     * `Connection to 5.1.1.1 timed out` and a dotted local part in `<a.5.1.1.b@example.test>` both yield a
     * three-part token that is not a status code. Neither can leak — the capture is digits and dots either way
     * — but presenting a fabricated diagnosis in the shape of a real one costs an operator the time this field
     * exists to save.
     */
    private const string ENHANCED_STATUS = '/(?:^|["\s-])([45]\.\d{1,3}\.\d{1,3})(?![.\d])/';

    private const string NO_STATUS = 'none';

    public static function from(Throwable $throwable): self
    {
        // The redaction pass is defence in depth over a string this class built out of a class name and two
        // numbers. It is expected to find nothing; it is here so that a future field added to this message
        // cannot turn a composed message back into a copied one without something catching it.
        // The code travels as the exception's own, not only inside the text: a normalising formatter emits it
        // as a top-level field, and an integer cannot carry an address, so propagating it is free.
        return new self(EmailAddressRedaction::apply(\sprintf(
            'SMTP delivery failed (%s, code %d, status %s) at %s:%d.',
            $throwable::class,
            (int) $throwable->getCode(),
            self::enhancedStatusOf($throwable->getMessage()),
            \basename($throwable->getFile()),
            $throwable->getLine(),
        )), (int) $throwable->getCode());
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
     * A refusal, not an absence of work to do. Should this class ever sit inside something that accumulates —
     * a round-robin transport appends the failed transport's transcript to the exception it is collecting —
     * this is the seam where the SMTP conversation would land, so the argument is dropped deliberately.
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
