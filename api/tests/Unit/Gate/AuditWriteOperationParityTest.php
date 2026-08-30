<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Shared\Audit\Domain\AuditWriteOperation;
use Erpify\Tests\Support\RepositoryRoot;
use Erpify\Tests\Support\TypeScriptSource;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * {@see AuditWriteOperation} is a PURE enum, so its case NAMES are the wire and not an implementation
 * detail: `AuditWriteCaptureListener` writes `$operation->name` straight into
 * `audit_log.metadata.operation`. The PWA restates the same three literals in `AuditChange.ts`, because
 * nothing imports across the two deployables.
 *
 * **Drift here is silent in the worst direction.** `ApiAuditEventDetailRepository` guards the decoded
 * value and, on a miss, DROPS it — the detail is returned without an `operation` at all, which the read
 * side is required to treat as "unknown" rather than as a fourth kind. So a name only one side knows
 * does not raise, does not warn and does not fail a payload: the forensic drawer quietly loses the
 * snapshot header that says whether it is looking at an initial state, a final state before deletion,
 * or an update, on a screen whose entire purpose is to say what happened.
 *
 * Read as TEXT on the PWA side on purpose — one is PHP and the other TypeScript, so no import can make
 * them agree by construction. Comments are blanked before anything is extracted: this file's own
 * docblocks name the enum, and a value quoted in a note beside the declaration would otherwise join the
 * vocabulary the gate compares.
 *
 * Order is not part of the comparison — the vocabulary is a closed SET and neither declaration's order
 * reaches the wire — so both sides are sorted before they are compared. Multiplicity IS: sorting keeps
 * duplicates, so a repeated literal on one side is still a divergence.
 *
 * The second assertion is the one {@see EnumWireContractGateTest} paid for and this gate inherits
 * rather than rediscovers: the guard is built FROM the declaration, so comparing the declaration means
 * something. A `Set` written back as a literal would admit its own vocabulary while this gate compared
 * the object beside it and passed.
 *
 * What a green proves, and only this: the two declare the same three names, and the payload guard is
 * derived from the PWA's declaration. It proves nothing about what any row in `audit_log` actually
 * holds, nothing about the labels either side renders for a name, and nothing about a fourth consumer
 * that restates the values instead of importing them.
 *
 * @internal
 */
#[CoversNothing]
final class AuditWriteOperationParityTest extends TestCase
{
    private const string VOCABULARY = 'pwa/src/context/backoffice/audit/domain/AuditChange.ts';

    private const string GUARD = 'pwa/src/context/backoffice/audit/infrastructure/ApiAuditEventDetailRepository.ts';

    #[Test]
    public function theWriteOperationVocabularyIsTheSameOnBothDeployables(): void
    {
        $this->assertSame(
            $this->declaredCaseNames(),
            $this->mirroredValues(),
            'The API enum case names and the PWA `AuditWriteOperation` literals have drifted apart. A '
            . 'name only one side knows is dropped by the PWA guard rather than reported, so the audit '
            . 'drawer silently renders a change row as though its write kind were unknown.',
        );
    }

    #[Test]
    public function thePayloadGuardIsDerivedFromTheMirroredVocabulary(): void
    {
        $code = TypeScriptSource::withoutComments($this->read($this->repoRoot() . '/' . self::GUARD));

        $derivations = \preg_match_all(
            '/AUDIT_WRITE_OPERATIONS\s*(?::[^=]*)?=\s*new Set\(\s*Object\.values\(\s*AuditWriteOperation\s*\)\s*\)/',
            $code,
        );

        $this->assertSame(1, $derivations, \sprintf(
            'Expected `AUDIT_WRITE_OPERATIONS` in %s to be built exactly once from '
            . '`Object.values(AuditWriteOperation)`, found %d. A guard that restates the names instead '
            . 'of deriving them is a second vocabulary: it would admit its own list while the assertion '
            . 'above compared the declaration beside it and passed.',
            self::GUARD,
            $derivations,
        ));
    }

    /**
     * No population floor is asserted here, and that is a limit of the instrument rather than an
     * oversight: PHPStan resolves an enum's cases statically, so any emptiness check over them is a
     * claim it can prove and therefore one this suite can never see fail. The floor is carried on the
     * side that is read at runtime — the mirrored values come out of a regex over the PWA source and
     * are asserted non-empty there — so an emptied enum still reds, through the equality rather than
     * through a check of its own.
     *
     * @return list<string>
     */
    private function declaredCaseNames(): array
    {
        $names = \array_map(
            static fn (AuditWriteOperation $case): string => $case->name,
            AuditWriteOperation::cases(),
        );

        \sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    private function mirroredValues(): array
    {
        $code = TypeScriptSource::withoutComments($this->read($this->repoRoot() . '/' . self::VOCABULARY));

        $declarations = \preg_match_all(
            '/const\s+AuditWriteOperation\s*=\s*\{([^}]*)\}\s*as const/',
            $code,
            $matches,
        );

        $this->assertSame(1, $declarations, \sprintf(
            'Expected exactly one `AuditWriteOperation` object literal in %s, found %d. Zero means the '
            . 'mirror was renamed, moved, or is no longer built from a literal — which this gate refuses '
            . 'to read as "declares nothing" — and more than one means a later edit can move the '
            . 'declaration the application uses while this gate keeps reading the other.',
            self::VOCABULARY,
            $declarations,
        ));

        \preg_match_all('/"([^"]*)"/', $matches[1][0], $values);

        $mirrored = $values[1];

        $this->assertNotEmpty($mirrored, \sprintf(
            'The `AuditWriteOperation` object in %s declares no value at all, so the PWA guard would '
            . 'drop every operation the API stamps.',
            self::VOCABULARY,
        ));

        \sort($mirrored);

        return $mirrored;
    }

    /**
     * The subject sits outside the `./api` build context, so in the container it arrives only through the
     * read-only `./` bind mount at `/app/repo` declared in `compose.dev.yaml`. Missing it is a failure and
     * never a skip: a gate that passes when it cannot see what it compares reports an agreement it never
     * checked.
     */
    private function repoRoot(): string
    {
        return RepositoryRoot::path() ?? $this->fail(
            'The PWA sites of the write-operation vocabulary are unreachable, so this parity gate cannot compare '
            . 'anything. Inside the container it comes from the read-only `./` bind mount at /app/repo declared in '
            . 'compose.dev.yaml — restore it rather than relaxing this failure into a skip.',
        );
    }

    private function read(string $path): string
    {
        $this->assertFileExists($path, \sprintf(
            'A site of the write-operation vocabulary is missing: %s. Re-derive this gate against '
            . 'wherever it moved rather than deleting it.',
            $path,
        ));

        $contents = \file_get_contents($path);

        $this->assertIsString($contents, \sprintf('Could not read %s', $path));

        return $contents;
    }
}
