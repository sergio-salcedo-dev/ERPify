<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Mail;

use Erpify\Iam\Identity\Infrastructure\Mail\SymfonyAccountLockedEmailSender;
use Erpify\Shared\Mailer\Infrastructure\BulletproofEmailChrome;
use Erpify\Shared\Mailer\Infrastructure\DeliverableSecurityTransport;
use Erpify\Shared\Mailer\Infrastructure\RedactingMailer;
use Erpify\Shared\Mailer\Infrastructure\SecurityMailerMisconfigured;
use Erpify\Shared\Mailer\Infrastructure\SecuritySenderAddress;
use Erpify\Tests\Unit\Shared\Mailer\Infrastructure\CapturingMailer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The rendered lockout notice.
 *
 * **The linklessness is the whole security argument, not the styling**, and this file is the only thing that
 * can falsify it. The notice is delivered over the email namespace an attacker already consumes to trip the
 * lock, so by the recovery-channel ADR it may carry nothing exercisable; "it adds no edge to the recovery
 * graph" is derived from that and from nothing else. Without these assertions, adding an unlock link ships
 * with every other test in the tree green.
 *
 * @internal
 */
#[CoversClass(SymfonyAccountLockedEmailSender::class)]
final class SymfonyAccountLockedEmailSenderTest extends TestCase
{
    private const string FROM = 'seguridad@erpify.test';

    private const string RECIPIENT = 'locked@erpify.test';

    public function testSetsTheSecurityEnvelope(): void
    {
        $email = $this->send();

        $this->assertSame('Your ERPify account has been temporarily locked', $email->getSubject());
        $this->assertSame([self::FROM], $this->addresses($email->getFrom()));
        $this->assertSame([self::RECIPIENT], $this->addresses($email->getTo()));
    }

    public function testTheBodyCarriesNothingExercisable(): void
    {
        $email = $this->send();
        $html = $email->getHtmlBody();
        $text = $email->getTextBody();

        $this->assertIsString($html);
        $this->assertIsString($text);

        // No anchor, no button, no URL of any kind — in either part. The shared chrome's <style> block still
        // names .erpify-btn; the invariant is that no link ELEMENT and no address are rendered.
        foreach ([$html, $text] as $body) {
            $this->assertStringNotContainsString('href', $body);
            $this->assertStringNotContainsString('http', $body);
        }

        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringNotContainsString('token', \strtolower($html));
    }

    /**
     * The substance the notice is allowed to carry: the fact, that it self-heals, and the ORDER to recover in.
     * Rotating a password before evicting the other sessions leaves an attacker holding a live session, so the
     * ordering is the one actionable thing here — and it is guidance, never a lever this mail hands over.
     */
    public function testTheBodyStatesTheFactAndTheRecoveryOrder(): void
    {
        $text = $this->send()->getTextBody();

        $this->assertIsString($text);
        $this->assertStringContainsString('temporarily locked', $text);
        $this->assertStringContainsString('unlocks again on its own', $text);
        $this->assertStringContainsString('revoke the others first, then change your password', $text);
        $this->assertStringContainsString('Contact ' . self::FROM, $text);
    }

    /**
     * The body names no attempt count, no source address and no timestamp. Each would tell whoever received
     * the mail — including an attacker who has the mailbox — something about the traffic that drove the lock.
     */
    public function testTheBodyDescribesNeitherTheAttemptsNorTheirOrigin(): void
    {
        $email = $this->send();
        $text = $email->getTextBody();

        $this->assertIsString($text);
        $this->assertStringNotContainsString('10', $text);
        $this->assertStringNotContainsString('15 minutes', $text);
        $this->assertStringNotContainsString(self::RECIPIENT, $text, 'The recipient is the envelope, not copy.');
        $this->assertMatchesRegularExpression('/^[^0-9]*$/', $text, 'No count, duration, IP or timestamp.');
    }

    /** The transport guard runs before anything is composed, so a discarding deploy never reaches the mailer. */
    public function testItRefusesToComposeOverADiscardingTransport(): void
    {
        $mailer = new CapturingMailer();
        $sender = new SymfonyAccountLockedEmailSender(
            new RedactingMailer($mailer),
            new SecuritySenderAddress(self::FROM, 'dev'),
            new BulletproofEmailChrome(),
            new DeliverableSecurityTransport('null://null', 'prod'),
        );

        try {
            $sender->send(self::RECIPIENT);
            $this->fail('A discarding transport must refuse rather than report a send that did not happen.');
        } catch (SecurityMailerMisconfigured) {
            $this->assertNotInstanceOf(Email::class, $mailer->lastEmail);
        }
    }

    private function send(): Email
    {
        $mailer = new CapturingMailer();
        $sender = new SymfonyAccountLockedEmailSender(
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
     * @param Address[] $addresses
     *
     * @return list<string>
     */
    private function addresses(array $addresses): array
    {
        return \array_map(static fn (Address $address): string => $address->getAddress(), \array_values($addresses));
    }
}
