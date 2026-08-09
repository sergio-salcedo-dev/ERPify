<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Cli;

use Erpify\Iam\Identity\Application\StoredIdentityIntegrity;
use Erpify\Iam\Identity\Application\StoredIdentityProbeFailed;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
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
 * Three exit codes, because the contract of a control IS its exit code: `SUCCESS` (both reads came back
 * empty), `FAILURE` (a row needs repairing) and `INVALID` (a read failed, so nothing was established either
 * way and no repair should be attempted on the strength of this run).
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
        private readonly StoredIdentityIntegrity $storedIdentities,
        private readonly AuditLogger $auditLogger,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $orphanRoles = $this->storedIdentities->roleValuesOutsideTheEnum();
            $unreadableCredentials = $this->storedIdentities->identitiesWithAnUnreadableCredential();
        } catch (StoredIdentityProbeFailed $storedIdentityProbeFailed) {
            $io->error([
                'The inspection could not be completed, so nothing is known about the stored identity rows. '
                . 'This is NOT a finding: do not repair anything on the strength of it.',
                $storedIdentityProbeFailed->getMessage(),
            ]);

            return Command::INVALID;
        }

        if ([] === $orphanRoles && 0 === $unreadableCredentials) {
            // Both columns BY NAME rather than a bare "all clear": what a green covers is exactly these two
            // reads, and a success line that claimed more would be the kind of guarantee nobody can check.
            $io->success('Every stored identity row is representable by the code that reads it.');
            $io->listing(['identity_user.roles', 'identity_user.password_hash']);

            return Command::SUCCESS;
        }

        $this->report($io, $orphanRoles, $unreadableCredentials);
        $this->recordDrift($io, $orphanRoles, $unreadableCredentials);

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
     *
     * @param list<string> $orphanRoles
     */
    private function recordDrift(SymfonyStyle $io, array $orphanRoles, int $unreadableCredentials): void
    {
        try {
            $this->auditLogger->log(self::DRIFT_ACTION, AuditLevel::SECURITY, null, [
                'orphan_role_values' => $orphanRoles,
                'unreadable_credentials' => $unreadableCredentials,
            ]);
        } catch (Throwable $throwable) {
            // The finding stands and has already been printed — only its trail entry failed. Saying so is what
            // stops an operator concluding, from a trail with no such row, that the drift was never detected.
            $io->error(\sprintf(
                'The finding above could not be recorded in the audit trail: %s',
                $throwable->getMessage(),
            ));
        }
    }

    /**
     * @param list<string> $orphanRoles
     */
    private function report(SymfonyStyle $io, array $orphanRoles, int $unreadableCredentials): void
    {
        if ([] !== $orphanRoles) {
            $io->section('identity_user.roles — values no Role case backs');
            $io->listing($orphanRoles);
            $io->note([
                'These are DISCARDED on every read, so the identities carrying them authenticate and simply '
                . 'hold fewer grants than their row says. Nobody is locked out and nothing is over-granted: '
                . 'the permission model is grant-only, so an unrecognised value concedes nothing.',
                'Repair by rewriting the column to the values the enum still defines. Retiring a case is what '
                . 'usually produces this, in which case the migration that retired it is what is missing.',
            ]);
        }

        if (0 !== $unreadableCredentials) {
            $io->section('identity_user.password_hash — credentials the value object refuses');
            $io->text(\sprintf('%d identity(ies).', $unreadableCredentials));
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
    }
}
