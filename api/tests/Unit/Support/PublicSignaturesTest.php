<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Support;

use Erpify\Tests\Support\PublicSignatures;
use Erpify\Tests\Support\SignatureReader;
use PHPUnit\Framework\Attributes\CoversClass;
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
#[CoversClass(SignatureReader::class)]
final class PublicSignaturesTest extends TestCase
{
    #[Test]
    public function itReadsEveryParameterPastAParameterAttribute(): void
    {
        // `#[` is one T_ATTRIBUTE token rather than the bracket it opens, so a reader counting only the
        // literal `[` sees its `]` close the parameter list and loses everything after it.
        $signature = $this->firstSignatureIn(<<<'PHP'
            <?php
            final class C {
                public function read(string $id, #[SensitiveParameter] string $bytes): void {}
            }
            PHP);

        $this->assertSame(['id', 'bytes'], $signature['parameters']);
    }

    #[Test]
    public function itReadsAPromotedConstructorPropertyAsAParameter(): void
    {
        $signature = $this->firstSignatureIn(<<<'PHP'
            <?php
            final class C {
                public function __construct(private readonly string $path, public int $size) {}
            }
            PHP);

        $this->assertSame(['path', 'size'], $signature['parameters']);
    }

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
    public function aDefaultValueNeitherEndsTheListNorCountsAsAType(): void
    {
        $signature = $this->firstSignatureIn(<<<'PHP'
            <?php
            final class C {
                public function a(
                    array $options = ['a', 'b'],
                    ?Clock $clock = new SystemClock(),
                    string $id = '',
                ): void {}
            }
            PHP);

        $this->assertSame(['options', 'clock', 'id'], $signature['parameters']);
        $this->assertNotContains('SystemClock', $signature['types']);
    }

    #[Test]
    public function itReadsAParameterListBrokenOverSeveralLines(): void
    {
        $signature = $this->firstSignatureIn(<<<'PHP'
            <?php
            final class C {
                public function a(
                    string $id,
                    ?SplFileInfo $handle = null,
                ): void {
                }
            }
            PHP);

        $this->assertSame(['id', 'handle'], $signature['parameters']);
        $this->assertContains('SplFileInfo', $signature['types']);
    }

    #[Test]
    public function itReadsTheReturnTypeIncludingAUnion(): void
    {
        $signature = $this->firstSignatureIn(<<<'PHP'
            <?php
            final class C {
                public function a(): StreamInterface|string {}
            }
            PHP);

        $this->assertContains('StreamInterface', $signature['types']);
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
