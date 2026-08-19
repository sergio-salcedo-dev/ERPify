<?php

declare(strict_types=1);

namespace Erpify\Shared\Mailer\Infrastructure;

use SensitiveParameter;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * The one place a MIME message is built, and it builds and sends it in a single call.
 *
 * **Assembly throws, and it throws quoting the person.** `Email::to()` parses its argument into a
 * `Mime\Address`, which answers a non-compliant value with an `RfcComplianceException` whose message embeds
 * that value verbatim. A sender that assembled its own message therefore raised that inside itself — upstream
 * of {@see RedactingTransport}, which by position reaches only what the send raises — and every one of these
 * paths is wrapped best-effort by a caller that logs the throwable raw, onto `php://stderr` under a driver
 * with no rotation, no TTL and no owner of erasure.
 *
 * **The senders hand over strings and never hold an `Email`.** That is what makes the guarantee structural
 * rather than a habit a reviewer has to re-check per sender: naming the MIME component anywhere else in
 * `api/src` reopens the hole, and holding the mailer anywhere else is the other way in — a class that reaches
 * it directly can send a message this never assembled. `MailAssemblyBoundaryGateTest` pins both sets.
 *
 * **Only the assembly is translated here; the send is deliberately left alone.** The transport already
 * answers a delivery failure with a {@see MailDeliveryFailed} composed from the reply, and catching that a
 * second time would recompose it out of its own class name and origin — the diagnosis an operator reads
 * would then name this file instead of the socket.
 *
 * Not a `MailerInterface` implementation, and that is the point: the contract of that interface is to accept
 * a `RawMessage` somebody else built, which is the shape this class exists to remove from its callers.
 */
final readonly class RedactingMailer
{
    public function __construct(private MailerInterface $mailer)
    {
    }

    /**
     * The bodies are declared sensitive because two of the four mail paths put a single-use credential in
     * them: the invitation and the reset assemble their token into a link, and the assembled link reaches this
     * method as text. The frames that hold it a level up mark their own token, and a trace rendering the body
     * in clear would hand back exactly what those marks withhold — the argument-stripping ini covers it today,
     * and this is the half that survives a change to the ini.
     */
    public function send(
        string $from,
        #[SensitiveParameter]
        string $recipientEmail,
        string $subject,
        #[SensitiveParameter]
        string $text,
        #[SensitiveParameter]
        string $html,
    ): void {
        try {
            $email = (new Email())
                ->from($from)
                ->to($recipientEmail)
                ->subject($subject)
                ->text($text)
                ->html($html)
            ;
        } catch (Throwable $throwable) {
            throw MailDeliveryFailed::whileAssembling($throwable);
        }

        $this->mailer->send($email);
    }
}
