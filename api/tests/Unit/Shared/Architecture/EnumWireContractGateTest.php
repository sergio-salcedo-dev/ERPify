<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use BackedEnum;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountStatus;
use Erpify\Shared\Kernel\Domain\Enum\Currency;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The PWA validates every bank-account payload against hand-written `Set`s of admissible enum values, and
 * a value present on one side and not the other is not a per-row problem: the guard rejects the whole
 * payload, so one unknown status collapses the entire list into a failed load. Nothing imports across the
 * two deployables, so today the sets agree only because both were written from the same reading.
 *
 * This gate compares the VALUES, never the files: it extracts the cases the server enum declares and the
 * literals the PWA `Set` admits, and asserts the two sets are equal. The mechanism — a PHP test reading a
 * non-PHP file and failing the build on divergence — is the one {@see CaddyfileAccessLogRedactionGateTest}
 * established; the comparison is not textual, so reformatting either side is free and only the vocabulary
 * is pinned.
 *
 * Every failure mode of the extraction is a RED, never a pass over nothing or over the wrong thing: an
 * absent or renamed declaration reads as "not found" rather than as an empty set, more than one candidate
 * declaration fails (a future refactor would otherwise leave the gate reading a stale one while the
 * application uses the other), an empty `Set` literal fails, comments are blanked before anything is read
 * so no value can be admitted by a note or a docblock, and a guard consulted by fewer payload predicates
 * than the contract names fails — a guard the payload path stopped calling admits everything.
 *
 * Multiplicity is not part of the comparison: a `Set` deduplicates, so the extracted literals are made
 * unique before the sets are compared, and a repeated literal is not a divergence.
 *
 * What a green does NOT prove, so nobody reads it as more:
 *
 *  - That the pairs below are all of them. They are enumerated by hand and there is no signal in the tree
 *    that marks an enum as wire-carried, so a NEW guarded enum joins this provider only because someone
 *    adds it. That direction rests on review.
 *  - That each consulted guard sits on the branch a response actually takes. The gate counts the payload
 *    predicates that consult it; it does not read their control flow.
 *  - That the same vocabulary is not hand-written a third time elsewhere in the PWA. It reads one file.
 *  - That the server serialises the case values it declares. The wire shape is the Resource DTO's, and
 *    this reads the enum.
 *
 * @internal
 */
#[CoversNothing]
final class EnumWireContractGateTest extends TestCase
{
    private const string BANK_ACCOUNT_ADAPTER
        = 'pwa/src/context/backoffice/bankaccount/infrastructure/ApiBankAccountRepository.ts';

    /**
     * @param class-string<BackedEnum> $enum
     */
    #[Test]
    #[DataProvider('provideTheServerEnumAndThePwaGuardAdmitTheSameValuesCases')]
    public function theServerEnumAndThePwaGuardAdmitTheSameValues(
        string $enum,
        string $constant,
        string $file,
        int $predicates,
        string $consequence,
    ): void {
        $this->assertSame(
            $this->declaredCases($enum),
            $this->admittedValues($file, $constant, $predicates),
            \sprintf(
                '`%s` and the PWA guard `%s` no longer admit the same values. %s',
                $enum,
                $constant,
                $consequence,
            ),
        );
    }

    /**
     * The predicate count is part of the contract, not decoration. The adapter validates two payload
     * shapes — a single account and a collection row — and each consults both guards. Asserting only
     * that a guard is consulted *somewhere* in the file would let every clause of one predicate be
     * deleted with the gate green, leaving the cross-bank list ungated while its vocabulary stayed
     * pinned; that list's blast radius is the whole point. A third payload shape means raising this
     * number deliberately.
     *
     * @return iterable<string, array{class-string<BackedEnum>, string, string, int, string}>
     */
    public static function provideTheServerEnumAndThePwaGuardAdmitTheSameValuesCases(): iterable
    {
        yield 'Currency' => [
            Currency::class,
            'CURRENCIES',
            self::BANK_ACCOUNT_ADAPTER,
            2,
            'A currency the server emits and the guard does not admit fails the whole payload, so one '
            . 'account in an unlisted currency takes down every list and detail screen that reads it.',
        ];

        yield 'BankAccountStatus' => [
            BankAccountStatus::class,
            'STATUSES',
            self::BANK_ACCOUNT_ADAPTER,
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
            '`%s` declares no case at all, so comparing it against the PWA guard would compare two empty '
            . 'sets and pass over nothing.',
            $enum,
        ));

        \sort($values);

        return $values;
    }

    /**
     * @return list<string>
     */
    private function admittedValues(string $relativePath, string $constant, int $predicates): array
    {
        $code = $this->withoutComments($this->read($this->repoRoot() . '/' . $relativePath));
        $quoted = \preg_quote($constant, '/');

        $declarations = \preg_match_all(
            '/const\s+' . $quoted . '\s*:[^=]*=\s*new Set\(\[([^\]]*)\]\)/',
            $code,
            $matches,
        );

        $this->assertSame(1, $declarations, \sprintf(
            'Expected exactly one `%s` Set literal in %s, found %d. Zero means the guard was renamed, '
            . 'removed, or is no longer built from a literal — which this gate refuses to read as "admits '
            . 'nothing" — and more than one means a future edit can move the declaration the application '
            . 'uses while this gate keeps reading the other.',
            $constant,
            $relativePath,
            $declarations,
        ));

        $consultations = \preg_match_all('/(?<![A-Za-z0-9_$])' . $quoted . '\.has\(/', $code);

        $this->assertSame($predicates, $consultations, \sprintf(
            '`%s` is consulted by %d payload predicate(s) in %s, not the %d this contract expects. A guard '
            . 'the payload path stopped calling admits everything, and agreeing with the server enum then '
            . 'proves nothing about what the application accepts.',
            $constant,
            $consultations,
            $relativePath,
            $predicates,
        ));

        $literal = $matches[1][0] ?? null;

        $this->assertIsString($literal);

        \preg_match_all('/"([^"]*)"/', $literal, $values);
        $admitted = \array_values(\array_unique($values[1]));

        $this->assertNotEmpty($admitted, \sprintf(
            'The `%s` Set in %s admits no value at all, which would reject every payload the server sends.',
            $constant,
            $relativePath,
        ));

        \sort($admitted);

        return $admitted;
    }

    /**
     * Blanks TypeScript comments before anything is extracted. Without this the gate reads text that
     * never executes: a value named in a note beside the literal (`"CLOSED", // "SUSPENDED" next`) would
     * join the admitted set, and a docblock quoting the old declaration would BE the single declaration
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
            'The PWA half of the enum contract is missing: %s. Re-derive this gate against wherever the '
            . 'payload guard moved rather than deleting it.',
            $path,
        ));

        $source = \file_get_contents($path);

        $this->assertIsString($source);

        return $source;
    }
}
