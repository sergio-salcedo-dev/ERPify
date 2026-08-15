<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Mailer;

use Erpify\Shared\Mailer\Infrastructure\TimeBoundedSmtpTransportFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;

/**
 * The unit suite hands the factory a float and never boots a container, so it says nothing about the two
 * halves that only exist in configuration: that `#[AsDecorator]` names a service id that exists, and that
 * `#[Autowire('%env(float:MAILER_SMTP_TIMEOUT)%')]` names a variable something defines. Both are strings.
 * Misspell either and PHPUnit, PHPStan, deptrac and `make php.lint.prod-container` all stay green — env
 * placeholders resolve at runtime and compiling a container does not instantiate its services — while the
 * first production email throws `EnvNotFoundException` or sends unbounded.
 *
 * For a change whose whole subject is a setting that was silently doing nothing, that gap is the one worth
 * closing with a test rather than with prose.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.Superglobals") `$_ENV` is what this project mandates for reading the environment
 * (`docs/project-context.md:128` forbids `getenv()`), and here it is the only anchor for the comparison that
 * is not the container under test — resolving the value through the container would assert it against itself
 */
#[CoversClass(TimeBoundedSmtpTransportFactory::class)]
final class TimeBoundedSmtpTransportWiringTest extends KernelTestCase
{
    private const string SMTP_FACTORY_ID = 'mailer.transport_factory.smtp';

    public function testTheDecoratorIsWiredOntoTheSmtpTransportFactory(): void
    {
        self::bootKernel();

        $factory = self::getContainer()->get(self::SMTP_FACTORY_ID);

        $this->assertInstanceOf(TimeBoundedSmtpTransportFactory::class, $factory);
    }

    /**
     * The observable that no unit test can reach: the value the container injects is the one the environment
     * defines, so a typo in the env name inside the `#[Autowire]` string reds here and only here.
     */
    public function testTheConfiguredEnvironmentValueReachesTheSocket(): void
    {
        self::bootKernel();

        $configured = $_ENV['MAILER_SMTP_TIMEOUT'] ?? null;
        $this->assertIsNumeric($configured, 'MAILER_SMTP_TIMEOUT is not defined for this environment');

        $factory = self::getContainer()->get(self::SMTP_FACTORY_ID);
        $this->assertInstanceOf(TransportFactoryInterface::class, $factory);

        $transport = $factory->create(new Dsn('smtp', 'mail.example.com', null, null, 587));
        $this->assertInstanceOf(SmtpTransport::class, $transport);

        $stream = $transport->getStream();
        $this->assertInstanceOf(SocketStream::class, $stream);

        $this->assertEqualsWithDelta((float) $configured, $stream->getTimeout(), PHP_FLOAT_EPSILON);
    }

    /**
     * `default_socket_timeout` is what the bound replaces; if the two ever coincide the assertion above
     * would pass over a decorator that had stopped being wired at all.
     */
    public function testTheBoundIsDistinguishableFromTheSocketDefault(): void
    {
        $configured = $_ENV['MAILER_SMTP_TIMEOUT'] ?? null;
        $this->assertIsNumeric($configured, 'MAILER_SMTP_TIMEOUT is not defined for this environment');

        $this->assertNotEqualsWithDelta(
            (float) \ini_get('default_socket_timeout'),
            (float) $configured,
            PHP_FLOAT_EPSILON,
            'MAILER_SMTP_TIMEOUT equals default_socket_timeout, so nothing here can tell a wired bound from an '
            . 'unwired one',
        );
    }
}
