<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Mailer\Infrastructure;

use Erpify\Shared\Mailer\Infrastructure\MailDeliveryFailed;
use Erpify\Shared\Mailer\Infrastructure\RedactingTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * @internal
 */
#[CoversClass(RedactingTransport::class)]
#[CoversClass(MailDeliveryFailed::class)]
final class RedactingTransportTest extends TestCase
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

    /**
     * A normalising formatter emits `code` as a top-level field of its own, so a translation that kept the
     * number only inside the text would lose it for every reader that parses the record rather than the prose.
     */
    #[Test]
    public function theSmtpCodeTravelsAsTheExceptionsOwnCode(): void
    {
        $thrown = $this->sendAndCatch(new UnexpectedResponseException(self::SMTP_REFUSAL, 550));

        $this->assertSame(550, $thrown->getCode());
    }

    #[Test]
    public function theDiagnosisSurvivesTheBoundary(): void
    {
        // What an operator acts on: which failure it was, what the server said in numbers, and where it was
        // raised. The origin is built from the failure rather than written out, because the assertion is that
        // it is REPORTED, not that this test file sits at a particular line.
        $failure = new UnexpectedResponseException(self::SMTP_REFUSAL, 550);

        $thrown = $this->sendAndCatch($failure);

        $this->assertSame(
            \sprintf(
                'SMTP delivery failed (%s, code 550, status 5.1.1) at %s:%d.',
                UnexpectedResponseException::class,
                \basename($failure->getFile()),
                $failure->getLine(),
            ),
            $thrown->getMessage(),
        );
    }

    /**
     * A failure with no reply is distinguished by nothing but its origin: no code, no status, and the same
     * class for most of them. The origin is read off the failure rather than written out, so the first half
     * asserts that it is REPORTED rather than that this file sits at a particular line.
     *
     * The second half pins its measured limit, so the trade stays declared rather than becoming a surprise:
     * the socket layer raises every connection failure from one `throw` inside an error handler that never
     * sees the errno, so a refused connection and a DNS failure arrive with the same origin and the same code.
     * Two failures raised from ONE site are therefore indistinguishable — what would tell them apart is the
     * text of the reply, which is the text carrying the recipient.
     */
    #[Test]
    public function theOriginIdentifiesAFailureWithNoReplyAsFarAsOneSiteAllows(): void
    {
        $failure = new RuntimeException('Connection could not be established.');

        $thrown = $this->sendAndCatch($failure);

        $this->assertStringContainsString(
            \sprintf('at %s:%d.', \basename($failure->getFile()), $failure->getLine()),
            $thrown->getMessage(),
        );

        $raise = static fn (string $message): RuntimeException => new RuntimeException($message);

        $this->assertSame(
            $this->sendAndCatch($raise('Connection refused'))->getMessage(),
            $this->sendAndCatch($raise('php_network_getaddresses: getaddrinfo failed'))->getMessage(),
        );
    }

    #[Test]
    public function aFailureCarryingNoStatusCodeSaysSoRatherThanInventingOne(): void
    {
        $thrown = $this->sendAndCatch(new RuntimeException('Connection could not be established.'));

        $this->assertStringContainsString(
            'SMTP delivery failed (' . RuntimeException::class . ', code 0, status none) at ',
            $thrown->getMessage(),
        );
    }

    #[Test]
    public function aSendThatSucceedsForwardsBothArgumentsAndReturnsWhatTheTransportReturned(): void
    {
        // Asserting only that the return is null would stay green on a body of `return null;` — and Messenger
        // stamps the `SentMessage` this returns, so dropping it is a real loss. The arguments are pinned for
        // the same reason: a decorator that forwarded neither would satisfy every other test in this file.
        $message = (new Email())
            ->from('sender@example.test')
            ->to('rcpt@example.test')
            ->subject('hello')
            ->text('body')
        ;
        $envelope = new Envelope(new Address('sender@example.test'), [new Address('rcpt@example.test')]);
        $sent = new SentMessage($message, $envelope);

        $inner = $this->createMock(TransportInterface::class);
        $inner->expects($this->once())
            ->method('send')
            ->with($this->identicalTo($message), $this->identicalTo($envelope))
            ->willReturn($sent)
        ;

        $this->assertSame($sent, (new RedactingTransport($inner))->send($message, $envelope));
    }

    #[Test]
    public function theTransportKeepsItsName(): void
    {
        // `Mailer::send()` reads this to name the transport in the `MessageEvent`, so a decorator that
        // answered for itself would rename every transport in the application to its own class.
        $inner = $this->createStub(TransportInterface::class);
        $inner->method('__toString')->willReturn('[main]');

        $this->assertSame('[main]', (string) (new RedactingTransport($inner)));
    }

    #[Test]
    public function aTranscriptCannotBeAttachedToTheTranslatedFailureAfterTheFact(): void
    {
        // Nothing in this application calls `appendDebug()` on a translated failure — which is exactly why the
        // no-op needs an assertion of its own: without one, making it accumulate and hand the transcript back
        // through `getDebug()` would leave the whole suite green.
        $thrown = $this->sendAndCatch(new UnexpectedResponseException(self::SMTP_REFUSAL, 550));

        $thrown->appendDebug('RCPT TO:<' . self::ADDRESS . '>');

        $this->assertSame('', $thrown->getDebug());
    }

    private function sendAndCatch(Throwable $failure): MailDeliveryFailed
    {
        $inner = $this->createStub(TransportInterface::class);
        $inner->method('send')->willThrowException($failure);

        try {
            (new RedactingTransport($inner))->send(new Email());
        } catch (MailDeliveryFailed $mailDeliveryFailed) {
            return $mailDeliveryFailed;
        }

        $this->fail('the boundary let the transport failure through untranslated');
    }
}
