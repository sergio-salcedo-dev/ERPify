<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Application\IdentityRowLock;
use Erpify\Tests\Support\ApiSourceFiles;
use Erpify\Tests\Support\SubjectAuditWriteMechanisms;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every audit row the identity module writes naming its subject — `AuditResource::of(FulfilIdentityErasure::
 * SUBJECT_RESOURCE_TYPE, …)` under `Iam/Identity/Application` — is serialised with that subject's
 * `identity_user` row, so it cannot commit after an erasure's pass over the trail has run. A row that does is
 * residue nothing clears: the erasure redacts only what is committed when it passes.
 *
 * A site is serialised in one of three ways, and the sweep names which ({@see SubjectAuditWriteMechanisms}):
 *  - `row-lock` — the site, or a private method every call of which, sits inside the closure handed to
 *    {@see IdentityRowLock::whileHeld()} or {@see IdentityRowLock::whileHeldByEmail()};
 *  - `locking-read` — every call of the method holding the site sits inside a `->transactional(` closure, after
 *    a `->…ForUpdate(` read in that same closure;
 *  - `creates-subject` — every call of it follows a `User::invite(` in the same method, so the audited identity
 *    is created in the transaction that writes the row and no erasure can hold or reach it first.
 *
 * Anything else is `unserialised`, and the per-file population is PINNED: a writer added without a lock reds
 * as `unserialised`, and a writer added with one reds too until it is listed — which is what keeps the sweep
 * from passing over a selection that stopped matching.
 *
 * Read as tokens, so a docblock naming the constant is no write. **A green proves** each listed site is
 * lexically placed as its mechanism says. It proves nothing about which row a `ForUpdate` read locks (the
 * subject's or another), about a lock taken and released before the write, about a call reached through a
 * callable, a helper in another file or a trait, about the resource type spelled as a literal or reached under
 * an alias, nor about a writer outside `Iam/Identity/Application`. The erasure itself
 * ({@see FulfilIdentityErasure}) spells the constant through `self::` and is outside the sweep by construction.
 *
 * @internal
 */
#[CoversNothing]
final class SubjectAuditWriteSerialisationGateTest extends TestCase
{
    private const string SCOPE = '/Iam/Identity/Application/';

    /**
     * @var array<string, string> file under the scope => the mechanism serialising its write
     */
    private const array POPULATION = [
        'ChangeUserRoles.php' => 'locking-read',
        'InviteUser.php' => 'creates-subject',
        'RecordLockoutAuditBestEffort.php' => 'row-lock',
        'RecordLockoutNoticeAuditBestEffort.php' => 'row-lock',
        'RecordRecoverySecretAuditBestEffort.php' => 'row-lock',
        'RecordRecoveryThrottleAuditBestEffort.php' => 'row-lock',
        'UnlockUserAccount.php' => 'locking-read',
    ];

    private const int SITES = 7;

    #[Test]
    public function everySubjectAuditWriteIsSerialisedWithTheSubjectsRow(): void
    {
        $found = [];
        $sites = 0;

        foreach (ApiSourceFiles::phpFiles(ApiSourceFiles::root()) as $file) {
            $path = $file->getPathname();

            if (!\str_contains($path, self::SCOPE)) {
                continue;
            }

            foreach ($this->mechanismsOf((string) \file_get_contents($path)) as $mechanism) {
                ++$sites;
                $found[\basename($path)][] = $mechanism;
            }
        }

        $flattened = \array_map(
            static fn (array $mechanisms): string => \implode(',', \array_unique($mechanisms)),
            $found,
        );
        \ksort($flattened);

        $this->assertSame(self::SITES, $sites, 'the sweep no longer matches the subject writes it was built over');
        $this->assertSame(
            self::POPULATION,
            $flattened,
            'A row naming the identity subject must be written under IdentityRowLock or after a locking read in '
            . 'the same transaction; a new writer is listed here once it is.',
        );
    }

    #[Test]
    public function aWriteInsideTheRowLockClosureIsSerialised(): void
    {
        $this->assertSame(['row-lock'], $this->mechanismsOf(<<<'PHP'
            <?php
            final class A {
                public function record(string $id): void {
                    $this->rows->whileHeld($id, function () use ($id): void {
                        $this->log(AuditResource::of(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $id));
                    });
                }
            }
            PHP));
    }

    #[Test]
    public function aHelperCalledOnlyFromTheClosureIsSerialised(): void
    {
        $this->assertSame(['row-lock'], $this->mechanismsOf(<<<'PHP'
            <?php
            final class A {
                public function record(Email $e): void {
                    $this->rows->whileHeldByEmail($e, function ($id) { $this->log($this->subject($id)); });
                }
                private function subject(string $id): AuditResource {
                    return AuditResource::of(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $id);
                }
            }
            PHP));
    }

    #[Test]
    public function aHelperAlsoCalledOutsideTheClosureIsNot(): void
    {
        $this->assertSame(['unserialised'], $this->mechanismsOf(<<<'PHP'
            <?php
            final class A {
                public function record(string $id): void {
                    $this->rows->whileHeld($id, function () use ($id): void { $this->write($id); });
                    $this->write($id);
                }
                private function write(string $id): void {
                    $this->log(AuditResource::of(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $id));
                }
            }
            PHP));
    }

    #[Test]
    public function aWriteAfterALockingReadInTheSameTransactionIsSerialised(): void
    {
        $this->assertSame(['locking-read'], $this->mechanismsOf(<<<'PHP'
            <?php
            final class A {
                public function run(string $id): void {
                    $this->tx->transactional(function () use ($id): void {
                        $user = $this->users->findByIdForUpdate($id);
                        $this->audit($id);
                    });
                }
                private function audit(string $id): void {
                    $this->log(AuditResource::of(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $id));
                }
            }
            PHP));
    }

    #[Test]
    public function aWriteBeforeTheLockingReadOrWithoutOneIsNot(): void
    {
        $this->assertSame(['unserialised'], $this->mechanismsOf(<<<'PHP'
            <?php
            final class A {
                public function run(string $id): void {
                    $this->tx->transactional(function () use ($id): void {
                        $this->audit($id);
                        $user = $this->users->findByIdForUpdate($id);
                    });
                }
                private function audit(string $id): void {
                    $this->log(AuditResource::of(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $id));
                }
            }
            PHP));
        $this->assertSame(['unserialised'], $this->mechanismsOf(<<<'PHP'
            <?php
            final class A {
                public function record(string $id): void {
                    $this->log(AuditResource::of(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $id));
                }
            }
            PHP));
    }

    #[Test]
    public function aWriteForAnIdentityCreatedInTheSameMethodIsSerialised(): void
    {
        $this->assertSame(['creates-subject'], $this->mechanismsOf(<<<'PHP'
            <?php
            final class A {
                public function invite(string $e): User {
                    $user = User::invite(Uuid::generate(), $e);
                    $this->grant($user);
                    return $user;
                }
                private function grant(User $u): void {
                    $this->log(AuditResource::of(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $u->id));
                }
            }
            PHP));
    }

    #[Test]
    public function aMentionInADocblockIsNoWrite(): void
    {
        $this->assertSame([], $this->mechanismsOf(<<<'PHP'
            <?php
            /** Writes AuditResource::of(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $id). */
            final class A {
                // AuditResource::of(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $id)
                public function f(): void {}
            }
            PHP));
    }

    /**
     * @return list<string>
     */
    private function mechanismsOf(string $source): array
    {
        return SubjectAuditWriteMechanisms::of($source);
    }
}
