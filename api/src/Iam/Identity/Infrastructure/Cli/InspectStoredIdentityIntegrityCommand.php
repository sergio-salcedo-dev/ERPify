<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Cli;

use Erpify\Iam\Identity\Application\InspectStoredIdentity;
use Erpify\Iam\Identity\Application\StoredIdentityDrift;
use Erpify\Iam\Identity\Application\StoredIdentityProbeFailed;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Reports the stored identity rows the authentication path handles silently: role values no `Role` case backs,
 * and credentials `HashedPassword` refuses.
 *
 * It exists because both are handled *well*. The runtime discards the unknown role and refuses the unreadable
 * credential with a 401, which is the correct behaviour and also the reason nobody ever finds out: neither
 * raises, neither logs a finding, and every gate stays green over a table that is drifting. The runtime filter
 * proves the code is written; only a read of the rows proves the state is clean — the preventive/detective
 * split `api/.person-reference-policy` documents, applied to the identity's own columns.
 *
 * Three exit codes, because the contract of a control IS its exit code: `SUCCESS` (every read came back
 * empty), `FAILURE` (a row needs repairing) and `INVALID` (a read failed, so nothing was established either
 * way and no repair should be attempted on the strength of this run).
 *
 * The operator's arm of a control that also runs unattended: `IdentityMaintenanceSchedule` ticks the same
 * inspection daily and raises an `error` line on a finding. This one exists beside it because the two answer
 * different needs — the alarm says a table drifted, and this says WHICH values did, which the alarm
 * deliberately withholds from log storage. It is also what an operator runs after a repair to confirm it.
 *
 * What it does NOT cover: any other column, and any corruption of a shape these two queries do not describe. A
 * green here is a statement about `identity_user.roles` and `identity_user.password_hash` and nothing else,
 * which is why the success line names both rather than announcing a clean database.
 */
#[AsCommand(
    name: 'identity:integrity:inspect',
    description: 'Report identity rows the auth path tolerates silently: orphan roles, unreadable credentials',
)]
final class InspectStoredIdentityIntegrityCommand extends Command
{
    private const string DRIFT_ACTION = 'STORED_IDENTITY_DRIFT_DETECTED';

    public function __construct(
        private readonly InspectStoredIdentity $inspectStoredIdentity,
        private readonly AuditLogger $auditLogger,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $findings = ($this->inspectStoredIdentity)();
        } catch (StoredIdentityProbeFailed $storedIdentityProbeFailed) {
            $io->error([
                'The inspection could not be completed, so nothing is known about the stored identity rows. '
                . 'This is NOT a finding: do not repair anything on the strength of it.',
                $storedIdentityProbeFailed->getMessage(),
            ]);

            return Command::INVALID;
        }

        if ($findings->isClean()) {
            // Both columns BY NAME rather than a bare "all clear": what a green covers is exactly these
            // reads, and a success line that claimed more would be the kind of guarantee nobody can check.
            $io->success('Every stored identity row is representable by the code that reads it.');
            $io->listing(['identity_user.roles', 'identity_user.password_hash']);

            return Command::SUCCESS;
        }

        $this->report($io, $findings);
        $this->recordDrift($io, $findings);

        return Command::FAILURE;
    }

    /**
     * ONE `security` row per run that finds something — never one per identity and never one per request.
     *
     * The refusals themselves are the wrong place to record this. Both are re-evaluated on EVERY authenticated
     * request (the firewall reloads the identity each time), so a row at the point of refusal would be a write
     * amplifier bounded by nothing but the traffic of a broken account — the same reason
     * {@see \Erpify\Iam\Identity\Infrastructure\Http\InvalidCurrentPasswordAuditListener} is only meaningful
     * behind a throttle, except here there is no budget to put in front of it. Nor can the hot path recognise a
     * TRANSITION: telling "this row just drifted" from "this row has been drifting for a week" would mean
     * remembering the previous state, and the only store available would be the audit trail it is about to
     * write to. A reader of the whole table can tell, cheaply, once.
     *
     * Resource-less and count-only on purpose: the drifted identities are exactly the person ids this axis
     * would then have to erase, and the finding needs none of them to be actionable. The actor is sealed by the
     * adapter as `system`, which is the truth here — no request is in flight.
     *
     * Reported AFTER the operator's report, so a failing trail write cannot swallow the finding it is about.
     */
    private function recordDrift(SymfonyStyle $io, StoredIdentityDrift $findings): void
    {
        try {
            $this->auditLogger->log(
                self::DRIFT_ACTION,
                AuditLevel::SECURITY,
                null,
                $findings->toAuditMetadata(),
            );
        } catch (Throwable $throwable) {
            // The finding stands and has already been printed — only its trail entry failed. Saying so is what
            // stops an operator concluding, from a trail with no such row, that the drift was never detected.
            $io->error(\sprintf(
                'The finding above could not be recorded in the audit trail: %s',
                $throwable->getMessage(),
            ));
        }
    }

    private function report(SymfonyStyle $io, StoredIdentityDrift $findings): void
    {
        if ([] !== $findings->orphanRoleValues) {
            $io->section('identity_user.roles — values no Role case backs');
            // Escaped: the values are raw column text, and the console formatter reads `<info>`-shaped input
            // as markup. An operator repairs the string they were shown, so it has to be the stored one.
            $io->listing(\array_map(OutputFormatter::escape(...), $findings->orphanRoleValues));
            $io->note([
                'These are DISCARDED on every read, so the identities carrying them authenticate and simply '
                . 'hold fewer grants than their row says. Nobody is locked out and nothing is over-granted: '
                . 'the permission model is grant-only, so an unrecognised value concedes nothing.',
                'Repair by rewriting the column to the values the enum still defines. Retiring a case is what '
                . 'usually produces this, in which case the migration that retired it is what is missing.',
            ]);
        }

        if (0 !== $findings->malformedRoles) {
            $io->section('identity_user.roles — columns that are not a JSON array');
            $io->text(\sprintf('%d identity(ies).', $findings->malformedRoles));
            $io->note([
                "This one does not degrade quietly: a scalar there fails the entity's array property type "
                . 'during hydration, so the identity cannot be loaded at all and every path that reads it '
                . 'raises before any accessor runs.',
                "Find them with: SELECT id FROM identity_user WHERE json_typeof(roles) <> 'array'",
            ]);
        }

        if (0 !== $findings->unreadableCredentials) {
            $io->section('identity_user.password_hash — credentials the value object refuses');
            $io->text(\sprintf('%d identity(ies).', $findings->unreadableCredentials));
            $io->note([
                'Each is refused a session exactly as an unknown email is, so its owner cannot sign in and '
                . 'gets no clue why. The rows are NOT listed here on purpose: an identity id is a person '
                . 'reference and this output reaches terminals and job logs.',
                "Find them with: SELECT id FROM identity_user WHERE password_hash = ''",
                'The owner recovers by password reset, which rewrites the credential — the reset path reads '
                . 'the identity by email and never re-hydrates the stored hash, so a corrupt row does not '
                . 'block its own repair.',
            ]);
        }

        if (0 !== $findings->admittedWithoutCredential) {
            $io->section('identity_user.password_hash — admitted identities holding no credential');
            $io->text(\sprintf('%d identity(ies).', $findings->admittedWithoutCredential));
            $io->note([
                'The quietest of the four: nothing refuses it loudly, because the value object is never asked. '
                . 'The credentials check simply reads a null password as "cannot authenticate" and answers the '
                . 'same uniform 401 a wrong password gets, so the owner is locked out of a live account with '
                . 'nothing to tell it apart from a typo.',
                'Find them with: SELECT id FROM identity_user '
                . "WHERE password_hash IS NULL AND status NOT IN ('INVITED', 'REVOKED')",
                'The owner recovers by password reset, which sets a credential without reading the missing one.',
            ]);
        }
    }
}
