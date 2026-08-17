<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Messenger\Maintenance;

use Erpify\Shared\Audit\Application\SubjectErasureReconciler;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the GDPR subject-erasure reconciliation on the daily schedule and alarms when a destroyed key has no
 * `GDPR_SUBJECT_ERASED` evidence, so the integrity divergence surfaces in monitoring instead of staying
 * silent. It only reports — remediation (recording the missing entry) stays a human decision.
 *
 * `error` and not `warning`, and the reason is no longer the buffered handler: this alarm goes to the
 * always-on `observability` channel, which `monolog.yaml` excludes from `fingers_crossed` by name, so it
 * arrives at any level. The level says what the finding IS — a divergence the operator must act on — and
 * routing it here is what stops it paying for arrival by flushing the worker's whole buffer. An alarm that
 * cannot fire in production is not an alarm; one that fires by dragging fifty unrelated records with it is a
 * different defect.
 *
 * A COUNT, never the scope ids. An `EncryptionScopeId` names the aggregate whose key was destroyed, so the
 * list identifies the subjects behind an erasure; emitting it here would copy that into log storage, which
 * carries a retention of its own and no declared owner of its erasure. The ids belong to
 * `audit:gdpr:reconcile-erasures`, which an operator runs deliberately and whose output is ephemeral — this
 * line is what tells them to go run it.
 */
#[AsMessageHandler]
final readonly class ReconcileSubjectErasuresHandler
{
    public function __construct(
        private SubjectErasureReconciler $reconciler,
        #[Autowire(service: 'monolog.logger.observability')]
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReconcileSubjectErasuresMessage $message): void
    {
        unset($message);

        $unreconciled = $this->reconciler->unreconciledScopes();

        if ([] === $unreconciled) {
            return;
        }

        $this->logger->error(
            'GDPR subject-erasure integrity divergence: {count} destroyed key(s) without a GDPR_SUBJECT_ERASED entry.',
            ['count' => \count($unreconciled), 'inspectWith' => 'audit:gdpr:reconcile-erasures'],
        );
    }
}
