<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain;

/**
 * The closed vocabulary of `audit_log.metadata`: every top-level key an entry may carry, with the shape its
 * value must have. `AuditLogEntry::create()` refuses any other key, so a new one is a reviewed edit here and
 * never a literal somebody added at a call site.
 *
 * **Enforced where every row is built, not where its keys are written.** Producers assemble their keys in
 * places a source sweep cannot read — a method returning the array, a ternary choosing between two, a
 * listener forwarding a parameter, a diff appended by the change-capture listener without passing through
 * the logger at all. All of them converge on one constructor, so that is the only point that sees them all.
 *
 * **The key is half of it; the shape is what makes the key mean anything.** A name alone is a declaration
 * that checks itself: `route` would admit a URL path, `refusal` a sentence, and either would carry a person's
 * identifier into the one column no erasure rewrites. Each shape admits countable or server-chosen values
 * only, except the two {@see AuditMetadataShape} names for admitting more.
 *
 * What this cannot judge is the classification itself: a UUID under {@see AnonymizedActorId} is a pseudonym
 * only because the erasure that mints it says so, and the shape cannot tell it from a real person's id. The
 * acceptance witness of the identity erasure is what holds that.
 */
enum AuditMetadataKey: string
{
    public function shape(): AuditMetadataShape
    {
        return match ($this) {
            self::Route => AuditMetadataShape::RouteName,
            self::Refusal => AuditMetadataShape::Slug,
            self::AuditEventId => AuditMetadataShape::AuditEntryId,
            self::AnonymizedActorId => AuditMetadataShape::Pseudonym,
            self::AffectedRows,
            self::AnonymizedResourceRows,
            self::AnonymizedEventRows,
            self::SessionsDeleted,
            self::ResetTokensDeleted,
            self::RecoverySecretsDeleted,
            self::MembershipsDeleted,
            self::InvitationsDeleted,
            self::MalformedRoles,
            self::UnreadableCredentials,
            self::AdmittedWithoutCredential => AuditMetadataShape::Count,
            self::Resweep, self::Unlocked => AuditMetadataShape::Flag,
            self::EncryptionScopeId => AuditMetadataShape::EncryptionScope,
            self::PreviousRoles, self::NewRoles => AuditMetadataShape::RoleNames,
            self::LockedUntil => AuditMetadataShape::Instant,
            self::OrphanRoleValues => AuditMetadataShape::StoredValues,
            self::Changes => AuditMetadataShape::FieldDiff,
            self::Operation => AuditMetadataShape::WriteOperation,
        };
    }

    /**
     * Only the change-capture listener seals a diff's personal values, so the diff and its write operation
     * belong to a `change` entry alone: on any other level the diff would reach storage in clear.
     */
    public function isReservedToChangeEntries(): bool
    {
        return self::Changes === $this || self::Operation === $this;
    }

    case Route = 'route';
    case Refusal = 'refusal';
    case AuditEventId = 'auditEventId';
    case AnonymizedActorId = 'anonymized_actor_id';
    case AffectedRows = 'affected_rows';
    case AnonymizedResourceRows = 'anonymized_resource_rows';
    case AnonymizedEventRows = 'anonymized_event_rows';
    case SessionsDeleted = 'sessions_deleted';
    case ResetTokensDeleted = 'reset_tokens_deleted';
    case RecoverySecretsDeleted = 'recovery_secrets_deleted';
    case MembershipsDeleted = 'memberships_deleted';
    case InvitationsDeleted = 'invitations_deleted';
    case Resweep = 'resweep';
    case EncryptionScopeId = 'encryption_scope_id';
    case PreviousRoles = 'previous_roles';
    case NewRoles = 'new_roles';
    case Unlocked = 'unlocked';
    case LockedUntil = 'lockedUntil';
    case OrphanRoleValues = 'orphan_role_values';
    case MalformedRoles = 'malformed_roles';
    case UnreadableCredentials = 'unreadable_credentials';
    case AdmittedWithoutCredential = 'admitted_without_credential';
    case Changes = 'changes';
    case Operation = 'operation';
}
