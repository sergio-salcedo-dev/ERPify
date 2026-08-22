<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Shared\Audit\Domain\AuditErasureEvidence;
use Erpify\Tests\Support\AuditEvidenceActions;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Static gate over `api/.audit-evidence-actions`: every audit action `src` declares is classified as the
 * trail's own erasure evidence or as an ordinary row, and the evidence half agrees with the closed set the
 * pruner binds. The resolution rules live in {@see AuditEvidenceActions}; this class is the assertions.
 *
 * {@see AuditErasureEvidence} made a DRIFTED token impossible — the literals are centralised, so a rename
 * breaks compilation rather than silently returning a row to the sweep. It could not see a MISSING MEMBER: a
 * context minting its own erasure-evidence action and never pointing at that class. The prune would take the
 * row at its level's ceiling and any reconciler built on it would then report every correctly-erased subject
 * as a permanent divergence — the exact defect the exemption exists to close, back in place with every gate
 * green. The omission failed toward deletion, which is why it earned a registry rather than a comment.
 *
 * What it cannot do is judge a classification: `ordinary` over real evidence passes. The registry header
 * states that limit and the rest in full. Falsifiability of the rules themselves — that each direction can
 * still go red at all — is {@see AuditEvidenceActionRulesGateTest}, against synthetic input.
 *
 * @internal
 */
#[CoversNothing]
final class AuditEvidenceActionRegistryGateTest extends TestCase
{
    /**
     * Failure preamble — a class const so CI log scrapers can grep the literal string, and so the rule
     * travels with the failure instead of just the offending token.
     */
    public const string FAILURE_PREAMBLE
        = 'Evidence may not expire before the thing it attests. An audit action that records an executed '
        . 'erasure must be exempt from the retention prune, or the reconciler that anti-joins it against an '
        . 'eternal tombstone reports every correctly-erased subject as a permanent divergence. Classify the '
        . 'action in api/.audit-evidence-actions, and keep the `evidence` half equal to '
        . 'AuditErasureEvidence::ACTIONS.';

    #[Test]
    public function everyAuditActionInSourceIsClassified(): void
    {
        $actions = $this->actions();

        $this->assertSame([], $this->disagreements()['unclassified'], \sprintf(
            '%s These action tokens are declared in src but carry no line in .audit-evidence-actions: %s. '
            . 'Declared at: %s.',
            self::FAILURE_PREAMBLE,
            \implode(', ', $this->disagreements()['unclassified']),
            \implode('; ', \array_merge(...\array_values(\array_intersect_key(
                $actions->actionsInSource(),
                \array_flip($this->disagreements()['unclassified']),
            )) ?: [[]])),
        ));
    }

    #[Test]
    public function everyClassifiedActionStillExistsInSource(): void
    {
        $this->assertSame([], $this->disagreements()['stale'], \sprintf(
            'These tokens are classified in .audit-evidence-actions but no longer declared anywhere in src: '
            . '%s. A registry nobody prunes stops describing the tree, and a stale `evidence` line is how a '
            . 'deleted exemption goes unnoticed.',
            \implode(', ', $this->disagreements()['stale']),
        ));
    }

    /**
     * Both directions, because either one alone is satisfiable while the defect ships: an `evidence` line
     * the pruner never binds retains nothing, and an exempt action nobody classified is the missing member
     * this gate exists for.
     */
    #[Test]
    public function theRegistryAndTheClosedSetAgreeInBothDirections(): void
    {
        $disagreements = $this->disagreements();

        $this->assertSame([], $disagreements['evidenceButNotExempt'], \sprintf(
            '%s Classified `evidence` but absent from AuditErasureEvidence::ACTIONS, so the prune still '
            . 'takes the row: %s.',
            self::FAILURE_PREAMBLE,
            \implode(', ', $disagreements['evidenceButNotExempt']),
        ));
        $this->assertSame([], $disagreements['exemptButOrdinary'], \sprintf(
            '%s Exempt from the prune but not classified `evidence`, so the registry no longer describes '
            . 'what ships: %s.',
            self::FAILURE_PREAMBLE,
            \implode(', ', $disagreements['exemptButOrdinary']),
        ));
    }

    /**
     * The tree is not allowed to be empty. Every assertion above is satisfied by a derivation that returns
     * nothing — a renamed collaborator, a moved `src`, a reflection that quietly stops matching — and would
     * report success over a gate that had stopped looking at anything.
     */
    #[Test]
    public function theDerivationActuallyReachesTheAuditWriters(): void
    {
        $inSource = $this->actions()->actionsInSource();

        $this->assertNotSame([], $inSource, 'The sweep found no audit action at all — it is not looking.');

        foreach (AuditErasureEvidence::ACTIONS as $action) {
            $this->assertArrayHasKey($action, $inSource, \sprintf(
                'The sweep did not reach %s, which is written by a known erasure path — so a green here '
                . 'proves nothing about the actions it did not see.',
                $action,
            ));
        }
    }

    /**
     * @return array{
     *     unclassified: list<string>,
     *     stale: list<string>,
     *     exemptButOrdinary: list<string>,
     *     evidenceButNotExempt: list<string>,
     * }
     */
    private function disagreements(): array
    {
        $actions = $this->actions();

        return AuditEvidenceActions::disagreements(
            $actions->actionsInSource(),
            $actions->classification(),
            AuditErasureEvidence::ACTIONS,
        );
    }

    private function actions(): AuditEvidenceActions
    {
        return AuditEvidenceActions::fromGateLocation(__DIR__);
    }
}
