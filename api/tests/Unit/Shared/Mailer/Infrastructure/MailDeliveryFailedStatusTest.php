<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Mailer\Infrastructure;

use Erpify\Shared\Mailer\Infrastructure\MailDeliveryFailed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The composed message's diagnosis field, on its own: what counts as an RFC 3463 enhanced status, and what
 * only looks like one. Apart from {@see RedactingTransportTest} because the question is the composition, not
 * the transport that triggers it — and because a diagnosis read out of prose is a defect of its own kind.
 *
 * @internal
 */
#[CoversClass(MailDeliveryFailed::class)]
final class MailDeliveryFailedStatusTest extends TestCase
{
    /**
     * A status is identified by the reply code in front of it, not by its shape. Both of these were measured
     * reporting a status before that anchor existed: a local part whose hyphen opened the run, and a version
     * number in prose. A fabricated diagnosis costs an operator the time this field exists to save.
     */
    #[Test]
    #[DataProvider('provideItRefusesADiagnosisNoReplyCodeIntroducesCases')]
    public function itRefusesADiagnosisNoReplyCodeIntroduces(string $message): void
    {
        $failure = MailDeliveryFailed::from(new RuntimeException($message));

        $this->assertStringContainsString('status none', $failure->getMessage());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideItRefusesADiagnosisNoReplyCodeIntroducesCases(): iterable
    {
        yield 'a dotted run inside a local part' => ['a-5.1.1@example.test rejected'];
        yield 'a version number in prose' => ['Upgrade to version 4.2.3 first'];
        yield 'an IPv4 address' => ['Connection to 5.1.1.1 timed out'];
    }

    /** A bounce naming one status per recipient answered with the first, which is the wrong half as often as the right one. */
    #[Test]
    public function itReportsEveryStatusTheReplyCarries(): void
    {
        $failure = MailDeliveryFailed::from(new RuntimeException('550 5.7.0 first; 550 4.4.7 second'));

        $this->assertStringContainsString('status 5.7.0/4.4.7', $failure->getMessage());
    }

    /** Every line but the last of a multi-line reply spells the code with a hyphen, which is what Google emits. */
    #[Test]
    public function itReadsTheStatusOffAContinuationLine(): void
    {
        $failure = MailDeliveryFailed::from(new RuntimeException('550-5.1.1 mailbox unavailable'));

        $this->assertStringContainsString('status 5.1.1', $failure->getMessage());
    }
}
