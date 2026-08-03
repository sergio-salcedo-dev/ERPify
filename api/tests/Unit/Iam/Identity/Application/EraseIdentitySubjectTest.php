<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\EraseIdentitySubject;
use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(EraseIdentitySubject::class)]
final class EraseIdentitySubjectTest extends TestCase
{
    private const string TOKEN_ID = '0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5e21';

    public function testErasesTheIdentityItsResetTokensAndSelfAuditsOnce(): void
    {
        $users = new InMemoryUserRepository(UserMother::create());
        $tokens = new InMemoryPasswordResetTokenRepository($this->tokenFor(UserMother::DEFAULT_ID));
        $audit = new RecordingAuditLogger();

        $result = $this->useCase($users, $tokens, $audit)->execute(UserMother::DEFAULT_ID);

        $this->assertTrue($result->identityErased);
        $this->assertSame(1, $result->resetTokensDeleted);
        $this->assertTrue($users->removeCalled);
        $this->assertSame([UserMother::DEFAULT_ID], $tokens->deleteAllForUserCalls);
        $this->assertCount(1, $audit->records);
        $this->assertSame('GDPR_SUBJECT_ERASED', $audit->records[0]['action']);
        $this->assertSame(AuditLevel::SECURITY, $audit->records[0]['level']);
        // Counts only. The subject travels as the entry's RESOURCE, never as a metadata key: no anonymiser
        // reaches inside `metadata`, so an id written there outlives the erasure that wrote it, while
        // `resource_id` is rewritten to the pseudonym by the anonymiser the erasure chain already runs.
        $this->assertSame(['reset_tokens_deleted' => 1], $audit->records[0]['metadata']);
        // Asserted part by part rather than against a rebuilt `AuditResource::of(...)`: the value object is
        // constructed fresh on every call, so comparing objects would pin identity or equality semantics that
        // are beside the point — what the erasure owes is this type carrying this id. Read through `?->`, so
        // an absent resource fails this assertion rather than needing a null guard of its own.
        $resource = $audit->records[0]['resource'];
        $this->assertSame(
            [FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, UserMother::DEFAULT_ID],
            [$resource?->type, $resource?->id],
        );
    }

    public function testTheSubjectIdNeverTravelsAsAMetadataValue(): void
    {
        $users = new InMemoryUserRepository(UserMother::create());
        $tokens = new InMemoryPasswordResetTokenRepository($this->tokenFor(UserMother::DEFAULT_ID));
        $audit = new RecordingAuditLogger();

        $this->useCase($users, $tokens, $audit)->execute(UserMother::DEFAULT_ID);

        // The entry has to exist before its absence of an id means anything: with no record at all, the
        // assertion below reads an undefined offset, encodes `null` and passes without having tested a thing.
        $this->assertCount(1, $audit->records);
        // Asserted over the serialised metadata rather than over its keys: a future key holding the id under
        // any name is the defect, so pinning one key name would leave the next one through. Case-insensitively,
        // because the id reaches this use case as the caller spelled it — `Uuid::ensure()` validates without
        // normalising — and RFC 4122 hex is case-insensitive, so a mixed-case id is the same identifier.
        $this->assertStringNotContainsStringIgnoringCase(
            UserMother::DEFAULT_ID,
            \json_encode($audit->records[0]['metadata'], JSON_THROW_ON_ERROR),
        );
    }

    public function testARerunWithNothingLeftErasesNothingAndSkipsTheSelfAudit(): void
    {
        $users = new InMemoryUserRepository();
        $tokens = new InMemoryPasswordResetTokenRepository();
        $audit = new RecordingAuditLogger();

        $result = $this->useCase($users, $tokens, $audit)->execute(UserMother::DEFAULT_ID);

        $this->assertFalse($result->erasedAnything());
        $this->assertFalse($users->removeCalled);
        $this->assertSame([], $audit->records);
    }

    public function testAMalformedSubjectIdIsRejectedBeforeAnyWork(): void
    {
        $tokens = new InMemoryPasswordResetTokenRepository();
        $useCase = $this->useCase(new InMemoryUserRepository(), $tokens, new RecordingAuditLogger());

        $this->expectException(InvalidUuidException::class);

        $useCase->execute('not-a-uuid');
    }

    private function useCase(
        InMemoryUserRepository $users,
        InMemoryPasswordResetTokenRepository $tokens,
        RecordingAuditLogger $audit,
    ): EraseIdentitySubject {
        return new EraseIdentitySubject($users, $tokens, $audit, new InlineTransactionManager());
    }

    private function tokenFor(string $userId): PasswordResetToken
    {
        $generated = SingleUseToken::mint(new DateTimeImmutable('2026-07-14T13:00:00+00:00'));

        return PasswordResetToken::issue(self::TOKEN_ID, $userId, $generated->token);
    }
}
