<?php

declare(strict_types=1);

namespace Erpify\Shared\Mailer\Infrastructure;

use Erpify\Shared\Mailer\Application\MailAddressRedaction;
use RuntimeException;
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
 * enhanced status code are digits and dots, so a message built only from them cannot contain an address. A
 * filter over the server's text would instead have to be right about every form a mail server anywhere might
 * emit, and would fail silently and toward the leak whenever it was not.
 *
 * **Nothing is chained as `previous`.** A normalising formatter walks that chain and would print the original
 * message again, which would undo the whole exercise; and `TransportException::getDebug()` accumulates the
 * entire SMTP conversation, `RCPT TO:` included, so keeping a reference to the original keeps that transcript
 * reachable too. What survives is what an operator acts on: which failure it was, what the server said in
 * numbers, and where it happened.
 */
final class MailDeliveryFailed extends RuntimeException
{
    /**
     * The enhanced status code of RFC 3463, as a mail server writes it in a reply: a `4` or `5` class, then a
     * subject and a detail of up to three digits each. It is the part of the reply that distinguishes "mailbox
     * full" from "user unknown" from "relay denied" — the diagnosis an operator needs — and its alphabet is
     * digits and dots, so it can carry no address.
     */
    private const string ENHANCED_STATUS = '/\b[45]\.\d{1,3}\.\d{1,3}\b/';

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

    private static function enhancedStatusOf(string $message): string
    {
        $matched = \preg_match(self::ENHANCED_STATUS, $message, $matches);

        return 1 === $matched ? $matches[0] : self::NO_STATUS;
    }
}
