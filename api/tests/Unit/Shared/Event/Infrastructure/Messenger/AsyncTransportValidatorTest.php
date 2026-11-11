<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Messenger;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Event\Infrastructure\Messenger\AsyncTransportValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * @internal
 */
#[CoversClass(AsyncTransportValidator::class)]
final class AsyncTransportValidatorTest extends TestCase
{
    #[Test]
    public function itAdmitsATransportClassTheConfigurationNames(): void
    {
        $validator = new AsyncTransportValidator(
            new ServiceLocator(['async' => static fn (): InMemoryTransport => new InMemoryTransport()]),
            $this->createStub(EntityManagerInterface::class),
            [InMemoryTransport::class],
        );

        $this->assertNull($validator->problem());
    }

    #[Test]
    public function itInspectsTheTransportOncePerProcess(): void
    {
        $builds = 0;
        $validator = new AsyncTransportValidator(
            new ServiceLocator(['async' => static function () use (&$builds): InMemoryTransport {
                ++$builds;

                return new InMemoryTransport();
            }]),
            $this->createStub(EntityManagerInterface::class),
        );

        $first = $validator->problem();

        $this->assertNotNull($first, 'an in-memory transport is not admitted unless configured');
        $this->assertSame($first, $validator->problem());
        $this->assertSame(1, $builds);
    }

    #[Test]
    public function itInspectsTheTransportOnceEvenWhenTheAnswerIsNoProblem(): void
    {
        $builds = 0;
        $validator = new AsyncTransportValidator(
            new ServiceLocator(['async' => static function () use (&$builds): InMemoryTransport {
                ++$builds;

                return new InMemoryTransport();
            }]),
            $this->createStub(EntityManagerInterface::class),
            [InMemoryTransport::class],
        );

        $this->assertNull($validator->problem());
        $this->assertNull($validator->problem());
        $this->assertSame(1, $builds);
    }
}
