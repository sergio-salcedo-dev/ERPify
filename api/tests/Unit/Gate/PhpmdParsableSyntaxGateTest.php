<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * PHP 8.4 lets a `new` expression be dereferenced without parentheses — `new Foo()->bar()`. PDepend, which
 * PHPMD parses the tree with, cannot read it, and the way it fails is the reason this gate exists rather
 * than a comment.
 *
 * **It fails as an ERROR, not as a violation**, and the summary line reads
 * `Found 0 violations and 1 error` — which at a glance says the sweep was clean. The detail is one line of
 * `Unexpected token: ->` above a 38-frame PDepend stack trace naming files inside a `.phar`, so what a
 * reader takes from a red `make php.md` is "the tool broke", not "I wrote something the tool cannot read".
 * And the blast radius is the whole sweep: PDepend abandons the run, so every other rule over `bin`,
 * `config`, `src`, `tests`, `tools` and `public` reports nothing for as long as one file carries the form.
 *
 * **A note at the site cannot carry this.** The remedy is invisible until somebody writes the form, and the
 * only reader a comment beside an existing pair of parentheses reaches is whoever edits that line. Everyone
 * else meets the stack trace with nothing pointing at the cause, which is what makes the rule belong in a
 * check.
 *
 * **What is refused is measured, not assumed** — each form run through the same sweep `make php.md` runs, on
 * the pinned `phpmd.phar` (2.15.0-snapshot, 2023-12-11, which predates the syntax):
 *
 * | Form                  | PDepend                   |
 * |-----------------------|---------------------------|
 * | `new X()->m()`        | `Unexpected token: ->`    |
 * | `new X($a)->m()`      | `Unexpected token: ->`     |
 * | `new X($a)->prop`     | `Unexpected token: ->`     |
 * | `new X($a)::CONST`    | `Unexpected token: ::`     |
 * | `new X($a)[0]`        | parses                    |
 * | `(new X($a))->m()`    | parses                    |
 *
 * So the rule is `->` and `::` after a `new`'s argument list, and the array-index form is deliberately NOT
 * refused: a gate that reported it would be refusing valid code over a limitation that does not exist.
 *
 * **The remedy is parentheses**, which every PHP version reads the same way. Nothing here argues the bare
 * form is worse style — it is refused because one tool in this repository's own sweep cannot read it, and
 * the honest place to say that is a check, not a comment on three lines out of thousands.
 *
 * A green proves no file in the swept set carries the two refused forms. It proves nothing about a version
 * of PDepend other than the committed `.phar`, and it does not itself run PHPMD — upgrading that artifact is
 * what would retire this gate, and until then the two are pinned to each other by this docblock alone.
 *
 * @internal
 */
#[CoversNothing]
final class PhpmdParsableSyntaxGateTest extends TestCase
{
    /** The directories `make php.md` hands PHPMD, mirrored from `make/php-quality.mk`. */
    private const array SWEPT = ['bin', 'config', 'src', 'tests', 'tools', 'public'];

    /** `tools/phpmd/phpmd.xml`'s own exclusions; a path under one of these is never parsed. */
    private const array EXCLUDED = ['/vendor/', '/var/'];

    /**
     * What may stand between `new` and its argument list: a name, a namespace-qualified name, a variable or
     * `static`. `class` is deliberately absent — an anonymous class is not dereferenceable this way, so
     * meeting it means this construction is not the shape being looked for.
     */
    private const array CLASS_REFERENCE = [
        T_STRING,
        T_NAME_QUALIFIED,
        T_NAME_FULLY_QUALIFIED,
        T_NAME_RELATIVE,
        T_VARIABLE,
        T_STATIC,
    ];

    /**
     * A floor on the walk. Without it a broken glob makes every assertion below pass over an empty set,
     * which is the failure this repository has shipped more than once.
     */
    private const int MIN_FILES = 500;

    #[Test]
    public function noSweptFileCarriesASyntaxTheSweepsOwnParserCannotRead(): void
    {
        $offenders = [];
        $scanned = 0;

        foreach ($this->sweptFiles() as $file) {
            ++$scanned;
            $source = \file_get_contents($file);

            if (false === $source) {
                $this->fail(\sprintf('%s could not be read, so the sweep is not covering it.', $file));
            }

            foreach ($this->bareNewDereferences($source) as $line) {
                $offenders[] = \sprintf('%s:%d', $this->relative($file), $line);
            }
        }

        $this->assertGreaterThanOrEqual(
            self::MIN_FILES,
            $scanned,
            'the walk found almost nothing, so a green here would be about an empty set',
        );
        $this->assertSame(
            [],
            $offenders,
            'a bare `new X()->…` or `new X()::…` aborts the whole PHPMD sweep with a parser error reported as '
            . '`0 violations and 1 error`. Wrap the `new` in parentheses: `(new X())->…`.',
        );
    }

    #[Test]
    #[DataProvider('provideTheDetectorFindsEveryFormTheParserRefusesCases')]
    public function theDetectorFindsEveryFormTheParserRefuses(string $source): void
    {
        // The half that keeps the sweep above from being a tautology: a detector that found nothing would
        // report the tree clean for ever, and the tree being clean is exactly what it looks like.
        $this->assertNotSame([], $this->bareNewDereferences($source), 'a refused form went undetected');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideTheDetectorFindsEveryFormTheParserRefusesCases(): iterable
    {
        // Each measured against the real sweep before being pinned here.
        yield 'method call, no constructor arguments' => ['<?php $x = new Foo()->bar();'];
        yield 'method call, with arguments' => ['<?php $x = new Foo($a, $b)->bar();'];
        yield 'property read' => ['<?php $x = new Foo($a)->bar;'];
        yield 'static member' => ['<?php $x = new Foo($a)::BAZ;'];
        yield 'qualified class name' => ['<?php $x = new \Some\Foo($a)->bar();'];
        yield 'variable class name' => ['<?php $x = new $class($a)->bar();'];
    }

    #[Test]
    #[DataProvider('provideTheDetectorLeavesEveryFormTheParserAcceptsAloneCases')]
    public function theDetectorLeavesEveryFormTheParserAcceptsAlone(string $source): void
    {
        // The direction that decays in silence. A detector reporting the remedy, or the array form PDepend
        // reads fine, would push authors away from valid code — and `wrap(new Foo(), 2)->bar()` is the shape
        // a naive matcher gets wrong, because the `->` belongs to the OUTER call.
        $this->assertSame([], $this->bareNewDereferences($source));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideTheDetectorLeavesEveryFormTheParserAcceptsAloneCases(): iterable
    {
        yield 'parenthesised, the remedy' => ['<?php $x = (new Foo($a))->bar();'];
        yield 'array index, which PDepend does parse' => ['<?php $x = new Foo($a)[0];'];
        yield 'plain construction' => ['<?php $x = new Foo($a); $y = $x->bar();'];
        yield 'anonymous class' => ['<?php $x = new class($a) extends Foo {};'];
        yield 'construction as an argument' => ['<?php $x = wrap(new Foo($a), 2)->bar();'];
        yield 'the token in a comment' => ['<?php // new Foo($a)->bar() is refused' . "\n" . '$x = 1;'];
        yield 'the token in a string' => ['<?php $x = "new Foo($a)->bar()";'];
    }

    /**
     * Lines carrying a `new` whose argument list is dereferenced without parentheses.
     *
     * Read with `token_get_all` rather than a regular expression, for the reason this repository has already
     * written down twice: a line-oriented matcher cannot tell the construct from the same characters inside a
     * comment or a string, and cannot follow an argument list across lines.
     *
     * @return list<int>
     */
    private function bareNewDereferences(string $source): array
    {
        $tokens = $this->significantTokens($source);
        $lines = [];

        foreach ($tokens as $index => $token) {
            if (\is_array($token) && T_NEW === $token[0] && $this->isDereferencedBare($tokens, $index + 1)) {
                $lines[] = $token[2];
            }
        }

        return $lines;
    }

    /**
     * Whitespace and comments dropped, so the walk below reasons about adjacency without stepping over
     * formatting — and so a `new Foo()->bar()` written inside a comment never reaches it at all.
     *
     * @return list<array{int, string, int}|string>
     */
    private function significantTokens(string $source): array
    {
        return \array_values(\array_filter(
            \token_get_all($source),
            static fn (array|string $token): bool => !\is_array($token)
                || !\in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
    }

    /**
     * Whether the construction starting at `$from` closes its argument list straight into a `->` or a `::`.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private function isDereferencedBare(array $tokens, int $from): bool
    {
        $open = $this->argumentListOf($tokens, $from);

        if (null === $open) {
            return false;
        }

        $after = $this->matchingParenthesis($tokens, $open);

        if (null === $after) {
            return false;
        }

        $next = $tokens[$after] ?? null;

        return \is_array($next) && \in_array($next[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
    }

    /**
     * The index of the `(` opening a `new`'s argument list, or null when the construction has none — a bare
     * `new Foo`, or an anonymous class, neither of which can be dereferenced this way.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private function argumentListOf(array $tokens, int $from): ?int
    {
        for ($i = $from, $count = \count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i] ?? null;

            if ('(' === $token) {
                return $i;
            }

            if (!\is_array($token) || !\in_array($token[0], self::CLASS_REFERENCE, true)) {
                return null;
            }
        }

        return null;
    }

    /**
     * The index one past the `)` closing the parenthesis at `$open`, or null when it never closes.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private function matchingParenthesis(array $tokens, int $open): ?int
    {
        $depth = 0;

        for ($i = $open, $count = \count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i] ?? null;

            if ('(' === $token) {
                ++$depth;
            } elseif (')' === $token && 0 === --$depth) {
                return $i + 1;
            }
        }

        return null;
    }

    /**
     * @return iterable<string>
     */
    private function sweptFiles(): iterable
    {
        foreach (self::SWEPT as $directory) {
            $root = $this->apiRoot() . '/' . $directory;

            if (!\is_dir($root)) {
                continue;
            }

            /** @var iterable<SplFileInfo> $files */
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if (!$file->isFile() || 'php' !== $file->getExtension()) {
                    continue;
                }

                $path = \str_replace('\\', '/', $file->getPathname());

                foreach (self::EXCLUDED as $excluded) {
                    if (\str_contains($path, $excluded)) {
                        continue 2;
                    }
                }

                yield $path;
            }
        }
    }

    private function apiRoot(): string
    {
        return \dirname(__DIR__, 3);
    }

    private function relative(string $path): string
    {
        return \str_replace($this->apiRoot() . '/', '', $path);
    }
}
