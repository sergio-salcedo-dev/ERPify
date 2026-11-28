<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Mail;

use Erpify\Iam\Identity\Infrastructure\Mail\SymfonySecondFactorEnabledEmailSender;
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
#[CoversClass(SymfonySecondFactorEnabledEmailSender::class)]
final class SymfonySecondFactorEnabledEmailSenderTest extends TestCase
{
    private const string FROM = 'seguridad@erpify.test';

    private const string RECIPIENT = 'user@erpify.test';

    public function testSetsTheSecurityEnvelope(): void
    {
        $email = $this->send();

        $this->assertSame('Two-step verification is now on for your ERPify account', $email->getSubject());
        $this->assertSame([self::FROM], $this->addresses($email->getFrom()));
        $this->assertSame([self::RECIPIENT], $this->addresses($email->getTo()));
    }

    public function testTheBodyIsStaticPointsAtTheSecurityMailboxAndCarriesNothingExercisable(): void
    {
        $email = $this->send();
        $text = $email->getTextBody();
        $html = $email->getHtmlBody();

        $this->assertIsString($text);
        $this->assertIsString($html);
        $this->assertStringContainsString('Two-step verification is now on for your ERPify account.', $text);
        $this->assertStringContainsString('Contact ' . self::FROM . ' immediately.', $text);
        // A notification, not an action email: no link, no button, and no code or secret to type anywhere.
        $this->assertStringNotContainsString('href', $html);
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringNotContainsString('otpauth', $text . $html);
    }

    public function testTheBodyClaimsOnlyWhatTheActivationItselfGuarantees(): void
    {
        // The other devices are signed out best effort, and that teardown swallows its failures, so the mail may
        // not report it.
        $email = $this->send();
        $bodies = (string) $email->getTextBody() . (string) $email->getHtmlBody();

        foreach (['signed out', 'signed you out', 'other devices', 'is now secure', 'is secure'] as $claim) {
            $this->assertStringNotContainsString($claim, $bodies);
        }
    }

    private function send(): Email
    {
        $mailer = new CapturingMailer();
        $sender = new SymfonySecondFactorEnabledEmailSender(
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
