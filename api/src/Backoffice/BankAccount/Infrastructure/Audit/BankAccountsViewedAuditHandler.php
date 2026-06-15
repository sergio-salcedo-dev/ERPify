<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Audit;

use DateTimeInterface;
use Erpify\Backoffice\BankAccount\Application\Audit\BankAccountsViewedAuditEvent;
use Erpify\Shared\Domain\Logging\Logger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Records a structured audit-trail line for each accounts read. Idempotent (a pure log write) and
 * scrubbed of PII: it logs WHAT (bankId) and WHEN only — never the IBAN or any account field.
 */
#[AsMessageHandler]
final readonly class BankAccountsViewedAuditHandler
{
    public function __construct(private Logger $logger)
    {
    }

    public function __invoke(BankAccountsViewedAuditEvent $event): void
    {
        $this->logger->info('bank_accounts.viewed', [
            'bankId' => $event->bankId,
            'occurredOn' => $event->occurredOn->format(DateTimeInterface::ATOM),
        ]);
    }
}
