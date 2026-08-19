<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Mailer\Infrastructure;

use Erpify\Shared\Mailer\Infrastructure\BulletproofEmailChrome;
use Erpify\Shared\Mailer\Infrastructure\DeliverableSecurityTransport;
use Erpify\Shared\Mailer\Infrastructure\RedactingMailer;
use Erpify\Shared\Mailer\Infrastructure\SecurityLinkEmailContent;
use Erpify\Shared\Mailer\Infrastructure\SecurityLinkMailer;
use Erpify\Shared\Mailer\Infrastructure\SecurityMailerMisconfigured;
use Erpify\Shared\Mailer\Infrastructure\SecuritySenderAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * @internal
 */
#[CoversClass(SecurityLinkMailer::class)]
#[CoversClass(SecurityLinkEmailContent::class)]
#[CoversClass(SecurityMailerMisconfigured::class)]
final class SecurityLinkMailerTest extends TestCase
{
    private const string FROM = 'noreply@erpify.local';

    private const string BASE_URL = 'https://app.erpify.test/';

    private const string RECIPIENT = 'user@erpify.test';

    private const string TOKEN = 'tokenid.secret';

    private const string EXPECTED_LINK = 'https://app.erpify.test/reset-password?token=tokenid.secret';

    public function testSetsTheEnvelopeFromContent(): void
    {
        $email = $this->send();

        $this->assertSame('Reset subject', $email->getSubject());
        $this->assertSame([self::FROM], $this->addresses($email->getFrom()));
        $this->assertSame([self::RECIPIENT], $this->addresses($email->getTo()));
    }

    public function testBuildsTheTextBodyWithTheLinkBetweenLeadAndTrailer(): void
    {
        $expected = \implode("\n", [
            'Lead line.',
            '',
            'Open this link:',
            self::EXPECTED_LINK,
            '',
            'Trailer one.',
            'Trailer two.',
        ]);

        $this->assertSame($expected, $this->send()->getTextBody());
    }

    public function testHtmlBodyEmitsTrustedCopyAndTheEscapedLink(): void
    {
        $html = $this->send()->getHtmlBody();

        $this->assertIsString($html);
        $this->assertStringContainsString('<!doctype html>', $html);
        $this->assertStringContainsString('Lead with <strong>ERPify</strong>.', $html);
        $this->assertStringContainsString('Reset now', $html);
        $this->assertStringContainsString('class="erpify-btn" href="' . self::EXPECTED_LINK . '"', $html);
    }

    public function testANonHttpsBaseUrlFailsLoudlyOutsideLocalEnvironments(): void
    {
        // Defence in depth: a misconfigured production base URL must never emit a credential-bearing link
        // over plaintext http — the send aborts before any mail is built.
        $mailer = new CapturingMailer();
        $sut = $this->mailer($mailer, baseUrl: 'http://app.erpify.example', environment: 'prod');

        $this->expectException(SecurityMailerMisconfigured::class);

        try {
            $sut->send($this->content(), self::RECIPIENT, self::TOKEN);
        } finally {
            $this->assertNotInstanceOf(Email::class, $mailer->lastEmail);
        }
    }

    public function testAnHttpsBaseUrlSendsOutsideLocalEnvironments(): void
    {
        $mailer = new CapturingMailer();
        $this->mailer($mailer, from: 'seguridad@erpify.com', environment: 'prod')
            ->send($this->content(), self::RECIPIENT, self::TOKEN)
        ;

        $this->assertInstanceOf(Email::class, $mailer->lastEmail);
    }

    public function testTheLocalHttpBaseUrlStillSendsInDev(): void
    {
        $mailer = new CapturingMailer();
        $this->mailer($mailer, baseUrl: 'http://localhost')->send($this->content(), self::RECIPIENT, self::TOKEN);

        $this->assertInstanceOf(Email::class, $mailer->lastEmail);
    }

    private function send(): Email
    {
        $mailer = new CapturingMailer();
        $this->mailer($mailer)->send($this->content(), self::RECIPIENT, self::TOKEN);

        $email = $mailer->lastEmail;
        $this->assertInstanceOf(Email::class, $email);

        return $email;
    }

    private function mailer(
        CapturingMailer $mailer,
        string $from = self::FROM,
        string $baseUrl = self::BASE_URL,
        string $environment = 'dev',
    ): SecurityLinkMailer {
        return new SecurityLinkMailer(
            new RedactingMailer($mailer),
            new SecuritySenderAddress($from, $environment),
            $baseUrl,
            new BulletproofEmailChrome(),
            $environment,
            new DeliverableSecurityTransport('smtp://localhost', $environment),
        );
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

    private function content(): SecurityLinkEmailContent
    {
        return new SecurityLinkEmailContent(
            path: '/reset-password',
            subject: 'Reset subject',
            textLead: ['Lead line.', '', 'Open this link:'],
            textTrailer: ['Trailer one.', 'Trailer two.'],
            htmlLead: 'Lead with <strong>ERPify</strong>.',
            htmlDetail: 'Some detail.',
            ctaLabel: 'Reset now',
            htmlFootnote: 'Footnote.',
        );
    }
}
