<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Mailer;

use Erpify\Shared\Mailer\Infrastructure\RedactingTransport;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Command\MailerTestCommand;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * The observable a unit test cannot reach: the translation boundary is the transport this application injects.
 *
 * `RedactingTransportTest` drives the decorator by constructing it, which stays true whether or not the
 * container ever puts it in front of anything — and a decorator nobody wires is a silent no-op that every unit
 * assertion still passes.
 *
 * The command is asserted separately from the collection on purpose. It is the reason the boundary sits at the
 * transport rather than at the mailer: it takes the collection directly, calls `send()` on it with no `try`,
 * and its failure reaches the console error listener, which logs at `critical` with the recipient in three
 * fields. An enrolment assertion that only read the mailer would stay green while that path reopened.
 *
 * @internal
 */
#[CoversNothing]
final class MailerBoundaryEnrolmentTest extends KernelTestCase
{
    #[Test]
    public function everySendGoesThroughTheTranslationBoundary(): void
    {
        self::bootKernel();

        $this->assertInstanceOf(
            RedactingTransport::class,
            self::getContainer()->get('mailer.transports'),
            'a send can reach the transport without its failure being translated',
        );
    }

    #[Test]
    public function theMailerItselfSendsThroughTheBoundary(): void
    {
        self::bootKernel();

        $mailer = self::getContainer()->get(MailerInterface::class);
        $transport = new ReflectionProperty($mailer, 'transport');

        $this->assertInstanceOf(RedactingTransport::class, $transport->getValue($mailer));
    }

    #[Test]
    public function theConsoleTestCommandSendsThroughTheBoundary(): void
    {
        self::bootKernel();

        $command = self::getContainer()->get('console.command.mailer_test');
        $this->assertInstanceOf(MailerTestCommand::class, $command);

        $transport = new ReflectionProperty($command, 'transport');
        $value = $transport->getValue($command);

        $this->assertInstanceOf(TransportInterface::class, $value);
        $this->assertInstanceOf(
            RedactingTransport::class,
            $value,
            '`mailer:test` reaches the transport untranslated, and the console error listener logs the recipient',
        );
    }
}
