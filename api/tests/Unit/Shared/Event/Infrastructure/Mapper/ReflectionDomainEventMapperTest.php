<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Mapper;

use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use Erpify\Shared\Event\Infrastructure\Mapper\ReflectionDomainEventMapper;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ReflectionDomainEventMapper::class)]
final class ReflectionDomainEventMapperTest extends TestCase
{
    private const string KEY = 'erpify.backoffice.bank.created:1';

    #[Test]
    public function itResolvesTheClassAndAggregateTypeForAKnownKey(): void
    {
        $mapper = $this->mapper();

        $this->assertSame(BankCreatedDomainEvent::class, $mapper->classFor('erpify.backoffice.bank.created', 1));
        $this->assertSame('Backoffice.Bank', $mapper->aggregateTypeFor('erpify.backoffice.bank.created', 1));
    }

    #[Test]
    public function classForThrowsForAnUnknownKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No domain event is registered for "erpify.backoffice.bank.created" (v2).');

        $this->mapper()->classFor('erpify.backoffice.bank.created', 2);
    }

    #[Test]
    public function aggregateTypeForThrowsForAnUnknownKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No domain event is registered for "unknown" (v1).');

        $this->mapper()->aggregateTypeFor('unknown', 1);
    }

    private function mapper(): ReflectionDomainEventMapper
    {
        return new ReflectionDomainEventMapper(
            [self::KEY => BankCreatedDomainEvent::class],
            [self::KEY => 'Backoffice.Bank'],
        );
    }
}
