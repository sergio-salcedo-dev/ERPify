<?php

declare(strict_types=1);

namespace Erpify\Shared\Mailer\Infrastructure;

use Erpify\Shared\ErrorContract\Application\EmailAddressRedaction;
use Override;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * The application's own account of a mail that did not go out, raised in place of the vendor's.
 *
 * One type for both ways that happens — the transport refused it, or it could not be assembled — because the
 * only decision any caller makes on it is whether the mail was sent, and both answer no. The two are told
 * apart by the composed text and by the origin it carries, not by a class a `catch` would have to enumerate.
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
     * **Anchored on the reply code, because shape alone does not identify a status.** Bounding the run at both
     * ends stops `Connection to 5.1.1.1 timed out` and `<a.5.1.1.b@example.test>`, and it was still not enough:
     * measured, `a-5.1.1@example.test rejected` reported `5.1.1` — synthesised out of a local part, the hyphen
     * opening it — and `Upgrade to version 4.2.3 first` reported `4.2.3`, a version number. RFC 3463 statuses
     * arrive in an SMTP reply, and a reply always spells its three-digit code first (`550 5.1.1 …`, or
     * `550-5.1.1 …` on every line but the last of a multi-line reply, which is what Google emits). Requiring
     * that code is what tells a diagnosis from a coincidence. A message carrying a status with no reply code in
     * front of it now reports none, which is the safe direction: no diagnosis beats a fabricated one.
     */
    private const string ENHANCED_STATUS = '/[45]\d{2}[ \-]([45]\.\d{1,3}\.\d{1,3})(?![.\d])/';

    private const string NO_STATUS = 'none';

    /** Distinct from `none`: the engine refused the scan, so nothing was measured either way. */
    private const string UNREADABLE_STATUS = 'unreadable';

    private const string NO_REPLY_CODE = 'none';

    public static function from(Throwable $throwable): self
    {
        // The redaction pass is defence in depth over a string this class built out of a class name and two
        // numbers. It is expected to find nothing; it is here so that a future field added to this message
        // cannot turn a composed message back into a copied one without something catching it.
        // The code travels as the exception's own, not only inside the text: a normalising formatter emits it
        // as a top-level field, and an integer cannot carry an address, so propagating it is free.
        return new self(EmailAddressRedaction::apply(\sprintf(
            'SMTP delivery failed (%s, code %s, status %s) at %s:%d.',
            $throwable::class,
            self::replyCodeOf($throwable),
            self::enhancedStatusOf($throwable->getMessage()),
            \basename($throwable->getFile()),
            $throwable->getLine(),
        )), (int) $throwable->getCode());
    }

    /**
     * The other way a mail fails before it is on the wire, raised by {@see RedactingMailer} while the message
     * is being assembled. `Mime\Address` refuses a non-compliant value with a message embedding it verbatim,
     * so the vendor text is dropped for exactly the reason the transport's is.
     *
     * **Neither a reply code nor an enhanced status is reported, because neither exists** — nothing has spoken
     * to a server yet. Scanning this message for one would be worse than useless: the only variable part of it
     * is the rejected value, which is arbitrary text. Measured, `550-5.1.1` is a value `Address` refuses and
     * {@see self::ENHANCED_STATUS} reads `5.1.1` straight back out of the refusal. A field that can only be
     * right by accident is not a diagnosis.
     *
     * What identifies the failure is the class and the origin, and here the origin is load-bearing rather than
     * a tie-breaker: the whole assembly is one expression setting an envelope and two bodies, so `Address.php`
     * rather than some other vendor file is what says an address was refused at all.
     */
    public static function whileAssembling(Throwable $throwable): self
    {
        return new self(EmailAddressRedaction::apply(\sprintf(
            'Mail assembly failed (%s) at %s:%d.',
            $throwable::class,
            \basename($throwable->getFile()),
            $throwable->getLine(),
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
     * A refusal, not an absence of work to do. Should this class ever sit inside something that accumulates —
     * a round-robin transport appends the failed transport's transcript to the exception it is collecting —
     * this is the seam where the SMTP conversation would land, so the argument is dropped deliberately.
     */
    #[Override]
    public function appendDebug(string $debug): void
    {
        unset($debug);
    }

    /**
     * A reply code is three digits from 2xx to 5xx, and only a code of that shape is one. Everything else is
     * the throwable's OWN code, and printing it after the word SMTP asserts a provenance it does not have — a
     * driver's `23505` would read as a reply the server never sent, and `0` reads as a reply of zero rather
     * than as the absence of one. The code still travels as the exception's own code; what is refused here is
     * the claim the text makes about where it came from.
     */
    private static function replyCodeOf(Throwable $throwable): string
    {
        $code = (int) $throwable->getCode();

        return $code >= 200 && $code <= 599 ? (string) $code : self::NO_REPLY_CODE;
    }

    /**
     * Every status the reply carries, not the first: a bounce that names one status per recipient reported only
     * the leading one, which is the wrong half of the answer as often as the right one. An engine failure
     * answers differently from a reply that carried nothing, because `none` on a run PCRE refused to scan reads
     * as a measurement and is not one.
     */
    private static function enhancedStatusOf(string $message): string
    {
        $matched = \preg_match_all(self::ENHANCED_STATUS, $message, $matches);

        if (false === $matched) {
            return self::UNREADABLE_STATUS;
        }

        $statuses = \array_values(\array_unique($matches[1]));

        return [] === $statuses ? self::NO_STATUS : \implode('/', $statuses);
    }
}
