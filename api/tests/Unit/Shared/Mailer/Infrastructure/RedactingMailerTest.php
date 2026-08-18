<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Mailer\Infrastructure;

use Erpify\Shared\Mailer\Infrastructure\MailDeliveryFailed;
use Erpify\Shared\Mailer\Infrastructure\RedactingMailer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Throwable;

/**
 * @internal
 */
#[CoversClass(RedactingMailer::class)]
#[CoversClass(MailDeliveryFailed::class)]
final class RedactingMailerTest extends TestCase
{
    private const string ADDRESS = 'alice@example.test';

    /**
     * The message Symfony builds, reproduced from `SmtpTransport::assertResponseCode()`: the server's reply is
     * embedded verbatim, and the command whose reply fails on a rejected recipient names the recipient.
     */
    private const string SMTP_REFUSAL
        = 'Expected response code "250/251/252" but got code "550", with message '
        . '"550 5.1.1 <' . self::ADDRESS . '>: Recipient address rejected: User unknown".';

    #[Test]
    public function theAddressDoesNotSurviveTheBoundary(): void
    {
        $thrown = $this->sendAndCatch(new UnexpectedResponseException(self::SMTP_REFUSAL, 550));

        $this->assertStringNotContainsString(self::ADDRESS, $thrown->getMessage());
    }

    #[Test]
    public function theDiagnosisSurvivesTheBoundary(): void
    {
        // What an operator acts on: which failure it was, and what the server said in numbers.
        $thrown = $this->sendAndCatch(new UnexpectedResponseException(self::SMTP_REFUSAL, 550));

        $this->assertSame(
            'SMTP delivery failed (' . UnexpectedResponseException::class . ', code 550, status 5.1.1).',
            $thrown->getMessage(),
        );
    }

    #[Test]
    public function nothingIsChainedThatWouldCarryTheAddressBack(): void
    {
        // A normalising formatter walks `previous` and would print the original message again; the transport
        // exception also accumulates the whole SMTP conversation, `RCPT TO:` included, behind `getDebug()`.
        $thrown = $this->sendAndCatch(new UnexpectedResponseException(self::SMTP_REFUSAL, 550));

        $this->assertNotInstanceOf(Throwable::class, $thrown->getPrevious());
    }

    #[Test]
    public function aMalformedAddressIsTranslatedTooEvenThoughItIsNoTransportFailure(): void
    {
        // The MIME layer quotes the argument it refused, and its exception implements no transport interface,
        // so a boundary that admitted only the failures it could name would let this class through untouched.
        $thrown = $this->sendAndCatch(new RfcComplianceException(
            \sprintf('Email "%s" does not comply with addr-spec of RFC 2822.', self::ADDRESS),
        ));

        $this->assertStringNotContainsString(self::ADDRESS, $thrown->getMessage());
    }

    #[Test]
    public function aFailureCarryingNoStatusCodeSaysSoRatherThanInventingOne(): void
    {
        $thrown = $this->sendAndCatch(new RuntimeException('Connection could not be established.'));

        $this->assertSame(
            'SMTP delivery failed (' . RuntimeException::class . ', code 0, status none).',
            $thrown->getMessage(),
        );
    }

    #[Test]
    public function aSendThatSucceedsPassesThrough(): void
    {
        $inner = new CapturingMailer();
        $email = (new Email())->to(self::ADDRESS)->subject('hello')->text('body');

        (new RedactingMailer($inner))->send($email);

        $this->assertSame($email, $inner->lastEmail);
    }

    private function sendAndCatch(Throwable $failure): MailDeliveryFailed
    {
        $inner = $this->createMock(MailerInterface::class);
        $inner->method('send')->willThrowException($failure);

        try {
            (new RedactingMailer($inner))->send(new Email());
        } catch (MailDeliveryFailed $mailDeliveryFailed) {
            return $mailDeliveryFailed;
        }

        $this->fail('the boundary let the transport failure through untranslated');
    }
}
