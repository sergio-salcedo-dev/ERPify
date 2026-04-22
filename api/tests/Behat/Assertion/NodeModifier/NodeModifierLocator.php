<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Assertion\NodeModifier;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

readonly class NodeModifierLocator
{
    /** @param iterable<NodeModifierInterface> $nodeModifiers */
    public function __construct(
        #[AutowireIterator('test.node_modifier')]
        private iterable $nodeModifiers,
    ) {
    }

    public function getFor(string $path, mixed $value): ?NodeModifierInterface
    {
        foreach ($this->nodeModifiers as $nodeModifier) {
            if ($nodeModifier->support($path, $value)) {
                return $nodeModifier;
            }
        }

        return null;
    }
}
