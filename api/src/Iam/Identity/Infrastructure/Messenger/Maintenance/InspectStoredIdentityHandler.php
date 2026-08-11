<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance;

use Erpify\Iam\Identity\Application\InspectStoredIdentity;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The stored-identity alarm: on each scheduled tick, emits one structured `error` line when a row has drifted
 * out of what the code reading it can represent, and stays silent otherwise.
 *
 * Without it the detective half of this pair only ran when a human typed the command — which is the weaker
 * half of a preventive/detective split, and the half whose absence is invisible: the runtime discards the
 * unknown role and refuses the unreadable credential without a word, so a drifting table produces no
 * exception, no log line and no red gate.
 *
 * `error` and not `warning`, which is not a taste call: prod routes Monolog through `fingers_crossed` with
 * `action_level: error` (config/packages/monolog.yaml), so a lower level is buffered and discarded — an alarm
 * that cannot fire in production is not an alarm.
 *
 * **Counts, never identities**, matching its sibling. The verdict knows nothing about who drifted (the probes
 * return counts by design), but the orphan role VALUES it does know are left out too: they are arbitrary text
 * from a column an out-of-band write controls, and log storage has its own retention and no declared owner of
 * its erasure. They stay in the command's report and in the audit row, both of which an operator reaches
 * deliberately; this line is what tells them to go look.
 *
 * **No audit row from here, and that is the decision rather than an omission.** The trail records the run an
 * operator performed — one row, resource-less, actor `system`; a scheduled arm writing one too would add a
 * row per day for as long as a finding stands, which is retention spent on repetition rather than on facts.
 *
 * **A fault leaves by the other door.** A failed probe is not caught: `StoredIdentityProbeFailed` propagates,
 * Messenger logs it at `critical` and Sentry captures it — while a finding, which is a data problem and not a
 * software fault, stays a log line. Scheduler transports are not among the failure transports, so the throw
 * neither retries, nor lands in `failed`, nor stops the worker.
 */
#[AsMessageHandler]
final readonly class InspectStoredIdentityHandler
{
    public function __construct(
        private InspectStoredIdentity $inspectStoredIdentity,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(InspectStoredIdentityMessage $message): void
    {
        unset($message);

        $findings = ($this->inspectStoredIdentity)();

        if ($findings->isClean()) {
            return;
        }

        $this->logger->error(
            'Stored identity drift: {orphanRoleValues} role value(s) no case backs, {malformedRoles} '
            . 'malformed roles column(s), {unreadableCredentials} unreadable credential(s), '
            . '{admittedWithoutCredential} admitted identity(ies) with no credential.',
            [
                'orphanRoleValues' => \count($findings->orphanRoleValues),
                'malformedRoles' => $findings->malformedRoles,
                'unreadableCredentials' => $findings->unreadableCredentials,
                'admittedWithoutCredential' => $findings->admittedWithoutCredential,
                // Every fact by name even when zero, so the line says which of the four is clean rather than
                // leaving a reader to infer it from a message that only mentions the others. A count that
                // silently stops being reported is the way a control shrinks without anything going red.
                'columnsChecked' => ['identity_user.roles', 'identity_user.password_hash'],
                'repairWith' => 'identity:integrity:inspect',
            ],
        );
    }
}
