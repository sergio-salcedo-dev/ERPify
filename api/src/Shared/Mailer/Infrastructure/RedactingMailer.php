<?php

declare(strict_types=1);

namespace Erpify\Shared\Mailer\Infrastructure;

use Closure;
use SensitiveParameter;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * The one place `api/src` builds a MIME message, and it builds and sends it in a single call.
 *
 * **Assembly throws, and it throws quoting the person.** `Email::to()` parses its argument into a
 * `Mime\Address`, which answers a non-compliant value with an `RfcComplianceException` whose message embeds
 * that value verbatim. A sender that assembled its own message therefore raised that inside itself — upstream
 * of {@see RedactingTransport}, which by position reaches only what the send raises — and every one of these
 * paths is wrapped best-effort by a caller that logs the throwable raw, onto `php://stderr` under a driver
 * with a size bound but no TTL and no owner of erasure.
 *
 * **The senders hand over strings and never hold an `Email`.** That is what makes the guarantee structural
 * rather than a habit a reviewer has to re-check per sender: naming the MIME component anywhere else in
 * `api/src` reopens the hole, and holding the mailer anywhere else is the other way in — a class that reaches
 * it directly can send a message this never assembled. `MailAssemblyBoundaryGateTest` pins both sets.
 *
 * **`api/src` is the whole of the claim, and vendor code assembles too.** `MailerTestCommand` builds its own
 * `Email` and calls `to()` on the argument an operator typed, which is upstream of everything here; measured,
 * `bin/console mailer:test alice-the-victim` writes that value five times, across four fields of one
 * `console.CRITICAL` record — a channel the prod `main` handler does not exclude, on `php://stderr`. Nothing
 * in this file reaches it, and the checklist carries it as a residual rather than as closed.
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
     *
     * `?string` on the two bodies is the vendor's own contract, kept rather than narrowed: `null` is a mail
     * with no part of that type, which is a shape `Email` supports, and taking assembly away from the callers
     * is not a reason to take that shape away with it. `''` remains a different mail — one empty part — and it
     * is not rejected here: a body the caller rendered empty is a defect in that renderer, and a transport
     * boundary is the wrong place to hold a rule about what a template must produce.
     */
    public function send(
        string $from,
        #[SensitiveParameter]
        string $recipientEmail,
        string $subject,
        #[SensitiveParameter]
        ?string $text,
        #[SensitiveParameter]
        ?string $html,
    ): void {
        $email = new Email();

        $this->assemble(MailAssemblyField::From, static fn (): Email => $email->from($from));
        $this->assemble(MailAssemblyField::Recipient, static fn (): Email => $email->to($recipientEmail));
        $this->assemble(MailAssemblyField::Subject, static fn (): Email => $email->subject($subject));
        $this->assemble(MailAssemblyField::Text, static fn (): Email => $email->text($text));
        $this->assemble(MailAssemblyField::Html, static fn (): Email => $email->html($html));

        $this->mailer->send($email);
    }

    /**
     * One step per argument, so the failure can name the argument that caused it. A single chained expression
     * cannot: `Address` refuses `from` and `recipientEmail` at the same line of the same vendor file, so all
     * four of a refused sender, an empty sender, a refused recipient and an empty recipient composed the same
     * text — which reads a deployment that can send no mail at all as one person's row being bad.
     *
     * Every step is wrapped, not only the two that can refuse an address today, because the alternative is a
     * grouping judgement that has to be re-made each time an argument is added, and the one made wrongly is
     * the one nobody notices. The uniform shape costs a closure per field and buys attribution by
     * construction.
     *
     * The closure carries the recipient and the bodies in its bound scope, and that is deliberately not a way
     * back in: a trace renders a `Closure` as an object, never as the variables it closed over, so the frame
     * this adds holds an enum case and an object handle.
     *
     * @param Closure(): Email $step
     */
    private function assemble(MailAssemblyField $field, Closure $step): void
    {
        try {
            $step();
        } catch (Throwable $throwable) {
            throw MailDeliveryFailed::whileAssembling($field, $throwable);
        }
    }
}
