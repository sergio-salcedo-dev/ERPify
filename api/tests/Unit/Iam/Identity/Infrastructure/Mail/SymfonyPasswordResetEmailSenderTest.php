<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Mail;

use Erpify\Iam\Identity\Infrastructure\Mail\SymfonyPasswordResetEmailSender;
use Erpify\Shared\Mailer\Infrastructure\BulletproofEmailChrome;
use Erpify\Shared\Mailer\Infrastructure\DeliverableSecurityTransport;
use Erpify\Shared\Mailer\Infrastructure\SecurityLinkMailer;
use Erpify\Shared\Mailer\Infrastructure\SecuritySenderAddress;
use Erpify\Tests\Unit\Shared\Mailer\Infrastructure\CapturingMailer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;

/**
 * @internal
 */
#[CoversClass(SymfonyPasswordResetEmailSender::class)]
final class SymfonyPasswordResetEmailSenderTest extends TestCase
{
    private const string RECIPIENT = 'user@erpify.test';

    private const string TOKEN = 'tokenid.secret';

    private const string EXPECTED_LINK = 'https://app.erpify.test/reset-password?token=tokenid.secret';

    public function testRendersTheEnglishResetCopyAroundTheEmailedLink(): void
    {
        $email = $this->send();
        $text = $email->getTextBody();
        $html = $email->getHtmlBody();

        $this->assertSame('Reset your ERPify password', $email->getSubject());
        $this->assertIsString($text);
        $this->assertIsString($html);

        // The English copy wraps the single reset link in both the text and HTML bodies.
        $this->assertStringContainsString('You requested to reset your ERPify password.', $text);
        $this->assertStringContainsString(self::EXPECTED_LINK, $text);
        $this->assertStringContainsString('your password will not change.', $text);
        $this->assertStringContainsString('You requested to reset your <strong>ERPify</strong> password.', $html);
        $this->assertStringContainsString('Reset password', $html);
        $this->assertStringContainsString('href="' . self::EXPECTED_LINK . '"', $html);
    }

    private function send(): Email
    {
        $mailer = new CapturingMailer();
        $sender = new SymfonyPasswordResetEmailSender(
            new SecurityLinkMailer(
                $mailer,
                new SecuritySenderAddress('security@erpify.test', 'dev'),
                'https://app.erpify.test',
                new BulletproofEmailChrome(),
                'dev',
                new DeliverableSecurityTransport('smtp://localhost', 'dev'),
            ),
        );

        $sender->send(self::RECIPIENT, self::TOKEN);

        $email = $mailer->lastEmail;
        $this->assertInstanceOf(Email::class, $email);

        return $email;
    }
}
