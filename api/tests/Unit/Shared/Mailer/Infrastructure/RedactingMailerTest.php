<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Mailer\Infrastructure;

use Erpify\Shared\Mailer\Infrastructure\MailDeliveryFailed;
use Erpify\Shared\Mailer\Infrastructure\RedactingMailer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The half of the mail boundary a transport decorator cannot reach: the message is refused while it is being
 * built, before anything has been handed to a transport at all.
 *
 * @internal
 */
#[CoversClass(RedactingMailer::class)]
#[CoversClass(MailDeliveryFailed::class)]
final class RedactingMailerTest extends TestCase
{
    private const string FROM = 'sender@erpify.test';

    private const string RECIPIENT = 'alice@example.test';

    /**
     * Refused by `MessageIDValidation`: a bare local part has no domain to validate. Spelled with a plausible
     * person's name because the assertion is that this string does not survive, and a placeholder nobody would
     * mistake for an address would pass a `assertStringNotContainsString` for the wrong reason.
     */
    private const string MALFORMED_RECIPIENT = 'alice';

    #[Test]
    public function itAssemblesTheEnvelopeAndBothBodiesFromTheStringsItIsGiven(): void
    {
        $mailer = new CapturingMailer();

        (new RedactingMailer($mailer))->send(
            from: self::FROM,
            recipientEmail: self::RECIPIENT,
            subject: 'Subject',
            text: 'text body',
            html: '<p>html body</p>',
        );

        $email = $mailer->lastEmail;
        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame([self::FROM], $this->addresses($email->getFrom()));
        $this->assertSame([self::RECIPIENT], $this->addresses($email->getTo()));
        $this->assertSame('Subject', $email->getSubject());
        $this->assertSame('text body', $email->getTextBody());
        $this->assertSame('<p>html body</p>', $email->getHtmlBody());
    }

    /**
     * The defect this class exists for. `Mime\Address` embeds the value it refuses in its message, and every
     * caller of a sender wraps the send best-effort and logs the throwable, so before the translation the
     * rejected address was written verbatim to a sink no erasure path reaches.
     */
    #[Test]
    public function aRefusedRecipientDoesNotSurviveTheAssembly(): void
    {
        $thrown = $this->assembleAndCatch(recipient: self::MALFORMED_RECIPIENT);

        $this->assertStringNotContainsString(self::MALFORMED_RECIPIENT, $thrown->getMessage());
    }

    /**
     * The sender is a configured value rather than a person's, and it is refused by the same parser at the
     * same site — so it is translated by the same arm, and the diagnosis has to survive it.
     */
    #[Test]
    public function aRefusedSenderReportsTheRefusingClassAndItsOrigin(): void
    {
        $thrown = $this->assembleAndCatch(from: 'not-an-address');

        $this->assertMatchesRegularExpression(
            '/^Mail assembly failed \(\S+\) at Address\.php:\d+\.$/',
            $thrown->getMessage(),
        );
    }

    /**
     * No reply code and no enhanced status, because nothing has spoken to a server. The words are asserted
     * rather than the absence of a number: `code none, status none` would be a measurement of a conversation
     * that never happened, and it reads as one.
     */
    #[Test]
    public function anAssemblyFailureClaimsNoReplyItCouldNotHaveReceived(): void
    {
        $thrown = $this->assembleAndCatch(recipient: self::MALFORMED_RECIPIENT);

        $this->assertStringNotContainsString('code', $thrown->getMessage());
        $this->assertStringNotContainsString('status', $thrown->getMessage());
        $this->assertStringNotContainsString('SMTP', $thrown->getMessage());
    }

    #[Test]
    public function nothingIsHandedToTheMailerWhenTheMessageCannotBeBuilt(): void
    {
        $mailer = new CapturingMailer();

        try {
            (new RedactingMailer($mailer))->send(
                from: self::FROM,
                recipientEmail: self::MALFORMED_RECIPIENT,
                subject: 'Subject',
                text: 'text body',
                html: '<p>html body</p>',
            );
            $this->fail('a message that cannot be assembled was reported as sent');
        } catch (MailDeliveryFailed) {
            $this->assertNotInstanceOf(Email::class, $mailer->lastEmail);
        }
    }

    /**
     * The send is outside the `try` on purpose. The transport boundary already composes a delivery failure out
     * of the reply, and re-translating it here would rebuild that message from THIS class's own name and
     * origin — an operator would read the assembly site instead of the socket, and the SMTP code and status
     * would be gone.
     */
    #[Test]
    public function aDeliveryFailureIsNotRecomposedOnItsWayOut(): void
    {
        $translated = MailDeliveryFailed::from(new RuntimeException('Connection refused'));

        $inner = $this->createStub(MailerInterface::class);
        $inner->method('send')->willThrowException($translated);

        try {
            (new RedactingMailer($inner))->send(
                from: self::FROM,
                recipientEmail: self::RECIPIENT,
                subject: 'Subject',
                text: 'text body',
                html: '<p>html body</p>',
            );
            $this->fail('the delivery failure did not reach the caller');
        } catch (MailDeliveryFailed $mailDeliveryFailed) {
            $this->assertSame($translated, $mailDeliveryFailed);
        }
    }

    private function assembleAndCatch(
        string $from = self::FROM,
        string $recipient = self::RECIPIENT,
    ): MailDeliveryFailed {
        try {
            (new RedactingMailer(new CapturingMailer()))->send(
                from: $from,
                recipientEmail: $recipient,
                subject: 'Subject',
                text: 'text body',
                html: '<p>html body</p>',
            );
        } catch (MailDeliveryFailed $mailDeliveryFailed) {
            return $mailDeliveryFailed;
        }

        $this->fail('the boundary assembled a message the MIME parser refuses');
    }

    /**
     * @param array<Address> $addresses
     *
     * @return list<string>
     */
    private function addresses(array $addresses): array
    {
        return \array_values(
            \array_map(static fn (Address $address): string => $address->getAddress(), $addresses),
        );
    }
}
