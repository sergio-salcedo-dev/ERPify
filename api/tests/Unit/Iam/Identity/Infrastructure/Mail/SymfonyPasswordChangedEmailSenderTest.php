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
        $this->assertStringContainsString('Your previous password no longer works.', $text);
        $this->assertStringContainsString('Your previous password no longer works.', $html);
        // A notification, not an action email: no CTA, no link, no token. (The shared chrome's <style>
        // block still names .erpify-btn; the invariant is that no anchor/button ELEMENT is rendered.)
        $this->assertStringNotContainsString('href', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    public function testTheBodyClaimsOnlyWhatTheCredentialChangeItselfGuarantees(): void
    {
        // The eager session teardown is best-effort and swallows its failures, so a copy that promises every
        // session was signed out states an outcome the system does not guarantee — and, with the send ordered
        // after the revoke, would state it in the very run where the revoke silently failed. Replacing the
        // credential is what is certain, so that is all the mail is allowed to say.
        $email = $this->send();
        $text = $email->getTextBody();
        $html = $email->getHtmlBody();

        $this->assertIsString($text);
        $this->assertIsString($html);

        foreach (['signed out', 'signed you out', 'all your open sessions'] as $unguaranteed) {
            $this->assertStringNotContainsString($unguaranteed, $text);
            $this->assertStringNotContainsString($unguaranteed, $html);
        }
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
