<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Mailer;

use Erpify\Shared\Mailer\Infrastructure\RedactingTransport;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\DependencyInjection\Attribute\WhenNot;
use Symfony\Component\Mailer\Command\MailerTestCommand;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * The observable a unit test cannot reach: the translation boundary is the transport this application injects.
 *
 * `RedactingTransportTest` drives the decorator by constructing it, which stays true whether or not the
 * container ever puts it in front of anything — and a decorator nobody wires is a silent no-op that every unit
 * assertion still passes.
 *
 * **The collection is the primary assertion**, because decorating that id substitutes the decorator for every
 * consumer of it — including Messenger's `MessageHandler`, which is named nowhere here, and anything a future
 * bundle wires the same way. The mailer and the console command are asserted on top as defence: they are the
 * two consumers whose exposure is understood today, and naming them keeps a reader from having to rediscover
 * why the boundary is not at `MailerInterface`.
 *
 * All three read the TEST container, which is the same container shape that missed the defect this boundary
 * was moved to fix. `#[When]` closes that gap from the other side: an environment condition on the decorator
 * would keep every gate green while removing it from production, which is the failure mode `CLAUDE.md` records
 * under declaring a class out of production.
 *
 * @internal
 */
#[CoversNothing]
final class MailerBoundaryEnrolmentTest extends KernelTestCase
{
    #[Test]
    public function theBoundaryIsNotConditionedOutOfAnyEnvironment(): void
    {
        // Read off the class, not the container: the test container cannot answer for the production one, and
        // an attribute that removed the decorator there would leave every assertion in this file green.
        //
        // Both attributes, because `WhenNot` does not extend `When` — it is an independent class the loader
        // reads to DE-register a service in the environment it names, so a check for one of them is blind to
        // the other, and `#[WhenNot(env: 'prod')]` is the cheaper way to reopen exactly this hole.
        $reflection = new ReflectionClass(RedactingTransport::class);

        $this->assertSame(
            [],
            $reflection->getAttributes(When::class, ReflectionAttribute::IS_INSTANCEOF),
            'the boundary is conditioned into named environments, so production may not have it',
        );
        $this->assertSame(
            [],
            $reflection->getAttributes(WhenNot::class, ReflectionAttribute::IS_INSTANCEOF),
            'the boundary is conditioned out of a named environment, so production may not have it',
        );
    }

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
        // Guarded like its sibling below: without this the test dies with a ReflectionException naming a
        // missing property rather than an assertion naming the boundary, if the mailer is ever decorated.
        $this->assertInstanceOf(Mailer::class, $mailer);

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
