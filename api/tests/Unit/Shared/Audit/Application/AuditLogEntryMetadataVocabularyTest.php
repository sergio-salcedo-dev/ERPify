<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Application;

use DateTimeImmutable;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditMetadataKey;
use Erpify\Shared\Audit\Domain\AuditMetadataShape;
use Erpify\Shared\Audit\Domain\Exception\InvalidAuditLogEntry;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The closed vocabulary of `audit_log.metadata`: which keys an entry may carry and the shape each value must have.
 *
 * @internal
 */
#[CoversClass(AuditLogEntry::class)]
#[CoversClass(AuditMetadataKey::class)]
#[CoversClass(AuditMetadataShape::class)]
#[CoversClass(InvalidAuditLogEntry::class)]
final class AuditLogEntryMetadataVocabularyTest extends TestCase
{
    private const string SEALED = 'YqL_OuxJ0MI-7DgIto9CONEkAwH8MXCGoal-kNNnK5S1aMBFOe-bkQEqDRG4JzEgBHyXwenvLA';

    /**
     * One value per key in the shape its producer writes it, so a key whose shape stops admitting what the
     * tree writes goes red here before it goes red in production.
     *
     * @return array<string, mixed>
     */
    private static function writtenValues(): array
    {
        return [
            'route' => 'backoffice_audit_event_detail',
            'refusal' => 'self-role-change-forbidden',
            'auditEventId' => '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b',
            'anonymized_actor_id' => '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c',
            'affected_rows' => 3,
            'anonymized_resource_rows' => 0,
            'anonymized_event_rows' => 2,
            'sessions_deleted' => 1,
            'reset_tokens_deleted' => 0,
            'recovery_secrets_deleted' => 1,
            'memberships_deleted' => 1,
            'invitations_deleted' => 0,
            'resweep' => true,
            'encryption_scope_id' => 'BankAccount:0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d',
            'previous_roles' => [],
            'new_roles' => ['ADMIN', 'AUDIT_READER'],
            'unlocked' => false,
            'lockedUntil' => '2026-10-23T12:59:47+00:00',
            'orphan_role_values' => ['SUPERVISOR', '<null>'],
            'orphan_role_value_count' => 2,
            'malformed_roles' => 0,
            'unreadable_credentials' => 1,
            'admitted_without_credential' => 0,
            'changes' => [
                'holderName' => ['old' => null, 'new' => [AuditMetadataShape::SEALED_VALUE_KEY => self::SEALED]],
                'status' => ['old' => 'active', 'new' => 'closed'],
                'currency' => ['old' => 'EUR', 'new' => 'EUR'],
            ],
            'operation' => 'UPDATED',
        ];
    }

    public function testEveryKeyOfTheVocabularyHasAWrittenValueHere(): void
    {
        $keys = \array_map(static fn (AuditMetadataKey $key): string => $key->value, AuditMetadataKey::cases());

        $this->assertEqualsCanonicalizing($keys, \array_keys(self::writtenValues()));
    }

    #[DataProvider('provideAdmitsEveryKeyInTheShapeItsProducerWritesCases')]
    public function testAdmitsEveryKeyInTheShapeItsProducerWrites(string $key, mixed $value): void
    {
        $level = AuditMetadataKey::from($key)->isReservedToChangeEntries() ? AuditLevel::CHANGE : AuditLevel::SECURITY;

        $entry = $this->entry($level, [$key => $value]);

        $this->assertSame([$key => $value], $entry->metadata);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function provideAdmitsEveryKeyInTheShapeItsProducerWritesCases(): iterable
    {
        foreach (self::writtenValues() as $key => $value) {
            yield $key => [$key, $value];
        }

        yield 'a route that is absent' => ['route', null];
        yield 'an audit event id that is absent' => ['auditEventId', null];
        yield 'an upper-case audit event id, as a client may spell it' => [
            'auditEventId',
            '0199A1B2-C3D4-7E5F-8A9B-0C1D2E3F4A5B',
        ];
    }

    /**
     * The point of a closed vocabulary: `iban`, `email` and a request dump are not a reviewed decision, so they
     * do not reach a column no erasure rewrites.
     */
    #[DataProvider('provideRefusesAKeyOutsideTheVocabularyCases')]
    public function testRefusesAKeyOutsideTheVocabulary(int|string $key, string $printed): void
    {
        try {
            $this->entry(AuditLevel::SECURITY, [$key => 'x']);
            $this->fail('An unknown metadata key was admitted.');
        } catch (InvalidAuditLogEntry $invalidAuditLogEntry) {
            $this->assertSame(
                'Audit log entry metadata carries a key outside the audit metadata vocabulary.',
                $invalidAuditLogEntry->getMessage(),
            );
            $this->assertSame(['field' => 'metadata', 'key' => $printed], $invalidAuditLogEntry->context());
        }
    }

    /**
     * @return iterable<string, array{int|string, string}>
     */
    public static function provideRefusesAKeyOutsideTheVocabularyCases(): iterable
    {
        yield 'a personal field' => ['iban', 'iban'];
        yield 'a near-miss spelling' => ['lockedUntil ', '<not an identifier>'];
        yield 'a key in the wrong case' => ['Route', 'Route'];
        yield 'an identifier with a trailing newline' => ["route\n", '<not an identifier>'];
        yield 'a key that could be a person' => ['jane.doe@example.test', '<not an identifier>'];
        yield 'a list position' => [0, '<not an identifier>'];
    }

    #[DataProvider('provideRefusesAValueOutsideItsKeysShapeCases')]
    public function testRefusesAValueOutsideItsKeysShape(string $key, mixed $value): void
    {
        $metadataKey = AuditMetadataKey::from($key);
        $level = $metadataKey->isReservedToChangeEntries() ? AuditLevel::CHANGE : AuditLevel::SECURITY;

        try {
            $this->entry($level, [$key => $value]);
            $this->fail(\sprintf('metadata.%s admitted a value outside its shape.', $key));
        } catch (InvalidAuditLogEntry $invalidAuditLogEntry) {
            $this->assertSame(
                'Audit log entry metadata value does not have the shape its key declares.',
                $invalidAuditLogEntry->getMessage(),
            );
            $this->assertSame(
                ['field' => 'metadata.' . $key, 'shape' => $metadataKey->shape()->name],
                $invalidAuditLogEntry->context(),
            );
        }
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function provideRefusesAValueOutsideItsKeysShapeCases(): iterable
    {
        yield 'a route path, whose segments carry ids' => ['route', '/api/v1/backoffice/users/0199a1b2/erase'];
        yield 'a route with a space' => ['route', 'audit event'];
        yield 'an empty route' => ['route', ''];
        yield 'a route with a trailing newline' => ['route', "backoffice_bank_detail\n"];
        yield 'a refusal with a trailing newline' => ['refusal', "self-erasure-forbidden\n"];
        yield 'a role with a trailing newline' => ['new_roles', ["ADMIN\n"]];
        yield 'a scope type with a trailing newline' => [
            'encryption_scope_id',
            "BankAccount\n:0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d",
        ];
        yield 'a refusal written as a sentence' => ['refusal', 'You cannot change your own roles'];
        yield 'a refusal over its length' => ['refusal', \str_repeat('a', 65)];
        yield 'an audit event id that is not a UUID' => ['auditEventId', 'jane.doe@example.test'];
        yield 'a pseudonym that is not a UUID' => ['anonymized_actor_id', 'jane.doe@example.test'];
        yield 'an absent pseudonym' => ['anonymized_actor_id', null];
        yield 'a negative count' => ['affected_rows', -1];
        yield 'a count as a string' => ['sessions_deleted', '3'];
        yield 'a count as a float' => ['malformed_roles', 1.0];
        yield 'a flag as an int' => ['unlocked', 1];
        yield 'a scope whose id is an email' => ['encryption_scope_id', 'BankAccount:jane.doe@example.test'];
        yield 'a scope without its type' => ['encryption_scope_id', '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d'];
        yield 'a scope with a lower-case type' => [
            'encryption_scope_id',
            'bankAccount:0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d',
        ];
        yield 'roles as a map' => ['new_roles', ['admin' => 'ADMIN']];
        yield 'a role that is not a role name' => ['previous_roles', ['jane.doe@example.test']];
        yield 'an instant in another spelling' => ['lockedUntil', '2026-10-23 12:59:47'];
        yield 'an instant that does not exist' => ['lockedUntil', '2026-02-30T12:59:47+00:00'];
        yield 'stored values that are not strings' => ['orphan_role_values', [42]];
        yield 'stored values as a map' => ['orphan_role_values', ['row' => 'SUPERVISOR']];
        yield 'a diff side that is not a finite number' => ['changes', ['rate' => ['old' => 1.5, 'new' => INF]]];
        yield 'a diff side that is not a number' => ['changes', ['rate' => ['old' => NAN, 'new' => 1.5]]];
        yield 'a diff that is a list' => ['changes', [['old' => 'a', 'new' => 'b']]];
        yield 'a diff pair missing a side' => ['changes', ['name' => ['new' => 'b']]];
        yield 'a diff pair with an extra side' => ['changes', ['name' => ['old' => 'a', 'new' => 'b', 'by' => 'x']]];
        yield 'a diff side that is a nested object' => ['changes', ['name' => ['old' => null, 'new' => ['a' => 'b']]]];
        yield 'a sealed side whose ciphertext is not a string' => [
            'changes',
            ['iban' => ['old' => null, 'new' => ['__enc__' => 42]]],
        ];
        yield 'a side sealed under another key' => [
            'changes',
            ['iban' => ['old' => null, 'new' => ['__sealed__' => self::SEALED]]],
        ];
        yield 'an operation outside the enum' => ['operation', 'MERGED'];
        yield 'an operation in the wrong case' => ['operation', 'updated'];
    }

    /**
     * Ordering matters: a diff on the wrong level is refused for being there, whatever it holds.
     */
    #[DataProvider('provideRefusesAKeyReservedToChangeEntriesOnAnyOtherLevelCases')]
    public function testRefusesAKeyReservedToChangeEntriesOnAnyOtherLevel(AuditLevel $level, string $key): void
    {
        $this->expectException(InvalidAuditLogEntry::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('metadata.%s is reserved for change-level entries', $key));

        $this->entry($level, [$key => 'not even well shaped']);
    }

    /**
     * @return iterable<string, array{AuditLevel, string}>
     */
    public static function provideRefusesAKeyReservedToChangeEntriesOnAnyOtherLevelCases(): iterable
    {
        foreach ([AuditLevel::ACTIVITY, AuditLevel::SECURITY] as $level) {
            foreach (AuditMetadataKey::cases() as $key) {
                if ($key->isReservedToChangeEntries()) {
                    yield $level->value . ', ' . $key->value => [$level, $key->value];
                }
            }
        }
    }

    /**
     * @param array<array-key, mixed> $metadata
     */
    private function entry(AuditLevel $level, array $metadata): AuditLogEntry
    {
        return AuditLogEntry::create(
            'AUDIT_METADATA_PROBE',
            $level,
            ActorContext::anonymous(),
            Uuid::generate(),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            metadata: $metadata,
        );
    }
}
