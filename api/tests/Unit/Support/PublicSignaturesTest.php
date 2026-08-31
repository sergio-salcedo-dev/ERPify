<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Support;

use Erpify\Tests\Support\PublicSignatures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shapes the derivation has to keep seeing, because the way the gate above it goes silent is not a red
 * assertion — it is a signature the reader stops recognising. A parameter that disappears from the sweep
 * satisfies every "no offender was found" assertion perfectly.
 *
 * Each case below was a measured failure of a simpler reader: a parameter attribute whose closing bracket
 * ended the list early, a promoted constructor property whose visibility hid the parameter, a default value
 * holding a comma or its own parentheses, a method declaring no visibility at all, and a parameter list
 * broken over several lines.
 *
 * @internal
 */
#[CoversClass(PublicSignatures::class)]
final class PublicSignaturesTest extends TestCase
{
    #[Test]
    public function aMethodDeclaringNoVisibilityIsPublic(): void
    {
        // PHP says so, and reading only an explicit `public` would let exactly the signature a gate exists
        // to refuse slip past it.
        $signature = $this->firstSignatureIn(<<<'PHP'
            <?php
            final class C {
                function read(string $path): void {}
            }
            PHP);

        $this->assertSame(['path'], $signature['parameters']);
    }

    #[Test]
    public function itSkipsPrivateAndProtectedMethodsThroughTheirModifiers(): void
    {
        $signatures = PublicSignatures::inSource(<<<'PHP'
            <?php
            final class C {
                private static function a(string $path): void {}
                protected final function b(string $url): void {}
                public function c(string $id): void {}
            }
            PHP);

        $this->assertSame(['c'], \array_column($signatures, 'method'));
    }

    #[Test]
    public function anAttributeBetweenTheDocblockAndTheKeywordDoesNotHideTheVisibility(): void
    {
        $signatures = PublicSignatures::inSource(<<<'PHP'
            <?php
            final class C {
                /** A docblock. */
                #[Override]
                private function hidden(string $path): void {}
            }
            PHP);

        $this->assertSame([], $signatures);
    }

    #[Test]
    public function aClosureInsideAMethodIsNotAMemberSignature(): void
    {
        $signatures = PublicSignatures::inSource(<<<'PHP'
            <?php
            final class C {
                public function a(string $id): void {
                    $f = function (string $path): void {};
                    $g = fn (string $url) => $url;
                }
            }
            PHP);

        $this->assertSame(['a'], \array_column($signatures, 'method'));
        $this->assertSame(['id'], $signatures[0]['parameters'] ?? $this->fail('the one method vanished'));
    }

    /**
     * The failure direction here is TOTAL DISAPPEARANCE, not misreading: `&` is not a `T_STRING`, so the
     * method yielded no name and dropped off both axes of the scan — its types and its parameter names alike.
     */
    #[Test]
    public function itReadsAMethodThatReturnsByReference(): void
    {
        $signature = $this->firstSignatureIn(<<<'PHP'
            <?php
            final class C {
                public function &borrow(SplFileInfo $handle): array { return []; }
            }
            PHP);

        $this->assertSame('borrow', $signature['method']);
        $this->assertSame(['handle'], $signature['parameters']);
        $this->assertContains('SplFileInfo', $signature['types']);
    }

    /**
     * PHP admits its own keywords as METHOD names, and the lexer emits each as its own token rather than as
     * `T_STRING` — so the same total disappearance happened for a method called `list`, `print` or `match`.
     * Matched by identifier SHAPE rather than against a list of keywords, because a list is a thing somebody
     * has to maintain and PHP keeps adding to it.
     */
    #[Test]
    #[DataProvider('provideItReadsAMethodNamedWithASemiReservedWordCases')]
    public function itReadsAMethodNamedWithASemiReservedWord(string $name): void
    {
        $signature = $this->firstSignatureIn(\sprintf(
            "<?php\nfinal class C {\n    public function %s(SplFileInfo \$handle): void {}\n}\n",
            $name,
        ));

        $this->assertSame($name, $signature['method']);
        $this->assertSame(['handle'], $signature['parameters']);
        $this->assertContains('SplFileInfo', $signature['types']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideItReadsAMethodNamedWithASemiReservedWordCases(): iterable
    {
        foreach (['list', 'print', 'default', 'array', 'match', 'fn', 'unset', 'echo'] as $name) {
            yield $name => [$name];
        }
    }

    /**
     * The first signature the derivation found, or a failure — never a silently missing offset. The
     * distinction matters here more than usual: every assertion in this file is about the derivation SEEING
     * something, and an empty result would satisfy a lenient reading of each one.
     *
     * @return array{method: string, parameters: list<string>, types: list<string>}
     */
    private function firstSignatureIn(string $source): array
    {
        return PublicSignatures::inSource($source)[0] ?? $this->fail('The derivation parsed no public signature.');
    }
}
