<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\NodeModifier;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('test.node_modifier')]
interface NodeModifierInterface
{
    public function getModifier(): string;

    public function support(string $path, mixed $value): bool;

    public function getPathCleaned(string $path): string;

    public function getProcessedValue(mixed $value): mixed;

    public function compare(mixed $expected, mixed $value): bool;
}
