<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Persistence;

use Erpify\Shared\Audit\Application\AuditPiiAad;
use Erpify\Shared\Audit\Application\PiiFieldPosition;
use Erpify\Shared\Audit\Domain\AuditedEntity;
use Erpify\Shared\Crypto\Application\EnvelopeEncryptor;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Privacy\Application\PersonalDataClassifier;

/**
 * Crypto-shreds the personal-data fields of a change diff before it is persisted. The owning module
 * classifies its fields with `#[PersonalData]`; this reads that classification and encrypts only those
 * columns under the aggregate's encryption scope (`<ResourceType>:<id>`, derived from its audit resource),
 * leaving non-personal fields in clear. An encrypted value is wrapped in a `{"__enc__": "<ciphertext>"}`
 * marker so a reader can show it as sealed without ever holding the plaintext. A null keeps null — the
 * absence of a value is not personal and must stay visible so added/removed still reads.
 *
 * Each value is sealed under an {@see AuditPiiAad} that binds its slot — the field, the old/new side, and
 * the audit-log row id — so a ciphertext relocated within a subject's rows (a different field, side, or
 * row) fails authentication on read: the trail's "from which value to which" is now tamper-evident.
 *
 * When no personal field is present the diff is returned untouched with no scope (e.g. a `Bank` catalog),
 * so the sealer is a no-op for non-PII aggregates and never contaminates the domain entity with crypto
 * concerns (ADR D6/D11/D12/D17).
 */
final readonly class PiiDiffSealer
{
    public const string ENCRYPTED_MARKER = '__enc__';

    public function __construct(
        private PersonalDataClassifier $classifier,
        private EnvelopeEncryptor $encryptor,
    ) {
    }

    /**
     * `$auditLogId` is the id of the row this diff will be stored on; it is bound into each sealed value's
     * AAD so a ciphertext cannot be moved to another row of the same subject without failing authentication.
     *
     * @param array{changes: array<string, array{old: scalar|null, new: scalar|null}>} $diff
     */
    public function seal(AuditedEntity $entity, array $diff, string $auditLogId): SealedDiff
    {
        $changes = $diff['changes'];
        $personalFields = \array_intersect($this->classifier->personalFieldsOf($entity), \array_keys($changes));

        if ([] === $personalFields) {
            return new SealedDiff($diff, null);
        }

        $resource = $entity->auditResource();
        $scope = EncryptionScopeId::of($resource->type, $resource->id);

        $sealedChanges = [];

        foreach ($changes as $field => $change) {
            $sealedChanges[$field] = \in_array($field, $personalFields, true)
                ? [
                    'old' => $this->sealValue($scope, $field, PiiFieldPosition::Old, $auditLogId, $change['old']),
                    'new' => $this->sealValue($scope, $field, PiiFieldPosition::New, $auditLogId, $change['new']),
                ]
                : $change;
        }

        return new SealedDiff([...$diff, 'changes' => $sealedChanges], $scope->toString());
    }

    /**
     * @return array<string, string>|null
     */
    private function sealValue(
        EncryptionScopeId $scope,
        string $field,
        PiiFieldPosition $position,
        string $auditLogId,
        bool|float|int|string|null $value,
    ): ?array {
        if (null === $value) {
            return null;
        }

        $aad = AuditPiiAad::for($field, $position, $auditLogId)->toString();

        return [self::ENCRYPTED_MARKER => $this->encryptor->encrypt($scope, (string) $value, $aad)];
    }
}
