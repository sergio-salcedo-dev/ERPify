<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Mailer\Infrastructure;

use Erpify\Shared\Mailer\Infrastructure\PlainTextNotificationMailer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PlainTextNotificationMailer::class)]
final class PlainTextNotificationMailerTest extends TestCase
{
    public function testRendersScalarBooleanAndNullFieldsWithoutDroppingThem(): void
    {
        $mailer = new CapturingMailer();
        (new PlainTextNotificationMailer($mailer, 'noreply@erpify.test'))->send(
            'ops@erpify.test',
            'Subject',
            ['name' => 'Acme', 'count' => 0, 'active' => false, 'archived' => true, 'parent' => null],
        );

        $body = $this->capturedBody($mailer);

        // A scalar `false`/`0`/`null` must render visibly, never collapse to an empty `%s`.
        $this->assertStringContainsString('name: Acme', $body);
        $this->assertStringContainsString('count: 0', $body);
        $this->assertStringContainsString('active: false', $body);
        $this->assertStringContainsString('archived: true', $body);
        $this->assertStringContainsString('parent: null', $body);
    }

    public function testMarksAnUnserializableFieldInsteadOfDroppingIt(): void
    {
        $mailer = new CapturingMailer();
        // Invalid UTF-8 inside a non-scalar value makes json_encode() return false.
        (new PlainTextNotificationMailer($mailer, 'noreply@erpify.test'))->send(
            'ops@erpify.test',
            'Subject',
            ['payload' => ['bad' => "\xB1\x31"]],
        );

        $this->assertStringContainsString('payload: [unserializable]', $this->capturedBody($mailer));
    }

    public function testEncodesArrayFieldsAsJson(): void
    {
        $mailer = new CapturingMailer();
        (new PlainTextNotificationMailer($mailer, 'noreply@erpify.test'))->send(
            'ops@erpify.test',
            'Subject',
            ['meta' => ['k' => 'v']],
        );

        $this->assertStringContainsString('meta: {"k":"v"}', $this->capturedBody($mailer));
    }

    private function capturedBody(CapturingMailer $mailer): string
    {
        $this->assertInstanceOf(\Symfony\Component\Mime\Email::class, $mailer->lastEmail);
        $body = $mailer->lastEmail->getTextBody();
        $this->assertIsString($body);

        return $body;
    }
}
