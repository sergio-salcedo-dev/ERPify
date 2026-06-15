<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\NodeModifier;

/**
 * A comparison strategy for a single asserted node (JSON node or entity property).
 *
 * Resolution is by name: a selector carrying an explicit `field::<modifier>` suffix is
 * routed to the modifier whose {@see getModifier()} equals that suffix (see
 * {@see NodeModifierLocator}). When no suffix is present, value-aware modifiers may still
 * opt in through {@see supportsValue()}. The `test.node_modifier` tag (wired in
 * `config/services_behat.yaml` via `_instanceof`) is what makes an implementation
 * discoverable by the locator.
 */
interface NodeModifierInterface
{
    /** Canonical name: the `field::<modifier>` selector suffix and the locator's map key. */
    public function getModifier(): string;

    /** Strip this modifier's `::<modifier>` suffix from a selector, leaving the bare property path. */
    public function getPathCleaned(string $path): string;

    /** Normalise a value so both sides of a comparison share the same shape. */
    public function getProcessedValue(mixed $value): mixed;

    /** Whether `expected` and `value` are equal under this modifier's semantics. */
    public function compare(mixed $expected, mixed $value): bool;

    /**
     * Opt-in auto-detection for selectors with no explicit `::<modifier>` suffix: the locator
     * picks this modifier when it recognises the *value* shape (e.g. `now`, a backed-enum FQN).
     * Defaults to false in {@see AbstractNodeModifier}; only value-aware modifiers override it.
     */
    public function supportsValue(mixed $value): bool;
}
