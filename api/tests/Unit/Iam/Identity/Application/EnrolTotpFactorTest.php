<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Closure;
use Erpify\Iam\Identity\Application\EnrolTotpFactor;
use Erpify\Iam\Identity\Application\ProveCurrentPassword;
use Erpify\Iam\Identity\Application\TotpSecretVault;
use Erpify\Iam\Identity\Domain\Exception\InvalidCurrentPassword;
use Erpify\Iam\Identity\Domain\Exception\SecondFactorAlreadyEnabled;
use Erpify\Iam\Identity\Domain\Exception\UserNotFound;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Security\Rfc6238TotpAlgorithm;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Crypto\Infrastructure\ScopeKeyring;
use Erpify\Shared\Crypto\Infrastructure\SodiumEnvelopeEncryptor;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\TotpFactorMother;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use Erpify\Tests\Unit\Shared\Crypto\InMemoryKeystore;
use Erpify\Tests\Unit\Shared\Persistence\Double\LockOrderJournal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Starting an enrolment: what it refuses, in what order, what it seals and what it hands back.
 *
 * The encryptor is the real libsodium one over an in-memory keystore, so "the secret shown is the secret sealed"
 * is checked by opening the row rather than by trusting a double.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the arrange names the two repositories, the credential proof,
 *                                                   the real algorithm and envelope, the audit double and the
 *                                                   refusals the cases are stated in terms of
 */
#[CoversClass(EnrolTotpFactor::class)]
#[CoversClass(TotpSecretVault::class)]
final class EnrolTotpFactorTest extends TestCase
{
    private const string KEK = '0123456789abcdef0123456789abcdef';

    #[Test]
    public function itPersistsAPendingFactorSealingTheSecretItShowsOnce(): void
    {
        $factors = new InMemoryTotpFactorRepository();
        $vault = $this->vault();

        $enrolment = $this->useCase(new InMemoryUserRepository(UserMother::create()), $factors, $vault)
            ->enrol(UserMother::DEFAULT_ID, $this->acceptsTheCurrentPassword())
        ;

        $this->assertCount(1, $factors->saved);
        $factor = $factors->saved[0];
        $this->assertFalse($factor->isEnabled(), 'an enrolment gates nothing until a code confirms it');
        $this->assertNull($factor->lastAcceptedStep());
        $this->assertSame(UserMother::DEFAULT_ID, $factor->userId());
        // The row holds ciphertext only; opening it yields the very secret the response carries.
        $this->assertStringNotContainsString($enrolment->encodedSecret(), $factor->sealedSecret());
        $this->assertSame(
            $enrolment->encodedSecret(),
            (new Rfc6238TotpAlgorithm())->encodedSecret($vault->open($factor)),
        );
        $this->assertStringStartsWith('otpauth://totp/ERPify%3A', $enrolment->provisioningUri());
        $this->assertStringContainsString('secret=' . $enrolment->encodedSecret(), $enrolment->provisioningUri());
    }

    #[Test]
    public function theScopeIsRandomAndNamesNothingAboutThePerson(): void
    {
        $factors = new InMemoryTotpFactorRepository();

        $this->useCase(new InMemoryUserRepository(UserMother::create()), $factors)
            ->enrol(UserMother::DEFAULT_ID, $this->acceptsTheCurrentPassword())
        ;

        $this->assertCount(1, $factors->saved);
        $factor = $factors->saved[0];
        $this->assertNotSame(UserMother::DEFAULT_ID, $factor->scopeId());
        $this->assertNotSame($factor->getId(), $factor->scopeId());
    }

    #[Test]
    public function aWrongCurrentPasswordIsRefusedBeforeTheFactorIsConsulted(): void
    {
        // The ORDER is the property: the proof runs before the factor row is read or locked, so a refused re-proof
        // touches nothing, and the 409 an enabled factor earns is only ever given to a caller who proved the password.
        $journal = new LockOrderJournal();
        $factors = new InMemoryTotpFactorRepository(TotpFactorMother::enabledFor());
        $factors->lockOrderJournal = $journal;

        $this->expectException(InvalidCurrentPassword::class);

        try {
            $this->useCase(new InMemoryUserRepository(UserMother::create()), $factors)
                ->enrol(UserMother::DEFAULT_ID, $this->refusesTheCurrentPassword())
            ;
        } finally {
            $this->assertSame([], $factors->saved);
            $this->assertSame([], $journal->tablesLockedInOrder, 'the factor was read before the proof');
        }
    }

    #[Test]
    public function anEnabledFactorIsNeverReplaced(): void
    {
        $enabled = TotpFactorMother::enabledFor();
        $sealedBefore = $enabled->sealedSecret();
        $factors = new InMemoryTotpFactorRepository($enabled);

        $this->expectException(SecondFactorAlreadyEnabled::class);

        try {
            $this->useCase(new InMemoryUserRepository(UserMother::create()), $factors)
                ->enrol(UserMother::DEFAULT_ID, $this->acceptsTheCurrentPassword())
            ;
        } finally {
            $this->assertSame([], $factors->saved);
            $this->assertSame($sealedBefore, $enabled->sealedSecret());
        }
    }

    #[Test]
    public function aPendingEnrolmentIsRestartedUnderItsOwnScope(): void
    {
        $pending = TotpFactorMother::pendingFor();
        $factors = new InMemoryTotpFactorRepository($pending);

        $this->useCase(new InMemoryUserRepository(UserMother::create()), $factors)
            ->enrol(UserMother::DEFAULT_ID, $this->acceptsTheCurrentPassword())
        ;

        $this->assertSame([$pending], $factors->saved, 'the one row is restarted, never duplicated');
        $this->assertSame(TotpFactorMother::DEFAULT_SCOPE_ID, $pending->scopeId());
        $this->assertNotSame(TotpFactorMother::PLACEHOLDER_CIPHERTEXT, $pending->sealedSecret());
    }

    #[Test]
    public function anIdentityThatResolvedToNoRowIsRefused(): void
    {
        $factors = new InMemoryTotpFactorRepository();

        $this->expectException(UserNotFound::class);

        try {
            $this->useCase(new InMemoryUserRepository(), $factors)
                ->enrol(UserMother::DEFAULT_ID, $this->acceptsTheCurrentPassword())
            ;
        } finally {
            $this->assertSame([], $factors->saved);
        }
    }

    #[Test]
    public function theUserRowIsLockedBeforeTheFactorRow(): void
    {
        $journal = new LockOrderJournal();
        $users = new InMemoryUserRepository(UserMother::create());
        $users->lockOrderJournal = $journal;

        $factors = new InMemoryTotpFactorRepository();
        $factors->lockOrderJournal = $journal;

        $this->useCase($users, $factors)->enrol(UserMother::DEFAULT_ID, $this->acceptsTheCurrentPassword());

        $this->assertSame(
            [LockOrderJournal::IDENTITY_USER, LockOrderJournal::TOTP_FACTOR],
            $journal->crossTableOrder(),
        );
    }

    #[Test]
    public function theAuditRowNamesTheSubjectAndCarriesNothingAboutTheSecret(): void
    {
        $audit = new RecordingAuditLogger();

        $enrolment = $this->useCase(new InMemoryUserRepository(UserMother::create()), audit: $audit)
            ->enrol(UserMother::DEFAULT_ID, $this->acceptsTheCurrentPassword())
        ;

        $this->assertCount(1, $audit->records);
        $this->assertSame('SECOND_FACTOR_ENROLMENT_STARTED', $audit->records[0]['action']);
        $this->assertSame(AuditLevel::SECURITY, $audit->records[0]['level']);
        $this->assertSame(UserMother::DEFAULT_ID, $audit->records[0]['resource']?->id);
        $this->assertSame([], $audit->records[0]['metadata']);
        $this->assertStringNotContainsString(
            $enrolment->encodedSecret(),
            \json_encode($audit->records[0], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return Closure(HashedPassword): bool
     */
    private function acceptsTheCurrentPassword(): Closure
    {
        return static fn (HashedPassword $stored): bool => '' !== $stored->toString();
    }

    /**
     * @return Closure(HashedPassword): bool
     */
    private function refusesTheCurrentPassword(): Closure
    {
        return static fn (HashedPassword $stored): bool => '' === $stored->toString();
    }

    private function vault(): TotpSecretVault
    {
        return new TotpSecretVault(new SodiumEnvelopeEncryptor(new ScopeKeyring(new InMemoryKeystore(), self::KEK)));
    }

    private function useCase(
        InMemoryUserRepository $users,
        InMemoryTotpFactorRepository $factors = new InMemoryTotpFactorRepository(),
        ?TotpSecretVault $vault = null,
        RecordingAuditLogger $audit = new RecordingAuditLogger(),
    ): EnrolTotpFactor {
        return new EnrolTotpFactor(
            $users,
            $factors,
            new ProveCurrentPassword(),
            new Rfc6238TotpAlgorithm(),
            $vault ?? $this->vault(),
            $audit,
            new InlineTransactionManager(),
        );
    }
}
