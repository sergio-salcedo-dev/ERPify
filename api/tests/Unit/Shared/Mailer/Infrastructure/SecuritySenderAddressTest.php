<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Mailer\Infrastructure;

use Erpify\Shared\Mailer\Infrastructure\SecurityMailerMisconfigured;
use Erpify\Shared\Mailer\Infrastructure\SecuritySenderAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SecuritySenderAddress::class)]
#[CoversClass(SecurityMailerMisconfigured::class)]
final class SecuritySenderAddressTest extends TestCase
{
    public function testReturnsAReplyableAddressOutsideLocalEnvironments(): void
    {
        $this->assertSame(
            'seguridad@erpify.com',
            (new SecuritySenderAddress('seguridad@erpify.com', 'prod'))->toString(),
        );
    }

    /**
     * A reserved or link-local domain passes every other check on this class — non-blank, not a no-reply —
     * while being a mailbox no recipient can reply to. The repository's own default sender is one
     * (`seguridad@erpify.local`) and it appears in no compose file, so a deploy that simply never set the
     * variable would have sent security mail claiming to come from a domain that does not exist.
     */
    #[DataProvider('provideRejectsAnUndeliverableSenderDomainCases')]
    public function testRejectsAnUndeliverableSenderDomain(string $address): void
    {
        $sender = new SecuritySenderAddress($address, 'prod');

        $this->expectException(SecurityMailerMisconfigured::class);

        $sender->toString();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRejectsAnUndeliverableSenderDomainCases(): iterable
    {
        yield 'the repository default' => ['seguridad@erpify.local'];
        yield 'reserved test' => ['security@erpify.test'];
        yield 'reserved example' => ['security@erpify.example'];
        yield 'reserved invalid' => ['security@erpify.invalid'];
        yield 'bare localhost' => ['security@localhost'];
        yield 'case-insensitive' => ['security@ERPIFY.LOCAL'];
    }

    #[DataProvider('provideRejectsAnUnmonitoredSenderOutsideLocalEnvironmentsCases')]
    public function testRejectsAnUnmonitoredSenderOutsideLocalEnvironments(string $address): void
    {
        $sender = new SecuritySenderAddress($address, 'prod');

        $this->expectException(SecurityMailerMisconfigured::class);

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
