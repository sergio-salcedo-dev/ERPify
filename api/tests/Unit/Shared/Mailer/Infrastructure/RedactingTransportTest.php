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
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
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
    public function neitherTheChainNorTheTranscriptCarriesTheAddressBack(): void
    {
        // A normalising formatter walks `previous` and would print the original message again; the transport
        // exception also accumulates the whole SMTP conversation, `RCPT TO:` included, behind `getDebug()`.
        $failure = new UnexpectedResponseException(self::SMTP_REFUSAL, 550);
        $failure->appendDebug('RCPT TO:<' . self::ADDRESS . '>');

        $thrown = $this->sendAndCatch($failure);

        $this->assertNotInstanceOf(Throwable::class, $thrown->getPrevious());
        $this->assertSame('', $thrown->getDebug());
    }

    /**
     * `Mailer::send()` unwraps a `HandlerFailedException` looking for this interface and rethrows it bare.
     * Without it the mailer surfaces a Messenger wrapper and the failure an operator reads names the bus.
     */
    #[Test]
    public function theTranslatedFailureIsStillATransportFailure(): void
    {
        // Read off the class rather than off a caught instance: the return type already carries the interface,
        // so `assertInstanceOf` on it is a tautology PHPStan resolves at analysis time, and an invariant whose
        // assertion cannot fail is unguarded. This one goes red the moment the `implements` clause is dropped.
        $implemented = \class_implements(MailDeliveryFailed::class);
        $this->assertIsArray($implemented);

        $this->assertContains(
            TransportExceptionInterface::class,
            $implemented,
            'Mailer::send() would surface a Messenger wrapper instead of this failure',
        );
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
