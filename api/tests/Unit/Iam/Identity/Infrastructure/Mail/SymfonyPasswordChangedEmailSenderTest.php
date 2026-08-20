<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Mail;

use Erpify\Iam\Identity\Infrastructure\Mail\SymfonyPasswordChangedEmailSender;
use Erpify\Shared\Mailer\Infrastructure\BulletproofEmailChrome;
use Erpify\Shared\Mailer\Infrastructure\DeliverableSecurityTransport;
use Erpify\Shared\Mailer\Infrastructure\RedactingMailer;
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
        // A notification, not an action email: no CTA, no link, no token. (The shared chrome's <style>
        // block still names .erpify-btn; the invariant is that no anchor/button ELEMENT is rendered.)
        $this->assertStringNotContainsString('href', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    public function testTheBodyClaimsOnlyWhatTheCredentialChangeItselfGuarantees(): void
    {
        // Two claims this mail is NOT allowed to make, each false in a reachable run:
        //   - that every open session was signed out. The eager teardown is best-effort and swallows its
        //     failures, so the app's own session rows can stay ACTIVE while the mail says otherwise — and
        //     with the send ordered after the revoke, it would say so in the very run that failed.
        //   - that the previous password no longer works. Nothing requires the new credential to differ from
        //     the old one, so a reset to the same password falsifies it at the moment of sending.
        $email = $this->send();
        $text = $email->getTextBody();
        $html = $email->getHtmlBody();

        $this->assertIsString($text);
        $this->assertIsString($html);

        $unguaranteed = [
            'signed out',
            'signed you out',
            'all your open sessions',
            'previous password',
            'no longer works',
        ];

        foreach ($unguaranteed as $claim) {
            $this->assertStringNotContainsString($claim, $text);
            $this->assertStringNotContainsString($claim, $html);
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
            new RedactingMailer($mailer),
            new SecuritySenderAddress(self::FROM, 'dev'),
            new BulletproofEmailChrome(),
            new DeliverableSecurityTransport('smtp://localhost', 'dev'),
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
