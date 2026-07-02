<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Persistence;

use Erpify\Shared\Audit\Application\AuditPiiAad;
use Erpify\Shared\Audit\Application\PiiFieldPosition;
use Erpify\Shared\Audit\Infrastructure\Persistence\PiiDiffSealer;
use Erpify\Shared\Audit\Infrastructure\Persistence\SealedDiff;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\DecryptionFailed;
use Erpify\Shared\Crypto\Infrastructure\SodiumEnvelopeEncryptor;
use Erpify\Shared\Privacy\Infrastructure\ReflectionPersonalDataClassifier;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Unit\Shared\Crypto\InMemoryKeystore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PiiDiffSealer::class)]
#[CoversClass(SealedDiff::class)]
final class PiiDiffSealerTest extends TestCase
{
    private const string KEK = '0123456789abcdef0123456789abcdef';

    #[Test]
    public function itEncryptsPersonalFieldsLeavesClearFieldsAndReferencesTheScope(): void
    {
        $id = Uuid::generate();
        $sealed = $this->sealer()->seal(new AuditedSubjectFake($id), ['changes' => [
            'secret' => ['old' => 'BBVA', 'new' => 'BBVA S.A.'],
            'plain' => ['old' => 'a', 'new' => 'b'],
        ]], Uuid::generate());

        $json = \json_encode($sealed->metadata, JSON_THROW_ON_ERROR);

        $this->assertSame('BankAccount:' . $id, $sealed->encryptionScopeId);
        $this->assertStringNotContainsString('BBVA', $json, 'plaintext PII must never reach the metadata');
        $this->assertStringContainsString(PiiDiffSealer::ENCRYPTED_MARKER, $json, 'PII fields are sealed');
        $this->assertStringContainsString('"plain":{"old":"a","new":"b"}', $json, 'non-PII stays in clear');
    }

    #[Test]
    public function itKeepsNullValuesVisibleForAddedOrRemovedPersonalFields(): void
    {
        $sealed = $this->sealer()->seal(new AuditedSubjectFake(Uuid::generate()), ['changes' => [
            'secret' => ['old' => null, 'new' => 'BBVA'],
        ]], Uuid::generate());

        $json = \json_encode($sealed->metadata, JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('"old":null', $json, 'an absent value is not personal and stays visible');
        $this->assertStringContainsString(PiiDiffSealer::ENCRYPTED_MARKER, $json);
        $this->assertStringNotContainsString('"new":"BBVA"', $json);
    }

    #[Test]
    public function itLeavesANonPiiDiffUntouchedWithNoScope(): void
    {
        $diff = ['changes' => ['name' => ['old' => 'BBVA', 'new' => 'BBVA S.A.']]];

        $sealed = $this->sealer()->seal(new PlainAuditedFake(Uuid::generate()), $diff, Uuid::generate());

        $this->assertNull($sealed->encryptionScopeId);
        $this->assertSame($diff, $sealed->metadata);
    }

    #[Test]
    public function aSealedValueDecryptsUnderItsSlotAadAndFailsWhenRelocated(): void
    {
        $keystore = new InMemoryKeystore();
        $encryptor = new SodiumEnvelopeEncryptor($keystore, self::KEK);
        $sealer = new PiiDiffSealer(new ReflectionPersonalDataClassifier(), $encryptor);

        $id = Uuid::generate();
        $auditLogId = Uuid::generate();
        $sealed = $sealer->seal(new AuditedSubjectFake($id), ['changes' => [
            'secret' => ['old' => 'BBVA', 'new' => 'BBVA S.A.'],
        ]], $auditLogId);

        $scope = EncryptionScopeId::fromString((string) $sealed->encryptionScopeId);
        $oldCiphertext = $this->ciphertextAt($sealed->metadata, 'secret', 'old');
        $aadOld = AuditPiiAad::for('secret', PiiFieldPosition::Old, $auditLogId)->toString();

        $this->assertSame(
            'BBVA',
            $encryptor->decrypt($scope, $oldCiphertext, $aadOld),
            'a value decrypts under the exact slot it was sealed in',
        );

        // Relocating the ciphertext to the other side of the change (old -> new) must fail authentication.
        $aadNew = AuditPiiAad::for('secret', PiiFieldPosition::New, $auditLogId)->toString();
        $this->expectException(DecryptionFailed::class);
        $encryptor->decrypt($scope, $oldCiphertext, $aadNew);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function ciphertextAt(array $metadata, string $field, string $position): string
    {
        $changes = $metadata['changes'] ?? null;
        $this->assertIsArray($changes);
        $slot = $changes[$field] ?? null;
        $this->assertIsArray($slot);
        $sealedValue = $slot[$position] ?? null;
        $this->assertIsArray($sealedValue);
        $ciphertext = $sealedValue[PiiDiffSealer::ENCRYPTED_MARKER] ?? null;
        $this->assertIsString($ciphertext);

        return $ciphertext;
    }

    private function sealer(): PiiDiffSealer
    {
        return new PiiDiffSealer(
            new ReflectionPersonalDataClassifier(),
            new SodiumEnvelopeEncryptor(new InMemoryKeystore(), self::KEK),
        );
    }
}
