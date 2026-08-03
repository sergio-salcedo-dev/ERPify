<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Cli;

use Erpify\Iam\Identity\Application\ReconcileErasedSubjectReferences;
use Erpify\Iam\Identity\Application\UnreconciledPersonReferences;
use Erpify\Shared\Privacy\Domain\PersonReferenceAxis;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Verifies every place a person's identifier is persisted: no identity may be gone from `identity_user`
 * while another table still names it by its real id. Exits non-zero on divergence, so it serves an operator
 * on demand and a cron / monitoring check alike — the sibling of `audit:gdpr:reconcile-erasures`, which does
 * the same for crypto-shredding evidence.
 *
 * The report is grouped by place and never merged into one list. Which table still holds the id is what
 * decides whether an operator is looking at an audit trail whose resource axis went unanonymised or at a
 * membership that outlived the person it admitted, and a merged list would also let one wired place stand in
 * for all of them.
 *
 * A finding means some erasure path completed partially, leaving the subject's real id behind. The repair is
 * `identity:gdpr:erase-subject <id>` for every place alike: it is idempotent, so it runs against an identity
 * that is already gone and discharges the links that were skipped. Its outcome on the audit trail depends on
 * how the divergence arose, and both outcomes are correct:
 *
 *   - The identity was hard-deleted by a path that left the actor axis intact (a legacy identity-only
 *     delete). The repair's actor pass still matches those rows, so both axes take one pseudonym.
 *   - The actor axis was already erased on its own through `audit:gdpr:erase`. That pseudonym is
 *     irreversible by design and cannot be recovered, so the repair completes the resource axis under a
 *     *distinct* one; in a row where the subject was both actor and resource the two axes then read as two
 *     anonymous identities. This is accepted — neither pseudonym reverts to the person, which is the
 *     property that matters. Reusing the lost actor pseudonym is not possible, and teaching the actor-only
 *     command to refuse such a subject is a separate decision left open deliberately.
 */
#[AsCommand(
    name: 'identity:gdpr:reconcile-subject-references',
    description: 'Verify no erased identity is still named by its real id in any table that references one',
)]
final class ReconcileErasedSubjectReferencesCommand extends Command
{
    public function __construct(
        private readonly ReconcileErasedSubjectReferences $reconciler,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $verdict = $this->reconciler->unreconciledReferences();

        if ($verdict->isEmpty()) {
            // The count of places checked, not a bare "all clear": it is what tells an operator how much of
            // the obligation this run actually covered, and a silent drop to one place would otherwise read
            // exactly like a clean sweep.
            $io->success(\sprintf(
                'No erased identity is still named by its real id. %d reference axis(es) checked.',
                $verdict->axesChecked(),
            ));

            return Command::SUCCESS;
        }

        $this->report($io, $verdict);

        return Command::FAILURE;
    }

    private function report(SymfonyStyle $io, UnreconciledPersonReferences $verdict): void
    {
        $io->error(\sprintf(
            '%d person reference(s) survive an erasure, across %d of %d axis(es):',
            $verdict->total(),
            \count($verdict->findings()),
            $verdict->axesChecked(),
        ));

        foreach ($verdict->findings() as $personReferenceFinding) {
            $io->section($this->headingFor($personReferenceFinding->axis));
            $io->listing($personReferenceFinding->subjectIds);
        }

        $io->note([
            'Repair with `identity:gdpr:erase-subject <id>`, which discharges every reference of the '
            . 'subject and anonymises both audit axes. It is idempotent, so it runs against an identity '
            . 'that is already gone.',
            'If the actor axis was already erased on its own via `audit:gdpr:erase`, the resource axis is '
            . 'completed under a separate pseudonym — the original one is irreversible and cannot be reused. '
            . 'Neither reverts to the person, so this still completes the erasure.',
        ]);
    }

    /**
     * The axis key with its namespace dropped — `Membership::$userId`, `audit_log.resource_id`.
     *
     * Readable text is the presenter's job, never the value object's
     * (`docs/adr/domain-presentation-separation.md`), and it is DERIVED here rather than looked up in a
     * table of display names: a per-axis name maintained beside the key would be a second vocabulary for
     * the same column, and the two drift until the operator reading this report and the author reading
     * `api/.person-reference-policy` are naming different things with the same word.
     */
    private function headingFor(PersonReferenceAxis $axis): string
    {
        $namespace = \strrpos($axis->key(), '\\');

        return false === $namespace ? $axis->key() : \substr($axis->key(), $namespace + 1);
    }
}
