<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance;

use Erpify\Iam\Identity\Application\PersonReferenceFinding;
use Erpify\Iam\Identity\Application\ReconcileErasedSubjectReferences;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The person-reference alarm: on each scheduled tick, emits a single structured `error` log line when a
 * person's id survives an erasure somewhere, and stays silent otherwise.
 *
 * `error` and not `warning`, and the reason is no longer the buffered handler: this alarm goes to the
 * always-on `observability` channel, which `monolog.yaml` excludes from `fingers_crossed` by name, so it
 * arrives at any level. The level says what the finding IS — a divergence the operator must act on — and
 * routing it here is what stops it paying for arrival by flushing the worker's whole buffer. An alarm that
 * cannot fire in production is not an alarm; one that fires by dragging fifty unrelated records with it is a
 * different defect.
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
        #[Autowire(service: 'monolog.logger.observability')]
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
                // The denominator BY NAME as well as by count, matching what the CLI prints in both of its
                // branches. A number that drops from 5 to 4 says coverage was lost and cannot say which place
                // stopped being watched, and it drops in the one line an operator reads while already acting
                // on a finding. The keys are registry keys, never ids, so this carries no personal data.
                'axesCheckedKeys' => $verdict->axesCheckedKeys(),
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
