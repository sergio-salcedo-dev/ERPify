<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\DependencyInjection;

use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use Erpify\Shared\Event\Infrastructure\DependencyInjection\RegisterDomainEventsPass;
use Erpify\Shared\Event\Infrastructure\Mapper\ReflectionDomainEventMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * @internal
 */
#[CoversClass(RegisterDomainEventsPass::class)]
final class RegisterDomainEventsPassTest extends TestCase
{
    private const string BANK_CREATED_KEY = 'erpify.backoffice.bank.created:1';

    #[Test]
    public function itFeedsTheDiscoveredEventIdentityTablesIntoTheMapper(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $this->projectDir());

        $definition = $container->setDefinition(ReflectionDomainEventMapper::class, new Definition());

        (new RegisterDomainEventsPass())->process($container);

        $classByKey = $definition->getArgument('$classByKey');
        $aggregateTypeByKey = $definition->getArgument('$aggregateTypeByKey');

        $this->assertIsArray($classByKey);
        $this->assertIsArray($aggregateTypeByKey);
        $this->assertSame(BankCreatedDomainEvent::class, $classByKey[self::BANK_CREATED_KEY] ?? null);
        $this->assertSame('Backoffice.Bank', $aggregateTypeByKey[self::BANK_CREATED_KEY] ?? null);
    }

    #[Test]
    public function itIsANoOpWhenTheMapperIsNotRegistered(): void
    {
        $container = new ContainerBuilder();

        (new RegisterDomainEventsPass())->process($container);

        $this->assertFalse($container->hasDefinition(ReflectionDomainEventMapper::class));
    }

    private function projectDir(): string
    {
        $file = (new ReflectionClass(BankCreatedDomainEvent::class))->getFileName();
        $this->assertIsString($file);
        $position = \strpos($file, '/src/');
        $this->assertIsInt($position);

        return \substr($file, 0, $position);
    }
}
