<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Shared\Privacy\Domain\PersonReferenceAxis;
use LogicException;

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
     * A repeated axis is REFUSED rather than overwritten. Keying by axis makes at most one entry per axis
     * structurally true, but assignment would deliver that by discarding the earlier findings — one whole
     * place unreported while both sources look wired, which is the merged-verdict defect this type exists
     * against. It would also decrement `axesChecked()`, the one number an operator has to notice a shrunken
     * control. The build-time gate forbids two sources on one axis; this refuses the pairing anywhere the
     * gate cannot see, and it is a programming error either way.
     *
     * @param list<string> $subjectIds the ids of that axis no live identity backs, empty when it reconciles
     *
     * @throws LogicException when the axis has already been recorded
     */
    public function withAxis(PersonReferenceAxis $axis, array $subjectIds): self
    {
        if (\array_key_exists($axis->key(), $this->subjectIdsByAxisKey)) {
            throw new LogicException(\sprintf(
                'Two sources reported the person-reference axis "%s". One would silently replace the other, '
                . 'leaving that place unreported while both look wired.',
                $axis->key(),
            ));
        }

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

    /**
     * Which places were asked, sorted — the evidence behind the count. An operator reading a clean run
     * cannot otherwise tell a full sweep from a control that quietly lost a source, and a number alone puts
     * the burden of remembering how many there should be on whoever reads the output.
     *
     * @return list<string>
     */
    public function axesCheckedKeys(): array
    {
        $keys = \array_keys($this->subjectIdsByAxisKey);
        \sort($keys);

        return $keys;
    }

    public function total(): int
    {
        return \array_sum(\array_map(
            static fn (PersonReferenceFinding $finding): int => \count($finding->subjectIds),
            $this->findings(),
        ));
    }

    /**
     * Ordered by axis key, not by the order the places were asked. The asking order is the compiled
     * container's tagged-iterator order, which is a filesystem walk: stable within one build and free to
     * reshuffle across a redeploy. Sorting is the same argument each source's `ORDER BY` already makes one
     * level down — an alert that diffs this report must fire on a changed set, never on a changed order.
     *
     * @return list<PersonReferenceFinding> only the axes holding something
     */
    public function findings(): array
    {
        $byAxisKey = \array_filter($this->subjectIdsByAxisKey, static fn (array $ids): bool => [] !== $ids);
        \ksort($byAxisKey);

        $findings = [];

        foreach ($byAxisKey as $key => $subjectIds) {
            $findings[] = new PersonReferenceFinding(PersonReferenceAxis::of($key), $subjectIds);
        }

        return $findings;
    }
}
