<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use BackedEnum;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountStatus;
use Erpify\Shared\Kernel\Domain\Enum\Currency;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The PWA validates every bank-account payload against a closed vocabulary, and a value present on one
 * side and not the other is not a per-row problem: the guard rejects the whole payload, so one unknown
 * status collapses an entire list into a failed load. Nothing imports across the two deployables, so
 * the agreement holds only because something checks it.
 *
 * The PWA declares each vocabulary once, in its `domain/`, and derives the rest — the payload guard's
 * `Set`, the form's Zod enum, the status control's options. This gate therefore pins two things, and
 * needs both: that the single declaration matches the server enum's cases, and that the guard is
 * *derived from that declaration* rather than restating it. Without the second, a `Set` written back as
 * a literal would admit its own vocabulary while this gate compared the array beside it and passed.
 *
 * The mechanism — a PHP test reading TypeScript and failing the build on divergence — is the one
 * {@see CaddyfileAccessLogRedactionGateTest} established. The comparison is of VALUES, never of file
 * text, so reformatting either side is free.
 *
 * Every failure mode of the extraction is a RED, never a pass over nothing or over the wrong thing: an
 * absent or renamed declaration reads as "not found" rather than as an empty vocabulary, more than one
 * candidate declaration fails (a future refactor would otherwise leave the gate reading a stale one),
 * an empty array fails, comments are blanked before anything is read so no value can be admitted by a
 * note or a docblock, and a guard consulted by fewer payload predicates than the contract names fails —
 * a guard the payload path stopped calling admits everything.
 *
 * Multiplicity is not part of the comparison: the derived structure is a `Set`, so the declared values
 * are made unique before the sets are compared and a repeated literal is not a divergence.
 *
 * What a green does NOT prove, so nobody reads it as more:
 *
 *  - That the pairs below are all of them. They are enumerated by hand and there is no signal in the
 *    tree that marks an enum as wire-carried, so a NEW guarded enum joins this provider only because
 *    someone adds it. That direction rests on review.
 *  - That each consulted guard sits on the branch a response actually takes. The gate counts the
 *    payload predicates that consult it; it does not read their control flow.
 *  - That every consumer of the vocabulary derives from it. The gate pins the payload guard, which is
 *    the one whose divergence fails a whole screen; a future consumer that restates the values instead
 *    of importing them is invisible here.
 *  - That the server serialises the case values it declares. The wire shape is the Resource DTO's, and
 *    this reads the enum.
 *
 * @internal
 */
#[CoversNothing]
final class EnumWireContractGateTest extends TestCase
{
    private const string BANK_ACCOUNT_VOCABULARY
        = 'pwa/src/context/backoffice/bankaccount/domain/BankAccount.ts';

    private const string BANK_ACCOUNT_GUARD
        = 'pwa/src/context/backoffice/bankaccount/infrastructure/ApiBankAccountRepository.ts';

    /**
     * @param class-string<BackedEnum> $enum
     */
    #[Test]
    #[DataProvider('provideBankAccountVocabularyCases')]
    public function theServerEnumAndThePwaVocabularyDeclareTheSameValues(
        string $enum,
        string $vocabulary,
        string $vocabularyFile,
        string $guard,
        string $guardFile,
        int $predicates,
        string $consequence,
    ): void {
        unset($guard, $guardFile, $predicates);

        $this->assertSame(
            $this->declaredCases($enum),
            $this->declaredVocabulary($vocabularyFile, $vocabulary),
            \sprintf(
                '`%s` and the PWA vocabulary `%s` no longer declare the same values. %s',
                $enum,
                $vocabulary,
                $consequence,
            ),
        );
    }

    /**
     * The half that keeps the comparison above meaningful. The values are pinned against the
     * declaration; this pins that the thing the payload path actually consults is built FROM that
     * declaration, and by as many predicates as the contract names — the adapter validates two payload
     * shapes (a single account, a collection row) and each consults both guards. Without the count, the
     * clauses of one predicate could be deleted with the gate green, leaving the cross-bank list
     * ungated while its vocabulary stayed pinned. A third payload shape means raising it deliberately.
     *
     * @param class-string<BackedEnum> $enum
     */
    #[Test]
    #[DataProvider('provideBankAccountVocabularyCases')]
    public function theGuardIsDerivedFromTheVocabularyAndEveryPayloadPredicateConsultsIt(
        string $enum,
        string $vocabulary,
        string $vocabularyFile,
        string $guard,
        string $guardFile,
        int $predicates,
        string $consequence,
    ): void {
        unset($enum, $vocabularyFile, $consequence);

        $code = $this->withoutComments($this->read($this->repoRoot() . '/' . $guardFile));

        $derivations = \preg_match_all(
            '/const\s+' . \preg_quote($guard, '/') . '\s*:[^=]*=\s*new Set\(\s*'
                . \preg_quote($vocabulary, '/') . '\s*\)/',
            $code,
        );

        $this->assertSame(1, $derivations, \sprintf(
            'Expected `%s` in %s to be built exactly once as `new Set(%s)`, found %d. A guard that '
            . 'restates the values instead of deriving them is a second vocabulary: it would admit its '
            . 'own list while this gate compared the declaration beside it and passed.',
            $guard,
            $guardFile,
            $vocabulary,
            $derivations,
        ));

        $consultations = \preg_match_all(
            '/(?<![A-Za-z0-9_$])' . \preg_quote($guard, '/') . '\.has\(/',
            $code,
        );

        $this->assertSame($predicates, $consultations, \sprintf(
            '`%s` is consulted by %d payload predicate(s) in %s, not the %d this contract expects. A '
            . 'guard the payload path stopped calling admits everything, and agreeing with the server '
            . 'enum then proves nothing about what the application accepts.',
            $guard,
            $consultations,
            $guardFile,
            $predicates,
        ));
    }

    /**
     * @return iterable<string, array{class-string<BackedEnum>, string, string, string, string, int, string}>
     */
    public static function provideBankAccountVocabularyCases(): iterable
    {
        yield 'Currency' => [
            Currency::class,
            'BANK_ACCOUNT_CURRENCIES',
            self::BANK_ACCOUNT_VOCABULARY,
            'CURRENCIES',
            self::BANK_ACCOUNT_GUARD,
            2,
            'A currency the server emits and the guard does not admit fails the whole payload, so one '
            . 'account in an unlisted currency takes down every list and detail screen that reads it.',
        ];

        yield 'BankAccountStatus' => [
            BankAccountStatus::class,
            'BANK_ACCOUNT_STATUSES',
            self::BANK_ACCOUNT_VOCABULARY,
            'STATUSES',
            self::BANK_ACCOUNT_GUARD,
            2,
            'A status the server emits and the guard does not admit fails the whole payload, so a single '
            . 'account in a new lifecycle state takes down every list and detail screen that reads it.',
        ];
    }

    /**
     * @param class-string<BackedEnum> $enum
     *
     * @return list<string>
     */
    private function declaredCases(string $enum): array
    {
        $values = \array_map(static fn (BackedEnum $case): string => (string) $case->value, $enum::cases());

        $this->assertNotEmpty($values, \sprintf(
            '`%s` declares no case at all, so comparing it against the PWA vocabulary would compare two '
            . 'empty sets and pass over nothing.',
            $enum,
        ));

        \sort($values);

        return $values;
    }

    /**
     * @return list<string>
     */
    private function declaredVocabulary(string $relativePath, string $constant): array
    {
        $code = $this->withoutComments($this->read($this->repoRoot() . '/' . $relativePath));
        $quoted = \preg_quote($constant, '/');

        $declarations = \preg_match_all(
            '/const\s+' . $quoted . '\s*=\s*\[([^\]]*)\]\s*as const/',
            $code,
            $matches,
        );

        $this->assertSame(1, $declarations, \sprintf(
            'Expected exactly one `%s` array literal in %s, found %d. Zero means the vocabulary was '
            . 'renamed, moved, or is no longer built from a literal — which this gate refuses to read as '
            . '"declares nothing" — and more than one means a future edit can move the declaration the '
            . 'application uses while this gate keeps reading the other.',
            $constant,
            $relativePath,
            $declarations,
        ));

        $literal = $matches[1][0] ?? null;

        $this->assertIsString($literal);

        \preg_match_all('/"([^"]*)"/', $literal, $values);
        $declared = \array_values(\array_unique($values[1]));

        $this->assertNotEmpty($declared, \sprintf(
            'The `%s` array in %s declares no value at all, which would reject every payload the server '
            . 'sends.',
            $constant,
            $relativePath,
        ));

        \sort($declared);

        return $declared;
    }

    /**
     * Blanks TypeScript comments before anything is extracted. Without this the gate reads text that
     * never executes: a value named in a note beside the literal (`"CLOSED", // "SUSPENDED" next`) would
     * join the declared set, and a docblock quoting the old declaration would BE the single declaration
     * once the real one stopped matching — both green while the guard rejects the value.
     *
     * The blanking is textual, so a `//` inside a string literal blanks the rest of that line. That can
     * only remove text from the haystack, never invent it: the worst it can do is turn a declaration
     * into no declaration, which this gate already fails on.
     */
    private function withoutComments(string $source): string
    {
        $stripped = \preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source);

        $this->assertIsString($stripped);

        return $stripped;
    }

    /**
     * The PWA tree sits outside the `./api` build context, so in the container it arrives only through the
     * read-only `./` bind mount at `/app/repo` declared in `compose.dev.yaml`. Missing it is a failure, not
     * a skip: a contract gate that passes when it cannot see one of the two sides reports an agreement it
     * never checked.
     */
    private function repoRoot(): string
    {
        $apiRoot = \dirname(__DIR__, 4);

        foreach ([\dirname($apiRoot), \dirname($apiRoot) . '/repo'] as $candidate) {
            if (\is_dir($candidate . '/pwa/src')) {
                return $candidate;
            }
        }

        $this->fail(
            'The PWA tree is not reachable, so this gate cannot check anything. Inside the container it '
            . 'comes from the read-only `./` bind mount at /app/repo declared in compose.dev.yaml — '
            . 'restore it rather than relaxing this failure into a skip.',
        );
    }

    private function read(string $path): string
    {
        $this->assertFileExists($path, \sprintf(
            'A half of the enum contract is missing: %s. Re-derive this gate against wherever it moved '
            . 'rather than deleting it.',
            $path,
        ));

        $source = \file_get_contents($path);

        $this->assertIsString($source);

        return $source;
    }
}
