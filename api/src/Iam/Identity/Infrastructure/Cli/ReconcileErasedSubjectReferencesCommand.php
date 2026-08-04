<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Cli;

use Erpify\Iam\Identity\Application\PersonReferenceProbeFailed;
use Erpify\Iam\Identity\Application\ReconcileErasedSubjectReferences;
use Erpify\Iam\Identity\Application\UnreconciledPersonReferences;
use LogicException;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Verifies the places `api/.person-reference-policy` classifies as holding a person's identifier, plus the
 * audit trail's resource axis: no identity may be gone from `identity_user` while one of them still names it
 * by its real id. It serves an operator on demand and a cron / monitoring check alike — the sibling of
 * `audit:gdpr:reconcile-erasures`, which does the same for crypto-shredding evidence.
 *
 * Three exit codes, because a check that reads nothing but the code needs them distinct: `SUCCESS` (every
 * axis reconciles), `FAILURE` (a person reference survived its erasure — actionable, repair below) and
 * `INVALID` (no verdict was reached, so nothing is known either way).
 *
 * `INVALID` covers two causes and that is deliberate rather than a shortcut. A read that failed and a
 * control that is miswired differ in who repairs them, and the message says which — but they agree on the
 * only thing the exit code exists to convey: **this run established nothing, so do not repair a subject on
 * the strength of it.** Splitting them would need a fourth, project-invented number, and Console defines no
 * constant for it; the two are distinguished where the distinction is acted on, by a human reading the
 * error. The scheduled arm keeps them apart differently and on purpose — there the wiring bug propagates
 * untouched, so it reaches the error tracker as the programming fault it is.
 *
 * It does NOT cover every person id in the database, and the success message names what it checked rather
 * than counting it so that the difference is visible in the output itself: `audit_log.actor_id` and
 * `event_store.aggregate_id` hold person ids and are outside this control (the registry's blind-spot block
 * says so). Both are erased by the identity erasure all the same; being outside this command means an escaped
 * row there would go undetected, not that nobody is obliged to remove it.
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

        try {
            $verdict = $this->reconciler->unreconciledReferences();
        } catch (PersonReferenceProbeFailed $personReferenceProbeFailed) {
            return $this->reportBrokenProbe($io, $personReferenceProbeFailed);
        } catch (LogicException $logicException) {
            return $this->reportMiswiredControl($io, $logicException);
        }

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

    /**
     * A third exit code, because the contract of this command IS its exit code and two outcomes sharing one
     * non-zero value are indistinguishable to the only consumer it declares. `INVALID` is Console's existing
     * "this run did not produce an answer" — a fourth, project-invented number would mean nothing to the
     * `messenger:failed:*`-shaped tooling around it.
     *
     * The distinction is not cosmetic: `FAILURE` sends someone to `identity:gdpr:erase-subject`, which would
     * be the wrong act here — nothing was established about any subject, and repairing on the strength of a
     * failed read would be acting on a verdict that does not exist.
     */
    private function reportBrokenProbe(SymfonyStyle $io, PersonReferenceProbeFailed $probeFailed): int
    {
        $io->error([
            'The reconciliation could not be completed, so nothing is known about whether a person '
            . 'reference survived its erasure. This is NOT a finding: do not repair anything on the '
            . 'strength of it.',
            $probeFailed->getMessage(),
        ]);

        return Command::INVALID;
    }

    /**
     * The same exit code as a failed read, and a different repair. Two sources claiming one axis means a
     * place stopped being covered, so like a failed probe this run reached no trustworthy verdict — but the
     * fix is a wiring change by whoever added the source, not an infrastructure repair, and the report has
     * to say so or an operator will chase a database that is fine.
     *
     * Caught here and NOT left to propagate, because an uncaught throwable leaves Console with the code
     * carried by the exception, and a `LogicException` carries none — Symfony's `Application` then coerces
     * it to `1`, which is `FAILURE`, the one code that means "a person survived their erasure, go repair
     * them". Staying loud is worth having; being loud in the shape of a compliance finding is not.
     */
    private function reportMiswiredControl(SymfonyStyle $io, LogicException $logicException): int
    {
        $io->error([
            'The control is miswired, so this run reached no verdict. This is NOT a finding and NOT an '
            . 'infrastructure fault: a place is claimed by more than one source, which means some other '
            . 'place is going unchecked. Do not repair any subject on the strength of this run.',
            $logicException->getMessage(),
            'Fix the duplicate registration, then run `make php.lint.person-reference`, whose one-source-'
            . 'per-axis direction is what should have caught this before it shipped.',
        ]);

        return Command::INVALID;
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
