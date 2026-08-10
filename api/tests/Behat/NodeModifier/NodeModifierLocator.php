<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\NodeModifier;

use Erpify\Tests\Behat\NodeModifier\Exception\DuplicateNodeModifierException;
use Erpify\Tests\Behat\NodeModifier\Exception\EmptyNodeModifierRegistryException;
use Erpify\Tests\Behat\NodeModifier\Exception\UnknownNodeModifierException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Resolves the {@see NodeModifierInterface} for an asserted node.
 *
 * Resolution is deterministic and two-phase:
 *   1. Explicit — a selector ending in `::<modifier>` is looked up by name (O(1)); an
 *      unknown suffix is a loud {@see UnknownNodeModifierException}, never a silent miss.
 *   2. Auto-detect — a selector with no suffix is offered to value-aware modifiers
 *      (those overriding {@see NodeModifierInterface::supportsValue()}), in a stable,
 *      name-sorted order so the result never depends on service-registration order.
 *
 * The constructor fails fast if the `test.node_modifier` iterable is empty or carries a
 * duplicate modifier name, turning a mis-wired tag into an explicit error rather than a
 * confusing downstream `NoSuchPropertyException`.
 *
 * **Known limitation, accepted on purpose: a property path cannot carry a literal `::`.** The
 * suffix is taken from the last `::` in the selector, so `a::b` always reads as node `a` with
 * modifier `b` — a payload whose key genuinely contains `::` is unaddressable, and there is no
 * escape syntax. Two reasons to leave it:
 *
 *   1. The measured need is zero. Across the whole feature suite the only `::` in any selector are
 *      registered modifier suffixes; no path carries a literal one. An escape syntax would add a
 *      rule every reader of every `.feature` has to learn, for a case none of them has.
 *   2. The obvious alternative — treat the suffix as a modifier *only if the name is registered*,
 *      and as a literal path otherwise — contradicts the contract two paragraphs up. A misspelled
 *      modifier would stop being a loud `UnknownNodeModifierException` and become a silent lookup
 *      for a node no payload has. Trading a loud failure for a quiet one is the wrong direction,
 *      and it is the direction that costs debugging time.
 *
 * A bare trailing `::` is already handled below as a literal path, since it names no modifier.
 * If a payload key with `::` ever arrives, the escape belongs here and needs tests on both sides.
 */
readonly class NodeModifierLocator
{
    /** @var array<string, NodeModifierInterface> keyed by modifier name, name-sorted */
    private array $byName;

    /**
     * @param iterable<NodeModifierInterface> $nodeModifiers
     *
     * @throws DuplicateNodeModifierException
     * @throws EmptyNodeModifierRegistryException
     */
    public function __construct(
        #[AutowireIterator('test.node_modifier')]
        iterable $nodeModifiers,
    ) {
        $byName = [];

        foreach ($nodeModifiers as $nodeModifier) {
            $name = $nodeModifier->getModifier();

            if (isset($byName[$name])) {
                throw new DuplicateNodeModifierException($name, $byName[$name], $nodeModifier);
            }

            $byName[$name] = $nodeModifier;
        }

        if ([] === $byName) {
            throw new EmptyNodeModifierRegistryException();
        }

        \ksort($byName);
        $this->byName = $byName;
    }

    /**
     * @throws UnknownNodeModifierException when the selector names a `::<modifier>` that is not registered
     */
    public function getFor(string $path, mixed $value): ?NodeModifierInterface
    {
        $name = $this->explicitModifierName($path);

        if (null !== $name) {
            return $this->byName[$name]
                ?? throw new UnknownNodeModifierException($name, \array_keys($this->byName));
        }

        // why: with no explicit suffix the first value-aware modifier (in name-sorted order)
        // wins — there is no real tie-break. Value-aware modifiers must therefore claim
        // disjoint value shapes; e.g. SimpleDateNodeModifier opts out of supportsValue() so it
        // never competes with DateNodeModifier for a date-shaped value.
        foreach ($this->byName as $nodeModifier) {
            if ($nodeModifier->supportsValue($value)) {
                return $nodeModifier;
            }
        }

        return null;
    }

    private function explicitModifierName(string $path): ?string
    {
        $position = \strrpos($path, '::');

        if (false === $position) {
            return null;
        }

        $name = \substr($path, $position + 2);

        // A bare trailing `::` carries no modifier name: treat the path literally rather
        // than raising UnknownNodeModifierException for an empty name.
        return '' === $name ? null : $name;
    }
}
