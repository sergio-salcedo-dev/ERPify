<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Mailer;

use Erpify\Shared\Mailer\Infrastructure\RedactingMailer;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;

/**
 * The observable a unit test cannot reach: the translation boundary is the mailer this application injects.
 *
 * `RedactingMailerTest` drives the decorator by constructing it, which stays true whether or not the container
 * ever puts it in front of anything — and a decorator nobody wires is a silent no-op that every unit assertion
 * still passes. What makes the guarantee real is that resolving `MailerInterface` yields THIS class, so every
 * sender inherits the translation by injecting the interface rather than by remembering to catch.
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
            RedactingMailer::class,
            self::getContainer()->get(MailerInterface::class),
            'a send can reach the transport without its failure being translated',
        );
    }
}
