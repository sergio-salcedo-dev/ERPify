<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Support;

use Erpify\Tests\Support\PublicSignatures;
use Erpify\Tests\Support\SignatureReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What ONE signature contains: its parameter names, its parameter types and its return type.
 *
 * Split from {@see PublicSignaturesTest} along the same seam the production classes are split on — that one
 * asks WHICH methods are public and what they are called, this one asks what is inside the parentheses. The
 * two questions need different state machines, and a reader holding both at once is what the production
 * split existed to prevent; keeping their tests in one class quietly reinstated it.
 *
 * Every case below was a measured failure of a simpler reader, not a hypothetical: a parameter attribute
 * whose closing bracket ended the list early, a promoted constructor property, a default value holding a
 * comma or its own parentheses, a list broken over several lines, and a DNF type whose members sat one
 * bracket deeper than the filter admitted.
 *
 * @internal
 */
#[CoversClass(SignatureReader::class)]
final class SignatureReaderTest extends TestCase
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

    /**
     * A DNF type puts its members one bracket deeper than the parameter list, so a reader keeping only
     * depth 1 dropped them — and dropping a TYPE is the silent direction for a scan whose whole subject is
     * which types cross a boundary. The parameter name survived, so nothing looked wrong.
     */
    #[Test]
    public function itReadsTheMembersOfADisjunctiveNormalFormParameterType(): void
    {
        $signature = $this->firstSignatureIn(<<<'PHP'
            <?php
            final class C {
                public function accept((SplFileInfo&Countable)|string $handle): void {}
            }
            PHP);

        $this->assertSame(['handle'], $signature['parameters']);
        $this->assertContains('SplFileInfo', $signature['types']);
        $this->assertContains('Countable', $signature['types']);
    }

    /**
     * The other side of admitting depth 2: an attribute's own arguments stay excluded, so a type named
     * inside `#[Foo(Bar::class)]` never counts as a parameter type. Without this the DNF fix would trade one
     * blind spot for a false positive.
     */
    #[Test]
    public function itStillIgnoresTypesNamedInsideAParameterAttributesArguments(): void
    {
        $signature = $this->firstSignatureIn(<<<'PHP'
            <?php
            final class C {
                public function accept(#[Choice(choices: [SplFileInfo::class])] string $handle): void {}
            }
            PHP);

        $this->assertSame(['handle'], $signature['parameters']);
        $this->assertNotContains('SplFileInfo', $signature['types']);
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
