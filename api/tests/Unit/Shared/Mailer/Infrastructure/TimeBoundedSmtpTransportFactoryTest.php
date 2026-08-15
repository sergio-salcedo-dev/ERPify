<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Mailer\Infrastructure;

use Erpify\Shared\Mailer\Infrastructure\TimeBoundedSmtpTransportFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\NullTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Email;

/**
 * @internal
 */
#[CoversClass(TimeBoundedSmtpTransportFactory::class)]
final class TimeBoundedSmtpTransportFactoryTest extends TestCase
{
    private const float BOUND = 1.0;

    /**
     * `EsmtpTransportFactory` reads no `timeout` option, and `SocketStream::getTimeout()` falls back to
     * `default_socket_timeout` — 60 seconds — so without this the socket is unbounded from the app's side.
     */
    public function testBoundsTheSocketTimeoutOfAnSmtpTransport(): void
    {
        $transport = $this->factory()->create(new Dsn('smtp', 'mail.example.com', null, null, 587));

        self::assertSame(self::BOUND, $this->socketOf($transport)->getTimeout());
    }

    /**
     * `MAILER_DSN=smtp://host?timeout=5` is the spelling an operator reaches for first, and upstream silently
     * discards it. Honouring it here removes the no-op rather than leaving a second trap beside the new knob.
     */
    public function testTheDsnOptionOverridesTheConfiguredDefault(): void
    {
        $dsn = new Dsn('smtp', 'mail.example.com', null, null, 587, ['timeout' => '5']);

        $transport = $this->factory()->create($dsn);

        self::assertSame(5.0, $this->socketOf($transport)->getTimeout());
    }

    #[DataProvider('provideIgnoresADsnOptionThatIsNotAPositiveNumberCases')]
    public function testIgnoresADsnOptionThatIsNotAPositiveNumber(string $option): void
    {
        $dsn = new Dsn('smtp', 'mail.example.com', null, null, 587, ['timeout' => $option]);

        $transport = $this->factory()->create($dsn);

        self::assertSame(self::BOUND, $this->socketOf($transport)->getTimeout());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideIgnoresADsnOptionThatIsNotAPositiveNumberCases(): iterable
    {
        yield 'empty' => [''];
        yield 'not a number' => ['soon'];
        yield 'zero would mean no timeout at all' => ['0'];
        yield 'negative' => ['-1'];
    }

    /**
     * The decorator is registered on the SMTP factory, but a transport it hands back is only bounded when it
     * owns a socket — nothing here may assume the concrete type.
     */
    public function testLeavesATransportWithoutASocketStreamUntouched(): void
    {
        $factory = new TimeBoundedSmtpTransportFactory(new NullTransportFactory(), self::BOUND);

        $transport = $factory->create(new Dsn('null', 'null'));

        self::assertNotInstanceOf(SmtpTransport::class, $transport);
    }

    public function testDelegatesSupportsToTheDecoratedFactory(): void
    {
        $factory = $this->factory();

        self::assertTrue($factory->supports(new Dsn('smtp', 'mail.example.com')));
        self::assertFalse($factory->supports(new Dsn('null', 'null')));
    }

    /**
     * The observable the issue names, and the one an assertion on `getTimeout()` cannot make: a server that
     * accepts the connection and then says nothing. Unbounded, `readLine()` waits `default_socket_timeout`;
     * bounded, it gives up at the configured value. Removing `setTimeout` from the decorator reds this test —
     * after it has spent 60 seconds hanging, which is the price of proving the real behaviour.
     */
    #[Group('slow')]
    public function testAHungServerFailsWithinTheBoundInsteadOfTheSocketDefault(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($server, \sprintf('could not open the probe socket: %s (%d)', $errstr, $errno));

        $address = stream_socket_get_name($server, false);
        self::assertIsString($address);
        $port = (int) substr($address, strrpos($address, ':') + 1);

        $transport = $this->factory()->create(new Dsn('smtp', '127.0.0.1', null, null, $port));

        $startedAt = microtime(true);

        try {
            $transport->send($this->anEmail());
            self::fail('a server that never answers must not produce a successful send');
        } catch (TransportException) {
            $elapsed = microtime(true) - $startedAt;
        } finally {
            fclose($server);
        }

        self::assertLessThan(
            (float) \ini_get('default_socket_timeout'),
            $elapsed,
            'the send blocked for the unbounded socket default, so the transport is not time-bounded',
        );
        self::assertLessThan(self::BOUND * 5, $elapsed, 'the send outlived its own bound by more than a margin');
    }

    private function factory(): TimeBoundedSmtpTransportFactory
    {
        return new TimeBoundedSmtpTransportFactory(new EsmtpTransportFactory(), self::BOUND);
    }

    private function socketOf(object $transport): SocketStream
    {
        self::assertInstanceOf(SmtpTransport::class, $transport);
        $stream = $transport->getStream();
        self::assertInstanceOf(SocketStream::class, $stream);

        return $stream;
    }

    private function anEmail(): Email
    {
        return (new Email())
            ->from('security@erpify.com')
            ->to('someone@example.com')
            ->subject('probe')
            ->text('probe');
    }
}
