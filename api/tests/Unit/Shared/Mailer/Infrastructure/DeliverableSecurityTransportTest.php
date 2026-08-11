<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Mailer\Infrastructure;

use Erpify\Shared\Mailer\Infrastructure\DeliverableSecurityTransport;
use Erpify\Shared\Mailer\Infrastructure\SecurityMailerMisconfigured;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DeliverableSecurityTransport::class)]
#[CoversClass(SecurityMailerMisconfigured::class)]
final class DeliverableSecurityTransportTest extends TestCase
{
    /**
     * The discard transport is `MAILER_DSN`'s default in every compose file, and Symfony's `NullTransport`
     * documents itself as pretending messages have been sent — so without this refusal a caller that records
     * state on a successful send records it for mail that was never transmitted.
     */
    #[DataProvider('provideRefusesTheDiscardTransportOutsideLocalEnvironmentsCases')]
    public function testRefusesTheDiscardTransportOutsideLocalEnvironments(string $dsn): void
    {
        $transport = new DeliverableSecurityTransport($dsn, 'prod');

        $this->expectException(SecurityMailerMisconfigured::class);

        $transport->ensureDeliverable();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRefusesTheDiscardTransportOutsideLocalEnvironmentsCases(): iterable
    {
        yield 'the compose default' => ['null://null'];
        yield 'bare scheme' => ['null://'];
        yield 'uppercase' => ['NULL://null'];
        yield 'surrounded by whitespace' => ['  null://null  '];
    }

    public function testAcceptsARealTransportOutsideLocalEnvironments(): void
    {
        $transport = new DeliverableSecurityTransport('smtp://user:pass@mail.erpify.com:587', 'prod');

        $transport->ensureDeliverable();

        $this->expectNotToPerformAssertions();
    }

    /**
     * Local stacks point at Mailpit or at nothing on purpose; the guard targets a deployment, not the loop.
     */
    #[DataProvider('provideLocalEnvironmentsTolerateTheDiscardTransportCases')]
    public function testLocalEnvironmentsTolerateTheDiscardTransport(string $environment): void
    {
        $transport = new DeliverableSecurityTransport('null://null', $environment);

        $transport->ensureDeliverable();

        $this->expectNotToPerformAssertions();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideLocalEnvironmentsTolerateTheDiscardTransportCases(): iterable
    {
        yield 'dev' => ['dev'];
        yield 'test' => ['test'];
    }
}
