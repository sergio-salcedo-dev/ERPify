<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Mailer\Infrastructure;

use Erpify\Shared\Mailer\Infrastructure\SecurityLinkEmailContent;
use Erpify\Shared\Mailer\Infrastructure\SecurityLinkMailer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * @internal
 */
#[CoversClass(SecurityLinkMailer::class)]
#[CoversClass(SecurityLinkEmailContent::class)]
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

    private function send(): Email
    {
        $mailer = new CapturingMailer();
        (new SecurityLinkMailer($mailer, self::FROM, self::BASE_URL))->send(
            $this->content(),
            self::RECIPIENT,
            self::TOKEN,
        );

        $email = $mailer->lastEmail;
        $this->assertInstanceOf(Email::class, $email);

        return $email;
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
