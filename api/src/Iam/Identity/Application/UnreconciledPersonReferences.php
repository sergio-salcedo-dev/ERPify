<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Shared\Privacy\Domain\PersonReferenceAxis;

/**
 * The verdict of {@see ReconcileErasedSubjectReferences}: per axis, the subject ids that place still holds
 * although no live identity backs them.
 *
 * A type rather than `array<string, list<string>>` for two reasons, and neither is "it may grow methods
 * later". First, key and value cannot be swapped or merged: {@see withAxis()} accepts exactly one pairing,
 * so a flat list of ids with no place attached is not expressible. Second, the command that prints this
 * never learns how it is stored — it asks for what has to be reported and gets places and ids, so a change
 * of shape here is not a change to the console output.
 *
 * Axes that reconcile are KEPT, not dropped. An axis reporting nothing is evidence it ran; an axis missing
 * from the verdict is indistinguishable from one that was never wired, which is the failure this control is
 * built to make visible rather than to reproduce internally. {@see findings()} is the reporting projection
 * and drops them; {@see axesChecked()} is the count that proves they were there.
 *
 * The internal key is a plain `string` because it has to be: PHP array keys are `int|string`, so
 * `array<PersonReferenceAxis, list<string>>` cannot be written and PHPStan at `level: max` — the only type
 * gate here — rejects it. The rich type is what the API speaks in; the map underneath is keyed by the axis
 * key, which also makes one entry per axis a property of the structure rather than a rule to remember.
 */
final readonly class UnreconciledPersonReferences
{
    /**
     * @param array<string, list<string>> $subjectIdsByAxisKey
     */
    private function __construct(private array $subjectIdsByAxisKey)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param list<string> $subjectIds the ids of that axis no live identity backs, empty when it reconciles
     */
    public function withAxis(PersonReferenceAxis $axis, array $subjectIds): self
    {
        $byAxisKey = $this->subjectIdsByAxisKey;
        $byAxisKey[$axis->key()] = $subjectIds;

        return new self($byAxisKey);
    }

    public function isEmpty(): bool
    {
        return [] === $this->findings();
    }

    /**
     * How many axes were asked, including the ones with nothing to report — the number an operator reads as
     * "this is how much of the obligation was actually checked".
     */
    public function axesChecked(): int
    {
        return \count($this->subjectIdsByAxisKey);
    }

    public function total(): int
    {
        return \array_sum(\array_map(
            static fn (PersonReferenceFinding $finding): int => \count($finding->subjectIds),
            $this->findings(),
        ));
    }

    /**
     * @return list<PersonReferenceFinding> only the axes holding something, in the order they were checked
     */
    public function findings(): array
    {
        $findings = [];

        foreach ($this->subjectIdsByAxisKey as $key => $subjectIds) {
            if ([] !== $subjectIds) {
                $findings[] = new PersonReferenceFinding(PersonReferenceAxis::of($key), $subjectIds);
            }
        }

        return $findings;
    }
}
