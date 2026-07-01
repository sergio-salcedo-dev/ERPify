<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Messenger\Maintenance;

use Erpify\Shared\Audit\Application\SubjectErasureReconciler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the GDPR subject-erasure reconciliation on the daily schedule and logs a warning when a destroyed
 * key has no `GDPR_SUBJECT_ERASED` evidence, so the integrity divergence surfaces in monitoring instead of
 * staying silent. It only reports — remediation (recording the missing entry) stays a human decision.
 */
#[AsMessageHandler]
final readonly class ReconcileSubjectErasuresHandler
{
    public function __construct(
        private SubjectErasureReconciler $reconciler,
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

        $this->logger->warning(
            'GDPR subject-erasure integrity divergence: {count} destroyed key(s) without a GDPR_SUBJECT_ERASED entry.',
            ['count' => \count($unreconciled), 'encryption_scope_ids' => $unreconciled],
        );
    }
}
