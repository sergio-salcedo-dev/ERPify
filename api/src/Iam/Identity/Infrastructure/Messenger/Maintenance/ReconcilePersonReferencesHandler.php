<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance;

use Erpify\Iam\Identity\Application\PersonReferenceFinding;
use Erpify\Iam\Identity\Application\ReconcileErasedSubjectReferences;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The person-reference alarm: on each scheduled tick, emits a single structured `error` log line when a
 * person's id survives an erasure somewhere, and stays silent otherwise.
 *
 * `error` and not `warning`, which is not a taste call: prod routes Monolog through `fingers_crossed` with
 * `action_level: error` (config/packages/monolog.yaml), so a lower level is buffered and discarded — an alarm
 * that cannot fire in production is not an alarm.
 *
 * **Counts, never ids.** The verdict knows exactly which subjects diverged and this line reports only how
 * many, per place. Writing those ids here would copy them into log storage, which has its own retention and
 * no declared owner of their erasure — so the control built to enforce that no person's id outlives its
 * erasure would create fresh copies that do. The ids stay in
 * `identity:gdpr:reconcile-subject-references`, which an operator runs deliberately and whose output is
 * ephemeral; this line is what tells them to go run it.
 *
 * Naming the axes is the other half. A count with no places cannot distinguish a control sweeping everything
 * from one that quietly lost a source, and `axesChecked` is the denominator that makes a shrunken control
 * visible in the alarm itself.
 *
 * **A fault leaves by the other door.** A failed probe is not caught: it propagates as
 * {@see \Erpify\Iam\Identity\Application\PersonReferenceProbeFailed}, which Messenger logs at `critical` and
 * — unlike a log line, the Monolog→Sentry bridge being deliberately unwired (config/packages/sentry.yaml) —
 * Sentry captures. That asymmetry is the design: a compliance finding is not a software fault and does not
 * belong in an error tracker, while a control that cannot run is exactly what an engineer must be paged
 * about. It costs nothing to recover from: scheduler transports are not among the failure transports, so the
 * throw neither retries, nor lands in `failed`, nor stops the worker.
 */
#[AsMessageHandler]
final readonly class ReconcilePersonReferencesHandler
{
    public function __construct(
        private ReconcileErasedSubjectReferences $reconciler,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReconcilePersonReferencesMessage $message): void
    {
        unset($message);

        $verdict = $this->reconciler->unreconciledReferences();

        if ($verdict->isEmpty()) {
            return;
        }

        $this->logger->error(
            'GDPR person-reference divergence: {total} reference(s) survive an erasure across {axes} axis(es).',
            [
                'total' => $verdict->total(),
                'axes' => \count($verdict->findings()),
                'axesChecked' => $verdict->axesChecked(),
                'divergentByAxis' => $this->countsByAxis($verdict->findings()),
                'repairWith' => 'identity:gdpr:reconcile-subject-references',
            ],
        );
    }

    /**
     * @param list<PersonReferenceFinding> $findings
     *
     * @return array<string, int>
     */
    private function countsByAxis(array $findings): array
    {
        $counts = [];

        foreach ($findings as $finding) {
            $counts[$finding->axis->key()] = \count($finding->subjectIds);
        }

        return $counts;
    }
}
