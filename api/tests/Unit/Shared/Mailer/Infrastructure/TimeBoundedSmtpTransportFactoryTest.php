<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Mailer\Infrastructure;

use Erpify\Shared\Mailer\Infrastructure\TimeBoundedSmtpTransportFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Symfony\Component\Mime\Email;

/**
 * @internal
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") three of the eleven are `#[DataProvider]` methods, public
 * by PHPUnit's contract rather than by design; the class covers one collaborator and splitting it would put
 * the range cases in a different file from the guard they exercise
 */
#[CoversClass(TimeBoundedSmtpTransportFactory::class)]
final class TimeBoundedSmtpTransportFactoryTest extends TestCase
{
    /**
     * Deliberately not 1.0: an array cast to float yields exactly 1.0 and 1.0 sits inside the accepted
     * range, so a bound of 1.0 would make the `?timeout[]=` case assert its own failure value and pass
     * whether or not the option is validated at all.
     */
    private const float BOUND = 2.5;

    /**
     * `EsmtpTransportFactory` reads no `timeout` option, and `SocketStream::getTimeout()` falls back to
     * `default_socket_timeout` — 60 seconds — so without this the socket is unbounded from the app's side.
     */
    public function testBoundsTheSocketTimeoutOfAnSmtpTransport(): void
    {
        $transport = $this->factory()->create(new Dsn('smtp', 'mail.example.com', null, null, 587));

        $this->assertSame(self::BOUND, $this->socketOf($transport)->getTimeout());
    }

    /**
     * `MAILER_DSN=smtp://host?timeout=5` is the spelling an operator reaches for first, and upstream silently
     * discards it. Honouring it here removes the no-op rather than leaving a second trap beside the new knob.
     */
    public function testTheDsnOptionOverridesTheConfiguredDefault(): void
    {
        $dsn = new Dsn('smtp', 'mail.example.com', null, null, 587, ['timeout' => '5']);

        $transport = $this->factory()->create($dsn);

        $this->assertEqualsWithDelta(5.0, $this->socketOf($transport)->getTimeout(), PHP_FLOAT_EPSILON);
    }

    #[DataProvider('provideIgnoresADsnOptionOutsideTheAcceptedRangeCases')]
    public function testIgnoresADsnOptionOutsideTheAcceptedRange(mixed $option): void
    {
        $dsn = new Dsn('smtp', 'mail.example.com', null, null, 587, ['timeout' => $option]);

        $transport = $this->factory()->create($dsn);

        $this->assertSame(self::BOUND, $this->socketOf($transport)->getTimeout());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideIgnoresADsnOptionOutsideTheAcceptedRangeCases(): iterable
    {
        yield 'empty' => [''];
        yield 'not a number' => ['soon'];
        yield 'zero gives up before it connects' => ['0'];
        yield 'negative never returns' => ['-1'];

        // `Dsn::fromString` parses the query with `parse_str`, so `?timeout[]=99` arrives as an array — and
        // casting a non-empty array to float yields 1.0, a value the accepted range would otherwise wave
        // through as a one-second bound nobody asked for.
        yield 'an array, as ?timeout[]= produces' => [['99']];

        // Accepted by every type check and still unable to complete a round trip against a healthy relay:
        // it would discard mail while looking configured.
        yield 'below the floor no relay can answer within' => ['0.001'];

        yield 'above the ceiling' => ['301'];

        // Survives a float cast and then becomes 0 inside `stream_set_timeout()`, i.e. instant failure.
        yield 'so large it casts back to zero' => ['1e308'];

        // Numeric, and a float cast turns it into INF — which reaches `stream_socket_client()` as a
        // `ValueError`, not a `TransportExceptionInterface`, so it escapes the mailer's own error contract.
        // The ceiling is what refuses it; the type check never sees anything wrong.
        yield 'beyond float range' => ['1e400'];
    }

    /**
     * The refusal is what makes the configured default different from the DSN option: falling back here
     * would reinstate exactly the silent no-op this class exists to remove. `%env(float:…)%` already rejects
     * anything `filter_var` will not read as a float, so a number is the only thing that reaches the
     * constructor — and each of these numbers is worse than the sixty-second default it replaces. Measured:
     * `0` fails every send in 0.00s, `-1` had not returned after 150s, `0.001` fails against a healthy relay
     * in 0.01s, and `1e308` casts to `0` inside `stream_set_timeout()`.
     */
    #[DataProvider('provideRefusesAConfiguredDefaultOutsideTheAcceptedRangeCases')]
    public function testRefusesAConfiguredDefaultOutsideTheAcceptedRange(float $configured): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TimeBoundedSmtpTransportFactory(new EsmtpTransportFactory(), $configured);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function provideRefusesAConfiguredDefaultOutsideTheAcceptedRangeCases(): iterable
    {
        yield 'zero gives up before it connects' => [0.0];
        yield 'negative never returns' => [-1.0];
        yield 'below the floor' => [0.5];
        yield 'just above the ceiling' => [300.5];
        yield 'so large it casts back to zero' => [1e308];
    }

    /**
     * The range is inclusive at both ends; without this the bounds could tighten by one representable step
     * and no other test would notice.
     */
    #[DataProvider('provideAcceptsTheEndsOfTheRangeCases')]
    public function testAcceptsTheEndsOfTheRange(float $configured): void
    {
        $transport = (new TimeBoundedSmtpTransportFactory(new EsmtpTransportFactory(), $configured))
            ->create(new Dsn('smtp', 'mail.example.com', null, null, 587))
        ;

        $this->assertSame($configured, $this->socketOf($transport)->getTimeout());
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function provideAcceptsTheEndsOfTheRangeCases(): iterable
    {
        yield 'the floor itself' => [1.0];
        yield 'the ceiling itself' => [300.0];
    }

    /**
     * A transport with no socket of ours must come back as it went in. Asserting the identity rather than
     * the type is what gives this a red: `assertNotInstanceOf(SmtpTransport::class, …)` would only restate
     * what the decorated factory was told to return, and stays green against a `create()` whose body has
     * been deleted entirely — measured, by deleting it.
     */
    public function testReturnsATransportWithoutASocketOfOursUntouched(): void
    {
        $inner = new NullTransport();
        $decorated = $this->createStub(TransportFactoryInterface::class);
        $decorated->method('create')->willReturn($inner);

        $factory = new TimeBoundedSmtpTransportFactory($decorated, self::BOUND);

        $this->assertSame($inner, $factory->create(new Dsn('null', 'null')));
    }

    public function testDelegatesSupportsToTheDecoratedFactory(): void
    {
        $factory = $this->factory();

        $this->assertTrue($factory->supports(new Dsn('smtp', 'mail.example.com')));
        $this->assertFalse($factory->supports(new Dsn('null', 'null')));
    }

    /**
     * The observable the issue names, and the one an assertion on `getTimeout()` cannot make: a server that
     * accepts the connection and then says nothing. Unbounded, `readLine()` waits `default_socket_timeout`;
     * bounded, it gives up at the configured value. Removing `setTimeout` from the decorator reds this test —
     * after it has spent 60 seconds hanging, which is the price of proving the real behaviour.
     *
     * The ceiling is deliberately close to the bound. A looser one (`BOUND * 5`) passes for a bound four
     * times too slow, and any looser assertion is also subsumed by this one, so a second `assertLessThan`
     * against `default_socket_timeout` would add no detection — only a 0.1% margin that fires first and
     * hides this one.
     */
    #[Group('slow')]
    public function testAHungServerFailsWithinTheBoundInsteadOfTheSocketDefault(): void
    {
        $server = \stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, \sprintf('could not open the probe socket: %s (%d)', $errstr, $errno));

        $address = \stream_socket_get_name($server, false);
        $this->assertIsString($address);
        $port = (int) \substr($address, \strrpos($address, ':') + 1);

        $transport = $this->factory()->create(new Dsn('smtp', '127.0.0.1', null, null, $port));

        $startedAt = \microtime(true);

        try {
            $transport->send($this->anEmail());
            $this->fail('a server that never answers must not produce a successful send');
        } catch (TransportException) {
            $elapsed = \microtime(true) - $startedAt;
        } finally {
            \fclose($server);
        }

        $this->assertLessThan(
            self::BOUND * 2,
            $elapsed,
            \sprintf(
                'the send outlived its bound of %ss; unbounded it would have waited %ss',
                self::BOUND,
                \ini_get('default_socket_timeout'),
            ),
        );
    }

    private function factory(): TimeBoundedSmtpTransportFactory
    {
        return new TimeBoundedSmtpTransportFactory(new EsmtpTransportFactory(), self::BOUND);
    }

    private function socketOf(object $transport): SocketStream
    {
        $this->assertInstanceOf(SmtpTransport::class, $transport);
        $stream = $transport->getStream();
        $this->assertInstanceOf(SocketStream::class, $stream);

        return $stream;
    }

    private function anEmail(): Email
    {
        return (new Email())
            ->from('security@erpify.com')
            ->to('someone@example.com')
            ->subject('probe')
            ->text('probe')
        ;
    }
}
