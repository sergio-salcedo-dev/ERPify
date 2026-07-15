<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Mail;

use Erpify\Iam\Identity\Infrastructure\Mail\SymfonyPasswordChangedEmailSender;
use Erpify\Shared\Mailer\Infrastructure\BulletproofEmailChrome;
use Erpify\Shared\Mailer\Infrastructure\SecuritySenderAddress;
use Erpify\Tests\Unit\Shared\Mailer\Infrastructure\CapturingMailer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * @internal
 */
#[CoversClass(SymfonyPasswordChangedEmailSender::class)]
final class SymfonyPasswordChangedEmailSenderTest extends TestCase
{
    private const string FROM = 'seguridad@erpify.test';

    private const string RECIPIENT = 'user@erpify.test';

    public function testSetsTheSecurityEnvelope(): void
    {
        $email = $this->send();

        $this->assertSame('Your ERPify password has changed', $email->getSubject());
        $this->assertSame([self::FROM], $this->addresses($email->getFrom()));
        $this->assertSame([self::RECIPIENT], $this->addresses($email->getTo()));
    }

    public function testTheBodyIsStaticPointsAtTheSecurityMailboxAndCarriesNoActionLink(): void
    {
        $email = $this->send();
        $text = $email->getTextBody();
        $html = $email->getHtmlBody();

        $this->assertIsString($text);
        $this->assertIsString($html);
        $this->assertStringContainsString('Your ERPify password has changed.', $text);
        $this->assertStringContainsString('contact ' . self::FROM . ' immediately', $text);
        $this->assertStringContainsString('We signed out all your open sessions for security.', $html);
        // A notification, not an action email: no CTA, no link, no token — which is why it may ride the
        // async transport while the invitation/reset emails must not. (The shared chrome's <style> block
        // still names .erpify-btn; the invariant is that no anchor/button ELEMENT is rendered.)
        $this->assertStringNotContainsString('href', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    public function testRendersThroughTheSharedChrome(): void
    {
        $html = $this->send()->getHtmlBody();

        $this->assertIsString($html);
        $this->assertStringContainsString('<html lang="en">', $html);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $html);
        $this->assertStringContainsString(
            "font-family:-apple-system,system-ui,'Segoe UI',Roboto,sans-serif",
            $html,
        );
    }

    private function send(): Email
    {
        $mailer = new CapturingMailer();
        $sender = new SymfonyPasswordChangedEmailSender(
            $mailer,
            new SecuritySenderAddress(self::FROM, 'dev'),
            new BulletproofEmailChrome(),
        );

        $sender->send(self::RECIPIENT);

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
}
