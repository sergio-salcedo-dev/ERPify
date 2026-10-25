<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Persistence\Application\TransactionManager;

/**
 * The `security` rows naming an invited identity that the invitation console levers owe the trail: who resent or
 * revoked a person's invitation. The event store records the transition and never who caused it.
 *
 * It lives here rather than beside those levers in `Iam/Invitation` because the row names a person, and the
 * context that owns the person is the one that erases what names them (`docs/adr/audit-activity-log.md` D4);
 * the levers reach it as a published seam.
 *
 * The row is written under the identity's own row lock, taken in a unit of work nested in the caller's, so it
 * commits with the caller's transaction. That puts it on one side of {@see FulfilIdentityErasure}: lock first and
 * the erasure waits on the row, then finds this one committed and redacts it; arrive second and the identity is
 * gone, so no row naming it is written. The caller already holds the person's invitation rows, which is the
 * order the erasure and the accept take them too — invitations, then the identity.
 */
final readonly class RecordInvitationAudit
{
    private const string RESENT_ACTION = 'INVITATION_RESENT';

    private const string REVOKED_ACTION = 'INVITATION_REVOKED';

    public function __construct(
        private UserRepository $users,
        private AuditLogger $auditLogger,
        private TransactionManager $transactionManager,
    ) {
    }

    public function resent(string $invitedUserId): void
    {
        $this->record(self::RESENT_ACTION, $invitedUserId);
    }

    public function revoked(string $invitedUserId): void
    {
        $this->record(self::REVOKED_ACTION, $invitedUserId);
    }

    private function record(string $action, string $invitedUserId): void
    {
        $this->transactionManager->transactional(function () use ($action, $invitedUserId): void {
            if ($this->users->findByIdForUpdate($invitedUserId) instanceof User) {
                $this->write($action, $invitedUserId);
            }
        });
    }

    private function write(string $action, string $invitedUserId): void
    {
        $this->auditLogger->log(
            $action,
            AuditLevel::SECURITY,
            AuditResource::of(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $invitedUserId),
        );
    }
}
