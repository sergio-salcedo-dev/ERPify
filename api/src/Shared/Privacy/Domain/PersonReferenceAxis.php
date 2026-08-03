<?php

declare(strict_types=1);

namespace Erpify\Shared\Privacy\Domain;

/**
 * One place a natural person's identifier is persisted, named by the key it is classified under.
 *
 * It exists so a verdict about person references cannot be a flat merged list. With one list, a check that
 * seeds a single place and asserts non-empty passes while every other source sits unwired — the detective
 * control would then need a detective control of its own. Carrying the place with the ids makes that
 * impossible to express, and makes key and value impossible to swap: `withAxis(self, list<string>)` accepts
 * no other pairing.
 *
 * The key is the `<Fqcn>::$<property>` line of `api/.person-reference-policy` for every column the registry
 * carries, which is what lets the completeness gate enumerate the sources against the registry rather than
 * against a hand-written list. One place is deliberately outside that vocabulary — `audit_log.resource_id`,
 * whose table has no Doctrine entity, so no property declares it and the registry's reflection sweep is
 * structurally blind to it (the registry header states that blind spot in full). It is named by its column
 * instead, which is why this type takes any non-empty key rather than only a registry one.
 *
 * There is no runtime validation of the key. Every caller passes a compile-time constant, and the one thing
 * a wrong key could mean — a source covering a column the registry does not carry, or a registry line no
 * source covers — is exactly what the completeness gate reports, in both directions. A guard here would
 * duplicate that check where it can only fire after the build already went green.
 *
 * It carries the identity and nothing readable. What an operator's console prints for an axis is derived
 * from this key by whoever is presenting it (`docs/adr/domain-presentation-separation.md`) — derived rather
 * than stored, so there is never a display name maintained beside the key for the two to drift apart.
 */
final readonly class PersonReferenceAxis
{
    private function __construct(private string $key)
    {
    }

    public static function of(string $key): self
    {
        return new self($key);
    }

    public function key(): string
    {
        return $this->key;
    }
}
