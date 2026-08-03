<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Cli;

use Erpify\Iam\Identity\Application\ReconcileErasedSubjectReferences;
use Erpify\Iam\Identity\Application\UnreconciledPersonReferences;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Verifies the places `api/.person-reference-policy` classifies as holding a person's identifier, plus the
 * audit trail's resource axis: no identity may be gone from `identity_user` while one of them still names it
 * by its real id. Exits non-zero on divergence, so it serves an operator on demand and a cron / monitoring
 * check alike — the sibling of `audit:gdpr:reconcile-erasures`, which does the same for crypto-shredding
 * evidence.
 *
 * It does NOT cover every person id in the database, and the success message names what it checked rather
 * than counting it so that the difference is visible in the output itself: `audit_log.actor_id`,
 * `audit_log.metadata` and `event_store.aggregate_id` hold person ids and are outside this control (the
 * registry's blind-spot block says so, and each has a story of its own).
 *
 * The report is grouped by place and never merged into one list. Which table still holds the id is what
 * decides whether an operator is looking at an audit trail whose resource axis went unanonymised or at a
 * membership that outlived the person it admitted, and a merged list would also let one wired place stand in
 * for all of them.
 *
 * A finding means some erasure path completed partially, leaving the subject's real id behind. The repair is
 * `identity:gdpr:erase-subject <id> --force` for every place alike: it is idempotent, so it runs against an
 * identity that is already gone and discharges the links that were skipped. `--force` is not optional in an
 * unattended repair — without it the command prompts, and a non-interactive run declines and erases nothing
 * while still exiting zero. Its outcome on the audit trail depends on how the divergence arose, and both
 * outcomes are correct:
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
    description: 'Verify no erased identity is still named by its real id in a classified person-reference column',
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
            // The places checked BY NAME, not a bare "all clear" and not a bare count: nothing at runtime
            // fails when the collection of sources shrinks, so a control silently reduced to one axis would
            // print what a full clean sweep prints and a monitoring check reading the exit code would never
            // know. Naming them puts the drop in the output, where a diffing alert sees it with no expected
            // count for anyone to maintain — and it is also what keeps the success line from reading as a
            // guarantee about every person id in the database.
            $io->success('No erased identity is still named by its real id in the axes checked.');
            $io->listing($verdict->axesCheckedKeys());

            return Command::SUCCESS;
        }

        $this->report($io, $verdict);

        return Command::FAILURE;
    }

    private function report(SymfonyStyle $io, UnreconciledPersonReferences $verdict): void
    {
        $findings = $verdict->findings();

        $io->error(\sprintf(
            '%d person reference(s) survive an erasure, across %d of %d axis(es):',
            $verdict->total(),
            \count($findings),
            $verdict->axesChecked(),
        ));

        // The denominator BY NAME, for the same reason the clean run lists them: a count cannot say WHICH
        // place stopped being checked. A collection that quietly loses a source while another axis diverges
        // would otherwise show only a smaller denominator beside a real finding — the moment an operator is
        // least likely to audit the control itself, because they are already acting on its output.
        $io->section('Axes checked');
        $io->listing($verdict->axesCheckedKeys());

        foreach ($findings as $finding) {
            // The registry key verbatim, not a short name. Readable text is the presenter's job, never the
            // value object's (`docs/adr/domain-presentation-separation.md`) — but the shortest readable
            // form is the wrong one here: two axes in different modules can share a class name, and a
            // heading that collapses them lets one section quietly stand for another. The full key is also
            // exactly what an operator greps for in `api/.person-reference-policy`, which is the point of
            // deriving the heading from the key instead of maintaining display names beside it.
            $io->section($finding->axis->key());
            $io->listing($finding->subjectIds);
        }

        $io->note([
            'Repair with `identity:gdpr:erase-subject <id> --force`, which discharges every reference of '
            . 'the subject and anonymises both audit axes. It is idempotent, so it runs against an identity '
            . 'that is already gone; without `--force` it prompts, and a non-interactive run then declines '
            . 'and erases nothing while still exiting zero.',
            'If the actor axis was already erased on its own via `audit:gdpr:erase`, the resource axis is '
            . 'completed under a separate pseudonym — the original one is irreversible and cannot be reused. '
            . 'Neither reverts to the person, so this still completes the erasure.',
        ]);
    }
}
