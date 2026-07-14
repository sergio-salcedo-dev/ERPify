<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Mailer\Infrastructure;

use Erpify\Shared\Mailer\Infrastructure\SecuritySenderAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(SecuritySenderAddress::class)]
final class SecuritySenderAddressTest extends TestCase
{
    public function testReturnsAReplyableAddressOutsideLocalEnvironments(): void
    {
        $this->assertSame(
            'seguridad@erpify.example',
            (new SecuritySenderAddress('seguridad@erpify.example', 'prod'))->toString(),
        );
    }

    #[DataProvider('provideRejectsAnUnmonitoredSenderOutsideLocalEnvironmentsCases')]
    public function testRejectsAnUnmonitoredSenderOutsideLocalEnvironments(string $address): void
    {
        $sender = new SecuritySenderAddress($address, 'prod');

        $this->expectException(RuntimeException::class);

        $sender->toString();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRejectsAnUnmonitoredSenderOutsideLocalEnvironmentsCases(): iterable
    {
        yield 'blank' => ['   '];
        yield 'no-reply with hyphen' => ['no-reply@erpify.example'];
        yield 'noreply without hyphen' => ['noreply@erpify.example'];
        yield 'uppercase variant' => ['NoReply@erpify.example'];
    }

    #[DataProvider('provideLocalEnvironmentsAcceptThePlaceholderSenderCases')]
    public function testLocalEnvironmentsAcceptThePlaceholderSender(string $environment): void
    {
        $this->assertSame(
            'noreply@erpify.local',
            (new SecuritySenderAddress('noreply@erpify.local', $environment))->toString(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideLocalEnvironmentsAcceptThePlaceholderSenderCases(): iterable
    {
        yield 'dev' => ['dev'];
        yield 'test' => ['test'];
    }
}
